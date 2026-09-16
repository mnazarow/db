<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\DocumentRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Документ портала. Бывает двух видов: файл (загруженный документ любого формата)
 * и страница (текст, написанный в редакторе). Содержимое хранится в версиях (DocumentVersion),
 * карточка документа ссылается на текущую версию.
 *
 * Статусы: draft — черновик (виден только модераторам раздела и администраторам),
 * published — опубликован (виден всем сотрудникам), archived — архив (снят с публикации, сохранён для истории).
 *
 * Актуальность: validUntil — дата, до которой документ считается актуальным (null — бессрочно).
 * Доступ: isPublic — открытый документ (читается без входа), иначе — только для сотрудников после входа.
 */
#[ORM\Entity(repositoryClass: DocumentRepository::class)]
#[ORM\Table(name: 'document')]
#[ORM\Index(name: 'idx_document_section_status', columns: ['section_id', 'status'])]
#[ORM\Index(name: 'idx_document_status_valid', columns: ['status', 'valid_until'])]
#[ORM\Index(name: 'idx_document_updated', columns: ['updated_at'])]
#[ORM\Index(name: 'idx_document_code', columns: ['code'])]
#[ORM\Index(name: 'idx_document_public', columns: ['status', 'is_public'])]
#[ORM\HasLifecycleCallbacks]
class Document
{
    public const TYPE_FILE = 'file';
    public const TYPE_PAGE = 'page';

    public const STATUS_DRAFT = 'draft';
    public const STATUS_PUBLISHED = 'published';
    public const STATUS_ARCHIVED = 'archived';

    public const STATUSES = [self::STATUS_DRAFT, self::STATUS_PUBLISHED, self::STATUS_ARCHIVED];

