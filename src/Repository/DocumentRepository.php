<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Document;
use App\Entity\Section;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Document>
 */
final class DocumentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Document::class);
    }

    private function baseQb(string $alias = 'd'): QueryBuilder
    {
        return $this->createQueryBuilder($alias)
            ->leftJoin($alias.'.currentVersion', 'cv')->addSelect('cv')
            ->leftJoin($alias.'.owner', 'o')->addSelect('o')
            ->join($alias.'.section', 's')->addSelect('s');
    }

    /**
     * Документы раздела (без подразделов). $statuses — какие статусы показывать.
     *
     * @param list<string> $statuses
     *
     * @return list<Document>
     */
    public function findBySection(Section $section, array $statuses, string $sort = 'title'): array
    {
        $qb = $this->baseQb()->andWhere('d.section = :s')->setParameter('s', $section)
            ->andWhere('d.status IN (:st)')->setParameter('st', $statuses);
        $this->applySort($qb, $sort);

        return $qb->getQuery()->getResult();
    }

    /**
     * Документы раздела и всех его подразделов.
     *
     * @param list<string> $statuses
     *
     * @return list<Document>
     */
    public function findInSubtree(Section $root, array $statuses, string $sort = 'title', int $limit = 0): array
    {
        $qb = $this->baseQb()->andWhere('s.path LIKE :path')->setParameter('path', $root->getPath().'%')
            ->andWhere('d.status IN (:st)')->setParameter('st', $statuses);
        $this->applySort($qb, $sort);
        if ($limit > 0) {
            $qb->setMaxResults($limit);
        }

        return $qb->getQuery()->getResult();
    }

    private function applySort(QueryBuilder $qb, string $sort): void
    {
        match ($sort) {
            'updated' => $qb->orderBy('d.updatedAt', 'DESC'),
            'valid' => $qb->addSelect('CASE WHEN d.validUntil IS NULL THEN 1 ELSE 0 END AS HIDDEN vnull')->orderBy('vnull', 'ASC')->addOrderBy('d.validUntil', 'ASC'),
            'views' => $qb->orderBy('d.viewCount', 'DESC'),
            'code' => $qb->orderBy('d.code', 'ASC')->addOrderBy('d.title', 'ASC'),
            default => $qb->orderBy('d.title', 'ASC'),
        };
    }

    /** @return list<Document> недавно обновлённые опубликованные документы */
    public function findRecentPublished(int $limit = 10): array
    {
        return $this->baseQb()->andWhere('d.status = :st')->setParameter('st', Document::STATUS_PUBLISHED)
            ->orderBy('d.updatedAt', 'DESC')->setMaxResults($limit)->getQuery()->getResult();
    }

    /**
     * Документы с истекающим или истёкшим сроком актуальности.
     *
     * @param list<int>|null $sectionIds ограничить разделами (null — все)
     *
     * @return list<Document>
     */
    public function findExpiring(\DateTimeImmutable $today, int $soonDays, ?array $sectionIds = null, bool $includeExpired = true, int $limit = 0): array
    {
        $qb = $this->baseQb()
            ->andWhere('d.status = :st')->setParameter('st', Document::STATUS_PUBLISHED)
            ->andWhere('d.validUntil IS NOT NULL')
            ->andWhere('d.validUntil <= :until')->setParameter('until', $today->modify('+'.$soonDays.' days')->format('Y-m-d'));
        if (!$includeExpired) {
            $qb->andWhere('d.validUntil >= :today')->setParameter('today', $today->format('Y-m-d'));
        }
        if (null !== $sectionIds) {
            if ([] === $sectionIds) {
                return [];
            }
            $qb->andWhere('d.section IN (:ids)')->setParameter('ids', $sectionIds);
        }
        $qb->orderBy('d.validUntil', 'ASC');
        if ($limit > 0) {
            $qb->setMaxResults($limit);
        }

        return $qb->getQuery()->getResult();
    }

    /** @return list<Document> черновики в указанных разделах (для блока «требуют внимания») */
    public function findDrafts(?array $sectionIds = null, int $limit = 10): array
    {
        $qb = $this->baseQb()->andWhere('d.status = :st')->setParameter('st', Document::STATUS_DRAFT)->orderBy('d.updatedAt', 'DESC');
        if (null !== $sectionIds) {
            if ([] === $sectionIds) {
                return [];
            }
            $qb->andWhere('d.section IN (:ids)')->setParameter('ids', $sectionIds);
        }

        return $qb->setMaxResults($limit)->getQuery()->getResult();
    }

    /**
     * Полнотекстовый поиск (по названию, обозначению, описанию, тегам и тексту текущей версии страницы).
     *
     * @param list<string> $statuses
     *
     * @return list<Document>
     */
    public function search(string $query, array $statuses, ?Section $section = null, int $limit = 100): array
    {
        $terms = array_values(array_filter(preg_split('/\s+/u', mb_strtolower(trim($query))) ?: [], static fn ($t) => mb_strlen($t) >= 2));
        if ([] === $terms) {
            return [];
        }
        $qb = $this->baseQb()->andWhere('d.status IN (:st)')->setParameter('st', $statuses);
        foreach (\array_slice($terms, 0, 6) as $i => $term) {
            $qb->andWhere(\sprintf('(LOWER(d.title) LIKE :t%1$d OR LOWER(d.code) LIKE :t%1$d OR LOWER(d.description) LIKE :t%1$d OR LOWER(d.tags) LIKE :t%1$d OR LOWER(cv.content) LIKE :t%1$d OR LOWER(cv.originalName) LIKE :t%1$d)', $i))
                ->setParameter('t'.$i, '%'.addcslashes($term, '%_').'%');
        }
        if (null !== $section) {
            $qb->andWhere('s.path LIKE :path')->setParameter('path', $section->getPath().'%');
        }

        return $qb->orderBy('d.updatedAt', 'DESC')->setMaxResults($limit)->getQuery()->getResult();
    }

    /**
     * Реестр документов для панели администратора с фильтрами.
     *
     * @param array{section?: ?Section, status?: ?string, type?: ?string, validity?: ?string, owner?: ?User, q?: ?string} $filters
     *
     * @return list<Document>
     */
    public function findForRegistry(array $filters, \DateTimeImmutable $today, int $soonDays, string $sort = 'updated', int $limit = 500): array
    {
        $qb = $this->baseQb();
        if (!empty($filters['section'])) {
            $qb->andWhere('s.path LIKE :path')->setParameter('path', $filters['section']->getPath().'%');
        }
        if (!empty($filters['status'])) {
            $qb->andWhere('d.status = :status')->setParameter('status', $filters['status']);
        }
        if (!empty($filters['type'])) {
            $qb->andWhere('d.type = :type')->setParameter('type', $filters['type']);
        }
        if (!empty($filters['owner'])) {
            $qb->andWhere('d.owner = :owner')->setParameter('owner', $filters['owner']);
        }
        if (!empty($filters['q'])) {
            $qb->andWhere('(LOWER(d.title) LIKE :q OR LOWER(d.code) LIKE :q)')->setParameter('q', '%'.mb_strtolower(trim($filters['q'])).'%');
        }
        switch ($filters['validity'] ?? '') {
            case 'expired':
                $qb->andWhere('d.validUntil < :today')->setParameter('today', $today->format('Y-m-d'));
                break;
            case 'soon':
                $qb->andWhere('d.validUntil >= :today AND d.validUntil <= :soon')
                    ->setParameter('today', $today->format('Y-m-d'))
                    ->setParameter('soon', $today->modify('+'.$soonDays.' days')->format('Y-m-d'));
                break;
            case 'valid':
                $qb->andWhere('d.validUntil > :soon')->setParameter('soon', $today->modify('+'.$soonDays.' days')->format('Y-m-d'));
                break;
            case 'none':
                $qb->andWhere('d.validUntil IS NULL');
                break;
        }
        $this->applySort($qb, $sort);

        return $qb->setMaxResults($limit)->getQuery()->getResult();
    }

    /** @return array<string, int> число документов по статусам */
    public function countByStatus(?array $sectionIds = null): array
    {
        $qb = $this->createQueryBuilder('d')->select('d.status AS st, COUNT(d.id) AS cnt')->groupBy('d.status');
        if (null !== $sectionIds) {
            if ([] === $sectionIds) {
                return array_fill_keys(Document::STATUSES, 0);
            }
            $qb->andWhere('d.section IN (:ids)')->setParameter('ids', $sectionIds);
        }
        $out = array_fill_keys(Document::STATUSES, 0);
        foreach ($qb->getQuery()->getArrayResult() as $row) {
            $out[$row['st']] = (int) $row['cnt'];
        }

        return $out;
    }

    /** @return array<string, int> число документов по типам */
    public function countByType(): array
    {
        $out = array_fill_keys(Document::TYPES, 0);
        foreach ($this->createQueryBuilder('d')->select('d.type AS t, COUNT(d.id) AS cnt')->groupBy('d.type')->getQuery()->getArrayResult() as $row) {
            $out[$row['t']] = (int) $row['cnt'];
        }

        return $out;
    }

    /**
     * Сводка по актуальности опубликованных документов: expired / soon / valid / none.
     *
     * @return array{expired: int, soon: int, valid: int, none: int}
     */
    public function countByValidity(\DateTimeImmutable $today, int $soonDays, ?array $sectionIds = null): array
    {
        $qb = $this->createQueryBuilder('d')
            ->select('d.validUntil AS vu')
            ->andWhere('d.status = :st')->setParameter('st', Document::STATUS_PUBLISHED);
        if (null !== $sectionIds) {
            if ([] === $sectionIds) {
                return ['expired' => 0, 'soon' => 0, 'valid' => 0, 'none' => 0];
            }
            $qb->andWhere('d.section IN (:ids)')->setParameter('ids', $sectionIds);
        }
        $out = ['expired' => 0, 'soon' => 0, 'valid' => 0, 'none' => 0];
        $todayStr = $today->format('Y-m-d');
        $soonStr = $today->modify('+'.$soonDays.' days')->format('Y-m-d');
        foreach ($qb->getQuery()->getArrayResult() as $row) {
            $vu = $row['vu'];
            if (null === $vu) {
                ++$out['none'];
                continue;
            }
            $d = $vu instanceof \DateTimeInterface ? $vu->format('Y-m-d') : (string) $vu;
            if ($d < $todayStr) {
                ++$out['expired'];
            } elseif ($d <= $soonStr) {
                ++$out['soon'];
            } else {
                ++$out['valid'];
            }
        }

        return $out;
    }

    /**
     * Число документов по разделам (прямое вхождение), сгруппировано по статусам.
     *
     * @return array<int, array<string, int>> section_id => [status => count]
     */
    public function countPerSection(): array
    {
        $rows = $this->createQueryBuilder('d')->select('IDENTITY(d.section) AS sid, d.status AS st, COUNT(d.id) AS cnt')
            ->groupBy('sid', 'st')->getQuery()->getArrayResult();
        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['sid']][$row['st']] = (int) $row['cnt'];
        }

        return $out;
    }

    /** @return list<Document> самые просматриваемые опубликованные документы */
    public function findTopByViews(int $limit = 10): array
    {
        return $this->baseQb()->andWhere('d.status = :st')->setParameter('st', Document::STATUS_PUBLISHED)
            ->orderBy('d.viewCount', 'DESC')->addOrderBy('d.downloadCount', 'DESC')->setMaxResults($limit)->getQuery()->getResult();
    }

    /** @return list<Document> опубликованные документы, которые никто не открывал N дней */
    public function findStale(\DateTimeImmutable $since, int $limit = 20): array
    {
        return $this->baseQb()->andWhere('d.status = :st')->setParameter('st', Document::STATUS_PUBLISHED)
            ->andWhere('(d.lastViewedAt IS NULL AND d.publishedAt < :since) OR d.lastViewedAt < :since')->setParameter('since', $since)
            ->orderBy('d.lastViewedAt', 'ASC')->setMaxResults($limit)->getQuery()->getResult();
    }

    public function countAll(): int
    {
        return (int) $this->createQueryBuilder('d')->select('COUNT(d.id)')->getQuery()->getSingleScalarResult();
    }

    public function countInSection(Section $section, bool $subtree = false): int
    {
        $qb = $this->createQueryBuilder('d')->select('COUNT(d.id)');
        if ($subtree) {
            $qb->join('d.section', 's')->andWhere('s.path LIKE :path')->setParameter('path', $section->getPath().'%');
        } else {
            $qb->andWhere('d.section = :s')->setParameter('s', $section);
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /** Все документы (для консольных команд и пересчёта). @return list<Document> */
    public function findAllWithVersions(): array
    {
        return $this->createQueryBuilder('d')->leftJoin('d.versions', 'v')->addSelect('v')->getQuery()->getResult();
    }

    /**
     * Документ-файл раздела, текущая версия которого имеет заданное имя файла (без учёта регистра).
     * Архивные документы не учитываются — повторный импорт файла с тем же именем создаст новый документ.
     */
    public function findFileByName(Section $section, string $originalName): ?Document
    {
        return $this->createQueryBuilder('d')
            ->join('d.currentVersion', 'cv')->addSelect('cv')
            ->andWhere('d.section = :s')->setParameter('s', $section)
            ->andWhere('d.type = :type')->setParameter('type', Document::TYPE_FILE)
            ->andWhere('d.status <> :archived')->setParameter('archived', Document::STATUS_ARCHIVED)
            ->andWhere('LOWER(cv.originalName) = :name')->setParameter('name', mb_strtolower($originalName))
            ->orderBy('d.updatedAt', 'DESC')->addOrderBy('d.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()->getOneOrNullResult();
    }
}
