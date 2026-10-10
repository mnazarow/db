<?php

declare(strict_types=1);

namespace App\Service\Monitoring;

use App\Entity\SystemJob;
use App\Repository\SystemJobRepository;
use App\Service\FileStorage;
use App\Service\PortalSettings;
use App\Service\Preview\DocumentPreviewer;
use Doctrine\DBAL\Connection;
use Doctrine\Migrations\DependencyFactory;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Состояние портала для мониторинга (Zabbix, Prometheus) и страницы «Мониторинг» в панели администратора:
 * база данных, миграции, хранилище, регулярные задания (cron, резервные копии), очередь индексации.
 *
 * Статусы проверок: ok, warning (требует внимания), critical (портал работает неправильно), info (сведения).
 */
final class HealthReport
{
    public const OK = 'ok';
    public const WARNING = 'warning';
    public const CRITICAL = 'critical';
    public const INFO = 'info';

    /** Меньше этого свободного места в хранилище — предупреждение. */
    public const LOW_DISK_BYTES = 1024 * 1024 * 1024;

    /** Больше стольких документов ждут индексации — предупреждение. */
    public const INDEX_BACKLOG_WARNING = 200;

    private const SEVERITY = [self::INFO => 0, self::OK => 0, self::WARNING => 1, self::CRITICAL => 2];

    public function __construct(
        private readonly Connection $connection,
        private readonly FileStorage $storage,
        private readonly SystemJobRepository $jobs,
        private readonly PortalSettings $settings,
        private readonly DocumentPreviewer $previewer,
        #[Autowire(service: 'doctrine.migrations.dependency_factory')]
        private readonly ?DependencyFactory $migrations = null,
        #[Autowire(param: 'app.version')]
        private readonly string $version = '',
        #[Autowire(env: 'default::MAILER_DSN')]
        private readonly ?string $mailerDsn = null,
        #[Autowire(param: 'kernel.secret')]
        private readonly string $appSecret = '',
    ) {
    }

    /**
     * @return array{status: string, version: string, time: string, checks: list<array{key: string, label: string, status: string, message: string, value: int|float|string|null}>, jobs: list<array<string, mixed>>, metrics: array<string, int|float>}
     */
    public function build(): array
    {
        $checks = [];
        $metrics = [];
        $dbOk = $this->checkDatabase($checks);
        if ($dbOk) {
            $this->checkMigrations($checks);
        }
        $this->checkStorage($checks, $metrics);
        $jobs = $dbOk ? $this->checkJobs($checks) : [];
        if ($dbOk) {
            $this->collectCounts($checks, $metrics);
        }
        $this->checkMail($checks);
        $this->checkSecret($checks);
        $checks[] = $this->previewer->isAvailable()
            ? self::check('preview', 'Предпросмотр офисных файлов (LibreOffice)', self::INFO, 'установлен')
            : self::check('preview', 'Предпросмотр офисных файлов (LibreOffice)', self::INFO, 'не установлен — docx/xlsx открываются только скачиванием');

        $status = self::OK;
        foreach ($checks as $check) {
            if (self::SEVERITY[$check['status']] > self::SEVERITY[$status]) {
                $status = $check['status'];
            }
        }

        return [
            'status' => $status,
            'version' => $this->version,
            'time' => date('c'),
            'checks' => $checks,
            'jobs' => $jobs,
            'metrics' => $metrics,
        ];
    }

