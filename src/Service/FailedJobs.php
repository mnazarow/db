<?php

declare(strict_types=1);

namespace App\Service;

use App\Message\BuildSectionBundle;
use App\Message\NotifyAcknowledgementAssigned;
use App\Message\RefreshStatistics;
use App\Message\SendMaterialsReminder;
use App\Message\SendTeamDigest;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\Exception\MessageDecodingFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\ErrorDetailsStamp;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;
use Symfony\Component\Messenger\Transport\Receiver\ListableReceiverInterface;
use Symfony\Component\Messenger\Transport\Receiver\MessageCountAwareInterface;
use Symfony\Component\Messenger\Transport\Receiver\ReceiverInterface;

/**
 * Фоновые задачи, не выполненные и после повторов (очередь failed, config/packages/messenger.yaml): список,
 * повтор и удаление — из «Мониторинга», без консоли (docportal console messenger:failed:show / :retry).
 *
 * Повтор: если обработчик работает, задача снова ставится в очередь (новая запись — App\Messenger\AtMostOnceMiddleware
 * её не пропустит); если нет — выполняется сразу, в запросе администратора. Удачный повтор убирает запись из
 * невыполненных, неудачный — оставляет её с прежней ошибкой.
 */
final class FailedJobs
{
    /** Сколько задач показывать на странице «Мониторинг». */
    public const LIST_LIMIT = 100;

    private const LABELS = [
        BuildSectionBundle::class => 'PDF-сборник раздела',
        NotifyAcknowledgementAssigned::class => 'Уведомления о назначенном ознакомлении',
        RefreshStatistics::class => 'Пересчёт итогов статистики',
        SendMaterialsReminder::class => 'Напоминание по таблицам материалов',
        SendTeamDigest::class => 'Сводка руководителям',
    ];

    public function __construct(
        #[Autowire(service: 'messenger.transport.failed')]
        private readonly ReceiverInterface $receiver,
        private readonly MessageBusInterface $bus,
        private readonly BackgroundJobs $background,
        private readonly LoggerInterface $auditLogger,
    ) {
    }

    /** Название задачи для людей (по классу сообщения). */
    public static function label(object|string $message): string
    {
        $class = \is_object($message) ? $message::class : $message;

        return self::LABELS[$class] ?? (false !== ($p = strrpos($class, '\\')) ? substr($class, $p + 1) : $class);
    }

