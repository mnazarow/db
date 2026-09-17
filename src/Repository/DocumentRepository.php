<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Document;
use App\Entity\Section;
use App\Entity\User;
use App\Security\Viewer;
use App\Service\Text\SearchQuery;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Exception as DbalException;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;

/**
 * @extends ServiceEntityRepository<Document>
 */
final class DocumentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry, private readonly ?LoggerInterface $logger = null)
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
    public function findBySection(Section $section, array $statuses, string $sort = 'title', bool $publicOnly = false, ?Viewer $viewer = null): array
    {
        $qb = $this->baseQb()->andWhere('d.section = :s')->setParameter('s', $section)
            ->andWhere('d.status IN (:st)')->setParameter('st', $statuses);
        if ($publicOnly) {
            $qb->andWhere('d.isPublic = true');
        }
        $this->applyRestrictions($qb, $viewer);
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
    public function findInSubtree(Section $root, array $statuses, string $sort = 'title', int $limit = 0, bool $publicOnly = false, ?Viewer $viewer = null): array
    {
        $qb = $this->baseQb()->andWhere('s.path LIKE :path')->setParameter('path', $root->getPath().'%')
            ->andWhere('d.status IN (:st)')->setParameter('st', $statuses);
        if ($publicOnly) {
            $qb->andWhere('d.isPublic = true');
        }
        $this->applyRestrictions($qb, $viewer);
        $this->applySort($qb, $sort);
        if ($limit > 0) {
            $qb->setMaxResults($limit);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Убирает из выборки документы с ограниченным доступом, недоступные этому пользователю.
     * $viewer === null — контекст не задан (консоль, панель администратора): фильтр не применяется.
     */
    private function applyRestrictions(QueryBuilder $qb, ?Viewer $viewer, string $alias = 'd'): void
    {
        if (null === $viewer || $viewer->unrestricted) {
            return;
        }
        $conditions = [$alias.'.restricted = false'];
        if (null !== $viewer->user) {
            $conditions[] = ':viewer_user MEMBER OF '.$alias.'.allowedUsers';
            $qb->setParameter('viewer_user', $viewer->user);
            $department = trim((string) $viewer->user->getDepartment());
            if ('' !== $department) {
                $conditions[] = $alias.'.allowedDepartments LIKE :viewer_dept';
                $qb->setParameter('viewer_dept', '%'.addcslashes(Document::departmentNeedle($department), '%_').'%');
            }
            if ([] !== $viewer->managedSectionIds) {
                $conditions[] = $alias.'.section IN (:viewer_sections)';
                $qb->setParameter('viewer_sections', $viewer->managedSectionIds);
            }
        }
        $qb->andWhere('('.implode(' OR ', $conditions).')');
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

    /** @return list<Document> недавно обновлённые опубликованные документы (для гостей — только открытые) */
    public function findRecentPublished(int $limit = 10, bool $publicOnly = false, ?Viewer $viewer = null): array
    {
        $qb = $this->baseQb()->andWhere('d.status = :st')->setParameter('st', Document::STATUS_PUBLISHED);
        if ($publicOnly) {
            $qb->andWhere('d.isPublic = true');
        }
        $this->applyRestrictions($qb, $viewer);

        return $qb->orderBy('d.updatedAt', 'DESC')->setMaxResults($limit)->getQuery()->getResult();
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

    /** @return list<Document> черновики и документы на согласовании в разделах (для блока «требуют внимания») */
    public function findDrafts(?array $sectionIds = null, int $limit = 10): array
    {
        $qb = $this->baseQb()->andWhere('d.status IN (:st)')->setParameter('st', [Document::STATUS_DRAFT, Document::STATUS_REVIEW])->orderBy('d.updatedAt', 'DESC');
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
    public function search(string $query, array $statuses, ?Section $section = null, int $limit = 100, bool $publicOnly = false, ?Viewer $viewer = null): array
    {
        $terms = array_values(array_filter(preg_split('/\s+/u', mb_strtolower(trim($query))) ?: [], static fn ($t) => mb_strlen($t) >= 2));
        if ([] === $terms) {
            return [];
        }
        $qb = $this->baseQb()->andWhere('d.status IN (:st)')->setParameter('st', $statuses);
        if ($publicOnly) {
            $qb->andWhere('d.isPublic = true');
        }
        foreach (\array_slice($terms, 0, 6) as $i => $term) {
            $qb->andWhere(\sprintf('(LOWER(d.title) LIKE :t%1$d OR LOWER(d.code) LIKE :t%1$d OR LOWER(d.description) LIKE :t%1$d OR LOWER(d.tags) LIKE :t%1$d OR LOWER(cv.content) LIKE :t%1$d OR LOWER(cv.originalName) LIKE :t%1$d)', $i))
                ->setParameter('t'.$i, '%'.addcslashes($term, '%_').'%');
        }
        if (null !== $section) {
            $qb->andWhere('s.path LIKE :path')->setParameter('path', $section->getPath().'%');
        }
        $this->applyRestrictions($qb, $viewer);

        return $qb->orderBy('d.updatedAt', 'DESC')->setMaxResults($limit)->getQuery()->getResult();
    }

    /**
     * Реестр документов для панели администратора с фильтрами.
     *
     * @param array{section?: ?Section, status?: ?string, type?: ?string, validity?: ?string, owner?: ?User, access?: ?string, q?: ?string} $filters
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
        if (!empty($filters['access'])) {
            $qb->andWhere('d.isPublic = :public')->setParameter('public', 'public' === $filters['access']);
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

    /** @return array<string, int> число документов по статусам (для гостей — только открытых) */
    public function countByStatus(?array $sectionIds = null, bool $publicOnly = false, ?Viewer $viewer = null): array
    {
        $qb = $this->createQueryBuilder('d')->select('d.status AS st, COUNT(d.id) AS cnt')->groupBy('d.status');
        if ($publicOnly) {
            $qb->andWhere('d.isPublic = true');
        }
        $this->applyRestrictions($qb, $viewer);
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
     * Раздел и дата «актуален до» каждого опубликованного документа (для сводки актуальности по дереву разделов).
     *
     * @return list<array{sid: int, vu: ?\DateTimeImmutable}>
     */
    public function publishedValidity(): array
    {
        $rows = $this->createQueryBuilder('d')->select('IDENTITY(d.section) AS sid, d.validUntil AS vu')
            ->andWhere('d.status = :st')->setParameter('st', Document::STATUS_PUBLISHED)
            ->getQuery()->getArrayResult();
        $out = [];
        foreach ($rows as $row) {
            $vu = $row['vu'];
            if (null !== $vu && !$vu instanceof \DateTimeImmutable) {
                $vu = new \DateTimeImmutable($vu instanceof \DateTimeInterface ? $vu->format('Y-m-d') : (string) $vu);
            }
            $out[] = ['sid' => (int) $row['sid'], 'vu' => $vu];
        }

        return $out;
    }

    /**
     * Число документов по разделам (прямое вхождение), сгруппировано по статусам.
     *
     * @return array<int, array<string, int>> section_id => [status => count]
     */
    public function countPerSection(bool $publicOnly = false, ?Viewer $viewer = null): array
    {
        $qb = $this->createQueryBuilder('d')->select('IDENTITY(d.section) AS sid, d.status AS st, COUNT(d.id) AS cnt')->groupBy('sid', 'st');
        if ($publicOnly) {
            $qb->andWhere('d.isPublic = true');
        }
        $this->applyRestrictions($qb, $viewer);
        $rows = $qb->getQuery()->getArrayResult();
        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['sid']][$row['st']] = (int) $row['cnt'];
        }

        return $out;
    }

    /**
     * Полнотекстовый поиск по содержимому файлов (таблица document_text, индекс FULLTEXT).
     * Возвращает документы, отсортированные по релевантности, вместе с индексом их текста.
     *
     * @param list<string> $statuses
     *
     * @return list<array{document: Document, score: float, text: ?string}>
     */
    public function searchContent(SearchQuery $query, array $statuses, ?Section $section = null, int $limit = 30, bool $publicOnly = false, ?Viewer $viewer = null): array
    {
        $boolean = $query->booleanMode();
        if ('' === $boolean || [] === $statuses) {
            return [];
        }
        $sql = 'SELECT t.document_id AS id, t.content AS content, MATCH (t.content) AGAINST (:q IN BOOLEAN MODE) AS score
                FROM document_text t
                JOIN document d ON d.id = t.document_id
                JOIN section s ON s.id = d.section_id
                WHERE MATCH (t.content) AGAINST (:q IN BOOLEAN MODE) AND d.status IN (:statuses)';
        $params = ['q' => $boolean, 'statuses' => $statuses];
        $types = ['statuses' => ArrayParameterType::STRING];
        if ($publicOnly) {
            $sql .= ' AND d.is_public = 1';
        }
        if (null !== $section) {
            $sql .= ' AND s.path LIKE :path';
            $params['path'] = $section->getPath().'%';
        }
        if (null !== $viewer && !$viewer->unrestricted) {
            $conditions = ['d.restricted = 0'];
            if (null !== $viewer->user) {
                $conditions[] = 'EXISTS (SELECT 1 FROM document_allowed_user au WHERE au.document_id = d.id AND au.user_id = :viewer_id)';
                $params['viewer_id'] = (int) $viewer->user->getId();
                $department = trim((string) $viewer->user->getDepartment());
                if ('' !== $department) {
                    $conditions[] = 'd.allowed_departments LIKE :viewer_dept';
                    $params['viewer_dept'] = '%'.addcslashes(Document::departmentNeedle($department), '%_').'%';
                }
                if ([] !== $viewer->managedSectionIds) {
                    $conditions[] = 'd.section_id IN (:viewer_sections)';
                    $params['viewer_sections'] = $viewer->managedSectionIds;
                    $types['viewer_sections'] = ArrayParameterType::INTEGER;
                }
            }
            $sql .= ' AND ('.implode(' OR ', $conditions).')';
        }
        $sql .= ' ORDER BY score DESC LIMIT '.max(1, $limit);
        try {
            $rows = $this->getEntityManager()->getConnection()->executeQuery($sql, $params, $types)->fetchAllAssociative();
        } catch (DbalException $e) {
            // Поиск по содержимому — дополнение к поиску по реквизитам: ошибка индекса (например,
            // необычный запрос или отсутствующий FULLTEXT) не должна ронять страницу поиска.
            $this->logger?->warning('Полнотекстовый поиск не выполнен', ['query' => $boolean, 'error' => $e->getMessage()]);

            return [];
        }
        if ([] === $rows) {
            return [];
        }
        $documents = $this->baseQb()->andWhere('d.id IN (:ids)')->setParameter('ids', array_column($rows, 'id'))->getQuery()->getResult();
        $byId = [];
        foreach ($documents as $document) {
            $byId[$document->getId()] = $document;
        }
        $out = [];
        foreach ($rows as $row) {
            $document = $byId[(int) $row['id']] ?? null;
            if (null !== $document) {
                $out[] = ['document' => $document, 'score' => (float) $row['score'], 'text' => (string) $row['content']];
            }
        }

        return $out;
    }

    /**
     * Выборка для REST API (индексация в RAG): постраничный список видимых документов с фильтрами.
     *
     * @param array{statuses: list<string>, public_only: bool, section?: ?Section, subtree?: bool, type?: ?string, updated_since?: ?\DateTimeImmutable, tag?: ?string, q?: ?string} $filters
     *
     * @return array{items: list<Document>, total: int}
     */
    public function findForApi(array $filters, int $page, int $perPage): array
    {
        $qb = $this->apiQb($filters);
        $count = (clone $qb)->select('COUNT(d.id)')->resetDQLPart('orderBy');
        $total = (int) $count->getQuery()->getSingleScalarResult();
        $items = $qb->orderBy('d.updatedAt', 'ASC')->addOrderBy('d.id', 'ASC')
            ->setFirstResult(($page - 1) * $perPage)->setMaxResults($perPage)
            ->getQuery()->getResult();

        return ['items' => $items, 'total' => $total];
    }

    /**
     * Документы, которые изменились после $since, но внешней системе больше не видны
     * (сняты с публикации, в архиве или стали внутренними при ключе «только открытые»).
     *
     * @return list<Document>
     */
    public function findHiddenSinceForApi(\DateTimeImmutable $since, bool $publicOnly, bool $includeArchived, int $limit = 1000): array
    {
        $visibleStatuses = $includeArchived ? [Document::STATUS_PUBLISHED, Document::STATUS_ARCHIVED] : [Document::STATUS_PUBLISHED];
        $qb = $this->baseQb()->andWhere('d.updatedAt > :since')->setParameter('since', $since);
        // Ограничение доступа приравнивается к «документ стал невидимым»: внешняя система уберёт его из индекса.
        $hidden = '(d.status NOT IN (:visible) OR d.restricted = true)';
        $qb->setParameter('visible', $visibleStatuses);
        if ($publicOnly) {
            $hidden = '('.$hidden.' OR d.isPublic = false)';
        }

        return $qb->andWhere($hidden)->orderBy('d.updatedAt', 'ASC')->setMaxResults($limit)->getQuery()->getResult();
    }

    /** @param array{statuses: list<string>, public_only: bool, section?: ?Section, subtree?: bool, type?: ?string, updated_since?: ?\DateTimeImmutable, tag?: ?string, q?: ?string} $filters */
    private function apiQb(array $filters): QueryBuilder
    {
        // Документы с ограниченным доступом через API не отдаются: их список получателей задан
        // сотрудниками и подразделениями, а у ключа API такого соответствия нет.
        $qb = $this->baseQb()->andWhere('d.restricted = false')
            ->andWhere('d.status IN (:st)')->setParameter('st', $filters['statuses']);
        if ($filters['public_only']) {
            $qb->andWhere('d.isPublic = true');
        }
        if (!empty($filters['section'])) {
            if ($filters['subtree'] ?? true) {
                $qb->andWhere('s.path LIKE :path')->setParameter('path', $filters['section']->getPath().'%');
            } else {
                $qb->andWhere('d.section = :section')->setParameter('section', $filters['section']);
            }
        }
        if (!empty($filters['type'])) {
            $qb->andWhere('d.type = :type')->setParameter('type', $filters['type']);
        }
        if (!empty($filters['updated_since'])) {
            $qb->andWhere('d.updatedAt > :since')->setParameter('since', $filters['updated_since']);
        }
        if (!empty($filters['tag'])) {
            // Теги хранятся в JSON в нижнем регистре с \u-экранированием — ищем точное вхождение элемента массива.
            $encoded = json_encode(mb_strtolower(trim((string) $filters['tag'])), \JSON_THROW_ON_ERROR);
            $qb->andWhere('d.tags LIKE :tag')->setParameter('tag', '%'.addcslashes($encoded, '%_\\').'%');
        }
        if (!empty($filters['q'])) {
            $terms = array_values(array_filter(preg_split('/\s+/u', mb_strtolower(trim($filters['q']))) ?: [], static fn ($t) => mb_strlen($t) >= 2));
            foreach (\array_slice($terms, 0, 6) as $i => $term) {
                $qb->andWhere(\sprintf('(LOWER(d.title) LIKE :t%1$d OR LOWER(d.code) LIKE :t%1$d OR LOWER(d.description) LIKE :t%1$d OR LOWER(d.tags) LIKE :t%1$d OR LOWER(cv.content) LIKE :t%1$d OR LOWER(cv.originalName) LIKE :t%1$d)', $i))
                    ->setParameter('t'.$i, '%'.addcslashes($term, '%_').'%');
            }
        }

        return $qb;
    }

    /**
     * Документы для формирования описаний через LLM.
     * $mode: missing — без описания; regenerate — без описания или с описанием от LLM; force — все.
     *
     * @return list<Document>
     */
    public function findForDescribing(string $mode, int $limit, ?Section $section = null, bool $includeArchived = false): array
    {
        $qb = $this->baseQb()->andWhere('d.currentVersion IS NOT NULL');
        if (!$includeArchived) {
            $qb->andWhere('d.status <> :archived')->setParameter('archived', Document::STATUS_ARCHIVED);
        }
        if ('missing' === $mode) {
            $qb->andWhere("d.description IS NULL OR d.description = ''");
        } elseif ('regenerate' === $mode) {
            $qb->andWhere("d.description IS NULL OR d.description = '' OR d.descriptionSource = :llm")->setParameter('llm', Document::DESCRIPTION_LLM);
        }
        if (null !== $section) {
            $qb->andWhere('s.path LIKE :path')->setParameter('path', $section->getPath().'%');
        }

        return $qb->orderBy('d.id', 'ASC')->setMaxResults($limit)->getQuery()->getResult();
    }

    /** @return array{total: int, with_description: int, llm: int, without: int} сводка по описаниям (без архива) */
    public function countDescriptions(): array
    {
        $rows = $this->createQueryBuilder('d')
            ->select("CASE WHEN d.description IS NULL OR d.description = '' THEN 'none' WHEN d.descriptionSource = 'llm' THEN 'llm' ELSE 'manual' END AS src, COUNT(d.id) AS cnt")
            ->andWhere('d.status <> :archived')->setParameter('archived', Document::STATUS_ARCHIVED)
            ->groupBy('src')->getQuery()->getArrayResult();
        $out = ['none' => 0, 'llm' => 0, 'manual' => 0];
        foreach ($rows as $row) {
            $out[$row['src']] = (int) $row['cnt'];
        }

        return ['total' => $out['none'] + $out['llm'] + $out['manual'], 'with_description' => $out['llm'] + $out['manual'], 'llm' => $out['llm'], 'without' => $out['none']];
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
