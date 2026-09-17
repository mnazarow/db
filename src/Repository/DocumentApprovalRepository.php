<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Document;
use App\Entity\DocumentApproval;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DocumentApproval>
 */
final class DocumentApprovalRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DocumentApproval::class);
    }

    /** Текущий (незакрытый) запрос согласования по документу. */
    public function findPending(Document $document): ?DocumentApproval
    {
        return $this->createQueryBuilder('a')
            ->join('a.approver', 'u')->addSelect('u')
            ->andWhere('a.document = :d')->setParameter('d', $document)
            ->andWhere('a.decision = :pending')->setParameter('pending', DocumentApproval::PENDING)
            ->orderBy('a.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()->getOneOrNullResult();
    }

    /** Решение по указанной редакции (последнее). */
    public function findDecided(Document $document, int $versionNumber): ?DocumentApproval
    {
        return $this->createQueryBuilder('a')
            ->join('a.approver', 'u')->addSelect('u')
            ->andWhere('a.document = :d')->setParameter('d', $document)
            ->andWhere('a.versionNumber = :v')->setParameter('v', $versionNumber)
            ->andWhere('a.decision IN (:decided)')->setParameter('decided', [DocumentApproval::APPROVED, DocumentApproval::REJECTED])
            ->orderBy('a.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()->getOneOrNullResult();
    }

    /** Согласована ли эта редакция документа. */
    public function isApproved(Document $document, int $versionNumber): bool
    {
        return (bool) $this->createQueryBuilder('a')->select('COUNT(a.id)')
            ->andWhere('a.document = :d')->setParameter('d', $document)
            ->andWhere('a.versionNumber = :v')->setParameter('v', $versionNumber)
            ->andWhere('a.decision = :approved')->setParameter('approved', DocumentApproval::APPROVED)
            ->getQuery()->getSingleScalarResult();
    }

    /**
     * История согласований документа, новые сверху.
     *
     * @return list<DocumentApproval>
     */
    public function findForDocument(Document $document): array
    {
        return $this->createQueryBuilder('a')
            ->join('a.approver', 'u')->addSelect('u')
            ->leftJoin('a.requestedBy', 'r')->addSelect('r')
            ->andWhere('a.document = :d')->setParameter('d', $document)
            ->orderBy('a.id', 'DESC')
            ->getQuery()->getResult();
    }

    /**
     * Что ждёт решения этого сотрудника.
     *
     * @return list<DocumentApproval>
     */
    public function findPendingForUser(User $user, int $limit = 100): array
    {
        return $this->createQueryBuilder('a')
            ->join('a.document', 'd')->addSelect('d')
            ->join('d.section', 's')->addSelect('s')
            ->leftJoin('a.requestedBy', 'r')->addSelect('r')
            ->andWhere('a.approver = :u')->setParameter('u', $user)
            ->andWhere('a.decision = :pending')->setParameter('pending', DocumentApproval::PENDING)
            ->orderBy('a.requestedAt', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()->getResult();
    }

    /**
     * История решений сотрудника.
     *
     * @return list<DocumentApproval>
     */
    public function findDecidedByUser(User $user, int $limit = 50): array
    {
        return $this->createQueryBuilder('a')
            ->join('a.document', 'd')->addSelect('d')
            ->join('d.section', 's')->addSelect('s')
            ->andWhere('a.approver = :u')->setParameter('u', $user)
            ->andWhere('a.decision IN (:decided)')->setParameter('decided', [DocumentApproval::APPROVED, DocumentApproval::REJECTED])
            ->orderBy('a.decidedAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()->getResult();
    }

    public function countPendingForUser(User $user): int
    {
        return (int) $this->createQueryBuilder('a')->select('COUNT(a.id)')
            ->andWhere('a.approver = :u')->setParameter('u', $user)
            ->andWhere('a.decision = :pending')->setParameter('pending', DocumentApproval::PENDING)
            ->getQuery()->getSingleScalarResult();
    }

    /** Ждёт ли документ решения именно этого сотрудника (нужно, чтобы он мог открыть черновик). */
    public function isPendingApprover(User $user, Document $document): bool
    {
        return (bool) $this->createQueryBuilder('a')->select('COUNT(a.id)')
            ->andWhere('a.document = :d')->setParameter('d', $document)
            ->andWhere('a.approver = :u')->setParameter('u', $user)
            ->andWhere('a.decision = :pending')->setParameter('pending', DocumentApproval::PENDING)
            ->getQuery()->getSingleScalarResult();
    }

    /**
     * Все незакрытые согласования (сводка для администратора).
     *
     * @return list<DocumentApproval>
     */
    public function findAllPending(int $limit = 200): array
    {
        return $this->createQueryBuilder('a')
            ->join('a.document', 'd')->addSelect('d')
            ->join('d.section', 's')->addSelect('s')
            ->join('a.approver', 'u')->addSelect('u')
            ->leftJoin('a.requestedBy', 'r')->addSelect('r')
            ->andWhere('a.decision = :pending')->setParameter('pending', DocumentApproval::PENDING)
            ->orderBy('a.requestedAt', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()->getResult();
    }
}
