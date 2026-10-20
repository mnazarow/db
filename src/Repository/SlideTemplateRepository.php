<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\SlideTemplate;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SlideTemplate>
 */
class SlideTemplateRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SlideTemplate::class);
    }

    /** @return list<SlideTemplate> по категориям, внутри — по порядку */
    public function findOrdered(): array
    {
        return $this->createQueryBuilder('t')
            ->leftJoin('t.createdBy', 'u')->addSelect('u')
            ->orderBy('t.category', \SortDirection::Ascending)->addOrderBy('t.position', \SortDirection::Ascending)->addOrderBy('t.id', \SortDirection::Ascending)
            ->getQuery()->getResult();
    }

    /** @return list<string> категории библиотеки по алфавиту */
    public function categories(): array
    {
        return array_column($this->createQueryBuilder('t')->select('DISTINCT t.category AS c')->orderBy('c', \SortDirection::Ascending)->getQuery()->getScalarResult(), 'c');
    }

    public function nextPosition(string $category): int
    {
        return 1 + (int) $this->createQueryBuilder('t')->select('MAX(t.position)')->where('t.category = :c')->setParameter('c', $category)->getQuery()->getSingleScalarResult();
    }

    /** Изображение (asset:КЛЮЧ) есть на слайде библиотеки. */
    public function isTokenUsed(string $token, ?int $exceptId = null): bool
    {
        $qb = $this->createQueryBuilder('t')->select('COUNT(t.id)')->where('t.content LIKE :t')->setParameter('t', '%asset:'.$token.'%');
        if (null !== $exceptId) {
            $qb->andWhere('t.id <> :id')->setParameter('id', $exceptId);
        }

        return (int) $qb->getQuery()->getSingleScalarResult() > 0;
    }
}
