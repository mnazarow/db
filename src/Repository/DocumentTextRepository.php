<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Document;
use App\Entity\DocumentText;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DocumentText>
 */
final class DocumentTextRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DocumentText::class);
    }

    public function findForDocument(Document $document): ?DocumentText
    {
        return $this->find($document);
    }

    /** @return array{documents: int, indexed: int, chars: int, with_text: int} сводка по индексу */
    public function summary(): array
    {
        $row = $this->getEntityManager()->getConnection()->fetchAssociative(
            'SELECT COUNT(*) AS indexed, COALESCE(SUM(chars), 0) AS chars, SUM(CASE WHEN chars > 0 THEN 1 ELSE 0 END) AS with_text FROM document_text'
        ) ?: [];
        $documents = (int) $this->getEntityManager()->getConnection()->fetchOne('SELECT COUNT(*) FROM document');

        return [
            'documents' => $documents,
            'indexed' => (int) ($row['indexed'] ?? 0),
            'chars' => (int) ($row['chars'] ?? 0),
            'with_text' => (int) ($row['with_text'] ?? 0),
        ];
    }

    /**
     * Документы, у которых индекс отсутствует или отстал от текущей версии.
     *
     * @return list<int> идентификаторы документов
     */
    public function findOutdatedDocumentIds(int $limit = 500): array
    {
        $sql = <<<'SQL'
            SELECT d.id
            FROM document d
            JOIN document_version v ON v.id = d.current_version_id
            LEFT JOIN document_text t ON t.document_id = d.id
            WHERE t.document_id IS NULL OR t.version_number <> v.number
            ORDER BY d.id
            LIMIT :limit
            SQL;

        return array_map('intval', $this->getEntityManager()->getConnection()
            ->executeQuery(str_replace(':limit', (string) max(1, $limit), $sql))->fetchFirstColumn());
    }
}
