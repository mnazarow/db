<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Document;
use App\Entity\DocumentEvent;
use App\Entity\Section;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\Persistence\ManagerRegistry;

/**
 * События по документам — источник статистики (просмотры, скачивания, изменения).
 *
 * @extends ServiceEntityRepository<DocumentEvent>
 */
final class DocumentEventRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DocumentEvent::class);
    }

    /** @return list<DocumentEvent> */
    public function findForDocument(Document $document, int $limit = 100, ?string $type = null): array
    {
        $qb = $this->createQueryBuilder('e')->leftJoin('e.user', 'u')->addSelect('u')
            ->andWhere('e.document = :d')->setParameter('d', $document)
            ->orderBy('e.createdAt', \SortDirection::Descending)->setMaxResults($limit);
        if (null !== $type) {
            $qb->andWhere('e.type = :t')->setParameter('t', $type);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Журнал событий с фильтрами (панель администратора).
     *
     * @param array{type?: ?string, user?: ?User, document?: ?Document, section?: ?Section, from?: ?\DateTimeImmutable, to?: ?\DateTimeImmutable} $filters
     *
     * @return list<DocumentEvent>
     */
    public function findFiltered(array $filters, int $limit = 300): array
    {
        $qb = $this->createQueryBuilder('e')
            ->leftJoin('e.user', 'u')->addSelect('u')
            ->join('e.document', 'd')->addSelect('d')
            ->join('d.section', 's')->addSelect('s')
            ->orderBy('e.createdAt', \SortDirection::Descending)->setMaxResults($limit);
        if (!empty($filters['type'])) {
            $qb->andWhere('e.type = :t')->setParameter('t', $filters['type']);
        }
        if (!empty($filters['user'])) {
            $qb->andWhere('e.user = :u')->setParameter('u', $filters['user']);
        }
        if (!empty($filters['document'])) {
            $qb->andWhere('e.document = :d')->setParameter('d', $filters['document']);
        }
        if (!empty($filters['section'])) {
            $qb->andWhere('s.path LIKE :p')->setParameter('p', $filters['section']->getPath().'%');
        }
        if (!empty($filters['from'])) {
            $qb->andWhere('e.createdAt >= :from')->setParameter('from', $filters['from']->setTime(0, 0));
        }
        if (!empty($filters['to'])) {
            $qb->andWhere('e.createdAt < :to')->setParameter('to', $filters['to']->modify('+1 day')->setTime(0, 0));
        }

        return $qb->getQuery()->getResult();
    }

    /** Число событий указанного типа за период (по всему порталу или по разделам). */
    public function countByTypeSince(string $type, \DateTimeImmutable $since, ?array $sectionIds = null): int
    {
        $qb = $this->createQueryBuilder('e')->select('COUNT(e.id)')
            ->andWhere('e.type = :t')->setParameter('t', $type)
            ->andWhere('e.createdAt >= :since')->setParameter('since', $since);
        if (null !== $sectionIds) {
            if ([] === $sectionIds) {
                return 0;
            }
            $qb->join('e.document', 'd')->andWhere('d.section IN (:ids)')->setParameter('ids', $sectionIds);
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * Просмотры и скачивания по дням за период (для графика).
     *
     * @return array<string, array{view: int, download: int}> дата (Y-m-d) => счётчики
     */
    public function activityByDay(\DateTimeImmutable $from, \DateTimeImmutable $to, ?Document $document = null, ?array $sectionIds = null): array
    {
        if (null !== $sectionIds && [] === $sectionIds) {
            return $this->emptyDays($from, $to);
        }
        // Счёт — в базе (GROUP BY по дню): тысячи просмотров за месяц не загружаются в память по одному.
        $sql = 'SELECT e.type AS t, DATE(e.created_at) AS d, COUNT(*) AS cnt FROM document_event e';
        $params = ['types' => [DocumentEvent::VIEW, DocumentEvent::DOWNLOAD], 'from' => $from->setTime(0, 0)->format('Y-m-d H:i:s'), 'to' => $to->modify('+1 day')->setTime(0, 0)->format('Y-m-d H:i:s')];
        $types = ['types' => ArrayParameterType::STRING];
        if (null !== $sectionIds) {
            $sql .= ' JOIN document d ON d.id = e.document_id';
        }
        $sql .= ' WHERE e.type IN (:types) AND e.created_at >= :from AND e.created_at < :to';
        if (null !== $document) {
            $sql .= ' AND e.document_id = :document';
            $params['document'] = (int) $document->getId();
        }
        if (null !== $sectionIds) {
            $sql .= ' AND d.section_id IN (:sections)';
            $params['sections'] = array_map('intval', $sectionIds);
            $types['sections'] = ArrayParameterType::INTEGER;
        }
        $sql .= ' GROUP BY e.type, DATE(e.created_at)';
        $out = $this->emptyDays($from, $to);
        foreach ($this->getEntityManager()->getConnection()->fetchAllAssociative($sql, $params, $types) as $row) {
            $day = (string) $row['d'];
            if (isset($out[$day][$row['t']])) {
                $out[$day][$row['t']] += (int) $row['cnt'];
            }
        }

        return $out;
    }

    /** @return array<string, array{view: int, download: int}> */
    private function emptyDays(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $out = [];
        for ($d = $from->setTime(0, 0); $d <= $to; $d = $d->modify('+1 day')) {
            $out[$d->format('Y-m-d')] = ['view' => 0, 'download' => 0];
        }

        return $out;
    }

    /**
     * Активность по месяцам за последние N месяцев.
     *
     * @return array<string, array{view: int, download: int, new_version: int}> месяц (Y-m) => счётчики
     */
    public function activityByMonth(int $months = 12): array
    {
        $start = (new \DateTimeImmutable('first day of this month'))->setTime(0, 0)->modify('-'.($months - 1).' months');
        $out = [];
        for ($i = 0; $i < $months; ++$i) {
            $out[$start->modify('+'.$i.' months')->format('Y-m')] = ['view' => 0, 'download' => 0, 'new_version' => 0];
        }
        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            "SELECT e.type AS t, DATE_FORMAT(e.created_at, '%Y-%m') AS m, COUNT(*) AS cnt FROM document_event e"
            .' WHERE e.type IN (:types) AND e.created_at >= :from GROUP BY e.type, m',
            ['types' => [DocumentEvent::VIEW, DocumentEvent::DOWNLOAD, DocumentEvent::NEW_VERSION, DocumentEvent::CREATE], 'from' => $start->format('Y-m-d H:i:s')],
            ['types' => ArrayParameterType::STRING],
        );
        foreach ($rows as $row) {
            $m = (string) $row['m'];
            if (!isset($out[$m])) {
                continue;
            }
            $type = DocumentEvent::CREATE === $row['t'] ? 'new_version' : (string) $row['t'];
            $out[$m][$type] += (int) $row['cnt'];
        }

        return $out;
    }

    /**
     * Сводка по документам: просмотры, скачивания, уникальные пользователи, первое и последнее событие.
     *
     * @return array{views: int, downloads: int, unique_users: int, first: ?\DateTimeImmutable, last: ?\DateTimeImmutable}
     */
    public function documentSummary(Document $document): array
    {
        $rows = $this->createQueryBuilder('e')
            ->select('e.type AS t, COUNT(e.id) AS cnt, MIN(e.createdAt) AS first, MAX(e.createdAt) AS last')
            ->andWhere('e.document = :d')->setParameter('d', $document)
            ->andWhere('e.type IN (:types)')->setParameter('types', [DocumentEvent::VIEW, DocumentEvent::DOWNLOAD])
            ->groupBy('e.type')->getQuery()->getArrayResult();
        $views = $downloads = 0;
        $first = $last = null;
        foreach ($rows as $row) {
            if (DocumentEvent::VIEW === $row['t']) {
                $views = (int) $row['cnt'];
            } else {
                $downloads = (int) $row['cnt'];
            }
            $f = new \DateTimeImmutable((string) $row['first']);
            $l = new \DateTimeImmutable((string) $row['last']);
            $first = null === $first || $f < $first ? $f : $first;
            $last = null === $last || $l > $last ? $l : $last;
        }
        $unique = (int) $this->createQueryBuilder('e')->select('COUNT(DISTINCT e.user)')
            ->andWhere('e.document = :d')->setParameter('d', $document)
            ->andWhere('e.type IN (:types)')->setParameter('types', [DocumentEvent::VIEW, DocumentEvent::DOWNLOAD])
            ->andWhere('e.user IS NOT NULL')
            ->getQuery()->getSingleScalarResult();

        return ['views' => $views, 'downloads' => $downloads, 'unique_users' => $unique, 'first' => $first, 'last' => $last];
    }

    /**
     * Кто чаще всего открывал/скачивал документ.
     *
     * @return list<array{name: string, username: ?string, views: int, downloads: int, last: \DateTimeImmutable}>
     */
    public function documentUsers(Document $document, int $limit = 15): array
    {
        $rows = $this->createQueryBuilder('e')
            ->select('u.displayName AS name, u.username AS username, e.actorName AS actor, e.type AS t, COUNT(e.id) AS cnt, MAX(e.createdAt) AS last')
            ->leftJoin('e.user', 'u')
            ->andWhere('e.document = :d')->setParameter('d', $document)
            ->andWhere('e.type IN (:types)')->setParameter('types', [DocumentEvent::VIEW, DocumentEvent::DOWNLOAD])
            ->groupBy('u.id', 'u.displayName', 'u.username', 'e.actorName', 'e.type')
            ->getQuery()->getArrayResult();
        $out = [];
        foreach ($rows as $row) {
            $key = $row['username'] ?? ('~'.$row['actor']);
            $out[$key] ??= ['name' => $row['name'] ?? $row['actor'] ?? 'Неизвестно', 'username' => $row['username'], 'views' => 0, 'downloads' => 0, 'last' => new \DateTimeImmutable('2000-01-01')];
            $out[$key][DocumentEvent::VIEW === $row['t'] ? 'views' : 'downloads'] += (int) $row['cnt'];
            $last = new \DateTimeImmutable((string) $row['last']);
            if ($last > $out[$key]['last']) {
                $out[$key]['last'] = $last;
            }
        }
        usort($out, static fn ($a, $b) => ($b['views'] + $b['downloads']) <=> ($a['views'] + $a['downloads']));

        return \array_slice(array_values($out), 0, $limit);
    }

    /**
     * Сводка просмотров/скачиваний по документам за период.
     *
     * @return array<int, array{view: int, download: int}> document_id => счётчики
     */
    public function countsPerDocumentSince(?\DateTimeImmutable $since): array
    {
        $qb = $this->createQueryBuilder('e')
            ->select('IDENTITY(e.document) AS did, e.type AS t, COUNT(e.id) AS cnt')
            ->andWhere('e.type IN (:types)')->setParameter('types', [DocumentEvent::VIEW, DocumentEvent::DOWNLOAD])
            ->groupBy('did', 't');
        if (null !== $since) {
            $qb->andWhere('e.createdAt >= :since')->setParameter('since', $since);
        }
        $out = [];
        foreach ($qb->getQuery()->getArrayResult() as $row) {
            $out[(int) $row['did']] ??= ['view' => 0, 'download' => 0];
            $out[(int) $row['did']][$row['t']] = (int) $row['cnt'];
        }

        return $out;
    }

    /**
     * Активность пользователей за период: просмотры, скачивания, публикации/версии.
     *
     * @return list<array{name: string, username: ?string, views: int, downloads: int, changes: int, last: \DateTimeImmutable}>
     */
    public function userActivity(?\DateTimeImmutable $since, int $limit = 50): array
    {
        $qb = $this->createQueryBuilder('e')
            ->select('u.id AS uid, u.displayName AS name, u.username AS username, e.type AS t, COUNT(e.id) AS cnt, MAX(e.createdAt) AS last')
            ->join('e.user', 'u')
            ->groupBy('u.id', 'u.displayName', 'u.username', 'e.type');
        if (null !== $since) {
            $qb->andWhere('e.createdAt >= :since')->setParameter('since', $since);
        }
        $out = [];
        foreach ($qb->getQuery()->getArrayResult() as $row) {
            $uid = (int) $row['uid'];
            $out[$uid] ??= ['name' => $row['name'], 'username' => $row['username'], 'views' => 0, 'downloads' => 0, 'changes' => 0, 'last' => new \DateTimeImmutable('2000-01-01')];
            match ($row['t']) {
                DocumentEvent::VIEW => $out[$uid]['views'] += (int) $row['cnt'],
                DocumentEvent::DOWNLOAD => $out[$uid]['downloads'] += (int) $row['cnt'],
                DocumentEvent::EXPIRY_NOTICE => null,
                default => $out[$uid]['changes'] += (int) $row['cnt'],
            };
            $last = new \DateTimeImmutable((string) $row['last']);
            if ($last > $out[$uid]['last']) {
                $out[$uid]['last'] = $last;
            }
        }
        usort($out, static fn ($a, $b) => ($b['views'] + $b['downloads'] + $b['changes']) <=> ($a['views'] + $a['downloads'] + $a['changes']));

        return \array_slice(array_values($out), 0, $limit);
    }

    /** @return list<DocumentEvent> последние события портала */
    public function findRecent(int $limit = 20, ?array $sectionIds = null): array
    {
        $qb = $this->createQueryBuilder('e')
            ->leftJoin('e.user', 'u')->addSelect('u')
            ->join('e.document', 'd')->addSelect('d')
            ->join('d.section', 's')->addSelect('s')
            ->andWhere('e.type <> :view')->setParameter('view', DocumentEvent::VIEW)
            ->orderBy('e.createdAt', \SortDirection::Descending)->setMaxResults($limit);
        if (null !== $sectionIds) {
            if ([] === $sectionIds) {
                return [];
            }
            $qb->andWhere('d.section IN (:ids)')->setParameter('ids', $sectionIds);
        }

        return $qb->getQuery()->getResult();
    }

    /** Число событий по типам за всё время. @return array<string, int> */
    public function countAllByType(): array
    {
        $out = [];
        foreach ($this->createQueryBuilder('e')->select('e.type AS t, COUNT(e.id) AS cnt')->groupBy('e.type')->getQuery()->getArrayResult() as $row) {
            $out[$row['t']] = (int) $row['cnt'];
        }

        return $out;
    }
}
