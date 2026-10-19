<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Meeting;
use App\Entity\Presentation;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Presentation>
 */
final class PresentationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Presentation::class);
    }

    /** @return list<Presentation> презентации сотрудника (обычные или темы собраний), новые сверху */
    public function findByAuthor(User $user, string $kind, int $limit = 100): array
    {
        return $this->createQueryBuilder('p')
            ->leftJoin('p.meeting', 'm')->addSelect('m')
            ->andWhere('p.author = :u')->setParameter('u', $user)
            ->andWhere('p.kind = :k')->setParameter('k', $kind)
            ->orderBy('p.updatedAt', \SortDirection::Descending)
            ->setMaxResults($limit)
            ->getQuery()->getResult();
    }

    /** @return list<Presentation> одобренные обычные презентации (готовые — видны всем сотрудникам) */
    public function findApprovedGeneral(?string $query = null, int $limit = 60): array
    {
        $qb = $this->createQueryBuilder('p')
            ->leftJoin('p.author', 'a')->addSelect('a')
            ->andWhere('p.kind = :k')->setParameter('k', Presentation::KIND_GENERAL)
            ->andWhere('p.approvedContent IS NOT NULL')
            ->orderBy('p.approvedAt', \SortDirection::Descending)
            ->setMaxResults($limit);
        $query = null !== $query ? trim($query) : '';
        if ('' !== $query) {
            $qb->andWhere('LOWER(p.approvedTitle) LIKE :q OR LOWER(p.description) LIKE :q OR LOWER(a.displayName) LIKE :q')
                ->setParameter('q', '%'.addcslashes(mb_strtolower($query), '%_\\').'%');
        }

        return $qb->getQuery()->getResult();
    }

    /** @return list<Presentation> ждут решения модератора (обычные и темы), давние сверху */
    public function findModerationQueue(?string $kind = null): array
    {
        $qb = $this->createQueryBuilder('p')
            ->leftJoin('p.author', 'a')->addSelect('a')
            ->leftJoin('p.meeting', 'm')->addSelect('m')
            ->andWhere('p.status = :s')->setParameter('s', Presentation::STATUS_SUBMITTED)
            ->orderBy('p.submittedAt', \SortDirection::Ascending);
        if (null !== $kind) {
            $qb->andWhere('p.kind = :k')->setParameter('k', $kind);
        }

        return $qb->getQuery()->getResult();
    }

    public function countModerationQueue(): int
    {
        return (int) $this->createQueryBuilder('p')->select('COUNT(p.id)')
            ->andWhere('p.status = :s')->setParameter('s', Presentation::STATUS_SUBMITTED)
            ->getQuery()->getSingleScalarResult();
    }

    /** @return list<Presentation> решения модератора (последние) */
    public function findDecidedBy(User $user, int $limit = 30): array
    {
        return $this->createQueryBuilder('p')
            ->leftJoin('p.author', 'a')->addSelect('a')
            ->andWhere('p.decidedBy = :u')->setParameter('u', $user)
            ->orderBy('p.decidedAt', \SortDirection::Descending)
            ->setMaxResults($limit)
            ->getQuery()->getResult();
    }

    /** @return list<Presentation> все темы собрания по порядку */
    public function findTopics(Meeting $meeting): array
    {
        return $this->createQueryBuilder('p')
            ->leftJoin('p.author', 'a')->addSelect('a')
            ->andWhere('p.meeting = :m')->setParameter('m', $meeting)
            ->orderBy('p.position', \SortDirection::Ascending)
            ->addOrderBy('p.id', \SortDirection::Ascending)
            ->getQuery()->getResult();
    }

    /** @return list<Presentation> одобренные темы собрания по порядку — из них собирается презентация */
    public function findApprovedTopics(Meeting $meeting): array
    {
        return array_values(array_filter($this->findTopics($meeting), static fn (Presentation $p): bool => $p->hasApprovedVersion()));
    }

    public function maxPosition(Meeting $meeting): int
    {
        return (int) $this->createQueryBuilder('p')->select('MAX(p.position)')
            ->andWhere('p.meeting = :m')->setParameter('m', $meeting)
            ->getQuery()->getSingleScalarResult();
    }

    /**
     * Ссылаются ли на изображение другие презентации (рабочая или одобренная редакция).
     */
    public function isTokenUsedElsewhere(string $token, ?int $exceptId): bool
    {
        $qb = $this->createQueryBuilder('p')->select('COUNT(p.id)')
            ->andWhere('p.content LIKE :t OR p.approvedContent LIKE :t')->setParameter('t', '%asset:'.$token.'%');
        if (null !== $exceptId) {
            $qb->andWhere('p.id <> :id')->setParameter('id', $exceptId);
        }

        return (int) $qb->getQuery()->getSingleScalarResult() > 0;
    }
}
