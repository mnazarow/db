<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Presentation;
use App\Entity\PresentationVersion;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PresentationVersion>
 */
final class PresentationVersionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PresentationVersion::class);
    }

    /** @return list<PresentationVersion> новые сверху */
    public function findForPresentation(Presentation $presentation, int $limit = 60): array
    {
        return $this->createQueryBuilder('v')
            ->leftJoin('v.createdBy', 'u')->addSelect('u')
            ->andWhere('v.presentation = :p')->setParameter('p', $presentation)
            ->orderBy('v.createdAt', \SortDirection::Descending)
            ->addOrderBy('v.id', \SortDirection::Descending)
            ->setMaxResults($limit)
            ->getQuery()->getResult();
    }

    public function findLatest(Presentation $presentation): ?PresentationVersion
    {
        return $this->createQueryBuilder('v')
            ->andWhere('v.presentation = :p')->setParameter('p', $presentation)
            ->orderBy('v.createdAt', \SortDirection::Descending)
            ->addOrderBy('v.id', \SortDirection::Descending)
            ->setMaxResults(1)
            ->getQuery()->getOneOrNullResult();
    }

    /** Оставить последние $keep автосохранений (отмеченные версии — отправка, решения — хранятся все). */
    public function pruneAuto(Presentation $presentation, int $keep): void
    {
        $ids = $this->createQueryBuilder('v')->select('v.id')
            ->andWhere('v.presentation = :p')->setParameter('p', $presentation)
            ->andWhere('v.kind = :k')->setParameter('k', PresentationVersion::KIND_AUTO)
            ->orderBy('v.createdAt', \SortDirection::Descending)
            ->addOrderBy('v.id', \SortDirection::Descending)
            ->setFirstResult($keep)
            ->getQuery()->getSingleColumnResult();
        if ([] !== $ids) {
            $this->createQueryBuilder('v')->delete()->andWhere('v.id IN (:ids)')->setParameter('ids', $ids)->getQuery()->execute();
        }
    }

    /** Ссылается ли на изображение сохранённая версия другой презентации. */
    public function isTokenUsedElsewhere(string $token, ?int $exceptPresentationId): bool
    {
        $qb = $this->createQueryBuilder('v')->select('COUNT(v.id)')
            ->andWhere('v.content LIKE :t')->setParameter('t', '%asset:'.$token.'%');
        if (null !== $exceptPresentationId) {
            $qb->andWhere('IDENTITY(v.presentation) <> :id')->setParameter('id', $exceptPresentationId);
        }

        return (int) $qb->getQuery()->getSingleScalarResult() > 0;
    }
}
