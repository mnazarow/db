<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\SignatureSheetRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Выданный порталом лист ознакомления (PDF): код проверки с листа (QR) ведёт на страницу /verify/<код>,
 * где видно, что лист сформирован порталом и совпадают ли записи об ознакомлении и согласовании с текущими.
 *
 * Реквизиты документа хранятся как были при формировании: проверка работает и после удаления документа.
 */
#[ORM\Entity(repositoryClass: SignatureSheetRepository::class)]
#[ORM\Table(name: 'signature_sheet')]
#[ORM\UniqueConstraint(name: 'uniq_signature_sheet_code', columns: ['code'])]
#[ORM\Index(name: 'idx_signature_sheet_document', columns: ['document_id', 'version_number'])]
class SignatureSheet
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Код проверки: 16 символов (Crockford base32) группами по четыре через дефис. */
    #[ORM\Column(length: 32)]
    private string $code;

    #[ORM\ManyToOne(targetEntity: Document::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Document $document;

    #[ORM\Column(length: 255)]
    private string $documentTitle;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $documentCode;

    #[ORM\Column(type: Types::SMALLINT)]
    private int $versionNumber;

    /** SHA-256 файла редакции (для страниц — её текста). */
    #[ORM\Column(length: 64, nullable: true)]
    private ?string $fileChecksum;

    /** SHA-256 записей об ознакомлении и согласовании на момент формирования листа. */
    #[ORM\Column(length: 64)]
    private string $contentHash;

    #[ORM\Column]
    private int $acknowledged;

    #[ORM\Column]
    private int $assigned;

    #[ORM\Column]
    private int $approvals;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $createdBy;

    #[ORM\Column(length: 255)]
    private string $createdByName;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $code, Document $document, int $versionNumber, ?string $fileChecksum, string $contentHash, int $assigned, int $acknowledged, int $approvals, User $createdBy)
    {
        $this->code = $code;
        $this->document = $document;
        $this->documentTitle = mb_substr($document->getTitle(), 0, 255);
        $this->documentCode = null === $document->getCode() ? null : mb_substr($document->getCode(), 0, 100);
        $this->versionNumber = $versionNumber;
        $this->fileChecksum = $fileChecksum;
        $this->contentHash = $contentHash;
        $this->assigned = $assigned;
        $this->acknowledged = $acknowledged;
        $this->approvals = $approvals;
        $this->createdBy = $createdBy;
        $this->createdByName = mb_substr($createdBy->getDisplayName(), 0, 255);
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function getDocument(): ?Document
    {
        return $this->document;
    }

    public function getDocumentTitle(): string
    {
        return $this->documentTitle;
    }

    public function getDocumentCode(): ?string
    {
        return $this->documentCode;
    }

    public function getVersionNumber(): int
    {
        return $this->versionNumber;
    }

    public function getFileChecksum(): ?string
    {
        return $this->fileChecksum;
    }

    public function getContentHash(): string
    {
        return $this->contentHash;
    }

    public function getAssigned(): int
    {
        return $this->assigned;
    }

    public function getAcknowledged(): int
    {
        return $this->acknowledged;
    }

    public function getApprovals(): int
    {
        return $this->approvals;
    }

    public function getCreatedBy(): ?User
    {
        return $this->createdBy;
    }

    public function getCreatedByName(): string
    {
        return $this->createdByName;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
