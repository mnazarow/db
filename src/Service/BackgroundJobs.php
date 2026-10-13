<?php

declare(strict_types=1);

namespace App\Service;

use App\Message\BackgroundMessage;
use App\Message\UsesPortalLinks;
use Doctrine\DBAL\Connection;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Фоновые задачи: долгие операции (рассылки «Отправить сейчас», PDF-сборники, уведомления о назначенном
 * ознакомлении, пересчёт итогов статистики) уходят в очередь в базе портала и выполняются обработчиком —
 * docportal console messenger:consume async (в режиме native его каждую минуту запускает cron, в Docker —
 * процесс в контейнере app).
 *
 * Задача ставится в очередь, только если обработчик работает (его отметка в кэше свежая): иначе dispatch()
 * возвращает false, и операция выполняется прямо в запросе, как раньше, — без обработчика ничего не теряется
 * и не ждёт. Задачи со ссылками на портал — только если задан его постоянный адрес (APP_DEFAULT_URI).
 */
final class BackgroundJobs implements ResetInterface
{
    /** Имя обработчика в учёте регулярных заданий (system_job, страница «Мониторинг»). */
    public const WORKER_JOB = 'worker';

    /** Транспорт очереди фоновых задач (config/packages/messenger.yaml). */
    public const TRANSPORT = 'async';

    /** Отметка обработчика (ставится каждые 10 секунд и при каждой задаче) действует столько секунд. */
    public const HEARTBEAT_SECONDS = 150;

    private const HEARTBEAT_KEY = 'background_worker_heartbeat';

    /**
     * Обработчик остановился по ограничению времени работы — его перезапустят cron (каждую минуту) или цикл
     * в контейнере: столько секунд он ещё считается работающим, чтобы задачи в этом промежутке шли в очередь.
     */
    public const RESTART_GRACE_SECONDS = 90;

    /** Задачи, ждущие в очереди дольше этого (секунд), — признак остановившегося обработчика. */
    public const STALE_SECONDS = 600;

    private ?bool $alive = null;

    public function __construct(
        private readonly MessageBusInterface $bus,
        private readonly Connection $connection,
        private readonly CacheItemPoolInterface $cache,
        private readonly ?PortalUrl $portalUrl = null,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    public function reset(): void
    {
        $this->alive = null;
    }

    /** Обработчик фоновых задач работает: его отметка свежая (см. App\EventSubscriber\WorkerHeartbeatSubscriber). */
    public function isWorkerAlive(): bool
    {
        if (null !== $this->alive) {
            return $this->alive;
        }
        try {
            return $this->alive = $this->cache->getItem(self::HEARTBEAT_KEY)->isHit();
        } catch (\Throwable) {
            return $this->alive = false;
        }
    }

    public function markWorkerAlive(): void
    {
        try {
            $this->cache->save($this->cache->getItem(self::HEARTBEAT_KEY)->set(time())->expiresAfter(self::HEARTBEAT_SECONDS));
        } catch (\Throwable) {
        }
        $this->alive = null;
    }

    /**
     * Обработчик остановился. $restartWithin > 0 — его перезапустят в течение этого времени (секунд): до тех пор
     * он считается работающим; 0 — остановлен совсем, задачи сразу снова выполняются в запросе.
     */
    public function markWorkerStopped(int $restartWithin = 0): void
    {
        try {
            if ($restartWithin > 0) {
                $this->cache->save($this->cache->getItem(self::HEARTBEAT_KEY)->set(time())->expiresAfter($restartWithin));
            } else {
                $this->cache->deleteItem(self::HEARTBEAT_KEY);
            }
        } catch (\Throwable) {
        }
        $this->alive = null;
    }

    /**
     * Поставить задачу в очередь. false — обработчик не работает или очередь недоступна: вызывающий выполняет
     * операцию сам. Если очередь синхронная (sync://), задача выполняется сразу, а её ошибка — ошибка вызова.
     */
    public function dispatch(BackgroundMessage $message): bool
    {
        if (!$this->isWorkerAlive() || ($message instanceof UsesPortalLinks && false === $this->portalUrl?->isConfigured())) {
            return false;
        }
        try {
            $this->bus->dispatch($message);

            return true;
        } catch (HandlerFailedException $e) {
            throw $e->getPrevious() ?? $e;
        } catch (\Throwable $e) {
            $this->logger?->warning('Фоновая задача не поставлена в очередь — выполняется сразу', ['task' => $message::class, 'error' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * Состояние очереди: сколько задач ждёт и с какого времени самая старая, сколько не выполнено после повторов.
     *
     * @return array{pending: int, oldest: ?\DateTimeImmutable, failed: int}|null null — таблицы очереди нет
     */
    public function queueStats(): ?array
    {
        try {
            $row = $this->connection->fetchAssociative(
                "SELECT SUM(CASE WHEN queue_name <> 'failed' AND delivered_at IS NULL THEN 1 ELSE 0 END) AS pending,
                        MIN(CASE WHEN queue_name <> 'failed' AND delivered_at IS NULL THEN available_at END) AS oldest,
                        SUM(CASE WHEN queue_name = 'failed' THEN 1 ELSE 0 END) AS failed
                 FROM messenger_messages",
            );
        } catch (\Throwable) {
            return null;
        }
        if (false === $row) {
            return ['pending' => 0, 'oldest' => null, 'failed' => 0];
        }

        return [
            'pending' => (int) $row['pending'],
            'oldest' => null !== $row['oldest'] ? new \DateTimeImmutable((string) $row['oldest'], new \DateTimeZone('UTC')) : null, // очередь хранит время в UTC
            'failed' => (int) $row['failed'],
        ];
    }
}
