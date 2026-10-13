<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Document;
use App\Entity\DocumentEvent;
use App\Entity\Section;
use App\Entity\StatWindow;
use App\Repository\DocumentEventRepository;
use App\Repository\DocumentRepository;
use App\Repository\DocumentVersionRepository;
use App\Repository\SectionModeratorRepository;
use App\Repository\SectionRepository;
use App\Repository\UserRepository;

/**
 * Статистика по документам: сводные показатели, активность по дням/месяцам,
 * рейтинг документов, разрез по разделам и пользователям, актуальность.
 *
 * Показатели по журналу событий для всего портала берутся из ежедневных итогов (StatsAggregator) с добавлением
 * сегодняшних событий; пока итоги не готовы (и для отдельных разделов и документов) — считаются по журналу.
 */
final class StatsService
{
    public function __construct(
        private readonly DocumentRepository $documents,
        private readonly DocumentVersionRepository $versions,
        private readonly DocumentEventRepository $events,
        private readonly SectionRepository $sections,
        private readonly SectionModeratorRepository $moderators,
        private readonly UserRepository $users,
        private readonly Validity $validity,
        private readonly ?StatsAggregator $aggregates = null,
    ) {
    }

    /** Итоги, если они готовы (посчитаны сегодня или пересчитаны сейчас). */
    private function aggregates(): ?StatsAggregator
    {
        return null !== $this->aggregates && $this->aggregates->ensureFresh() ? $this->aggregates : null;
    }

    /** Период страницы статистики, для которого хранятся итоги (null — всё время → 0), или null. */
    private static function period(?int $days): ?int
    {
        $period = $days ?? 0;

        return \in_array($period, StatsAggregator::PERIODS, true) ? $period : null;
    }

    /**
     * Сводные показатели портала (или подмножества разделов).
     *
     * @param list<int>|null $sectionIds
     *
     * @return array<string, mixed>
     */
    public function overview(?array $sectionIds = null): array
    {
        $today = $this->validity->today();
        $since30 = $today->modify('-29 days');
        $byStatus = $this->documents->countByStatus($sectionIds);
        $validity = $this->documents->countByValidity($today, $this->validity->getSoonDays(), $sectionIds);
        $agg = null === $sectionIds ? $this->aggregates() : null;
        if (null !== $agg) {
            $sum = array_fill_keys([DocumentEvent::VIEW, DocumentEvent::DOWNLOAD, DocumentEvent::NEW_VERSION, DocumentEvent::CREATE], 0);
            foreach ($agg->dayCounts($since30, $today, array_keys($sum)) as $types) {
                foreach ($types as $type => $cnt) {
                    $sum[$type] += $cnt;
                }
            }
            $events = [
                'views_30d' => $sum[DocumentEvent::VIEW],
                'downloads_30d' => $sum[DocumentEvent::DOWNLOAD],
                'new_versions_30d' => $sum[DocumentEvent::NEW_VERSION] + $sum[DocumentEvent::CREATE],
                'events_by_type' => $agg->typeTotals(),
            ];
        } else {
            $events = [
                'views_30d' => $this->events->countByTypeSince(DocumentEvent::VIEW, $since30, $sectionIds),
                'downloads_30d' => $this->events->countByTypeSince(DocumentEvent::DOWNLOAD, $since30, $sectionIds),
                'new_versions_30d' => $this->events->countByTypeSince(DocumentEvent::NEW_VERSION, $since30, $sectionIds) + $this->events->countByTypeSince(DocumentEvent::CREATE, $since30, $sectionIds),
                'events_by_type' => $this->events->countAllByType(),
            ];
        }

        return $events + [
            'documents_total' => array_sum($byStatus),
            'documents_by_status' => $byStatus,
            'documents_by_type' => $this->documents->countByType(),
            'validity' => $validity,
            'sections' => $this->sections->countAll(),
            'moderators' => $this->moderators->countAll(),
            'users' => $this->users->countAll(),
            'users_by_source' => $this->users->countBySource(),
            'versions' => $this->versions->countAll(),
            'storage_bytes' => $this->versions->totalStorageBytes(),
        ];
    }

    /**
     * Активность по дням за последние N дней (для графика).
     *
     * @return array<string, array{view: int, download: int}>
     */
    public function activityByDay(int $days = 30, ?Document $document = null, ?array $sectionIds = null): array
    {
        $today = $this->validity->today();
        $from = $today->modify('-'.($days - 1).' days');
        $agg = null === $document && null === $sectionIds ? $this->aggregates() : null;
        if (null === $agg) {
            return $this->events->activityByDay($from, $today, $document, $sectionIds);
        }
        $counts = $agg->dayCounts($from, $today, [DocumentEvent::VIEW, DocumentEvent::DOWNLOAD]);
        $out = [];
        for ($d = $from; $d <= $today; $d = $d->modify('+1 day')) {
            $key = $d->format('Y-m-d');
            $out[$key] = ['view' => $counts[$key][DocumentEvent::VIEW] ?? 0, 'download' => $counts[$key][DocumentEvent::DOWNLOAD] ?? 0];
        }

        return $out;
    }

