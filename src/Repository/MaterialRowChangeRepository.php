<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\MaterialRow;
use App\Entity\MaterialRowChange;
use App\Entity\Section;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MaterialRowChange>
 */
final class MaterialRowChangeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MaterialRowChange::class);
    }

    /** @return list<MaterialRowChange> изменения строки, новые сверху */
    public function findForRow(MaterialRow $row, int $limit = 200): array
    {
        return $this->createQueryBuilder('c')
            ->leftJoin('c.user', 'u')->addSelect('u')
            ->andWhere('c.row = :row')->setParameter('row', $row)
            ->orderBy('c.createdAt', 'DESC')->addOrderBy('c.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()->getResult();
    }

    /** @return list<MaterialRowChange> журнал таблицы раздела, новые сверху */
    public function findForSection(Section $section, int $limit = 100, int $offset = 0, ?string $action = null): array
    {
        $qb = $this->createQueryBuilder('c')
            ->leftJoin('c.user', 'u')->addSelect('u')
            ->andWhere('c.section = :section')->setParameter('section', $section)
            ->orderBy('c.createdAt', 'DESC')->addOrderBy('c.id', 'DESC')
            ->setFirstResult(max(0, $offset))
            ->setMaxResults($limit);
        if (null !== $action && '' !== $action) {
            $qb->andWhere('c.action = :action')->setParameter('action', $action);
        }

        return $qb->getQuery()->getResult();
    }

    public function countForSection(Section $section, ?string $action = null): int
    {
        $qb = $this->createQueryBuilder('c')->select('COUNT(c.id)')
            ->andWhere('c.section = :section')->setParameter('section', $section);
        if (null !== $action && '' !== $action) {
            $qb->andWhere('c.action = :action')->setParameter('action', $action);
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }
}
