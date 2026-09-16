<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Document;
use App\Entity\DocumentEvent;
use App\Entity\Section;
use App\Repository\DocumentEventRepository;
use App\Repository\DocumentRepository;
use App\Repository\DocumentVersionRepository;
use App\Repository\SectionModeratorRepository;
use App\Repository\SectionRepository;
use App\Repository\UserRepository;

/**
 * Статистика по документам: сводные показатели, активность по дням/месяцам,
 * рейтинг документов, разрез по разделам и пользователям, актуальность.
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
    ) {
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

        return [
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
            'views_30d' => $this->events->countByTypeSince(DocumentEvent::VIEW, $since30, $sectionIds),
            'downloads_30d' => $this->events->countByTypeSince(DocumentEvent::DOWNLOAD, $since30, $sectionIds),
            'new_versions_30d' => $this->events->countByTypeSince(DocumentEvent::NEW_VERSION, $since30, $sectionIds) + $this->events->countByTypeSince(DocumentEvent::CREATE, $since30, $sectionIds),
            'events_by_type' => $this->events->countAllByType(),
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

        return $this->events->activityByDay($today->modify('-'.($days - 1).' days'), $today, $document, $sectionIds);
    }

    /** @return array<string, array{view: int, download: int, new_version: int}> */
    public function activityByMonth(int $months = 12): array
    {
        return $this->events->activityByMonth($months);
    }

    /**
     * Рейтинг документов по просмотрам/скачиваниям за период (null — за всё время).
     *
     * @return list<array{document: Document, views: int, downloads: int}>
     */
    public function topDocuments(?int $days = 30, int $limit = 10): array
    {
        $since = null === $days ? null : $this->validity->today()->modify('-'.($days - 1).' days');
        $counts = $this->events->countsPerDocumentSince($since);
        arsort($counts);
        uasort($counts, static fn ($a, $b) => ($b['view'] + $b['download']) <=> ($a['view'] + $a['download']));
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
        $since = null === $days ? null : $this->validity->today()->modify('-'.($days - 1).' days');
        $counts = $this->events->countsPerDocumentSince($since);

        // Раздел каждого документа с событиями.
        $docSection = [];
        if ([] !== $counts) {
            foreach ($this->documents->findBy(['id' => array_keys($counts)]) as $doc) {
                $docSection[$doc->getId()] = $doc->getSection()->getPathIds();
            }
        }
        $today = $this->validity->today();
        $expiring = $this->documents->findExpiring($today, $this->validity->getSoonDays());

        $out = [];
        foreach ($tree as $section) {
            $sid = (int) $section->getId();
            $direct = ($perSection[$sid] ?? []) + array_fill_keys(Document::STATUSES, 0);
            $subtree = array_fill_keys(Document::STATUSES, 0);
            foreach ($perSection as $otherId => $statuses) {
                $other = null;
                foreach ($tree as $t) {
                    if ($t->getId() === $otherId) {
                        $other = $t;
                        break;
                    }
                }
                if (null !== $other && $other->isSameOrDescendantOf($section)) {
                    foreach ($statuses as $st => $cnt) {
                        $subtree[$st] += $cnt;
                    }
                }
            }
            $views = $downloads = 0;
            foreach ($counts as $docId => $c) {
                if (isset($docSection[$docId]) && \in_array($sid, $docSection[$docId], true)) {
                    $views += $c['view'];
                    $downloads += $c['download'];
                }
            }
            $expired = $soon = 0;
            foreach ($expiring as $doc) {
                if ($doc->getSection()->isSameOrDescendantOf($section)) {
                    if ($doc->isExpired($today)) {
                        ++$expired;
                    } else {
                        ++$soon;
                    }
                }
            }
            $out[] = [
                'section' => $section,
                'direct' => $direct,
                'subtree' => $subtree,
                'views' => $views,
                'downloads' => $downloads,
                'expired' => $expired,
                'soon' => $soon,
                'moderators' => $section->getModerators()->count(),
            ];
        }

        return $out;
    }

    /** @return list<array{name: string, username: ?string, views: int, downloads: int, changes: int, last: \DateTimeImmutable}> */
    public function userActivity(?int $days = 30, int $limit = 50): array
    {
        $since = null === $days ? null : $this->validity->today()->modify('-'.($days - 1).' days');

        return $this->events->userActivity($since, $limit);
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