    /** @return array<string, array{view: int, download: int, new_version: int}> */
    public function activityByMonth(int $months = 12): array
    {
        $agg = $this->aggregates();
        if (null === $agg) {
            return $this->events->activityByMonth($months);
        }
        $today = $this->validity->today();
        $start = $today->modify('first day of this month')->modify('-'.($months - 1).' months');
        $out = [];
        for ($i = 0; $i < $months; ++$i) {
            $out[$start->modify('+'.$i.' months')->format('Y-m')] = ['view' => 0, 'download' => 0, 'new_version' => 0];
        }
        foreach ($agg->dayCounts($start, $today, [DocumentEvent::VIEW, DocumentEvent::DOWNLOAD, DocumentEvent::NEW_VERSION, DocumentEvent::CREATE]) as $day => $types) {
            $month = substr($day, 0, 7);
            if (!isset($out[$month])) {
                continue;
            }
            foreach ($types as $type => $cnt) {
                $out[$month][DocumentEvent::CREATE === $type ? 'new_version' : $type] += $cnt;
            }
        }

        return $out;
    }

    /**
     * Рейтинг документов по просмотрам/скачиваниям за период (null — за всё время).
     *
     * @return list<array{document: Document, views: int, downloads: int}>
     */
    public function topDocuments(?int $days = 30, int $limit = 10): array
    {
        $agg = null !== self::period($days) ? $this->aggregates() : null;
        if (null !== $agg) {
            $counts = [];
            foreach ($agg->top((int) self::period($days), StatWindow::KIND_DOCUMENT, $limit) as $row) {
                $counts[$row['id']] = ['view' => $row['views'], 'download' => $row['downloads']];
            }
        } else {
            $since = null === $days ? null : $this->validity->today()->modify('-'.($days - 1).' days');
            $counts = $this->events->countsPerDocumentSince($since);
            arsort($counts);
            uasort($counts, static fn ($a, $b) => ($b['view'] + $b['download']) <=> ($a['view'] + $a['download']));
        }
        $ids = \array_slice(array_keys($counts), 0, $limit);
        if ([] === $ids) {
            return [];
        }
        $docs = [];
        foreach ($this->documents->findBy(['id' => $ids]) as $doc) {
            $docs[$doc->getId()] = $doc;
        }
        $out = [];
        foreach ($ids as $id) {
            if (isset($docs[$id])) {
                $out[] = ['document' => $docs[$id], 'views' => $counts[$id]['view'], 'downloads' => $counts[$id]['download']];
            }
        }

        return $out;
    }

    /**
     * Разрез по разделам: документы (по статусам), просмотры и скачивания за период, актуальность.
     * Показатели считаются по разделу вместе со всеми подразделами.
     *
     * @return list<array{section: Section, direct: array<string,int>, subtree: array<string,int>, views: int, downloads: int, expired: int, soon: int, moderators: int}>
     */
    public function perSection(?int $days = 30): array
    {
        $tree = $this->sections->findAllTree();
        $perSection = $this->documents->countPerSection();
        $agg = null !== self::period($days) ? $this->aggregates() : null;
        if (null !== $agg) {
            $events = $agg->perSection((int) self::period($days));
        } else {
            $since = null === $days ? null : $this->validity->today()->modify('-'.($days - 1).' days');
            $events = $this->events->countsPerSectionSince($since);
        }
        $today = $this->validity->today();
        // Сроки — числами по разделам, а не списком документов (на крупном портале их тысячи).
        $expiringPerSection = $this->documents->countExpiringPerSection($today, $this->validity->getSoonDays());

        // Показатели раздела складываются во все разделы его пути (сам раздел и предки) — так за один
        // проход получаются суммы по поддеревьям (раньше — перебор всех пар разделов и документов).
        $paths = [];
        foreach ($tree as $section) {
            $paths[(int) $section->getId()] = $section->getPathIds();
        }
        $subtree = $views = $downloads = $expired = $soon = [];
        foreach ($perSection as $sid => $statuses) {
            foreach ($paths[$sid] ?? [] as $a) {
                foreach ($statuses as $st => $cnt) {
                    $subtree[$a][$st] = ($subtree[$a][$st] ?? 0) + $cnt;
                }
            }
        }
        foreach ($events as $sid => $c) {
            foreach ($paths[$sid] ?? [] as $a) {
                $views[$a] = ($views[$a] ?? 0) + $c['view'];
                $downloads[$a] = ($downloads[$a] ?? 0) + $c['download'];
            }
        }
        foreach ($expiringPerSection as $sid => $c) {
            foreach ($paths[$sid] ?? [] as $a) {
                $expired[$a] = ($expired[$a] ?? 0) + $c['expired'];
                $soon[$a] = ($soon[$a] ?? 0) + $c['soon'];
            }
        }

        $zero = array_fill_keys(Document::STATUSES, 0);
        $out = [];
        foreach ($tree as $section) {
            $sid = (int) $section->getId();
            $out[] = [
                'section' => $section,
                'direct' => ($perSection[$sid] ?? []) + $zero,
                'subtree' => array_merge($zero, $subtree[$sid] ?? []),
                'views' => $views[$sid] ?? 0,
                'downloads' => $downloads[$sid] ?? 0,
                'expired' => $expired[$sid] ?? 0,
                'soon' => $soon[$sid] ?? 0,
                'moderators' => $section->getModerators()->count(),
            ];
        }

        return $out;
    }

