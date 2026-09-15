<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\DocumentVersion;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DocumentVersion>
 */
final class DocumentVersionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DocumentVersion::class);
    }

    public function countAll(): int
    {
        return (int) $this->createQueryBuilder('v')->select('COUNT(v.id)')->getQuery()->getSingleScalarResult();
    }

    public function totalStorageBytes(): int
    {
        return (int) $this->createQueryBuilder('v')->select('COALESCE(SUM(v.size), 0)')->getQuery()->getSingleScalarResult();
    }
}
