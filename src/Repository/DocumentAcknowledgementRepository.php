<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Document;
use App\Entity\DocumentAcknowledgement;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
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
    public function findPendingForUser(User $user, int $limit = 100): array
    {
        return $this->createQueryBuilder('a')
            ->join('a.document', 'd')->addSelect('d')
            ->join('d.section', 's')->addSelect('s')
            ->andWhere('a.user = :u')->setParameter('u', $user)
            ->andWhere('a.confirmedAt IS NULL')
            ->andWhere(self::NOT_SUPERSEDED)
            ->orderBy('a.dueAt', 'ASC')->addOrderBy('a.assignedAt', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()->getResult();
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
     * Неподтверждённые назначения с истёкшим или наступающим сроком — для напоминаний по почте.
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
            ->andWhere('u.active = true')
            ->andWhere('a.dueAt IS NULL OR a.dueAt <= :soon')->setParameter('soon', $today->modify('+'.max(0, $soonDays).' days'))
            ->orderBy('u.id', 'ASC')->addOrderBy('a.dueAt', 'ASC');
        if (null !== $remindedBefore) {
            $qb->andWhere('a.remindedAt IS NULL OR a.remindedAt < :before')->setParameter('before', $remindedBefore);
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

    public function countPendingForUser(User $user): int
    {
        return (int) $this->createQueryBuilder('a')->select('COUNT(a.id)')
            ->andWhere('a.user = :u')->setParameter('u', $user)
            ->andWhere('a.confirmedAt IS NULL')
            ->andWhere(self::NOT_SUPERSEDED)
            ->getQuery()->getSingleScalarResult();
    }
}
