<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\DocumentApprovalRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Согласование редакции документа перед публикацией: кто отправил, кому, что решил.
 *
 * Решение относится к конкретной редакции: после загрузки новой версии согласование
 * нужно запрашивать заново, а прежние решения остаются историей.
 *
 * Без согласующего (approver = null) запрос — это модерация: материал помощника маркетолога ждёт
 * решения любого маркетолога (или модератора раздела), кто решил — записывается в decidedBy.
 */
#[ORM\Entity(repositoryClass: DocumentApprovalRepository::class)]
#[ORM\Table(name: 'document_approval')]
#[ORM\Index(name: 'idx_approval_approver', columns: ['approver_id', 'decision'])]
#[ORM\Index(name: 'idx_approval_document', columns: ['document_id', 'version_number'])]
class DocumentApproval
{
    public const PENDING = 'pending';
    public const APPROVED = 'approved';
    public const REJECTED = 'rejected';
    public const CANCELLED = 'cancelled';

    public const DECISIONS = [self::PENDING, self::APPROVED, self::REJECTED, self::CANCELLED];

    public const LABELS = [
        self::PENDING => 'на согласовании',
        self::APPROVED => 'согласовано',
        self::REJECTED => 'отклонено',
        self::CANCELLED => 'отозвано',
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Document::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Document $document;

    /** Номер редакции, которую согласуют. */
    #[ORM\Column(name: 'version_number', type: Types::SMALLINT)]
    private int $versionNumber;

    /** Назначенный согласующий; null — общая очередь модерации. */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?User $approver = null;

    /** Кто принял решение (при модерации — первый ответивший маркетолог). */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'decided_by_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $decidedBy = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'requested_by_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $requestedBy = null;

    #[ORM\Column(name: 'requested_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $requestedAt;

    #[ORM\Column(length: 12)]
    private string $decision = self::PENDING;

    #[ORM\Column(name: 'decided_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $decidedAt = null;

    /** Комментарий: при запросе — от отправителя, после решения — от согласующего. */
    #[ORM\Column(name: 'request_note', type: Types::TEXT, nullable: true)]
    private ?string $requestNote = null;

    #[ORM\Column(name: 'decision_note', type: Types::TEXT, nullable: true)]
    private ?string $decisionNote = null;

    public function __construct(Document $document, int $versionNumber, ?User $approver, ?User $requestedBy = null, ?string $requestNote = null)
    {
        $this->document = $document;
        $this->versionNumber = $versionNumber;
        $this->approver = $approver;
        $this->requestedBy = $requestedBy;
        $this->requestNote = self::trimOrNull($requestNote);
        $this->requestedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDocument(): Document
    {
        return $this->document;
    }

    public function getVersionNumber(): int
    {
        return $this->versionNumber;
    }

    public function getApprover(): ?User
    {
        return $this->approver;
    }

    /** Запрос в общую очередь модерации (без конкретного согласующего). */
    public function isModeration(): bool
    {
        return null === $this->approver;
    }

    public function getDecidedBy(): ?User
    {
        return $this->decidedBy ?? (self::PENDING !== $this->decision && self::CANCELLED !== $this->decision ? $this->approver : null);
    }

    /** Кому отправлено: имя согласующего или «модерация». */
    public function getApproverLabel(): string
    {
        return $this->approver?->getDisplayName() ?? 'модерация (маркетологи)';
    }

    /** Кто решил: принявший решение, а без записи о нём — назначенный согласующий. */
    public function getDeciderName(): string
    {
        return $this->getDecidedBy()?->getDisplayName() ?? $this->getApproverLabel();
    }

    public function getRequestedBy(): ?User
    {
        return $this->requestedBy;
    }

    public function getRequestedAt(): \DateTimeImmutable
    {
        return $this->requestedAt;
    }

    public function getDecision(): string
    {
        return $this->decision;
    }

    public const MODERATION_LABELS = [
        self::PENDING => 'на модерации',
        self::APPROVED => 'одобрено',
        self::REJECTED => 'возвращено на доработку',
        self::CANCELLED => 'снято с модерации',
    ];

    public function getDecisionLabel(): string
    {
        return ($this->isModeration() ? self::MODERATION_LABELS : self::LABELS)[$this->decision] ?? $this->decision;
    }

    public function isPending(): bool
    {
        return self::PENDING === $this->decision;
    }

    public function isApproved(): bool
    {
        return self::APPROVED === $this->decision;
    }

    public function isRejected(): bool
    {
        return self::REJECTED === $this->decision;
    }

    public function getDecidedAt(): ?\DateTimeImmutable
    {
        return $this->decidedAt;
    }

    public function getRequestNote(): ?string
    {
        return $this->requestNote;
    }

    public function getDecisionNote(): ?string
    {
        return $this->decisionNote;
    }

    /** Решение согласующего; повторно решение не меняется. */
    public function decide(string $decision, ?string $note = null, ?User $by = null): static
    {
        if (!\in_array($decision, [self::APPROVED, self::REJECTED, self::CANCELLED], true)) {
            throw new \InvalidArgumentException('Недопустимое решение по согласованию: '.$decision);
        }
        if (self::PENDING !== $this->decision) {
            return $this;
        }
        $this->decision = $decision;
        $this->decisionNote = self::trimOrNull($note);
        $this->decidedAt = new \DateTimeImmutable();
        if (null !== $by && self::CANCELLED !== $decision) {
            $this->decidedBy = $by;
        }

        return $this;
    }

    private static function trimOrNull(?string $value): ?string
    {
        $value = trim((string) $value);

        return '' === $value ? null : mb_substr($value, 0, 2000);
    }
}
