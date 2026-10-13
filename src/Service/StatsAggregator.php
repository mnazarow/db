<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\DocumentEvent;
use App\Entity\StatWindow;
use App\Message\RefreshStatistics;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\TransactionIsolationLevel;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Ежедневные итоги статистики: журнал событий (сотни тысяч записей на крупном портале) сводится раз в день —
 * по дням и типам (stat_day) и за периоды страницы «Статистика» по документам и сотрудникам (stat_window), —
 * а события сегодняшнего дня добавляются вживую. Страница статистики не перебирает весь журнал, а цифры на ней
 * остаются точными на текущий момент.
 *
 * Итоги пересчитывает задание cron после полуночи (app:stats:aggregate); если оно не выполнялось, — обработчик
 * фоновых задач по первому запросу статистики за день (а без обработчика — сам этот запрос); пока идёт пересчёт,
 * статистика считается по журналу, как раньше.
 */
final class StatsAggregator implements ResetInterface
{
    /** Периоды страницы статистики (дней; 0 — всё время), для которых хранятся итоги. */
    public const PERIODS = [7, 30, 90, 365, 0];

    /** События, которые не считаются «изменениями» сотрудника (как в журнале активности). */
    private const NOT_CHANGES = [DocumentEvent::VIEW, DocumentEvent::DOWNLOAD, DocumentEvent::EXPIRY_NOTICE];

    /** День, на который итоги проверены в этом процессе (чтобы не спрашивать базу на каждый показатель). */
    private ?string $checkedDay = null;

    private bool $fresh = false;

    public function __construct(
        private readonly Connection $connection,
        private readonly PortalSettings $settings,
        private readonly Validity $validity,
        private readonly ProcessLock $locks,
        private readonly ?LoggerInterface $logger = null,
        private readonly ?BackgroundJobs $background = null,
        private readonly ?CacheItemPoolInterface $cache = null,
    ) {
    }

    public function reset(): void
    {
        $this->checkedDay = null;
    }

    /** Итоги посчитаны сегодня (по вчерашний день включительно). */
    public function isFresh(): bool
    {
        $day = $this->day();
        if ($this->checkedDay !== $day) {
            $this->checkedDay = $day;
            $this->fresh = $day === $this->settings->fresh(PortalSettings::STATS_AGGREGATED_ON);
        }

        return $this->fresh;
    }

    /**
     * Журнал изменён задним числом (удалён документ — вместе с его событиями, демонстрационные данные) — итоги
     * пересчитаются при следующем обращении. Прямым запросом: вызывается и из событий Doctrine во время flush.
     */
    public function invalidate(): void
    {
        self::markStale($this->connection);
        $this->checkedDay = null;
    }

    public static function markStale(Connection $connection): void
    {
        $connection->executeStatement('UPDATE setting SET value = NULL WHERE name = ?', [PortalSettings::STATS_AGGREGATED_ON]);
    }

    /**
     * Итоги готовы к использованию: если они вчерашние, пересчитываются сейчас; false — пересчёт уже идёт в другом
     * процессе или не удался (тогда статистика считается по журналу).
     */
    public function ensureFresh(): bool
    {
        if ($this->isFresh()) {
            return true;
        }
        if (null !== $this->background && $this->background->isWorkerAlive()) {
            // Пересчёт — фоновой задачей (не чаще раза в 10 минут), а этот запрос считает по журналу.
            try {
                $item = $this->cache?->getItem('stats_refresh_requested');
                if (null === $item || !$item->isHit()) {
                    if (null !== $item) {
                        $this->cache?->save($item->set(true)->expiresAfter(600));
                    }
                    $this->background->dispatch(new RefreshStatistics());
                }
            } catch (\Throwable $e) {
                $this->logger?->error('Пересчёт итогов статистики не поставлен в очередь', ['error' => $e->getMessage()]);
            }
            $this->checkedDay = null;

            return $this->isFresh();
        }
        try {
            $this->refresh(false);
        } catch (\Throwable $e) {
            $this->logger?->error('Итоги статистики не пересчитаны — статистика считается по журналу событий', ['error' => $e->getMessage()]);

            return false;
        }

        return $this->fresh;
    }

