<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\PresentationRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Презентация в корпоративном стиле: обычная (раздел «Подготовка презентаций») или тема пятничного
 * собрания (раздел «Подготовка пятничной презентации», meeting не null).
 *
 * Слайды хранятся одним JSON-документом (content, формат описан в App\Service\Presentation\DeckValidator).
 * Автор готовит черновик и отправляет его на модерацию; маркетолог или помощник маркетолога одобряет его
 * или возвращает на доработку с комментарием. Одобренная редакция сохраняется отдельно (approvedContent):
 * её видят сотрудники и из неё собирается собрание, пока автор правит следующую.
 */
#[ORM\Entity(repositoryClass: PresentationRepository::class)]
#[ORM\Table(name: 'presentation')]
#[ORM\Index(name: 'idx_presentation_kind_status', columns: ['kind', 'status'])]
#[ORM\Index(name: 'idx_presentation_meeting', columns: ['meeting_id', 'position'])]
class Presentation
{
    public const KIND_GENERAL = 'general';
    public const KIND_TOPIC = 'topic';

    public const STATUS_DRAFT = 'draft';
    public const STATUS_SUBMITTED = 'submitted';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';

    public const STATUS_LABELS = [
        self::STATUS_DRAFT => 'черновик',
        self::STATUS_SUBMITTED => 'на модерации',
        self::STATUS_APPROVED => 'одобрена',
        self::STATUS_REJECTED => 'на доработке',
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 16)]
    private string $kind = self::KIND_GENERAL;

    #[ORM\Column(length: 255)]
    private string $title;

    /** «Ответственный: …» в шапке слайдов; по умолчанию — автор. */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $responsible = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'author_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $author = null;

    /** Собрание, к которому относится тема (только для KIND_TOPIC). */
    #[ORM\ManyToOne(targetEntity: Meeting::class)]
    #[ORM\JoinColumn(name: 'meeting_id', nullable: true, onDelete: 'CASCADE')]
    private ?Meeting $meeting = null;

    /** Порядок темы в собрании (задаёт маркетолог). */
    #[ORM\Column(type: Types::INTEGER)]
    private int $position = 0;

    #[ORM\Column(length: 16)]
    private string $status = self::STATUS_DRAFT;

    /** Рабочая редакция слайдов (JSON). */
    #[ORM\Column(type: Types::TEXT, length: 4294967295)]
    private string $content = '';

    /** Номер рабочей редакции: растёт при каждом сохранении (защита от одновременной правки). */
    #[ORM\Column(type: Types::INTEGER)]
    private int $revision = 0;

    /** Первый показываемый слайд рабочей редакции (JSON) — для миниатюр в списках. */
    #[ORM\Column(type: Types::TEXT, length: 16777215, nullable: true)]
    private ?string $preview = null;

    #[ORM\Column(name: 'slide_count', type: Types::SMALLINT)]
    private int $slideCount = 0;

    /** Одобренная редакция (JSON): её видят сотрудники и из неё собирается собрание. */
    #[ORM\Column(name: 'approved_content', type: Types::TEXT, length: 4294967295, nullable: true)]
    private ?string $approvedContent = null;

    #[ORM\Column(name: 'approved_preview', type: Types::TEXT, length: 16777215, nullable: true)]
    private ?string $approvedPreview = null;

    #[ORM\Column(name: 'approved_slide_count', type: Types::SMALLINT)]
    private int $approvedSlideCount = 0;

    #[ORM\Column(name: 'approved_revision', type: Types::INTEGER, nullable: true)]
    private ?int $approvedRevision = null;

    /** Заголовок и ответственный одобренной редакции — для содержания собрания и списка готовых. */
    #[ORM\Column(name: 'approved_title', length: 255, nullable: true)]
    private ?string $approvedTitle = null;

    #[ORM\Column(name: 'approved_responsible', length: 255, nullable: true)]
    private ?string $approvedResponsible = null;

    #[ORM\Column(name: 'approved_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $approvedAt = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'approved_by_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $approvedBy = null;

    #[ORM\Column(name: 'submitted_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $submittedAt = null;

    #[ORM\Column(name: 'submit_note', type: Types::TEXT, nullable: true)]
    private ?string $submitNote = null;

    #[ORM\Column(name: 'decision_note', type: Types::TEXT, nullable: true)]
    private ?string $decisionNote = null;

    #[ORM\Column(name: 'decided_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $decidedAt = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'decided_by_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $decidedBy = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'updated_by_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $updatedBy = null;

    public function __construct(string $kind, string $title, ?User $author)
    {
        $this->kind = self::KIND_TOPIC === $kind ? self::KIND_TOPIC : self::KIND_GENERAL;
        $this->title = self::clean($title, 255) ?? 'Без названия';
        $this->author = $author;
        $this->updatedBy = $author;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getKind(): string
    {
        return $this->kind;
    }

    public function isTopic(): bool
    {
        return self::KIND_TOPIC === $this->kind;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): static
    {
        $this->title = self::clean($title, 255) ?? $this->title;

        return $this;
    }

    public function getResponsible(): ?string
    {
        return $this->responsible;
    }

    /** Ответственный для шапки слайдов: заданный или автор («Имя Фамилия»). */
    public function getResponsibleLabel(): string
    {
        return $this->responsible ?? (null !== $this->author ? self::shortName($this->author->getDisplayName()) : '');
    }

    public function setResponsible(?string $responsible): static
    {
        $this->responsible = self::clean($responsible, 255);

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = self::clean($description, 2000);

        return $this;
    }

    public function getAuthor(): ?User
    {
        return $this->author;
    }

    public function isAuthor(?User $user): bool
    {
        return null !== $user && null !== $this->author && $this->author->getId() === $user->getId();
    }

    public function getMeeting(): ?Meeting
    {
        return $this->meeting;
    }

    public function setMeeting(?Meeting $meeting): static
    {
        $this->meeting = $meeting;

        return $this;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(int $position): static
    {
        $this->position = max(0, $position);

        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getStatusLabel(): string
    {
        if (self::STATUS_DRAFT === $this->status && null !== $this->approvedContent) {
            return 'изменена после одобрения';
        }

        return self::STATUS_LABELS[$this->status] ?? $this->status;
    }

    public function isDraft(): bool
    {
        return self::STATUS_DRAFT === $this->status;
    }

    public function isSubmitted(): bool
    {
        return self::STATUS_SUBMITTED === $this->status;
    }

    public function isApproved(): bool
    {
        return self::STATUS_APPROVED === $this->status;
    }

    public function isRejected(): bool
    {
        return self::STATUS_REJECTED === $this->status;
    }

    /** Есть одобренная редакция (её видят сотрудники), даже если автор уже правит следующую. */
    public function hasApprovedVersion(): bool
    {
        return null !== $this->approvedContent;
    }

    /** Рабочая редакция отличается от одобренной (правили после одобрения). */
    public function hasUnapprovedChanges(): bool
    {
        return null === $this->approvedRevision || $this->approvedRevision !== $this->revision;
    }

    public function getContent(): string
    {
        return $this->content;
    }

    public function getRevision(): int
    {
        return $this->revision;
    }

    /**
     * Новая рабочая редакция. Правка одобренной презентации делает её снова черновиком (одобренная редакция
     * остаётся доступной сотрудникам до следующего одобрения).
     */
    public function setContent(string $json, ?string $preview, int $slideCount, ?User $by): static
    {
        $this->content = $json;
        $this->preview = $preview;
        $this->slideCount = max(0, min(32767, $slideCount));
        ++$this->revision;
        $this->updatedBy = $by;
        if (self::STATUS_APPROVED === $this->status) {
            $this->status = self::STATUS_DRAFT;
        }

        return $this->touch();
    }

    public function getPreview(): ?string
    {
        return $this->preview;
    }

    public function getSlideCount(): int
    {
        return $this->slideCount;
    }

    public function getApprovedContent(): ?string
    {
        return $this->approvedContent;
    }

    public function getApprovedPreview(): ?string
    {
        return $this->approvedPreview;
    }

    public function getApprovedSlideCount(): int
    {
        return $this->approvedSlideCount;
    }

    public function getApprovedRevision(): ?int
    {
        return $this->approvedRevision;
    }

    public function getApprovedTitle(): string
    {
        return $this->approvedTitle ?? $this->title;
    }

    public function getApprovedResponsible(): string
    {
        return $this->approvedResponsible ?? $this->getResponsibleLabel();
    }

    public function getApprovedAt(): ?\DateTimeImmutable
    {
        return $this->approvedAt;
    }

    public function getApprovedBy(): ?User
    {
        return $this->approvedBy;
    }

    public function getSubmittedAt(): ?\DateTimeImmutable
    {
        return $this->submittedAt;
    }

    public function getSubmitNote(): ?string
    {
        return $this->submitNote;
    }

    public function getDecisionNote(): ?string
    {
        return $this->decisionNote;
    }

    public function getDecidedAt(): ?\DateTimeImmutable
    {
        return $this->decidedAt;
    }

    public function getDecidedBy(): ?User
    {
        return $this->decidedBy;
    }

    public function submit(?string $note): static
    {
        $this->status = self::STATUS_SUBMITTED;
        $this->submittedAt = new \DateTimeImmutable();
        $this->submitNote = self::clean($note, 2000);
        $this->decisionNote = null;

        return $this->touch();
    }

    /** Отозвать с модерации: снова черновик. */
    public function withdraw(): static
    {
        $this->status = self::STATUS_DRAFT;

        return $this->touch();
    }

    /** Одобрить текущую рабочую редакцию: она становится видна сотрудникам. */
    public function approve(User $by, ?string $note): static
    {
        $this->status = self::STATUS_APPROVED;
        $this->approvedContent = $this->content;
        $this->approvedPreview = $this->preview;
        $this->approvedSlideCount = $this->slideCount;
        $this->approvedRevision = $this->revision;
        $this->approvedTitle = $this->title;
        $this->approvedResponsible = $this->getResponsibleLabel();
        $this->approvedAt = new \DateTimeImmutable();
        $this->approvedBy = $by;
        $this->decidedAt = $this->approvedAt;
        $this->decidedBy = $by;
        $this->decisionNote = self::clean($note, 2000);

        return $this->touch();
    }

    public function reject(User $by, string $note): static
    {
        $this->status = self::STATUS_REJECTED;
        $this->decidedAt = new \DateTimeImmutable();
        $this->decidedBy = $by;
        $this->decisionNote = self::clean($note, 2000);

        return $this->touch();
    }

    /** Снять одобрение (например, тема перенесена): сотрудники и собрание её больше не видят. */
    public function revokeApproval(): static
    {
        $this->approvedContent = null;
        $this->approvedPreview = null;
        $this->approvedSlideCount = 0;
        $this->approvedRevision = null;
        $this->approvedTitle = null;
        $this->approvedResponsible = null;
        $this->approvedAt = null;
        $this->approvedBy = null;
        if (self::STATUS_APPROVED === $this->status) {
            $this->status = self::STATUS_DRAFT;
        }

        return $this->touch();
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function getUpdatedBy(): ?User
    {
        return $this->updatedBy;
    }

    public function touch(): static
    {
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    /** «Иванов Иван Иванович» → «Иван Иванов», как в шапке слайдов образца. */
    public static function shortName(string $displayName): string
    {
        $parts = preg_split('/\s+/u', trim($displayName)) ?: [];
        if (\count($parts) >= 2) {
            return $parts[1].' '.$parts[0];
        }

        return trim($displayName);
    }

    private static function clean(?string $value, int $max): ?string
    {
        if (null === $value) {
            return null;
        }
        $value = trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value) ?? '');

        return '' === $value ? null : mb_substr($value, 0, $max);
    }
}
