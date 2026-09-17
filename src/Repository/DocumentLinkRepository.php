<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Document;
use App\Entity\DocumentLink;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DocumentLink>
 */
final class DocumentLinkRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DocumentLink::class);
    }

    /**
     * Все связи документа — заведённые у него и указывающие на него.
     *
     * @return list<DocumentLink>
     */
    public function findForDocument(Document $document): array
    {
        /** @var list<DocumentLink> $links */
        $links = $this->createQueryBuilder('l')
            ->andWhere('l.source = :document OR l.target = :document')->setParameter('document', $document)
            ->leftJoin('l.source', 's')->addSelect('s')
            ->leftJoin('l.target', 't')->addSelect('t')
            ->orderBy('l.type', 'ASC')->addOrderBy('l.createdAt', 'ASC')
            ->getQuery()->getResult();

        return $links;
    }

    public function findBetween(Document $source, Document $target, string $type): ?DocumentLink
    {
        return $this->findOneBy(['source' => $source, 'target' => $target, 'type' => $type]);
    }

    /**
     * Документы, которые этот документ заменяет или отменяет (для подсказки «перенести в архив»).
     *
     * @return list<Document>
     */
    public function supersededBy(Document $document): array
    {
        /** @var list<DocumentLink> $links */
        $links = $this->createQueryBuilder('l')
            ->andWhere('l.source = :document')->setParameter('document', $document)
            ->andWhere('l.type IN (:types)')->setParameter('types', [DocumentLink::REPLACES, DocumentLink::CANCELS])
            ->getQuery()->getResult();

        return array_values(array_map(static fn (DocumentLink $link): Document => $link->getTarget(), $links));
    }

    /**
     * Счётчики связей сразу по нескольким документам (для реестра).
     *
     * @param list<int> $documentIds
     *
     * @return array<int, int>
     */
    public function countByDocuments(array $documentIds): array
    {
        if ([] === $documentIds) {
            return [];
        }
        $counts = [];
        foreach (['source', 'target'] as $side) {
            $rows = $this->createQueryBuilder('l')
                ->select('IDENTITY(l.'.$side.') AS id', 'COUNT(l.id) AS total')
                ->andWhere('l.'.$side.' IN (:ids)')->setParameter('ids', $documentIds)
                ->groupBy('l.'.$side)
                ->getQuery()->getArrayResult();
            foreach ($rows as $row) {
                $counts[(int) $row['id']] = ($counts[(int) $row['id']] ?? 0) + (int) $row['total'];
            }
        }

        return $counts;
    }
}
