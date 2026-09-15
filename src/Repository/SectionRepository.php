<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Section;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Section>
 */
final class SectionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Section::class);
    }

    /** @return list<Section> разделы верхнего уровня */
    public function findRoots(): array
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.parent IS NULL')
            ->orderBy('s.position', 'ASC')->addOrderBy('s.name', 'ASC')
            ->getQuery()->getResult();
    }

    /**
     * Все разделы одним запросом, отсортированные так, что дерево можно обойти по порядку (по пути).
     *
     * @return list<Section>
     */
    public function findAllTree(): array
    {
        /** @var list<Section> $all */
        $all = $this->createQueryBuilder('s')
            ->leftJoin('s.parent', 'p')->addSelect('p')
            ->orderBy('s.depth', 'ASC')->addOrderBy('s.position', 'ASC')->addOrderBy('s.name', 'ASC')
            ->getQuery()->getResult();

        return self::sortTree($all);
    }

    /**
     * Сортирует список разделов в порядке обхода дерева (родитель, затем его дети по position/name).
     *
     * @param list<Section> $sections
     *
     * @return list<Section>
     */
    public static function sortTree(array $sections): array
    {
        $byParent = [];
        foreach ($sections as $s) {
            $byParent[$s->getParent()?->getId() ?? 0][] = $s;
        }
        foreach ($byParent as &$list) {
            usort($list, static fn (Section $a, Section $b) => [$a->getPosition(), mb_strtolower($a->getName())] <=> [$b->getPosition(), mb_strtolower($b->getName())]);
        }
        unset($list);
        $out = [];
        $walk = static function (int $parentId) use (&$walk, &$out, $byParent): void {
            foreach ($byParent[$parentId] ?? [] as $s) {
                $out[] = $s;
                $walk((int) $s->getId());
            }
        };
        $walk(0);

        return $out;
    }

    /** @return list<Section> раздел и все его потомки */
    public function findSubtree(Section $root): array
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.path LIKE :path')->setParameter('path', $root->getPath().'%')
            ->orderBy('s.depth', 'ASC')->addOrderBy('s.position', 'ASC')
            ->getQuery()->getResult();
    }

    /** @return list<int> идентификаторы раздела и всех его потомков */
    public function findSubtreeIds(Section $root): array
    {
        $rows = $this->createQueryBuilder('s')->select('s.id')
            ->andWhere('s.path LIKE :path')->setParameter('path', $root->getPath().'%')
            ->getQuery()->getArrayResult();

        return array_map(static fn (array $r) => (int) $r['id'], $rows);
    }

    public function findOneBySlug(string $slug): ?Section
    {
        return $this->findOneBy(['slug' => $slug]);
    }

    public function slugExists(string $slug, ?int $exceptId = null): bool
    {
        $qb = $this->createQueryBuilder('s')->select('COUNT(s.id)')->andWhere('s.slug = :slug')->setParameter('slug', $slug);
        if (null !== $exceptId) {
            $qb->andWhere('s.id <> :id')->setParameter('id', $exceptId);
        }

        return (int) $qb->getQuery()->getSingleScalarResult() > 0;
    }

    public function countAll(): int
    {
        return (int) $this->createQueryBuilder('s')->select('COUNT(s.id)')->getQuery()->getSingleScalarResult();
    }

    public function nextPosition(?Section $parent): int
    {
        $qb = $this->createQueryBuilder('s')->select('MAX(s.position)');
        if (null === $parent) {
            $qb->andWhere('s.parent IS NULL');
        } else {
            $qb->andWhere('s.parent = :p')->setParameter('p', $parent);
        }

        return ((int) $qb->getQuery()->getSingleScalarResult()) + 10;
    }
}
