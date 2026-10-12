<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\MaterialParameter;
use App\Entity\Section;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MaterialParameter>
 */
final class MaterialParameterRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MaterialParameter::class);
    }

    /** @return list<MaterialParameter> столбцы раздела по порядку */
    public function findForSection(Section $section): array
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.section = :section')->setParameter('section', $section)
            ->orderBy('p.position', \SortDirection::Ascending)->addOrderBy('p.id', \SortDirection::Ascending)
            ->getQuery()->getResult();
    }

    /**
     * Столбцы нескольких разделов сразу (для сводной).
     *
     * @param list<int> $sectionIds
     *
     * @return array<int, list<MaterialParameter>> id раздела => столбцы
     */
    public function findForSections(array $sectionIds): array
    {
        if ([] === $sectionIds) {
            return [];
        }
        $grouped = [];
        /** @var MaterialParameter $parameter */
        foreach ($this->createQueryBuilder('p')->andWhere('p.section IN (:ids)')->setParameter('ids', $sectionIds)
            ->orderBy('p.position', \SortDirection::Ascending)->getQuery()->getResult() as $parameter) {
            $grouped[(int) $parameter->getSection()->getId()][] = $parameter;
        }

        return $grouped;
    }

    public function nextPosition(Section $section): int
    {
        return (int) $this->createQueryBuilder('p')->select('COALESCE(MAX(p.position), 0)')
            ->andWhere('p.section = :section')->setParameter('section', $section)
            ->getQuery()->getSingleScalarResult() + 1;
    }
}
