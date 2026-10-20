<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\PriceListRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Прайс-лист (раздел «Прайс-листы»): название, описание, валюта и действующая редакция — загруженный из Excel
 * файл, разобранный на позиции (PriceRevision, PriceItem). Открытый прайс-лист видят и гости (по тем же правилам
 * просмотра без входа, что и открытые документы), закрытый — только сотрудники.
 */
#[ORM\Entity(repositoryClass: PriceListRepository::class)]
#[ORM\Table(name: 'price_list')]
#[ORM\Index(name: 'idx_price_list_status', columns: ['status', 'position'])]
class PriceList
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_ARCHIVED = 'archived';

    public const CURRENCIES = ['RUB' => '₽', 'USD' => '$', 'EUR' => '€', 'CNY' => '¥', 'KZT' => '₸', 'BYN' => 'Br'];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private string $title;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(length: 3)]
    private string $currency = 'RUB';

    /** Открытый: виден гостям (просмотр без входа). */
    #[ORM\Column(name: 'is_public')]
    private bool $public = true;

    /**
     * Исходный файл могут скачать все, кто видит прайс-лист (иначе — только те, кто ведёт прайс-листы):
     * в файле бывают скрытые листы и столбцы, которые не загружались (закупочные цены, наценка).
     */
    #[ORM\Column(name: 'original_download', options: ['default' => false])]
    private bool $originalDownload = false;

    #[ORM\Column(length: 16)]
    private string $status = self::STATUS_ACTIVE;

    #[ORM\Column]
    private int $position = 0;

    /** Действующая (опубликованная) редакция. */
    #[ORM\ManyToOne(targetEntity: PriceRevision::class)]
    #[ORM\JoinColumn(name: 'current_revision_id', nullable: true, onDelete: 'SET NULL')]
    private ?PriceRevision $currentRevision = null;

    /** Сопоставление столбцов последней загрузки — подставляется при следующей (по названиям столбцов). */
    #[ORM\Column(name: 'last_mapping', type: Types::JSON, nullable: true)]
    private ?array $lastMapping = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'created_by_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $createdBy;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $title, ?User $createdBy)
    {
        $this->title = $title;
        $this->createdBy = $createdBy;
        $this->createdAt = $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): self
    {
        $this->title = $title;
        $this->touch();

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): self
    {
        $this->description = null !== $description && '' !== trim($description) ? trim($description) : null;
        $this->touch();

        return $this;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function getCurrencySign(): string
    {
        return self::CURRENCIES[$this->currency] ?? $this->currency;
    }

    public function setCurrency(string $currency): self
    {
        $this->currency = isset(self::CURRENCIES[$currency]) ? $currency : 'RUB';
        $this->touch();

        return $this;
    }

    public function isPublic(): bool
    {
        return $this->public;
    }

    public function setPublic(bool $public): self
    {
        $this->public = $public;
        $this->touch();

        return $this;
    }

    public function isOriginalDownload(): bool
    {
        return $this->originalDownload;
    }

    public function setOriginalDownload(bool $allowed): self
    {
        $this->originalDownload = $allowed;
        $this->touch();

        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function isArchived(): bool
    {
        return self::STATUS_ARCHIVED === $this->status;
    }

    public function setArchived(bool $archived): self
    {
        $this->status = $archived ? self::STATUS_ARCHIVED : self::STATUS_ACTIVE;
        $this->touch();

        return $this;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(int $position): self
    {
        $this->position = $position;

        return $this;
    }

    public function getCurrentRevision(): ?PriceRevision
    {
        return $this->currentRevision;
    }

    public function setCurrentRevision(?PriceRevision $revision): self
    {
        $this->currentRevision = $revision;
        $this->touch();

        return $this;
    }

    /** @return array<string, mixed>|null */
    public function getLastMapping(): ?array
    {
        return $this->lastMapping;
    }

    /** @param array<string, mixed>|null $mapping */
    public function setLastMapping(?array $mapping): self
    {
        $this->lastMapping = $mapping;

        return $this;
    }

    public function getCreatedBy(): ?User
    {
        return $this->createdBy;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
