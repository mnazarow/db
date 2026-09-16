<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Отметка об удалённом документе — чтобы внешние системы (индексатор RAG) могли узнать через API,
 * какие документы исчезли с момента последней синхронизации (события документа удаляются вместе с ним).
 */
#[ORM\Entity]
#[ORM\Table(name: 'document_deletion')]
#[ORM\Index(name: 'idx_document_deletion_at', columns: ['deleted_at'])]
class DocumentDeletion
{
    #[ORM\Id]
    #[ORM\Column(name: 'document_id')]
    private int $documentId;

    #[ORM\Column(length: 255)]
    private string $title;

    #[ORM\Column(name: 'section_path', length: 512, nullable: true)]
    private ?string $sectionPath = null;

    #[ORM\Column(name: 'deleted_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $deletedAt;

    #[ORM\Column(name: 'deleted_by', length: 128, nullable: true)]
    private ?string $deletedBy = null;

    public function __construct(int $documentId, string $title, ?string $sectionPath, ?string $deletedBy)
    {
        $this->documentId = $documentId;
        $this->title = mb_substr($title, 0, 255);
        $this->sectionPath = null === $sectionPath ? null : mb_substr($sectionPath, 0, 512);
        $this->deletedBy = $deletedBy;
        $this->deletedAt = new \DateTimeImmutable();
    }

    public function getDocumentId(): int
    {
        return $this->documentId;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getSectionPath(): ?string
    {
        return $this->sectionPath;
    }

    public function getDeletedAt(): \DateTimeImmutable
    {
        return $this->deletedAt;
    }

    public function getDeletedBy(): ?string
    {
        return $this->deletedBy;
    }
}
