<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Meeting;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Meeting>
 */
final class MeetingRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Meeting::class);
    }

    /** Ближайшее собрание начиная с указанного дня. */
    public function findUpcoming(\DateTimeImmutable $from): ?Meeting
    {
        return $this->createQueryBuilder('m')
            ->andWhere('m.date >= :d')->setParameter('d', $from->setTime(0, 0))
            ->orderBy('m.date', \SortDirection::Ascending)
            ->setMaxResults(1)
            ->getQuery()->getOneOrNullResult();
    }

    /** @return list<Meeting> собрания по убыванию даты (архив) */
    public function findRecent(int $limit = 30, bool $publishedOnly = false): array
    {
        $qb = $this->createQueryBuilder('m')->orderBy('m.date', \SortDirection::Descending)->setMaxResults($limit);
        if ($publishedOnly) {
            $qb->andWhere('m.status = :s')->setParameter('s', Meeting::STATUS_PUBLISHED);
        }

        return $qb->getQuery()->getResult();
    }

    public function findOneByDate(\DateTimeImmutable $date): ?Meeting
    {
        return $this->findOneBy(['date' => $date->setTime(0, 0)]);
    }

    public function findOneByNumber(int $number): ?Meeting
    {
        return $this->findOneBy(['number' => $number]);
    }

    /** Последнее (по дате) собрание — от его номера считается номер следующего. */
    public function findLast(): ?Meeting
    {
        return $this->createQueryBuilder('m')->orderBy('m.date', \SortDirection::Descending)->setMaxResults(1)->getQuery()->getOneOrNullResult();
    }

    public function maxNumber(): int
    {
        return (int) $this->createQueryBuilder('m')->select('MAX(m.number)')->getQuery()->getSingleScalarResult();
    }
}
