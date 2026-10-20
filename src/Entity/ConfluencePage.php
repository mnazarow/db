<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ConfluencePageRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Что импортировано из Confluence: страница (документ-страница и, если у неё есть дочерние, раздел) или вложение
 * (документ-файл). По этой записи повторный импорт обновляет документы (новая версия Confluence — новая версия
 * в портале), а ссылки между страницами Confluence ведут на импортированные документы.
 */
#[ORM\Entity(repositoryClass: ConfluencePageRepository::class)]
#[ORM\Table(name: 'confluence_page')]
#[ORM\UniqueConstraint(name: 'uniq_confluence_page', columns: ['base_hash', 'kind', 'external_id'])]
#[ORM\Index(name: 'idx_confluence_page_title', columns: ['space_key', 'title'])]
class ConfluencePage
{
    public const KIND_PAGE = 'page';
    public const KIND_ATTACHMENT = 'attachment';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** sha1 адреса Confluence: один и тот же номер страницы в разных экземплярах Confluence — разные страницы. */
    #[ORM\Column(name: 'base_hash', length: 40)]
    private string $baseHash;

    #[ORM\Column(length: 16)]
    private string $kind;

    /** Номер страницы или вложения в Confluence. */
    #[ORM\Column(name: 'external_id', length: 64)]
    private string $externalId;

    #[ORM\Column(name: 'space_key', length: 255)]
    private string $spaceKey;

    #[ORM\Column(length: 255)]
    private string $title;

    /** Для вложения — страница, к которой оно приложено. */
    #[ORM\Column(name: 'parent_id', length: 64, nullable: true)]
    private ?string $parentId = null;

    #[ORM\Column(type: Types::INTEGER)]
    private int $version = 0;

    /** Адрес в Confluence (на случай, если документ портала удалили). */
    #[ORM\Column(name: 'web_url', length: 1000, nullable: true)]
    private ?string $webUrl = null;

    #[ORM\ManyToOne(targetEntity: Document::class)]
    #[ORM\JoinColumn(name: 'document_id', nullable: true, onDelete: 'SET NULL')]
    private ?Document $document = null;

    /** Раздел страницы с дочерними страницами. */
    #[ORM\ManyToOne(targetEntity: Section::class)]
    #[ORM\JoinColumn(name: 'section_id', nullable: true, onDelete: 'SET NULL')]
    private ?Section $section = null;

    #[ORM\Column(name: 'imported_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $importedAt;

    public function __construct(string $baseHash, string $kind, string $externalId, string $spaceKey, string $title)
    {
        $this->baseHash = $baseHash;
        $this->kind = $kind;
        $this->externalId = $externalId;
        $this->spaceKey = $spaceKey;
        $this->title = mb_substr($title, 0, 255);
        $this->importedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getBaseHash(): string
    {
        return $this->baseHash;
    }

    public function getKind(): string
    {
        return $this->kind;
    }

    public function getExternalId(): string
    {
        return $this->externalId;
    }

    public function getSpaceKey(): string
    {
        return $this->spaceKey;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): self
    {
        $this->title = mb_substr($title, 0, 255);

        return $this;
    }

    public function getParentId(): ?string
    {
        return $this->parentId;
    }

    public function setParentId(?string $parentId): self
    {
        $this->parentId = $parentId;

        return $this;
    }

    public function getVersion(): int
    {
        return $this->version;
    }

    public function setVersion(int $version): self
    {
        $this->version = $version;
        $this->importedAt = new \DateTimeImmutable();

        return $this;
    }

    public function getWebUrl(): ?string
    {
        return $this->webUrl;
    }

    public function setWebUrl(?string $webUrl): self
    {
        $this->webUrl = null !== $webUrl ? mb_substr($webUrl, 0, 1000) : null;

        return $this;
    }

    public function getDocument(): ?Document
    {
        return $this->document;
    }

    public function setDocument(?Document $document): self
    {
        $this->document = $document;

        return $this;
    }

    public function getSection(): ?Section
    {
        return $this->section;
    }

    public function setSection(?Section $section): self
    {
        $this->section = $section;

        return $this;
    }

    public function getImportedAt(): \DateTimeImmutable
    {
        return $this->importedAt;
    }
}
