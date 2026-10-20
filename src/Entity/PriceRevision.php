<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\PriceRevisionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Редакция прайс-листа — загруженный файл Excel и его позиции. Черновик — файл загружен, столбцы ещё
 * не сопоставлены; на модерации — загрузил помощник маркетолога; опубликована — становится действующей
 * (прежние опубликованные остаются в истории); отклонена — с комментарием.
 */
#[ORM\Entity(repositoryClass: PriceRevisionRepository::class)]
#[ORM\Table(name: 'price_revision')]
#[ORM\Index(name: 'idx_price_revision_list', columns: ['price_list_id', 'number'])]
#[ORM\Index(name: 'idx_price_revision_status', columns: ['status'])]
class PriceRevision
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_PENDING = 'pending';
    public const STATUS_PUBLISHED = 'published';
    public const STATUS_REJECTED = 'rejected';

    public const STATUS_LABELS = [
        self::STATUS_DRAFT => 'Черновик',
        self::STATUS_PENDING => 'На модерации',
        self::STATUS_PUBLISHED => 'Опубликована',
        self::STATUS_REJECTED => 'Отклонена',
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: PriceList::class)]
    #[ORM\JoinColumn(name: 'price_list_id', nullable: false, onDelete: 'CASCADE')]
    private PriceList $priceList;

    #[ORM\Column]
    private int $number;

    #[ORM\Column(length: 16)]
    private string $status = self::STATUS_DRAFT;

    /** Файл в хранилище: prices/<прайс>/<токен>.<расширение>. */
    #[ORM\Column(name: 'file_path', length: 255)]
    private string $filePath;

    #[ORM\Column(name: 'original_name', length: 255)]
    private string $originalName;

    #[ORM\Column(name: 'file_size', type: Types::BIGINT)]
    private string $fileSize = '0';

    #[ORM\Column(length: 64)]
    private string $sha256 = '';

    #[ORM\Column(name: 'sheet_name', length: 255, nullable: true)]
    private ?string $sheetName = null;

    /** Сопоставление столбцов: лист, строка заголовков, роли столбцов, подписи цен. */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $mapping = null;

    /** Столбцы цен (подписи), как в файле: «Опт», «Розница»… */
    #[ORM\Column(name: 'price_columns', type: Types::JSON)]
    private array $priceColumns = [];

    /** Дополнительные столбцы (подписи). */
    #[ORM\Column(name: 'extra_columns', type: Types::JSON)]
    private array $extraColumns = [];

    #[ORM\Column(name: 'item_count')]
    private int $itemCount = 0;

    /** Отличия от предыдущей опубликованной редакции: added, removed, changed, up, down. */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $stats = null;

    #[ORM\Column(name: 'effective_from', type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $effectiveFrom = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $note = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'uploaded_by_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $uploadedBy;

    #[ORM\Column(name: 'uploaded_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $uploadedAt;

    #[ORM\Column(name: 'published_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $publishedAt = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'decided_by_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $decidedBy = null;

    #[ORM\Column(name: 'decision_note', type: Types::TEXT, nullable: true)]
    private ?string $decisionNote = null;

    public function __construct(PriceList $priceList, int $number, string $filePath, string $originalName, int $fileSize, string $sha256, ?User $uploadedBy)
    {
        $this->priceList = $priceList;
        $this->number = $number;
        $this->filePath = $filePath;
        $this->originalName = mb_substr($originalName, 0, 255);
        $this->fileSize = (string) $fileSize;
        $this->sha256 = $sha256;
        $this->uploadedBy = $uploadedBy;
        $this->uploadedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPriceList(): PriceList
    {
        return $this->priceList;
    }

    public function getNumber(): int
    {
        return $this->number;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getStatusLabel(): string
    {
        return self::STATUS_LABELS[$this->status] ?? $this->status;
    }

    public function setStatus(string $status): self
    {
        $this->status = $status;
        if (self::STATUS_PUBLISHED === $status) {
            $this->publishedAt ??= new \DateTimeImmutable();
        }

        return $this;
    }

    public function isDraft(): bool
    {
        return self::STATUS_DRAFT === $this->status;
    }

    public function isPending(): bool
    {
        return self::STATUS_PENDING === $this->status;
    }

    public function isPublished(): bool
    {
        return self::STATUS_PUBLISHED === $this->status;
    }

    public function isCurrent(): bool
    {
        return $this->priceList->getCurrentRevision()?->getId() === $this->id && null !== $this->id;
    }

    public function getFilePath(): string
    {
        return $this->filePath;
    }

    public function getOriginalName(): string
    {
        return $this->originalName;
    }

    /** Формат сохранённого файла (определён по содержимому при загрузке). */
    public function getExtension(): string
    {
        return strtolower(pathinfo($this->filePath, \PATHINFO_EXTENSION));
    }

    public function getFileSize(): int
    {
        return (int) $this->fileSize;
    }

    public function getSha256(): string
    {
        return $this->sha256;
    }

    public function getSheetName(): ?string
    {
        return $this->sheetName;
    }

    /** @return array<string, mixed>|null */
    public function getMapping(): ?array
    {
        return $this->mapping;
    }

    /**
     * @param array<string, mixed> $mapping
     * @param list<string>         $priceColumns
     * @param list<string>         $extraColumns
     */
    public function setImported(array $mapping, ?string $sheetName, array $priceColumns, array $extraColumns, int $itemCount): self
    {
        $this->mapping = $mapping;
        $this->sheetName = $sheetName;
        $this->priceColumns = array_values($priceColumns);
        $this->extraColumns = array_values($extraColumns);
        $this->itemCount = $itemCount;

        return $this;
    }

    /** @return list<string> */
    public function getPriceColumns(): array
    {
        return $this->priceColumns;
    }

    /** @return list<string> */
    public function getExtraColumns(): array
    {
        return $this->extraColumns;
    }

    public function getItemCount(): int
    {
        return $this->itemCount;
    }

    /** @return array<string, int>|null */
    public function getStats(): ?array
    {
        return $this->stats;
    }

    /** @param array<string, int>|null $stats */
    public function setStats(?array $stats): self
    {
        $this->stats = $stats;

        return $this;
    }

    public function getEffectiveFrom(): ?\DateTimeImmutable
    {
        return $this->effectiveFrom;
    }

    public function setEffectiveFrom(?\DateTimeImmutable $date): self
    {
        $this->effectiveFrom = $date;

        return $this;
    }

    public function getNote(): ?string
    {
        return $this->note;
    }

    public function setNote(?string $note): self
    {
        $this->note = null !== $note && '' !== trim($note) ? mb_substr(trim($note), 0, 2000) : null;

        return $this;
    }

    public function getUploadedBy(): ?User
    {
        return $this->uploadedBy;
    }

    public function getUploadedAt(): \DateTimeImmutable
    {
        return $this->uploadedAt;
    }

    public function getPublishedAt(): ?\DateTimeImmutable
    {
        return $this->publishedAt;
    }

    public function getDecidedBy(): ?User
    {
        return $this->decidedBy;
    }

    public function getDecisionNote(): ?string
    {
        return $this->decisionNote;
    }

    public function clearDecision(): self
    {
        $this->decidedBy = null;
        $this->decisionNote = null;

        return $this;
    }

    public function decide(User $by, ?string $note): self
    {
        $this->decidedBy = $by;
        $this->decisionNote = null !== $note && '' !== trim($note) ? mb_substr(trim($note), 0, 2000) : null;

        return $this;
    }
}
