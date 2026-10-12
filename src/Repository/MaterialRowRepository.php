<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Document;
use App\Entity\MaterialRow;
use App\Entity\Section;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MaterialRow>
 */
final class MaterialRowRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MaterialRow::class);
    }

    /** @return list<MaterialRow> строки раздела по порядку (с документами и типами) */
    public function findForSection(Section $section): array
    {
        return $this->createQueryBuilder('r')
            ->leftJoin('r.kind', 'k')->addSelect('k')
            ->leftJoin('r.document', 'd')->addSelect('d')
            ->leftJoin('d.currentVersion', 'v')->addSelect('v')
            ->andWhere('r.section = :section')->setParameter('section', $section)
            ->orderBy('r.position', \SortDirection::Ascending)->addOrderBy('r.id', \SortDirection::Ascending)
            ->getQuery()->getResult();
    }

    /**
     * @param list<int> $sectionIds
     *
     * @return array<int, list<MaterialRow>> id раздела => строки
     */
    public function findForSections(array $sectionIds): array
    {
        if ([] === $sectionIds) {
            return [];
        }
        $grouped = array_fill_keys($sectionIds, []);
        /** @var MaterialRow $row */
        foreach ($this->createQueryBuilder('r')->leftJoin('r.kind', 'k')->addSelect('k')
            ->andWhere('r.section IN (:ids)')->setParameter('ids', $sectionIds)
            ->orderBy('r.position', \SortDirection::Ascending)->getQuery()->getResult() as $row) {
            $grouped[(int) $row->getSection()->getId()][] = $row;
        }

        return $grouped;
    }

    /** @return list<MaterialRow> строки, из которых загружен документ */
    public function findByDocument(Document $document): array
    {
        return $this->findBy(['document' => $document]);
    }

    public function nextPosition(Section $section): int
    {
        return (int) $this->createQueryBuilder('r')->select('COALESCE(MAX(r.position), 0)')
            ->andWhere('r.section = :section')->setParameter('section', $section)
            ->getQuery()->getSingleScalarResult() + 1;
    }

    public function countForSection(Section $section): int
    {
        return (int) $this->createQueryBuilder('r')->select('COUNT(r.id)')
            ->andWhere('r.section = :section')->setParameter('section', $section)
            ->getQuery()->getSingleScalarResult();
    }
}
