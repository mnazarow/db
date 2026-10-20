<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\PriceList;
use App\Entity\PriceRevision;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PriceRevision>
 */
class PriceRevisionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PriceRevision::class);
    }

    public function nextNumber(PriceList $list): int
    {
        return 1 + (int) $this->createQueryBuilder('r')->select('MAX(r.number)')->where('r.priceList = :l')->setParameter('l', $list)->getQuery()->getSingleScalarResult();
    }

    /** @return list<PriceRevision> новые сверху */
    public function findForList(PriceList $list): array
    {
        return $this->createQueryBuilder('r')->leftJoin('r.uploadedBy', 'u')->addSelect('u')
            ->where('r.priceList = :l')->setParameter('l', $list)
            ->orderBy('r.number', \SortDirection::Descending)->getQuery()->getResult();
    }

    /** @return list<PriceRevision> */
    public function findPending(): array
    {
        return $this->createQueryBuilder('r')->join('r.priceList', 'l')->addSelect('l')->leftJoin('r.uploadedBy', 'u')->addSelect('u')
            ->where('r.status = :s')->setParameter('s', PriceRevision::STATUS_PENDING)
            ->orderBy('r.uploadedAt', \SortDirection::Ascending)->getQuery()->getResult();
    }

    public function countPending(): int
    {
        return (int) $this->createQueryBuilder('r')->select('COUNT(r.id)')->where('r.status = :s')->setParameter('s', PriceRevision::STATUS_PENDING)->getQuery()->getSingleScalarResult();
    }

    /** Предыдущая опубликованная редакция (по номеру) — для сравнения. */
    public function previousPublished(PriceRevision $revision): ?PriceRevision
    {
        return $this->createQueryBuilder('r')->where('r.priceList = :l AND r.status = :s AND r.number < :n')
            ->setParameter('l', $revision->getPriceList())->setParameter('s', PriceRevision::STATUS_PUBLISHED)->setParameter('n', $revision->getNumber())
            ->orderBy('r.number', \SortDirection::Descending)->setMaxResults(1)->getQuery()->getOneOrNullResult();
    }

    /** @return list<PriceRevision> черновики старше срока (файл загружен, столбцы не сопоставлены) */
    public function findStaleDrafts(\DateTimeImmutable $before): array
    {
        return $this->createQueryBuilder('r')->where('r.status = :s AND r.uploadedAt < :t')
            ->setParameter('s', PriceRevision::STATUS_DRAFT)->setParameter('t', $before)->getQuery()->getResult();
    }
}
