<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\DocumentVersionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Версия документа. Каждая загрузка нового файла или сохранение текста страницы
 * создаёт новую версию; старые версии никогда не изменяются и остаются доступными.
 */
#[ORM\Entity(repositoryClass: DocumentVersionRepository::class)]
#[ORM\Table(name: 'document_version')]
#[ORM\UniqueConstraint(name: 'uniq_document_version_number', columns: ['document_id', 'number'])]
#[ORM\Index(name: 'idx_document_version_created', columns: ['created_at'])]
class DocumentVersion
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Document::class, inversedBy: 'versions')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Document $document;

    #[ORM\Column]
    private int $number = 1;

    /** file | page */
    #[ORM\Column(length: 8)]
    private string $kind = Document::TYPE_FILE;

    /** Исходное имя загруженного файла (для файловых версий). */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $originalName = null;

    /** Путь к файлу относительно каталога хранилища. */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $storedPath = null;

    #[ORM\Column(length: 128, nullable: true)]
    private ?string $mimeType = null;

    #[ORM\Column(type: Types::BIGINT, nullable: true)]
    private ?int $size = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $checksum = null;

    /** HTML-содержимое страницы (для версий-страниц), уже очищенное от опасной разметки. */
    #[ORM\Column(type: Types::TEXT, length: 16777215, nullable: true)]
    private ?string $content = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $changeNote = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $createdBy = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(options: ['default' => 0])]
    private int $downloadCount = 0;

    public function __construct(Document $document, int $number)
    {
        $this->document = $document;
        $this->number = $number;
        $this->createdAt = new \DateTimeImmutable();
        $document->addVersion($this);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDocument(): Document
    {
        return $this->document;
    }

    public function getNumber(): int
    {
        return $this->number;
    }

    public function getKind(): string
    {
        return $this->kind;
    }

    public function setKind(string $kind): static
    {
        $this->kind = $kind;

        return $this;
    }

    public function isFile(): bool
    {
        return Document::TYPE_FILE === $this->kind;
    }

    public function isPage(): bool
    {
        return Document::TYPE_PAGE === $this->kind;
    }

    public function getOriginalName(): ?string
    {
        return $this->originalName;
    }

    public function setOriginalName(?string $originalName): static
    {
        $this->originalName = null === $originalName ? null : mb_substr($originalName, 0, 255);

        return $this;
    }

    public function getExtension(): string
    {
        return mb_strtolower(pathinfo((string) $this->originalName, \PATHINFO_EXTENSION));
    }

    public function getStoredPath(): ?string
    {
        return $this->storedPath;
    }

    public function setStoredPath(?string $storedPath): static
    {
        $this->storedPath = $storedPath;

        return $this;
    }

    public function getMimeType(): ?string
    {
        return $this->mimeType;
    }

    public function setMimeType(?string $mimeType): static
    {
        $this->mimeType = null === $mimeType ? null : mb_substr($mimeType, 0, 128);

        return $this;
    }

    public function getSize(): ?int
    {
        return $this->size;
    }

    public function setSize(?int $size): static
    {
        $this->size = $size;

        return $this;
    }

    public function getChecksum(): ?string
    {
        return $this->checksum;
    }

    public function setChecksum(?string $checksum): static
    {
        $this->checksum = $checksum;

        return $this;
    }

    public function getContent(): ?string
    {
        return $this->content;
    }

    public function setContent(?string $content): static
    {
        $this->content = $content;

        return $this;
    }

    /** Текст страницы без разметки (для поиска и сравнения версий). */
    public function getPlainText(): string
    {
        if (null === $this->content) {
            return '';
        }
        $html = preg_replace('#</(p|div|li|h[1-6]|tr|blockquote|pre)>#i', "$0\n", $this->content) ?? $this->content;
        $html = preg_replace('#<br\s*/?>#i', "\n", $html) ?? $html;
        $text = html_entity_decode(strip_tags($html), \ENT_QUOTES | \ENT_HTML5, 'UTF-8');
        $text = preg_replace("/[ \t]+/u", ' ', $text) ?? $text;
        $text = preg_replace("/\n{3,}/u", "\n\n", $text) ?? $text;

        return trim($text);
    }

    public function getChangeNote(): ?string
    {
        return $this->changeNote;
    }

    public function setChangeNote(?string $changeNote): static
    {
        $changeNote = null === $changeNote ? null : trim($changeNote);
        $this->changeNote = '' === $changeNote ? null : $changeNote;

        return $this;
    }

    public function getCreatedBy(): ?User
    {
        return $this->createdBy;
    }

    public function setCreatedBy(?User $createdBy): static
    {
        $this->createdBy = $createdBy;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): static
    {
        $this->createdAt = $createdAt;

        return $this;
    }

    public function getDownloadCount(): int
    {
        return $this->downloadCount;
    }

    public function incrementDownloadCount(): static
    {
        ++$this->downloadCount;

        return $this;
    }

    public function isCurrent(): bool
    {
        return $this->document->getCurrentVersion()?->getId() === $this->id;
    }
}
