<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\DocumentKind;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DocumentKind>
 */
final class DocumentKindRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DocumentKind::class);
    }

    /** @return list<DocumentKind> типы в порядке показа */
    public function findOrdered(): array
    {
        return $this->createQueryBuilder('k')->orderBy('k.position', 'ASC')->addOrderBy('k.name', 'ASC')->getQuery()->getResult();
    }

    /** Поиск по названию без учёта регистра и лишних пробелов. */
    public function findOneByName(string $name): ?DocumentKind
    {
        $name = DocumentKind::normalizeName($name);
        if ('' === $name) {
            return null;
        }
        foreach ($this->findOrdered() as $kind) {
            if (mb_strtolower($kind->getName()) === mb_strtolower($name)) {
                return $kind;
            }
        }

        return null;
    }

    public function findFallback(): ?DocumentKind
    {
        return $this->findOneBy(['system' => true]) ?? $this->findOneByName(DocumentKind::FALLBACK);
    }

    public function nextPosition(): int
    {
        return (int) $this->createQueryBuilder('k')->select('COALESCE(MAX(k.position), 0)')->getQuery()->getSingleScalarResult() + 1;
    }

    /**
     * Сколько документов каждого типа (для страницы управления типами).
     *
     * @return array<int, int> id типа => число документов
     */
    public function countDocuments(): array
    {
        $rows = $this->getEntityManager()->createQuery('SELECT IDENTITY(d.kind) AS id, COUNT(d.id) AS total FROM App\Entity\Document d WHERE d.kind IS NOT NULL GROUP BY d.kind')->getArrayResult();
        $counts = [];
        foreach ($rows as $row) {
            $counts[(int) $row['id']] = (int) $row['total'];
        }

        return $counts;
    }

    /**
     * Сколько строк таблиц материалов каждого типа.
     *
     * @return array<int, int>
     */
    public function countMaterials(): array
    {
        $rows = $this->getEntityManager()->createQuery('SELECT IDENTITY(r.kind) AS id, COUNT(r.id) AS total FROM App\Entity\MaterialRow r WHERE r.kind IS NOT NULL GROUP BY r.kind')->getArrayResult();
        $counts = [];
        foreach ($rows as $row) {
            $counts[(int) $row['id']] = (int) $row['total'];
        }

        return $counts;
    }
}