    /**
     * Метрики в текстовом формате Prometheus (exposition format 0.0.4).
     *
     * @param array<string, mixed> $report результат build()
     */
    public static function prometheus(array $report): string
    {
        $lines = [];
        $metric = static function (string $name, string $help, string $type, array $samples) use (&$lines): void {
            $lines[] = '# HELP '.$name.' '.$help;
            $lines[] = '# TYPE '.$name.' '.$type;
            foreach ($samples as [$labels, $value]) {
                $label = [] === $labels ? '' : '{'.implode(',', array_map(static fn (string $k, string $v): string => $k.'="'.addcslashes($v, "\\\"\n").'"', array_keys($labels), $labels)).'}';
                $lines[] = $name.$label.' '.self::number($value);
            }
        };
        $metric('docportal_up', 'Портал отвечает и база данных доступна (1/0).', 'gauge', [[[], 'critical' === ($report['checks'][0]['status'] ?? '') ? 0 : 1]]);
        $metric('docportal_info', 'Версия портала.', 'gauge', [[['version' => (string) $report['version']], 1]]);
        $metric('docportal_status', 'Общее состояние: 0 — норма, 1 — требует внимания, 2 — неисправность.', 'gauge', [[[], self::SEVERITY[$report['status']] ?? 0]]);
        $metric('docportal_check_status', 'Состояние проверки: 0 — норма, 1 — требует внимания, 2 — неисправность.', 'gauge',
            array_map(static fn (array $c): array => [['check' => $c['key']], self::SEVERITY[$c['status']] ?? 0], $report['checks']));
        $jobs = array_values(array_filter($report['jobs'], static fn (array $j): bool => null !== $j['last_success_at']));
        $metric('docportal_job_last_success_timestamp_seconds', 'Время последнего успешного запуска задания (Unix).', 'gauge',
            array_map(static fn (array $j): array => [['job' => $j['name']], (int) strtotime((string) $j['last_success_at'])], $jobs));
        $metric('docportal_job_last_duration_seconds', 'Длительность последнего запуска задания.', 'gauge',
            array_map(static fn (array $j): array => [['job' => $j['name']], ((int) ($j['duration_ms'] ?? 0)) / 1000], array_values(array_filter($report['jobs'], static fn (array $j): bool => null !== $j['duration_ms']))));
        $metric('docportal_job_failures', 'Сколько запусков задания подряд закончились ошибкой.', 'gauge',
            array_map(static fn (array $j): array => [['job' => $j['name']], (int) $j['failures']], $report['jobs']));
        $help = [
            'storage_free_bytes' => 'Свободное место в хранилище файлов, байт.',
            'storage_used_bytes' => 'Объём файлов всех версий документов, байт.',
            'documents_published' => 'Опубликованных документов.',
            'documents_draft' => 'Черновиков и документов на согласовании.',
            'documents_archived' => 'Документов в архиве.',
            'documents_expired' => 'Опубликованных документов с истёкшим сроком актуальности.',
            'users_active' => 'Активных учётных записей.',
            'acknowledgements_pending' => 'Неподтверждённых назначений ознакомления.',
            'acknowledgements_overdue' => 'Просроченных назначений ознакомления.',
            'approvals_pending' => 'Запросов согласования без решения.',
            'search_index_outdated' => 'Документов, ожидающих индексации содержимого.',
            'ocr_pending' => 'Сканов, ожидающих распознавания.',
        ];
        foreach ($report['metrics'] as $name => $value) {
            $metric('docportal_'.$name, $help[$name] ?? $name, 'gauge', [[[], $value]]);
        }

        return implode("\n", $lines)."\n";
    }

    private static function number(int|float $value): string
    {
        return \is_int($value) ? (string) $value : rtrim(rtrim(\sprintf('%.3F', $value), '0'), '.');
    }

    /** @param list<array<string, mixed>> $checks */
    private function checkDatabase(array &$checks): bool
    {
        try {
            $this->connection->executeQuery('SELECT 1')->fetchOne();
            $checks[] = self::check('database', 'База данных', self::OK, 'доступна');

            return true;
        } catch (\Throwable $e) {
            $checks[] = self::check('database', 'База данных', self::CRITICAL, 'недоступна: '.mb_substr($e->getMessage(), 0, 200));

            return false;
        }
    }

    /** @param list<array<string, mixed>> $checks */
    private function checkMigrations(array &$checks): void
    {
        if (null === $this->migrations) {
            return;
        }
        try {
            $pending = \count($this->migrations->getMigrationStatusCalculator()->getNewMigrations());
            $checks[] = 0 === $pending
                ? self::check('migrations', 'Схема базы данных', self::OK, 'соответствует версии', 0)
                : self::check('migrations', 'Схема базы данных', self::CRITICAL, \sprintf('не применены миграции: %d (docportal console doctrine:migrations:migrate)', $pending), $pending);
        } catch (\Throwable $e) {
            $checks[] = self::check('migrations', 'Схема базы данных', self::WARNING, 'не удалось проверить: '.mb_substr($e->getMessage(), 0, 200));
        }
    }

    /**
     * @param list<array<string, mixed>> $checks
     * @param array<string, int|float>   $metrics
     */
    private function checkStorage(array &$checks, array &$metrics): void
    {
        $dir = $this->storage->getStorageDir();
        if (!is_dir($dir) || !is_writable($dir)) {
            $checks[] = self::check('storage', 'Хранилище файлов', self::CRITICAL, 'каталог недоступен для записи: '.$dir);

            return;
        }
        $free = @disk_free_space($dir);
        if (false === $free) {
            $checks[] = self::check('storage', 'Хранилище файлов', self::OK, 'доступно для записи');

            return;
        }
        $metrics['storage_free_bytes'] = (int) $free;
        $checks[] = $free < self::LOW_DISK_BYTES
            ? self::check('storage', 'Хранилище файлов', self::WARNING, \sprintf('мало места: свободно %s', FileStorage::humanSize((int) $free)), (int) $free)
            : self::check('storage', 'Хранилище файлов', self::OK, \sprintf('свободно %s', FileStorage::humanSize((int) $free)), (int) $free);
    }