    /**
     * Пересчитать итоги по журналу (по вчерашний день включительно).
     *
     * @return array{status: string, days: int, documents: int, users: int, seconds: float}
     */
    public function refresh(bool $wait = true): array
    {
        $started = microtime(true);
        $result = ['status' => 'ok', 'days' => 0, 'documents' => 0, 'users' => 0, 'seconds' => 0.0];
        $release = $this->locks->acquire('stats-aggregate');
        for ($i = 0; null === $release && $wait && $i < 60; ++$i) {
            sleep(1);
            $release = $this->locks->acquire('stats-aggregate');
        }
        if (null === $release) {
            return ['status' => 'busy'] + $result;
        }
        try {
            $this->checkedDay = null;
            if (!$wait && $this->isFresh()) {
                // Пока ждали блокировку, итоги пересчитал другой процесс.
                return ['status' => 'fresh'] + $result;
            }
            $today = $this->validity->today();
            $todayStart = $today->format('Y-m-d 00:00:00');
            $c = $this->connection;
            // READ COMMITTED: INSERT … SELECT на уровне REPEATABLE READ блокирует прочитанные строки журнала (и вставку
            // новых событий — просмотров, скачиваний) до конца пересчёта; итоги считаются только по прошедшим дням.
            $isolation = null;
            if ($c->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
                $isolation = $c->getTransactionIsolation();
                $c->setTransactionIsolation(TransactionIsolationLevel::READ_COMMITTED);
            }
            $c->beginTransaction();
            try {
                $c->executeStatement('DELETE FROM stat_day');
                $result['days'] = (int) $c->executeStatement(
                    'INSERT INTO stat_day (day, type, cnt) SELECT DATE(created_at), type, COUNT(*) FROM document_event WHERE created_at < ? GROUP BY DATE(created_at), type',
                    [$todayStart],
                );
                $c->executeStatement('DELETE FROM stat_window');
                foreach (self::PERIODS as $period) {
                    $where = 'created_at < :today';
                    $params = ['today' => $todayStart];
                    if ($period > 0) {
                        $where .= ' AND created_at >= :from';
                        $params['from'] = $today->modify('-'.($period - 1).' days')->format('Y-m-d 00:00:00');
                    }
                    $result['documents'] += (int) $c->executeStatement(
                        \sprintf("INSERT INTO stat_window (period, kind, ref_id, views, downloads, changes, score, last_at)
                            SELECT %d, '%s', document_id, %s, %s, 0, COUNT(*), MAX(created_at)
                            FROM document_event WHERE type IN ('%s', '%s') AND %s GROUP BY document_id",
                            $period, StatWindow::KIND_DOCUMENT, self::countOf(DocumentEvent::VIEW), self::countOf(DocumentEvent::DOWNLOAD),
                            DocumentEvent::VIEW, DocumentEvent::DOWNLOAD, $where),
                        $params,
                    );
                    $result['users'] += (int) $c->executeStatement(
                        \sprintf("INSERT INTO stat_window (period, kind, ref_id, views, downloads, changes, score, last_at)
                            SELECT %d, '%s', user_id, %s, %s, %s, %s, MAX(created_at)
                            FROM document_event WHERE user_id IS NOT NULL AND %s GROUP BY user_id",
                            $period, StatWindow::KIND_USER, self::countOf(DocumentEvent::VIEW), self::countOf(DocumentEvent::DOWNLOAD),
                            self::changesExpr(), "SUM(CASE WHEN type <> '".DocumentEvent::EXPIRY_NOTICE."' THEN 1 ELSE 0 END)", $where),
                        $params,
                    );
                }
                $c->commit();
            } catch (\Throwable $e) {
                $c->rollBack();
                throw $e;
            } finally {
                if (null !== $isolation) {
                    $c->setTransactionIsolation($isolation);
                }
            }
            $this->settings->set(PortalSettings::STATS_AGGREGATED_ON, $today->format('Y-m-d'));
            $this->checkedDay = $today->format('Y-m-d');
            $this->fresh = true;
            $result['seconds'] = round(microtime(true) - $started, 2);
            $this->logger?->info('Пересчитаны итоги статистики', $result);

            return $result;
        } finally {
            $release();
        }
    }

