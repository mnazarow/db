<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AiVerifiedAnswer;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AiVerifiedAnswer>
 */
final class AiVerifiedAnswerRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AiVerifiedAnswer::class);
    }

    /** @return list<AiVerifiedAnswer> включённые сверху, затем недавно изменённые */
    public function findAllOrdered(): array
    {
        return $this->createQueryBuilder('a')
            ->orderBy('a.active', \SortDirection::Descending)->addOrderBy('a.updatedAt', \SortDirection::Descending)->addOrderBy('a.id', \SortDirection::Descending)
            ->getQuery()->getResult();
    }

    /** @return list<AiVerifiedAnswer> */
    public function findActive(): array
    {
        return $this->createQueryBuilder('a')->andWhere('a.active = true')->orderBy('a.id', \SortDirection::Ascending)->getQuery()->getResult();
    }

    public function countActive(): int
    {
        return (int) $this->createQueryBuilder('a')->select('COUNT(a.id)')->andWhere('a.active = true')->getQuery()->getSingleScalarResult();
    }
}