    /** @return list<array{name: string, username: ?string, views: int, downloads: int, changes: int, last: \DateTimeImmutable}> */
    public function userActivity(?int $days = 30, int $limit = 50): array
    {
        $agg = null !== self::period($days) ? $this->aggregates() : null;
        if (null === $agg) {
            $since = null === $days ? null : $this->validity->today()->modify('-'.($days - 1).' days');

            return $this->events->userActivity($since, $limit);
        }
        $rows = $agg->top((int) self::period($days), StatWindow::KIND_USER, $limit);
        $names = $this->users->namesByIds(array_column($rows, 'id'));
        $out = [];
        foreach ($rows as $row) {
            if (!isset($names[$row['id']])) {
                continue;
            }
            $out[] = [
                'name' => $names[$row['id']]['name'],
                'username' => $names[$row['id']]['username'],
                'views' => $row['views'],
                'downloads' => $row['downloads'],
                'changes' => $row['changes'],
                'last' => new \DateTimeImmutable($row['last'] ?? '2000-01-01'),
            ];
        }

        return $out;
    }

    /**
     * Полная статистика по одному документу.
     *
     * @return array<string, mixed>
     */
    public function forDocument(Document $document): array
    {
        $summary = $this->events->documentSummary($document);
        $byDay = $this->activityByDay(30, $document);
        $versions = [];
        foreach ($document->getVersions() as $v) {
            $versions[] = ['version' => $v, 'downloads' => $v->getDownloadCount()];
        }

        return [
            'summary' => $summary,
            'by_day' => $byDay,
            'views_30d' => array_sum(array_column($byDay, 'view')),
            'downloads_30d' => array_sum(array_column($byDay, 'download')),
            'users' => $this->events->documentUsers($document),
            'versions' => $versions,
            'validity' => $this->validity->stateOf($document),
            'validity_text' => $this->validity->describe($document),
            'events' => $this->events->findForDocument($document, 50),
        ];
    }