    /**
     * @param list<array<string, mixed>> $checks
     *
     * @return list<array<string, mixed>>
     */
    private function checkJobs(array &$checks): array
    {
        try {
            $records = $this->jobs->allByName();
        } catch (\Throwable) {
            return [];
        }
        $optional = [
            'describe' => $this->settings->isLlmEnabled(),
            'telegram' => $this->settings->isTelegramEnabled(),
            'materials-remind' => $this->settings->materialsRemind()['enabled'],
            // Внешнее хранилище и проверку восстановления настраивают отдельно: следим, если они уже запускались.
            'backup-remote' => isset($records['backup-remote']),
            'backup-verify' => isset($records['backup-verify']),
        ];
        $now = time();
        $since = $this->monitoringSince();
        $out = [];
        foreach (SystemJobRecorder::JOBS as $name => $meta) {
            $job = $records[$name] ?? null;
            $expected = $optional[$name] ?? true;
            $out[] = self::jobRow($name, $job, $expected);
            if (!$expected) {
                continue;
            }
            $label = $meta['label'];
            if (null === $job) {
                // Сразу после установки или обновления задания ещё не успели выполниться — это не неисправность,
                // пока не прошёл их обычный интервал (ночная копия — до суток).
                $checks[] = null !== $since && $now - $since <= $meta['max_age']
                    ? self::check('job:'.$name, $label, self::INFO, 'ещё не запускалось (учёт запусков ведётся с '.date('d.m.Y H:i', $since).')')
                    : self::check('job:'.$name, $label, self::WARNING, 'ещё не запускалось — проверьте задания cron');
                continue;
            }
            $success = $job->getLastSuccessAt();
            $age = null === $success ? null : $now - $success->getTimestamp();
            if (SystemJob::STATUS_ERROR === $job->getLastStatus()) {
                $checks[] = self::check('job:'.$name, $label, $job->getFailures() >= 3 ? self::CRITICAL : self::WARNING,
                    \sprintf('ошибка (подряд: %d): %s', $job->getFailures(), (string) $job->getLastMessage()), $age);
            } elseif (null === $age || $age > $meta['max_age']) {
                $checks[] = self::check('job:'.$name, $label, self::WARNING,
                    null === $success ? 'ни одного успешного запуска' : 'последний успешный запуск '.$success->format('d.m.Y H:i'), $age);
            } elseif (SystemJob::STATUS_WARNING === $job->getLastStatus()) {
                $checks[] = self::check('job:'.$name, $label, self::WARNING, (string) $job->getLastMessage(), $age);
            } else {
                $checks[] = self::check('job:'.$name, $label, self::OK, 'выполнено '.$success->format('d.m.Y H:i'), $age);
            }
        }

        return $out;
    }

    /** С какого момента ведётся учёт запусков: время применения миграции, создавшей таблицу system_job. */
    private function monitoringSince(): ?int
    {
        try {
            $at = $this->connection->fetchOne("SELECT MIN(executed_at) FROM doctrine_migration_versions WHERE version LIKE '%Version20261007000013'");
        } catch (\Throwable) {
            return null;
        }
        $ts = \is_string($at) && '' !== $at ? strtotime($at) : false;

        return false === $ts ? null : $ts;
    }

    /** @return array<string, mixed> */
    private static function jobRow(string $name, ?SystemJob $job, bool $expected): array
    {
        return [
            'name' => $name,
            'label' => SystemJobRecorder::label($name),
            'expected' => $expected,
            'status' => $job?->getLastStatus(),
            'last_started_at' => $job?->getLastStartedAt()?->format('c'),
            'last_finished_at' => $job?->getLastFinishedAt()?->format('c'),
            'last_success_at' => $job?->getLastSuccessAt()?->format('c'),
            'message' => $job?->getLastMessage(),
            'duration_ms' => $job?->getLastDurationMs(),
            'runs' => $job?->getRuns() ?? 0,
            'failures' => $job?->getFailures() ?? 0,
        ];
    }

