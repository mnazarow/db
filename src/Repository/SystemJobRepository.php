<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\SystemJob;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SystemJob>
 */
final class SystemJobRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SystemJob::class);
    }

    /** @return array<string, SystemJob> задания по имени */
    public function allByName(): array
    {
        $out = [];
        foreach ($this->createQueryBuilder('j')->orderBy('j.name', 'ASC')->getQuery()->getResult() as $job) {
            $out[$job->getName()] = $job;
        }

        return $out;
    }
}