    /**
     * Актуальность по дереву разделов: для каждого раздела — число опубликованных документов
     * по состояниям (просрочено / истекает / актуально / бессрочно) непосредственно в разделе
     * и вместе с подразделами, а также самый большой срок просрочки в поддереве.
     *
     * @return array{
     *     rows: list<array{section: Section, direct: array{expired: int, soon: int, valid: int, none: int}, subtree: array{expired: int, soon: int, valid: int, none: int}, problems: int, max_overdue: int, share: array{expired: float, soon: float, valid: float, none: float}}>,
     *     totals: array{expired: int, soon: int, valid: int, none: int, documents: int},
     *     today: \DateTimeImmutable,
     *     soon_days: int
     * }
     */
    public function validityBySection(?Section $root = null, bool $onlyProblems = false): array
    {
        $today = $this->validity->today();
        $soonDays = $this->validity->getSoonDays();
        $tree = $this->sections->findAllTree();
        if (null !== $root) {
            $tree = array_values(array_filter($tree, static fn (Section $s) => $s->isSameOrDescendantOf($root)));
        }
        $empty = ['expired' => 0, 'soon' => 0, 'valid' => 0, 'none' => 0];
        $direct = [];
        $maxOverdue = [];
        foreach ($tree as $s) {
            $direct[$s->getId()] = $empty;
            $maxOverdue[$s->getId()] = 0;
        }
        foreach ($this->documents->publishedValidity() as $row) {
            $sid = (int) $row['sid'];
            if (!isset($direct[$sid])) {
                continue;
            }
            $state = $this->validity->stateForDate($row['vu']);
            ++$direct[$sid][$state];
            if (Validity::EXPIRED === $state) {
                $days = (int) $today->diff($row['vu']->setTime(0, 0))->format('%a');
                $maxOverdue[$sid] = max($maxOverdue[$sid], $days);
            }
        }
        // Суммы по поддеревьям: каждый раздел добавляет свои счётчики всем предкам (в пределах выбранного дерева).
        $subtree = $direct;
        $subtreeOverdue = $maxOverdue;
        foreach ($tree as $s) {
            foreach ($s->getPathIds() as $ancestorId) {
                if ($ancestorId === $s->getId() || !isset($subtree[$ancestorId])) {
                    continue;
                }
                foreach ($direct[$s->getId()] as $k => $v) {
                    $subtree[$ancestorId][$k] += $v;
                }
                $subtreeOverdue[$ancestorId] = max($subtreeOverdue[$ancestorId], $maxOverdue[$s->getId()]);
            }
        }
        $rows = [];
        $totals = $empty + ['documents' => 0];
        foreach ($tree as $s) {
            $sid = (int) $s->getId();
            $sub = $subtree[$sid];
            $problems = $sub['expired'] + $sub['soon'];
            if ($onlyProblems && 0 === $problems) {
                continue;
            }
            $total = array_sum($sub);
            $share = $empty;
            if ($total > 0) {
                foreach ($sub as $k => $v) {
                    $share[$k] = round($v * 100 / $total, 1);
                }
            }
            $rows[] = ['section' => $s, 'direct' => $direct[$sid], 'subtree' => $sub, 'problems' => $problems, 'max_overdue' => $subtreeOverdue[$sid], 'share' => $share];
            if (null === $s->getParent() || (null !== $root && $s->getId() === $root->getId())) {
                foreach ($sub as $k => $v) {
                    $totals[$k] += $v;
                }
                $totals['documents'] += $total;
            }
        }

        return ['rows' => $rows, 'totals' => $totals, 'today' => $today, 'soon_days' => $soonDays];
    }

    /**
     * Устаревшие документы (просроченные и истекающие) с группировкой по ответственным.
     *
     * @return array{documents: list<Document>, owners: list<array{name: string, expired: int, soon: int}>}
     */
    public function outdatedDocuments(?Section $root = null): array
    {
        $ids = null !== $root ? $this->sections->findSubtreeIds($root) : null;
        $documents = $this->expiring($ids);
        $today = $this->validity->today();
        usort($documents, static fn (Document $a, Document $b) => [$a->getSection()->getPath(), $a->getValidUntil()?->format('Y-m-d') ?? ''] <=> [$b->getSection()->getPath(), $b->getValidUntil()?->format('Y-m-d') ?? '']);
        $owners = [];
        foreach ($documents as $doc) {
            $name = $doc->getOwner()?->getDisplayName() ?? '— без ответственного —';
            $owners[$name] ??= ['name' => $name, 'expired' => 0, 'soon' => 0];
            ++$owners[$name][$doc->isExpired($today) ? 'expired' : 'soon'];
        }
        usort($owners, static fn (array $a, array $b) => [$b['expired'], $b['soon']] <=> [$a['expired'], $a['soon']]);

        return ['documents' => $documents, 'owners' => array_values($owners)];
    }

    /** @return list<Document> */
    public function expiring(?array $sectionIds = null, int $limit = 0): array
    {
        return $this->documents->findExpiring($this->validity->today(), $this->validity->getSoonDays(), $sectionIds, true, $limit);
    }

    /** @return list<Document> */
    public function stale(int $days = 180, int $limit = 20): array
    {
        return $this->documents->findStale($this->validity->today()->modify('-'.$days.' days'), $limit);
    }

    /**
     * Данные для SVG-графика: подписи и значения.
     *
     * @param array<string, array<string, int>> $series
     *
     * @return array{labels: list<string>, view: list<int>, download: list<int>, max: int}
     */
    public static function chartSeries(array $series, string $labelFormat = 'd.m'): array
    {
        $labels = $view = $download = [];
        $max = 1;
        foreach ($series as $key => $values) {
            $labels[] = 7 === \strlen($key) ? (new \DateTimeImmutable($key.'-01'))->format('m.Y') : (new \DateTimeImmutable($key))->format($labelFormat);
            $view[] = $values['view'];
            $download[] = $values['download'];
            $max = max($max, $values['view'], $values['download']);
        }

        return ['labels' => $labels, 'view' => $view, 'download' => $download, 'max' => $max];
    }
}