    /** Сколько задач не выполнено (null — очередь недоступна). */
    public function count(): ?int
    {
        try {
            return $this->receiver instanceof MessageCountAwareInterface ? $this->receiver->getMessageCount() : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Невыполненные задачи, новые сверху. Запись, которую уже не прочитать (класс задачи удалён в новой версии),
     * очередь удаляет сама при чтении.
     *
     * @return list<array{id: string, label: string, details: string, error: string, error_class: string, failed_at: ?\DateTimeInterface, attempts: int}>
     */
    public function all(int $limit = self::LIST_LIMIT): array
    {
        if (!$this->receiver instanceof ListableReceiverInterface) {
            return [];
        }
        for ($attempt = 0; $attempt < 20; ++$attempt) {
            $rows = [];
            try {
                foreach ($this->receiver->all($limit) as $envelope) {
                    $rows[] = $this->describe($envelope);
                }
            } catch (MessageDecodingFailedException) {
                continue; // нечитаемая запись удалена — читаем список заново
            } catch (\Throwable) {
                return [];
            }
            usort($rows, static fn (array $a, array $b): int => ($b['failed_at']?->getTimestamp() ?? 0) <=> ($a['failed_at']?->getTimestamp() ?? 0));

            return $rows;
        }

        return [];
    }

    /**
     * Повторить задачу. Возвращает null при успехе (задача поставлена в очередь или выполнена) или текст ошибки.
     */
    public function retry(string $id, string $by): ?string
    {
        $envelope = $this->find($id);
        if (null === $envelope) {
            return 'Задача уже повторена или удалена.';
        }
        $message = $envelope->getMessage();
        $queued = $this->background->isWorkerAlive();
        try {
            if ($queued) {
                $this->bus->dispatch($message);
            } else {
                // Запись с ReceivedStamp в очередь не отправляется — задача выполняется здесь же.
                @set_time_limit(600);
                $this->bus->dispatch(new Envelope($message, [new ReceivedStamp('failed')]));
            }
        } catch (\Throwable $e) {
            $error = $e instanceof HandlerFailedException ? ($e->getPrevious() ?? $e) : $e;
            $this->auditLogger->warning('Повтор фоновой задачи не удался', ['task' => self::label($message), 'id' => $id, 'error' => $error->getMessage(), 'by' => $by]);

            return $error->getMessage();
        }
        $this->receiver->reject($envelope);
        $this->auditLogger->info($queued ? 'Фоновая задача поставлена в очередь повторно' : 'Фоновая задача выполнена повторно', ['task' => self::label($message), 'id' => $id, 'by' => $by]);

        return null;
    }

    public function delete(string $id, string $by): bool
    {
        $envelope = $this->find($id);
        if (null === $envelope) {
            return false;
        }
        $this->receiver->reject($envelope);
        $this->auditLogger->info('Невыполненная фоновая задача удалена', ['task' => self::label($envelope->getMessage()), 'id' => $id, 'by' => $by]);

        return true;
    }

    /**
     * @return array{retried: int, failed: int, deleted: int, errors: list<string>}
     */
    public function retryAll(string $by): array
    {
        $result = ['retried' => 0, 'failed' => 0, 'deleted' => 0, 'errors' => []];
        foreach ($this->all(self::LIST_LIMIT) as $row) {
            $error = $this->retry($row['id'], $by);
            if (null === $error) {
                ++$result['retried'];
            } else {
                ++$result['failed'];
                $result['errors'][] = $row['label'].': '.$error;
            }
        }

        return $result;
    }

    public function deleteAll(string $by): int
    {
        $n = 0;
        foreach ($this->all(self::LIST_LIMIT) as $row) {
            $n += $this->delete($row['id'], $by) ? 1 : 0;
        }

        return $n;
    }

    private function find(string $id): ?Envelope
    {
        if (!$this->receiver instanceof ListableReceiverInterface || '' === $id) {
            return null;
        }
        try {
            return $this->receiver->find($id);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return array{id: string, label: string, details: string, error: string, error_class: string, failed_at: ?\DateTimeInterface, attempts: int}
     */
    private function describe(Envelope $envelope): array
    {
        $message = $envelope->getMessage();
        $error = $envelope->last(ErrorDetailsStamp::class);
        $redeliveries = $envelope->all(RedeliveryStamp::class);
        $last = $envelope->last(RedeliveryStamp::class);
        $attempts = 1;
        foreach ($redeliveries as $stamp) {
            $attempts = max($attempts, $stamp->getRetryCount() + 1);
        }
        $details = match (true) {
            $message instanceof NotifyAcknowledgementAssigned => \sprintf('документ № %d, сотрудников: %d', $message->documentId, \count($message->userIds)),
            $message instanceof SendTeamDigest, $message instanceof SendMaterialsReminder => null !== $message->requestedBy ? 'запросил '.$message->requestedBy : '',
            $message instanceof BuildSectionBundle => 'задание '.$message->jobId,
            default => '',
        };

        return [
            'id' => (string) $envelope->last(TransportMessageIdStamp::class)?->getId(),
            'label' => self::label($message),
            'details' => $details,
            'error' => $error instanceof ErrorDetailsStamp ? $error->getExceptionMessage() : '',
            'error_class' => $error instanceof ErrorDetailsStamp ? $error->getExceptionClass() : '',
            'failed_at' => $last?->getRedeliveredAt(),
            'attempts' => $attempts,
        ];
    }
}