    /**
     * @param list<array<string, mixed>> $checks
     * @param array<string, int|float>   $metrics
     */
    private function collectCounts(array &$checks, array &$metrics): void
    {
        try {
            $c = $this->connection;
            $byStatus = $c->fetchAllKeyValue('SELECT status, COUNT(*) FROM document GROUP BY status');
            $metrics['documents_published'] = (int) ($byStatus['published'] ?? 0);
            $metrics['documents_draft'] = (int) ($byStatus['draft'] ?? 0) + (int) ($byStatus['review'] ?? 0);
            $metrics['documents_archived'] = (int) ($byStatus['archived'] ?? 0);
            $metrics['documents_expired'] = (int) $c->fetchOne("SELECT COUNT(*) FROM document WHERE status = 'published' AND valid_until IS NOT NULL AND valid_until < CURRENT_DATE");
            $metrics['storage_used_bytes'] = (int) $c->fetchOne('SELECT COALESCE(SUM(size), 0) FROM document_version');
            $metrics['users_active'] = (int) $c->fetchOne('SELECT COUNT(*) FROM `user` WHERE active = 1');
            $metrics['acknowledgements_pending'] = (int) $c->fetchOne('SELECT COUNT(*) FROM document_acknowledgement WHERE confirmed_at IS NULL');
            $metrics['acknowledgements_overdue'] = (int) $c->fetchOne('SELECT COUNT(*) FROM document_acknowledgement WHERE confirmed_at IS NULL AND due_at IS NOT NULL AND due_at < CURRENT_DATE');
            $metrics['approvals_pending'] = (int) $c->fetchOne("SELECT COUNT(*) FROM document_approval WHERE decision = 'pending'");
            $outdated = (int) $c->fetchOne(
                'SELECT COUNT(*) FROM document d JOIN document_version v ON v.id = d.current_version_id
                 LEFT JOIN document_text t ON t.document_id = d.id
                 WHERE t.document_id IS NULL OR t.version_number <> v.number OR t.status = \'pending\''
            );
            $metrics['search_index_outdated'] = $outdated;
            $metrics['ocr_pending'] = (int) $c->fetchOne("SELECT COUNT(*) FROM document_text WHERE status = 'pending'");
            $checks[] = $outdated > self::INDEX_BACKLOG_WARNING
                ? self::check('search_index', 'Индекс поиска по содержимому', self::WARNING, \sprintf('ждут индексации: %d документов', $outdated), $outdated)
                : self::check('search_index', 'Индекс поиска по содержимому', self::OK, 0 === $outdated ? 'актуален' : \sprintf('ждут индексации: %d', $outdated), $outdated);
        } catch (\Throwable $e) {
            $checks[] = self::check('counts', 'Сводные показатели', self::WARNING, 'не удалось подсчитать: '.mb_substr($e->getMessage(), 0, 200));
        }
    }

    /** @param list<array<string, mixed>> $checks */
    private function checkMail(array &$checks): void
    {
        $dsn = trim((string) $this->mailerDsn);
        $checks[] = '' === $dsn || str_starts_with($dsn, 'null://')
            ? self::check('mail', 'Почта', self::WARNING, 'письма не отправляются (MAILER_DSN=null://null)')
            : self::check('mail', 'Почта', self::OK, 'настроена');
    }

    /**
     * Секрет приложения (APP_SECRET) защищает сессии, CSRF и «Запомнить меня». Скрипты установки создают
     * случайный; значение из примера настроек (или слишком короткое) — неисправность.
     *
     * @param list<array<string, mixed>> $checks
     */
    private function checkSecret(array &$checks): void
    {
        $secret = trim($this->appSecret);
        $weak = '' === $secret || mb_strlen($secret) < 32 || str_contains(strtolower($secret), 'change_me') || 1 === preg_match('/^(.)\1+$/', $secret);
        $checks[] = $weak
            ? self::check('app_secret', 'Секрет приложения (APP_SECRET)', self::CRITICAL, 'значение по умолчанию или слишком короткое — задайте случайную строку (openssl rand -hex 32) в .env.local и перезапустите портал')
            : self::check('app_secret', 'Секрет приложения (APP_SECRET)', self::OK, 'задан');
    }

    /** @return array{key: string, label: string, status: string, message: string, value: int|float|string|null} */
    private static function check(string $key, string $label, string $status, string $message, int|float|string|null $value = null): array
    {
        return ['key' => $key, 'label' => $label, 'status' => $status, 'message' => $message, 'value' => $value];
    }
}