    /**
     * События по дням с $from по $to включительно (за сегодня — вживую).
     *
     * @param list<string> $types
     *
     * @return array<string, array<string, int>> день (Y-m-d) → тип → число
     */
    public function dayCounts(\DateTimeImmutable $from, \DateTimeImmutable $to, array $types): array
    {
        $out = [];
        $rows = $this->connection->fetchAllAssociative(
            'SELECT day, type, cnt FROM stat_day WHERE day >= :from AND day <= :to AND type IN (:types)',
            ['from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d'), 'types' => $types],
            ['types' => ArrayParameterType::STRING],
        );
        foreach ($rows as $row) {
            $day = substr((string) $row['day'], 0, 10);
            $out[$day][(string) $row['type']] = ($out[$day][(string) $row['type']] ?? 0) + (int) $row['cnt'];
        }
        $today = $this->validity->today();
        if ($to->format('Y-m-d') >= $today->format('Y-m-d') && $from->format('Y-m-d') <= $today->format('Y-m-d')) {
            foreach ($this->todayByType($types) as $type => $cnt) {
                $out[$today->format('Y-m-d')][$type] = ($out[$today->format('Y-m-d')][$type] ?? 0) + $cnt;
            }
        }

        return $out;
    }

    /** @return array<string, int> тип события → число за всё время (с сегодняшними) */
    public function typeTotals(): array
    {
        $out = [];
        foreach ($this->connection->fetchAllAssociative('SELECT type, SUM(cnt) AS cnt FROM stat_day GROUP BY type') as $row) {
            $out[(string) $row['type']] = (int) $row['cnt'];
        }
        foreach ($this->todayByType(null) as $type => $cnt) {
            $out[$type] = ($out[$type] ?? 0) + $cnt;
        }

        return $out;
    }

    /**
     * Лидеры периода — документы (по просмотрам и скачиваниям) или сотрудники (по всей активности), с сегодняшними
     * событиями. Кандидатов из итогов берётся на число «сегодняшних» больше: прибавка за сегодня не может поднять
     * в лидеры того, кого нет ни среди них, ни среди сегодняшних, — рейтинг точный.
     *
     * @return list<array{id: int, views: int, downloads: int, changes: int, score: int, last: ?string}>
     */
    public function top(int $period, string $kind, int $limit): array
    {
        $today = $this->todayByRef($kind);
        $table = StatWindow::KIND_USER === $kind ? $this->connection->quoteSingleIdentifier('user') : 'document';
        $rows = $this->connection->fetchAllAssociative(
            'SELECT w.ref_id, w.views, w.downloads, w.changes, w.score, w.last_at FROM stat_window w JOIN '.$table.' x ON x.id = w.ref_id'
            .' WHERE w.period = :period AND w.kind = :kind ORDER BY w.score DESC, w.views DESC, w.downloads DESC, w.ref_id LIMIT '.max(1, $limit + \count($today)),
            ['period' => $period, 'kind' => $kind],
        );
        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['ref_id']] = self::row($row);
        }
        $missing = array_values(array_diff(array_keys($today), array_keys($out)));
        if ([] !== $missing) {
            foreach ($this->connection->fetchAllAssociative(
                'SELECT ref_id, views, downloads, changes, score, last_at FROM stat_window WHERE period = :period AND kind = :kind AND ref_id IN (:ids)',
                ['period' => $period, 'kind' => $kind, 'ids' => $missing],
                ['ids' => ArrayParameterType::INTEGER],
            ) as $row) {
                $out[(int) $row['ref_id']] = self::row($row);
            }
        }
        foreach ($today as $id => $add) {
            $row = $out[$id] ?? ['id' => $id, 'views' => 0, 'downloads' => 0, 'changes' => 0, 'score' => 0, 'last' => null];
            foreach (['views', 'downloads', 'changes', 'score'] as $k) {
                $row[$k] += $add[$k];
            }
            $row['last'] = max((string) $row['last'], (string) $add['last']) ?: null;
            $out[$id] = $row;
        }
        // Порядок при равных суммах — как у подсчёта по журналу: больше просмотров, затем скачиваний, затем по номеру.
        uasort($out, static fn (array $a, array $b): int => [$b['score'], $b['views'], $b['downloads'], $a['id']] <=> [$a['score'], $a['views'], $a['downloads'], $b['id']]);

        return \array_slice(array_values($out), 0, $limit);
    }

    /**
     * Просмотры и скачивания за период по разделам документов (без подразделов; раздел — нынешний).
     *
     * @return array<int, array{view: int, download: int}>
     */
    public function perSection(int $period): array
    {
        $out = [];
        $add = static function (array $rows) use (&$out): void {
            foreach ($rows as $row) {
                $sid = (int) $row['sid'];
                $out[$sid] ??= ['view' => 0, 'download' => 0];
                $out[$sid]['view'] += (int) $row['v'];
                $out[$sid]['download'] += (int) $row['dl'];
            }
        };
        $add($this->connection->fetchAllAssociative(
            'SELECT d.section_id AS sid, SUM(w.views) AS v, SUM(w.downloads) AS dl FROM stat_window w JOIN document d ON d.id = w.ref_id'
            .' WHERE w.period = :period AND w.kind = :kind GROUP BY d.section_id',
            ['period' => $period, 'kind' => StatWindow::KIND_DOCUMENT],
        ));
        $add($this->connection->fetchAllAssociative(
            \sprintf('SELECT d.section_id AS sid, %s AS v, %s AS dl FROM document_event e JOIN document d ON d.id = e.document_id'
                .' WHERE e.type IN (:types) AND e.created_at >= :today GROUP BY d.section_id',
                self::countOf(DocumentEvent::VIEW, 'e.type'), self::countOf(DocumentEvent::DOWNLOAD, 'e.type')),
            ['types' => [DocumentEvent::VIEW, DocumentEvent::DOWNLOAD], 'today' => $this->todayStart()],
            ['types' => ArrayParameterType::STRING],
        ));

        return $out;
    }

    /** @param list<string>|null $types @return array<string, int> */
    private function todayByType(?array $types): array
    {
        $sql = 'SELECT type, COUNT(*) AS cnt FROM document_event WHERE created_at >= :today';
        $params = ['today' => $this->todayStart()];
        $paramTypes = [];
        if (null !== $types) {
            $sql .= ' AND type IN (:types)';
            $params['types'] = $types;
            $paramTypes['types'] = ArrayParameterType::STRING;
        }
        $out = [];
        foreach ($this->connection->fetchAllAssociative($sql.' GROUP BY type', $params, $paramTypes) as $row) {
            $out[(string) $row['type']] = (int) $row['cnt'];
        }

        return $out;
    }

    /** @return array<int, array{views: int, downloads: int, changes: int, score: int, last: ?string}> */
    private function todayByRef(string $kind): array
    {
        if (StatWindow::KIND_USER === $kind) {
            $sql = \sprintf("SELECT user_id AS ref_id, %s AS views, %s AS downloads, %s AS changes, SUM(CASE WHEN type <> '%s' THEN 1 ELSE 0 END) AS score, MAX(created_at) AS last_at
                FROM document_event WHERE user_id IS NOT NULL AND created_at >= :today GROUP BY user_id",
                self::countOf(DocumentEvent::VIEW), self::countOf(DocumentEvent::DOWNLOAD), self::changesExpr(), DocumentEvent::EXPIRY_NOTICE);
        } else {
            $sql = \sprintf("SELECT document_id AS ref_id, %s AS views, %s AS downloads, 0 AS changes, COUNT(*) AS score, MAX(created_at) AS last_at
                FROM document_event WHERE type IN ('%s', '%s') AND created_at >= :today GROUP BY document_id",
                self::countOf(DocumentEvent::VIEW), self::countOf(DocumentEvent::DOWNLOAD), DocumentEvent::VIEW, DocumentEvent::DOWNLOAD);
        }
        $out = [];
        foreach ($this->connection->fetchAllAssociative($sql, ['today' => $this->todayStart()]) as $row) {
            $out[(int) $row['ref_id']] = self::row($row);
        }

        return $out;
    }

    /** @param array<string, mixed> $row @return array{id: int, views: int, downloads: int, changes: int, score: int, last: ?string} */
    private static function row(array $row): array
    {
        return [
            'id' => (int) $row['ref_id'],
            'views' => (int) $row['views'],
            'downloads' => (int) $row['downloads'],
            'changes' => (int) $row['changes'],
            'score' => (int) $row['score'],
            'last' => null !== $row['last_at'] ? (string) $row['last_at'] : null,
        ];
    }

    private static function countOf(string $type, string $column = 'type'): string
    {
        return \sprintf("SUM(CASE WHEN %s = '%s' THEN 1 ELSE 0 END)", $column, $type);
    }

    private static function changesExpr(): string
    {
        return "SUM(CASE WHEN type NOT IN ('".implode("', '", self::NOT_CHANGES)."') THEN 1 ELSE 0 END)";
    }

    private function day(): string
    {
        return $this->validity->today()->format('Y-m-d');
    }

    private function todayStart(): string
    {
        return $this->validity->today()->format('Y-m-d 00:00:00');
    }
}
