<?php

declare(strict_types=1);

namespace App\Service\Monitoring;

use App\Entity\SystemJob;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;

/**
 * Учёт запусков регулярных заданий (таблица system_job). Пишет прямыми запросами, а не через
 * EntityManager: задание могло закрыть его ошибкой базы, а учёт не должен ни падать, ни мешать заданию.
 */
final class SystemJobRecorder
{
    /**
     * Известные задания: название и как давно должен был быть последний успешный запуск (секунды).
     * Для заданий, которые выполняются только при включённой функции, срок проверяет HealthReport.
     */
    public const JOBS = [
        'expiry' => ['label' => 'Проверка сроков актуальности', 'max_age' => 26 * 3600],
        'reindex' => ['label' => 'Индексация содержимого', 'max_age' => 2 * 3600 + 900],
        'acknowledge-remind' => ['label' => 'Напоминания об ознакомлении', 'max_age' => 26 * 3600],
        'describe' => ['label' => 'Описания через LLM', 'max_age' => 2 * 3600 + 900],
        'telegram' => ['label' => 'Сообщения бота Telegram', 'max_age' => 15 * 60],
        'materials-remind' => ['label' => 'Напоминания по таблицам материалов', 'max_age' => 8 * 86400],
        'ai-index' => ['label' => 'Смысловой индекс для ИИ', 'max_age' => 2 * 3600 + 900],
        'backup' => ['label' => 'Резервная копия', 'max_age' => 26 * 3600],
        'backup-remote' => ['label' => 'Копия во внешнем хранилище', 'max_age' => 26 * 3600],
        'backup-verify' => ['label' => 'Проверка восстановления из копии', 'max_age' => 8 * 86400],
        // docportal auto-update on: ночная проверка каталога обновлений (новой версии нет — тоже успешный запуск).
        'auto-update' => ['label' => 'Автоматическое обновление', 'max_age' => 26 * 3600],
    ];

    public const STATUSES = [SystemJob::STATUS_RUNNING, SystemJob::STATUS_OK, SystemJob::STATUS_WARNING, SystemJob::STATUS_ERROR];

    public function __construct(
        private readonly Connection $connection,
        private readonly LoggerInterface $logger,
    ) {
    }

    public static function label(string $name): string
    {
        return self::JOBS[$name]['label'] ?? $name;
    }

    public static function isValidName(string $name): bool
    {
        return 1 === preg_match('/^[a-z0-9][a-z0-9:_-]{0,63}$/', $name);
    }

    public function start(string $name): void
    {
        $this->write(
            'INSERT INTO system_job (name, last_started_at, last_status, runs, failures) VALUES (:name, :now, :status, 0, 0)
             ON DUPLICATE KEY UPDATE last_started_at = :now, last_status = :status',
            ['name' => $name, 'now' => self::now(), 'status' => SystemJob::STATUS_RUNNING],
        );
    }

    public function finish(string $name, string $status, ?string $message = null, ?int $durationMs = null): void
    {
        if (!\in_array($status, self::STATUSES, true) || SystemJob::STATUS_RUNNING === $status) {
            $status = SystemJob::STATUS_ERROR;
        }
        $now = self::now();
        $success = SystemJob::STATUS_ERROR === $status ? null : $now;
        $message = null === $message ? null : mb_substr(trim(preg_replace('/\s+/u', ' ', $message) ?? ''), 0, 500);
        $this->write(
            'INSERT INTO system_job (name, last_finished_at, last_success_at, last_status, last_message, last_duration_ms, runs, failures)
             VALUES (:name, :now, :success, :status, :message, :duration, 1, :first_failure)
             ON DUPLICATE KEY UPDATE last_finished_at = :now, last_success_at = COALESCE(:success, last_success_at),
                 last_status = :status, last_message = :message, last_duration_ms = :duration, runs = runs + 1,
                 failures = IF(:is_error = 1, failures + 1, 0)',
            [
                'name' => $name,
                'now' => $now,
                'success' => $success,
                'status' => $status,
                'message' => '' === $message ? null : $message,
                'duration' => null === $durationMs ? null : max(0, $durationMs),
                'first_failure' => SystemJob::STATUS_ERROR === $status ? 1 : 0,
                'is_error' => SystemJob::STATUS_ERROR === $status ? 1 : 0,
            ],
        );
    }

    /** @param array<string, mixed> $params */
    private function write(string $sql, array $params): void
    {
        try {
            $this->connection->executeStatement($sql, $params);
        } catch (\Throwable $e) {
            $this->logger->warning('Не удалось записать сведения о запуске задания', ['job' => $params['name'] ?? null, 'error' => $e->getMessage()]);
        }
    }

    private static function now(): string
    {
        return (new \DateTimeImmutable())->format('Y-m-d H:i:s');
    }
}
