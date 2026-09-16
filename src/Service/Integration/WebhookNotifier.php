<?php

declare(strict_types=1);

namespace App\Service\Integration;

use App\Entity\DocumentDeletion;
use App\Entity\DocumentEvent;
use App\Service\Http\HttpTransportInterface;
use App\Service\PortalSettings;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Events;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Webhook об изменениях документов для внешних систем (индексатор RAG): собирает события за время
 * обработки запроса (или консольной команды) и после ответа отправляет один POST с подписью HMAC-SHA256
 * (заголовок X-Docportal-Signature: sha256=…). Без повторов: при ошибке доставки внешняя система
 * должна опираться на GET /api/v1/changes?since=….
 */
#[AsDoctrineListener(event: Events::postPersist)]
final class WebhookNotifier implements EventSubscriberInterface
{
    public const EVENT_CHANGED = 'documents.changed';
    public const EVENT_PING = 'ping';

    /** Максимум записей в одном webhook. */
    public const MAX_ITEMS = 500;

    /** События документов, о которых стоит сообщать (просмотры, скачивания и уведомления о сроках не нужны). */
    private const NOTIFY_TYPES = [
        DocumentEvent::CREATE, DocumentEvent::UPDATE, DocumentEvent::NEW_VERSION, DocumentEvent::RESTORE,
        DocumentEvent::PUBLISH, DocumentEvent::UNPUBLISH, DocumentEvent::ARCHIVE, DocumentEvent::MOVE, DocumentEvent::DELETE,
    ];

    /** @var array<string, array{document_id: int, type: string, at: string}> */
    private array $changes = [];

    /** @var array<int, array{document_id: int, deleted_at: string}> */
    private array $deleted = [];

    public function __construct(
        private readonly HttpTransportInterface $http,
        private readonly PortalSettings $settings,
        private readonly UrlGeneratorInterface $urls,
        private readonly LoggerInterface $auditLogger,
        private readonly string $appName,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::TERMINATE => ['flush', -100],
            ConsoleEvents::TERMINATE => ['flush', -100],
        ];
    }

    public function postPersist(PostPersistEventArgs $args): void
    {
        $entity = $args->getObject();
        if ($entity instanceof DocumentEvent) {
            if (!\in_array($entity->getType(), self::NOTIFY_TYPES, true)) {
                return;
            }
            // Названия документов в webhook не передаём: получатель не предъявляет ключ API, а среди
            // изменившихся документов могут быть внутренние. Подробности он забирает сам через /api/v1.
            $id = (int) $entity->getDocument()->getId();
            $this->changes[$id.':'.$entity->getType()] = [
                'document_id' => $id,
                'type' => $entity->getType(),
                'at' => $entity->getCreatedAt()->format(\DATE_ATOM),
            ];
        } elseif ($entity instanceof DocumentDeletion) {
            $this->deleted[$entity->getDocumentId()] = [
                'document_id' => $entity->getDocumentId(),
                'deleted_at' => $entity->getDeletedAt()->format(\DATE_ATOM),
            ];
        }
    }

    /** Есть ли несохранённые изменения для отправки. */
    public function hasPending(): bool
    {
        return [] !== $this->changes || [] !== $this->deleted;
    }

    /** Отправляет накопленные изменения (если webhook настроен) и очищает очередь. */
    public function flush(): void
    {
        if (!$this->hasPending()) {
            return;
        }
        $changes = array_values($this->changes);
        $deleted = array_values($this->deleted);
        $this->changes = [];
        $this->deleted = [];
        $webhook = $this->settings->webhook();
        if (!$webhook['enabled']) {
            return;
        }
        // Очень большие пакеты (массовый импорт из консоли) обрезаем: получатель всё равно синхронизируется через /changes.
        $truncated = \count($changes) > self::MAX_ITEMS || \count($deleted) > self::MAX_ITEMS;
        $changes = \array_slice($changes, 0, self::MAX_ITEMS);
        $deleted = \array_slice($deleted, 0, self::MAX_ITEMS);
        $documents = array_values(array_unique(array_column($changes, 'document_id')));
        $this->send(self::EVENT_CHANGED, [
            'changes' => $changes,
            'deleted' => $deleted,
            'documents' => array_map(fn (int $id): array => ['id' => $id, 'url' => $this->urls->generate('api_document', ['id' => $id], UrlGeneratorInterface::ABSOLUTE_URL)], $documents),
            'truncated' => $truncated,
        ]);
    }

    /**
     * Пробная отправка (кнопка в панели администратора).
     *
     * @return array{ok: bool, message: string}
     */
    public function sendTest(): array
    {
        $webhook = $this->settings->webhook();
        if ('' === $webhook['url']) {
            return ['ok' => false, 'message' => 'Адрес webhook не задан.'];
        }

        return $this->send(self::EVENT_PING, ['message' => 'Проверка webhook портала документации.'], true);
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array{ok: bool, message: string}
     */
    private function send(string $event, array $data, bool $force = false): array
    {
        $webhook = $this->settings->webhook();
        if ('' === $webhook['url'] || (!$force && !$webhook['enabled'])) {
            return ['ok' => false, 'message' => 'Webhook выключен.'];
        }
        $payload = array_merge([
            'event' => $event,
            'portal' => ['name' => $this->appName, 'url' => $this->urls->generate('app_home', [], UrlGeneratorInterface::ABSOLUTE_URL), 'api' => $this->urls->generate('api_index', [], UrlGeneratorInterface::ABSOLUTE_URL)],
            'sent_at' => (new \DateTimeImmutable())->format(\DATE_ATOM),
        ], $data);
        $body = json_encode($payload, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
        if (false === $body) {
            return ['ok' => false, 'message' => 'Не удалось сформировать JSON.'];
        }
        $headers = [
            'Content-Type' => 'application/json',
            'X-Docportal-Event' => $event,
            'X-Docportal-Delivery' => bin2hex(random_bytes(8)),
        ];
        if ('' !== $webhook['secret']) {
            $headers['X-Docportal-Signature'] = 'sha256='.hash_hmac('sha256', $body, $webhook['secret']);
        }
        $response = $this->http->request('POST', $webhook['url'], $body, $headers, 10);
        if ($response->ok()) {
            $this->auditLogger->info('Webhook отправлен', ['event' => $event, 'url' => $webhook['url'], 'status' => $response->status]);

            return ['ok' => true, 'message' => \sprintf('Webhook принят: HTTP %d за %.1f с.', $response->status, $response->seconds)];
        }
        $this->auditLogger->warning('Ошибка отправки webhook', ['event' => $event, 'url' => $webhook['url'], 'error' => $response->describeError()]);

        return ['ok' => false, 'message' => 'Ошибка отправки webhook: '.$response->describeError()];
    }
}
