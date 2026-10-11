<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Document;
use App\Entity\DocumentAcknowledgement;
use App\Entity\DocumentApproval;
use App\Entity\User;
use App\Security\Viewer;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DocumentAcknowledgement>
 */
final class DocumentAcknowledgementRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DocumentAcknowledgement::class);
    }

    /**
     * Условие «назначение не перекрыто более новой редакцией»: после выпуска новой версии и повторного
     * назначения прежняя неподтверждённая запись остаётся в истории, но сотруднику больше не показывается.
     */
    private const NOT_SUPERSEDED = 'NOT EXISTS (SELECT a2.id FROM App\\Entity\\DocumentAcknowledgement a2 WHERE a2.document = a.document AND a2.user = a.user AND a2.versionNumber > a.versionNumber)';

    /**
     * Документ ещё можно прочитать: он опубликован и открыт сотруднику по тем же правилам, что и при открытии
     * документа (Access::canViewDocument()): документ без ограничений, сотрудник в списке допуска, его
     * подразделение (без учёта регистра, «ё» и лишних пробелов), разделы, которые он модерирует, администратор.
     * Назначения по архивным, снятым с публикации и закрытым от сотрудника документам ему не показываются:
     * выполнить их он всё равно не может. $viewer — контекст Access::viewer() (без него модерация не учитывается).
     */
    private function applyReadable(QueryBuilder $qb, User $user, ?Viewer $viewer): void
    {
        $qb->andWhere("d.status = 'published'");
        if (null !== $viewer && $viewer->unrestricted) {
            return;
        }
        $conditions = ['d.restricted = false', ':reader MEMBER OF d.allowedUsers'];
        $qb->setParameter('reader', $user);
        $department = trim((string) $user->getDepartment());
        if ('' !== $department) {
            $conditions[] = 'd.allowedDepartments LIKE :reader_department';
            $qb->setParameter('reader_department', '%'.addcslashes(Document::departmentNeedle($department), '%_\\').'%');
        }
        if (null !== $viewer && [] !== $viewer->managedSectionIds) {
            $conditions[] = 'd.section IN (:reader_sections)';
            $qb->setParameter('reader_sections', $viewer->managedSectionIds);
        }
        // Согласующий читает документ, который ждёт его решения (Access::isPendingApprover()).
        $conditions[] = 'EXISTS (SELECT ap.id FROM App\\Entity\\DocumentApproval ap WHERE ap.document = d AND ap.approver = :reader AND ap.decision = :reader_pending)';
        $qb->setParameter('reader_pending', DocumentApproval::PENDING);
        $qb->andWhere('('.implode(' OR ', $conditions).')');
    }

    /** Активное (неподтверждённое) назначение пользователя по документу — для кнопки «Ознакомлен». */
    public function findPending(Document $document, User $user): ?DocumentAcknowledgement
    {
        return $this->createQueryBuilder('a')
            ->andWhere('a.document = :d')->setParameter('d', $document)
            ->andWhere('a.user = :u')->setParameter('u', $user)
            ->andWhere('a.confirmedAt IS NULL')
            ->andWhere(self::NOT_SUPERSEDED)
            ->orderBy('a.versionNumber', 'DESC')
            ->setMaxResults(1)
            ->getQuery()->getOneOrNullResult();
    }

    public function findOneFor(Document $document, User $user, int $versionNumber): ?DocumentAcknowledgement
    {
        return $this->findOneBy(['document' => $document, 'user' => $user, 'versionNumber' => $versionNumber]);
    }

    /**
     * Назначения по документу (все версии), новые сверху.
     *
     * @return list<DocumentAcknowledgement>
     */
    public function findForDocument(Document $document, ?int $versionNumber = null): array
    {
        $qb = $this->createQueryBuilder('a')
            ->join('a.user', 'u')->addSelect('u')
            ->andWhere('a.document = :d')->setParameter('d', $document)
            ->orderBy('a.versionNumber', 'DESC')
            ->addOrderBy('u.displayName', 'ASC');
        if (null !== $versionNumber) {
            $qb->andWhere('a.versionNumber = :v')->setParameter('v', $versionNumber);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Что пользователю нужно прочитать (неподтверждённые назначения).
     *
     * @return list<DocumentAcknowledgement>
     */
    public function findPendingForUser(User $user, int $limit = 100, ?Viewer $viewer = null): array
    {
        $qb = $this->createQueryBuilder('a')
            ->join('a.document', 'd')->addSelect('d')
            ->join('d.section', 's')->addSelect('s')
            ->andWhere('a.user = :u_id')->setParameter('u_id', $user)
            ->andWhere('a.confirmedAt IS NULL')
            ->andWhere(self::NOT_SUPERSEDED)
            ->orderBy('a.dueAt', 'ASC')->addOrderBy('a.assignedAt', 'ASC')
            ->setMaxResults($limit);
        $this->applyReadable($qb, $user, $viewer);

        return $qb->getQuery()->getResult();
    }

    /**
     * История ознакомлений пользователя (подтверждённые), новые сверху.
     *
     * @return list<DocumentAcknowledgement>
     */
    public function findConfirmedForUser(User $user, int $limit = 100): array
    {
        return $this->createQueryBuilder('a')
            ->join('a.document', 'd')->addSelect('d')
            ->join('d.section', 's')->addSelect('s')
            ->andWhere('a.user = :u')->setParameter('u', $user)
            ->andWhere('a.confirmedAt IS NOT NULL')
            ->orderBy('a.confirmedAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()->getResult();
    }

    /**
     * Неподтверждённые назначения по опубликованным документам с истёкшим или наступающим сроком — для
     * напоминаний по почте. Доступ сотрудника к документу проверяет вызывающий код (Access::canViewDocument()):
     * в одном запросе на всех сотрудников права модераторов не выразить.
     *
     * @return list<DocumentAcknowledgement>
     */
    public function findForReminder(\DateTimeImmutable $today, int $soonDays, ?\DateTimeImmutable $remindedBefore = null): array
    {
        $qb = $this->createQueryBuilder('a')
            ->join('a.document', 'd')->addSelect('d')
            ->join('a.user', 'u')->addSelect('u')
            ->andWhere('a.confirmedAt IS NULL')
            ->andWhere(self::NOT_SUPERSEDED)
            ->andWhere("d.status = 'published'")
            ->andWhere('u.active = true')
            ->orderBy('u.id', 'ASC')->addOrderBy('a.dueAt', 'ASC');
        $soon = $today->modify('+'.max(0, $soonDays).' days');
        if (null === $remindedBefore) {
            $qb->andWhere('a.dueAt IS NULL OR a.dueAt <= :soon')->setParameter('soon', $soon);
        } else {
            // Со сроком — когда срок близко или прошёл, не чаще раза в REMINDER_INTERVAL_DAYS; без срока —
            // первый раз через несколько дней после назначения и дальше раз в неделю, а не каждые три дня.
            $qb->andWhere('(a.dueAt IS NOT NULL AND a.dueAt <= :soon AND (a.remindedAt IS NULL OR a.remindedAt < :before))'
                .' OR (a.dueAt IS NULL AND a.assignedAt < :before AND (a.remindedAt IS NULL OR a.remindedAt < :weekly))')
                ->setParameter('soon', $soon)
                ->setParameter('before', $remindedBefore)
                ->setParameter('weekly', $today->modify('-7 days'));
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Сводка по документу: сколько назначено и сколько подтвердили (по текущей версии).
     *
     * @return array{assigned: int, confirmed: int, overdue: int}
     */
    public function summaryForDocument(Document $document, int $versionNumber): array
    {
        $row = $this->createQueryBuilder('a')
            ->select('COUNT(a.id) AS assigned', 'SUM(CASE WHEN a.confirmedAt IS NOT NULL THEN 1 ELSE 0 END) AS confirmed', 'SUM(CASE WHEN a.confirmedAt IS NULL AND a.dueAt IS NOT NULL AND a.dueAt < :today THEN 1 ELSE 0 END) AS overdue')
            ->andWhere('a.document = :d')->setParameter('d', $document)
            ->andWhere('a.versionNumber = :v')->setParameter('v', $versionNumber)
            ->setParameter('today', new \DateTimeImmutable('today'))
            ->getQuery()->getSingleResult();

        return ['assigned' => (int) $row['assigned'], 'confirmed' => (int) $row['confirmed'], 'overdue' => (int) $row['overdue']];
    }

    /**
     * Сводка по всем документам, где назначено ознакомление (для панели администратора).
     *
     * @return list<array{document: Document, version: int, assigned: int, confirmed: int, overdue: int, last_assigned: \DateTimeImmutable}>
     */
    public function overview(bool $onlyPending = false, int $limit = 200): array
    {
        $rows = $this->createQueryBuilder('a')
            ->select('IDENTITY(a.document) AS document_id', 'a.versionNumber AS version', 'COUNT(a.id) AS assigned',
                'SUM(CASE WHEN a.confirmedAt IS NOT NULL THEN 1 ELSE 0 END) AS confirmed',
                'SUM(CASE WHEN a.confirmedAt IS NULL AND a.dueAt IS NOT NULL AND a.dueAt < :today THEN 1 ELSE 0 END) AS overdue',
                'MAX(a.assignedAt) AS last_assigned')
            ->setParameter('today', new \DateTimeImmutable('today'))
            ->groupBy('document_id', 'a.versionNumber')
            ->orderBy('last_assigned', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()->getArrayResult();
        if ([] === $rows) {
            return [];
        }
        $documents = $this->getEntityManager()->getRepository(Document::class)
            ->createQueryBuilder('d')->join('d.section', 's')->addSelect('s')
            ->andWhere('d.id IN (:ids)')->setParameter('ids', array_column($rows, 'document_id'))
            ->getQuery()->getResult();
        $byId = [];
        foreach ($documents as $document) {
            $byId[$document->getId()] = $document;
        }
        $out = [];
        foreach ($rows as $row) {
            $document = $byId[(int) $row['document_id']] ?? null;
            if (null === $document) {
                continue;
            }
            $pending = (int) $row['assigned'] - (int) $row['confirmed'];
            if ($onlyPending && 0 === $pending) {
                continue;
            }
            $out[] = [
                'document' => $document,
                'version' => (int) $row['version'],
                'assigned' => (int) $row['assigned'],
                'confirmed' => (int) $row['confirmed'],
                'overdue' => (int) $row['overdue'],
                'last_assigned' => new \DateTimeImmutable((string) $row['last_assigned']),
            ];
        }

        return $out;
    }

    /** @return array{assigned: int, confirmed: int, overdue: int, documents: int} общая сводка */
    public function totals(): array
    {
        $row = $this->createQueryBuilder('a')
            ->select('COUNT(a.id) AS assigned', 'SUM(CASE WHEN a.confirmedAt IS NOT NULL THEN 1 ELSE 0 END) AS confirmed',
                'SUM(CASE WHEN a.confirmedAt IS NULL AND a.dueAt IS NOT NULL AND a.dueAt < :today THEN 1 ELSE 0 END) AS overdue',
                'COUNT(DISTINCT a.document) AS documents')
            ->setParameter('today', new \DateTimeImmutable('today'))
            ->getQuery()->getSingleResult();

        return ['assigned' => (int) $row['assigned'], 'confirmed' => (int) $row['confirmed'], 'overdue' => (int) $row['overdue'], 'documents' => (int) $row['documents']];
    }

    /**
     * Строки сводки по подразделениям: для каждого документа и подразделения — сколько сотрудникам назначено,
     * сколько подтвердили и сколько просрочили. Учитываются последние назначения (более новая редакция
     * перекрывает прежнюю), опубликованные документы и действующие учётные записи.
     *
     * @param list<int>|null $sectionIds разделы (раздел с подразделами); null — все
     *
     * @return list<array{document_id: int, department: string, assigned: int, confirmed: int, overdue: int, last_assigned: string}>
     */
    public function departmentRows(?array $sectionIds = null): array
    {
        // Подразделения группируются по точному написанию (utf8mb4_bin): объединяет их вызывающий код,
        // выбирая для названия самое частое написание (иначе база вернула бы случайное из группы).
        $sql = 'SELECT a.document_id, COALESCE(u.department, \'\') COLLATE utf8mb4_bin AS dept_exact, COUNT(*) AS assigned,
                    SUM(CASE WHEN a.confirmed_at IS NOT NULL THEN 1 ELSE 0 END) AS confirmed,
                    SUM(CASE WHEN a.confirmed_at IS NULL AND a.due_at IS NOT NULL AND a.due_at < :today THEN 1 ELSE 0 END) AS overdue,
                    MAX(a.assigned_at) AS last_assigned
                FROM document_acknowledgement a
                JOIN `user` u ON u.id = a.user_id
                JOIN document d ON d.id = a.document_id
                WHERE u.active = 1 AND d.status = :published
                  AND NOT EXISTS (SELECT 1 FROM document_acknowledgement a2
                                  WHERE a2.document_id = a.document_id AND a2.user_id = a.user_id AND a2.version_number > a.version_number)';
        $params = ['today' => (new \DateTimeImmutable('today'))->format('Y-m-d'), 'published' => Document::STATUS_PUBLISHED];
        $types = [];
        if (null !== $sectionIds) {
            if ([] === $sectionIds) {
                return [];
            }
            $sql .= ' AND d.section_id IN (:sections)';
            $params['sections'] = $sectionIds;
            $types['sections'] = \Doctrine\DBAL\ArrayParameterType::INTEGER;
        }
        $sql .= ' GROUP BY a.document_id, COALESCE(u.department, \'\') COLLATE utf8mb4_bin';
        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative($sql, $params, $types);

        return array_map(static fn (array $r): array => [
            'document_id' => (int) $r['document_id'],
            'department' => (string) $r['dept_exact'],
            'assigned' => (int) $r['assigned'],
            'confirmed' => (int) $r['confirmed'],
            'overdue' => (int) $r['overdue'],
            'last_assigned' => (string) $r['last_assigned'],
        ], $rows);
    }

    /**
     * Ознакомления по сотрудникам (последняя назначенная редакция опубликованных документов, активные
     * сотрудники): user_id → назначено, подтверждено, просрочено. Для единого отчёта «Документы и видео».
     *
     * @param list<int>|null $sectionIds
     *
     * @return array<int, array{assigned: int, confirmed: int, overdue: int}>
     */
    public function userRows(?array $sectionIds = null): array
    {
        $sql = 'SELECT a.user_id, COUNT(*) AS assigned,
                    SUM(CASE WHEN a.confirmed_at IS NOT NULL THEN 1 ELSE 0 END) AS confirmed,
                    SUM(CASE WHEN a.confirmed_at IS NULL AND a.due_at IS NOT NULL AND a.due_at < :today THEN 1 ELSE 0 END) AS overdue
                FROM document_acknowledgement a
                JOIN `user` u ON u.id = a.user_id
                JOIN document d ON d.id = a.document_id
                WHERE u.active = 1 AND d.status = :published
                  AND NOT EXISTS (SELECT 1 FROM document_acknowledgement a2
                                  WHERE a2.document_id = a.document_id AND a2.user_id = a.user_id AND a2.version_number > a.version_number)';
        $params = ['today' => (new \DateTimeImmutable('today'))->format('Y-m-d'), 'published' => Document::STATUS_PUBLISHED];
        $types = [];
        if (null !== $sectionIds) {
            if ([] === $sectionIds) {
                return [];
            }
            $sql .= ' AND d.section_id IN (:sections)';
            $params['sections'] = $sectionIds;
            $types['sections'] = \Doctrine\DBAL\ArrayParameterType::INTEGER;
        }
        $sql .= ' GROUP BY a.user_id';
        $out = [];
        foreach ($this->getEntityManager()->getConnection()->fetchAllAssociative($sql, $params, $types) as $r) {
            $out[(int) $r['user_id']] = ['assigned' => (int) $r['assigned'], 'confirmed' => (int) $r['confirmed'], 'overdue' => (int) $r['overdue']];
        }

        return $out;
    }

    public function countPendingForUser(User $user, ?Viewer $viewer = null): int
    {
        $qb = $this->createQueryBuilder('a')->select('COUNT(a.id)')
            ->join('a.document', 'd')
            ->andWhere('a.user = :u_id')->setParameter('u_id', $user)
            ->andWhere('a.confirmedAt IS NULL')
            ->andWhere(self::NOT_SUPERSEDED);
        $this->applyReadable($qb, $user, $viewer);

        return (int) $qb->getQuery()->getSingleScalarResult();
    }
}
