<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Presentation;
use App\Entity\PresentationComment;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PresentationComment>
 */
final class PresentationCommentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PresentationComment::class);
    }

    /** @return list<PresentationComment> замечания и ответы по порядку */
    public function findForPresentation(Presentation $presentation): array
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.presentation = :p')->setParameter('p', $presentation)
            ->orderBy('c.createdAt', \SortDirection::Ascending)
            ->addOrderBy('c.id', \SortDirection::Ascending)
            ->getQuery()->getResult();
    }

    public function countOpen(Presentation $presentation): int
    {
        return (int) $this->createQueryBuilder('c')->select('COUNT(c.id)')
            ->andWhere('c.presentation = :p')->setParameter('p', $presentation)
            ->andWhere('c.parent IS NULL')
            ->andWhere('c.resolvedAt IS NULL')
            ->getQuery()->getSingleScalarResult();
    }
}
