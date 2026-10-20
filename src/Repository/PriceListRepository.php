<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\PriceList;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PriceList>
 */
class PriceListRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PriceList::class);
    }

    /**
     * Прайс-листы для списка раздела: действующие с опубликованной редакцией (гостю — только открытые);
     * $all — и без редакции, и архивные (тем, кто ведёт прайс-листы).
     *
     * @return list<PriceList>
     */
    public function findForListing(bool $guest, bool $all): array
    {
        $qb = $this->createQueryBuilder('l')->leftJoin('l.currentRevision', 'r')->addSelect('r')
            ->orderBy('l.position', \SortDirection::Ascending)->addOrderBy('l.title', \SortDirection::Ascending);
        if (!$all) {
            $qb->andWhere('l.status = :active AND l.currentRevision IS NOT NULL')->setParameter('active', PriceList::STATUS_ACTIVE);
            if ($guest) {
                $qb->andWhere('l.public = true');
            }
        }

        return $qb->getQuery()->getResult();
    }

    public function nextPosition(): int
    {
        return 1 + (int) $this->createQueryBuilder('l')->select('MAX(l.position)')->getQuery()->getSingleScalarResult();
    }
}