    public const DESCRIPTION_MANUAL = 'manual';
    public const DESCRIPTION_LLM = 'llm';
    public const TYPES = [self::TYPE_FILE, self::TYPE_PAGE];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Section::class, inversedBy: 'documents')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private Section $section;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank(message: 'Укажите название документа.')]
    #[Assert\Length(max: 255, maxMessage: 'Название не должно быть длиннее 255 символов.')]
    private string $title = '';

    /** Обозначение / регистрационный номер документа (необязательно), например «ИТ-РГ-003». */
    #[ORM\Column(length: 64, nullable: true)]
    #[Assert\Length(max: 64)]
    private ?string $code = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Assert\Length(max: 8000)]
    private ?string $description = null;

    /** Откуда описание: manual — введено человеком, llm — сгенерировано внешней языковой моделью. */
    #[ORM\Column(name: 'description_source', length: 8, nullable: true)]
    private ?string $descriptionSource = null;

    #[ORM\Column(name: 'description_generated_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $descriptionGeneratedAt = null;

    #[ORM\Column(length: 8)]
    private string $type = self::TYPE_FILE;

    #[ORM\Column(length: 12)]
    private string $status = self::STATUS_DRAFT;

    /**
     * Открытый документ: опубликованную версию можно читать без входа в портал (если гостевой доступ
     * разрешён настройками). Внутренние документы (false) видны только после входа.
     */
    #[ORM\Column(name: 'is_public', options: ['default' => true])]
    private bool $isPublic = true;

    /** @var list<string> */
    #[ORM\Column(type: Types::JSON)]
    private array $tags = [];

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $owner = null;

    #[ORM\OneToOne(targetEntity: DocumentVersion::class)]
    #[ORM\JoinColumn(name: 'current_version_id', nullable: true, onDelete: 'SET NULL')]
    private ?DocumentVersion $currentVersion = null;

    /** @var Collection<int, DocumentVersion> */
    #[ORM\OneToMany(targetEntity: DocumentVersion::class, mappedBy: 'document', cascade: ['persist', 'remove'])]
    #[ORM\OrderBy(['number' => 'DESC'])]
    private Collection $versions;

    /** @var Collection<int, DocumentEvent> */
    #[ORM\OneToMany(targetEntity: DocumentEvent::class, mappedBy: 'document', cascade: ['persist', 'remove'])]
    private Collection $events;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $validUntil = null;

    /** Стадия уведомления об истечении срока: 0 — не уведомляли, 1 — «истекает», 2 — «просрочен». */
    #[ORM\Column(type: Types::SMALLINT, options: ['default' => 0])]
    private int $expiryNoticeStage = 0;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $publishedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $archivedAt = null;

    #[ORM\Column(options: ['default' => 0])]
    private int $viewCount = 0;

    #[ORM\Column(options: ['default' => 0])]
    private int $downloadCount = 0;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastViewedAt = null;

    public function __construct(Section $section)
    {
        $this->section = $section;
        $this->versions = new ArrayCollection();
        $this->events = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    #[ORM\PreUpdate]
    public function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSection(): Section
    {
        return $this->section;
    }

    public function setSection(Section $section): static
    {
        $this->section = $section;

        return $this;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): static
    {
        $this->title = trim($title);

        return $this;
    }

    public function getCode(): ?string
    {
        return $this->code;
    }

    public function setCode(?string $code): static
    {
        $code = null === $code ? null : trim($code);
        $this->code = '' === $code ? null : $code;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $description = null === $description ? null : trim($description);
        $changed = $this->description !== ('' === $description ? null : $description);
        $this->description = '' === $description ? null : $description;
        if ($changed) {
            // Ручное изменение описания снимает пометку «сгенерировано LLM» (генератор ставит её сам после вызова).
            $this->descriptionSource = null === $this->description ? null : self::DESCRIPTION_MANUAL;
            $this->descriptionGeneratedAt = null;
        }

        return $this;
    }

    public function getDescriptionSource(): ?string
    {
        return $this->descriptionSource;
    }

    public function isDescriptionGenerated(): bool
    {
        return self::DESCRIPTION_LLM === $this->descriptionSource;
    }

    /** Записывает описание, сгенерированное языковой моделью. */
    public function setGeneratedDescription(string $description): static
    {
        $this->description = '' === trim($description) ? null : trim($description);
        $this->descriptionSource = null === $this->description ? null : self::DESCRIPTION_LLM;
        $this->descriptionGeneratedAt = null === $this->description ? null : new \DateTimeImmutable();

        return $this;
    }

    public function getDescriptionGeneratedAt(): ?\DateTimeImmutable
    {
        return $this->descriptionGeneratedAt;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function setType(string $type): static
    {
        if (!\in_array($type, self::TYPES, true)) {
            throw new \InvalidArgumentException('Неизвестный тип документа: '.$type);
        }
        $this->type = $type;

        return $this;
    }

    public function isFile(): bool
    {
        return self::TYPE_FILE === $this->type;
    }

    public function isPage(): bool
    {
        return self::TYPE_PAGE === $this->type;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): static
    {
        if (!\in_array($status, self::STATUSES, true)) {
            throw new \InvalidArgumentException('Неизвестный статус документа: '.$status);
        }
        $this->status = $status;

        return $this;
    }

    public function isPublished(): bool
    {
        return self::STATUS_PUBLISHED === $this->status;
    }

    public function isDraft(): bool
    {
        return self::STATUS_DRAFT === $this->status;
    }

    public function isArchived(): bool
    {
        return self::STATUS_ARCHIVED === $this->status;
    }

    public function isPublic(): bool
    {
        return $this->isPublic;
    }

    public function setPublic(bool $public): static
    {
        $this->isPublic = $public;

        return $this;
    }

    /** @return list<string> */
    public function getTags(): array
    {
        return $this->tags;
    }

    /** @param list<string> $tags */
    public function setTags(array $tags): static
    {
        $clean = [];
        foreach ($tags as $tag) {
            $tag = mb_strtolower(trim((string) $tag));
            if ('' !== $tag && !\in_array($tag, $clean, true)) {
                $clean[] = mb_substr($tag, 0, 40);
            }
        }
        $this->tags = \array_slice($clean, 0, 20);

        return $this;
    }

    public function getTagsString(): string
    {
        return implode(', ', $this->tags);
    }

    public function setTagsString(?string $tags): static
    {
        return $this->setTags(preg_split('/[,;]+/u', (string) $tags) ?: []);
    }

    public function getOwner(): ?User
    {
        return $this->owner;
    }

    public function setOwner(?User $owner): static
    {
        $this->owner = $owner;

        return $this;
    }

    public function getCurrentVersion(): ?DocumentVersion
    {
        return $this->currentVersion;
    }

    public function setCurrentVersion(?DocumentVersion $currentVersion): static
    {
        $this->currentVersion = $currentVersion;

        return $this;
    }

    /** @return Collection<int, DocumentVersion> */
    public function getVersions(): Collection
    {
        return $this->versions;
    }

    public function addVersion(DocumentVersion $version): static
    {
        if (!$this->versions->contains($version)) {
            $this->versions->add($version);
        }

        return $this;
    }

    public function getVersionCount(): int
    {
        return $this->versions->count();
    }

    public function getNextVersionNumber(): int
    {
        $max = 0;
        foreach ($this->versions as $v) {
            $max = max($max, $v->getNumber());
        }

        return $max + 1;
    }

    public function findVersion(int $number): ?DocumentVersion
    {
        foreach ($this->versions as $v) {
            if ($v->getNumber() === $number) {
                return $v;
            }
        }

        return null;
    }

    /** @return Collection<int, DocumentEvent> */
    public function getEvents(): Collection
    {
        return $this->events;
    }

    public function getValidUntil(): ?\DateTimeImmutable
    {
        return $this->validUntil;
    }

    public function setValidUntil(?\DateTimeImmutable $validUntil): static
    {
        $old = $this->validUntil?->format('Y-m-d');
        $new = $validUntil?->format('Y-m-d');
        $this->validUntil = $validUntil;
        if ($old !== $new) {
            // Срок изменился — уведомления об истечении нужно отправлять заново.
            $this->expiryNoticeStage = 0;
        }

        return $this;
    }

    public function getExpiryNoticeStage(): int
    {
        return $this->expiryNoticeStage;
    }

    public function setExpiryNoticeStage(int $stage): static
    {
        $this->expiryNoticeStage = $stage;

        return $this;
    }

    /**
     * Сколько дней осталось до окончания срока актуальности (отрицательное — просрочен на N дней; null — бессрочно).
     */
    public function getDaysLeft(?\DateTimeImmutable $today = null): ?int
    {
        if (null === $this->validUntil) {
            return null;
        }
        $today = ($today ?? new \DateTimeImmutable('today'))->setTime(0, 0);
        $until = $this->validUntil->setTime(0, 0);

        return (int) $today->diff($until)->format('%r%a');
    }

    public function isExpired(?\DateTimeImmutable $today = null): bool
    {
        $days = $this->getDaysLeft($today);

        return null !== $days && $days < 0;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(\DateTimeImmutable $updatedAt): static
    {
        $this->updatedAt = $updatedAt;

        return $this;
    }

    public function getPublishedAt(): ?\DateTimeImmutable
    {
        return $this->publishedAt;
    }

    public function setPublishedAt(?\DateTimeImmutable $publishedAt): static
    {
        $this->publishedAt = $publishedAt;

        return $this;
    }

    public function getArchivedAt(): ?\DateTimeImmutable
    {
        return $this->archivedAt;
    }

    public function setArchivedAt(?\DateTimeImmutable $archivedAt): static
    {
        $this->archivedAt = $archivedAt;

        return $this;
    }

    public function getViewCount(): int
    {
        return $this->viewCount;
    }

    public function incrementViewCount(): static
    {
        ++$this->viewCount;
        $this->lastViewedAt = new \DateTimeImmutable();

        return $this;
    }

    public function setViewCount(int $viewCount): static
    {
        $this->viewCount = $viewCount;

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

    public function setDownloadCount(int $downloadCount): static
    {
        $this->downloadCount = $downloadCount;

        return $this;
    }

    public function getLastViewedAt(): ?\DateTimeImmutable
    {
        return $this->lastViewedAt;
    }

    public function setLastViewedAt(?\DateTimeImmutable $lastViewedAt): static
    {
        $this->lastViewedAt = $lastViewedAt;

        return $this;
    }

    public function __toString(): string
    {
        return $this->title;
    }
}
