<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Document;
use App\Entity\DocumentQuestion;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DocumentQuestion>
 */
final class DocumentQuestionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DocumentQuestion::class);
    }

    /** @return list<DocumentQuestion> вопросы документа по порядку */
    public function findForDocument(Document $document): array
    {
        return $this->createQueryBuilder('q')
            ->andWhere('q.document = :d')->setParameter('d', $document)
            ->orderBy('q.position', 'ASC')->addOrderBy('q.id', 'ASC')
            ->getQuery()->getResult();
    }

    public function countForDocument(Document $document): int
    {
        return (int) $this->createQueryBuilder('q')->select('COUNT(q.id)')
            ->andWhere('q.document = :d')->setParameter('d', $document)
            ->getQuery()->getSingleScalarResult();
    }

    /** @return array<int, int> идентификатор документа => число вопросов */
    public function countByDocuments(array $documentIds): array
    {
        if ([] === $documentIds) {
            return [];
        }
        $rows = $this->createQueryBuilder('q')
            ->select('IDENTITY(q.document) AS document_id', 'COUNT(q.id) AS total')
            ->andWhere('q.document IN (:ids)')->setParameter('ids', $documentIds)
            ->groupBy('document_id')
            ->getQuery()->getArrayResult();
        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['document_id']] = (int) $row['total'];
        }

        return $out;
    }
}
