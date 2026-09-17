<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\DocumentAcknowledgementRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Ознакомление сотрудника с документом «под подпись»: кому назначено, с какой версией,
 * когда и с какого адреса подтверждено. Подтверждение привязано к номеру версии — после выпуска
 * новой редакции ознакомление назначается заново, а прежние записи остаются как история.
 */
#[ORM\Entity(repositoryClass: DocumentAcknowledgementRepository::class)]
#[ORM\Table(name: 'document_acknowledgement')]
#[ORM\UniqueConstraint(name: 'uniq_ack_document_user_version', columns: ['document_id', 'user_id', 'version_number'])]
#[ORM\Index(name: 'idx_ack_user', columns: ['user_id', 'confirmed_at'])]
#[ORM\Index(name: 'idx_ack_document', columns: ['document_id', 'confirmed_at'])]
class DocumentAcknowledgement
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Document::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Document $document;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    /** Версия документа, с которой сотрудник должен ознакомиться. */
    #[ORM\Column(name: 'version_number', type: Types::SMALLINT)]
    private int $versionNumber;

    #[ORM\Column(name: 'assigned_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $assignedAt;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'assigned_by_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $assignedBy = null;

    /** Срок ознакомления (включительно); null — без срока. */
    #[ORM\Column(name: 'due_at', type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $dueAt = null;

    #[ORM\Column(name: 'confirmed_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $confirmedAt = null;

    #[ORM\Column(name: 'confirmed_ip', length: 45, nullable: true)]
    private ?string $confirmedIp = null;

    #[ORM\Column(name: 'reminded_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $remindedAt = null;

    /** Проверка знаний: сколько было попыток и с каким результатом подтверждено ознакомление. */
    #[ORM\Column(name: 'quiz_attempts', type: Types::SMALLINT, options: ['default' => 0])]
    private int $quizAttempts = 0;

    #[ORM\Column(name: 'quiz_score', type: Types::SMALLINT, nullable: true)]
    private ?int $quizScore = null;

    #[ORM\Column(name: 'quiz_total', type: Types::SMALLINT, nullable: true)]
    private ?int $quizTotal = null;

    public function __construct(Document $document, User $user, int $versionNumber, ?User $assignedBy = null, ?\DateTimeImmutable $dueAt = null)
    {
        $this->document = $document;
        $this->user = $user;
        $this->versionNumber = $versionNumber;
        $this->assignedBy = $assignedBy;
        $this->setDueAt($dueAt);
        $this->assignedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDocument(): Document
    {
        return $this->document;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getVersionNumber(): int
    {
        return $this->versionNumber;
    }

    public function getAssignedAt(): \DateTimeImmutable
    {
        return $this->assignedAt;
    }

    public function getAssignedBy(): ?User
    {
        return $this->assignedBy;
    }

    public function getDueAt(): ?\DateTimeImmutable
    {
        return $this->dueAt;
    }

    public function setDueAt(?\DateTimeImmutable $dueAt): static
    {
        // В базе хранится дата без времени — приводим и в памяти, чтобы счёт дней не зависел от часа назначения.
        $this->dueAt = $dueAt?->setTime(0, 0);

        return $this;
    }

    public function getConfirmedAt(): ?\DateTimeImmutable
    {
        return $this->confirmedAt;
    }

    public function getConfirmedIp(): ?string
    {
        return $this->confirmedIp;
    }

    public function isConfirmed(): bool
    {
        return null !== $this->confirmedAt;
    }

    /** Отмечает ознакомление (повторное подтверждение не меняет дату). */
    public function confirm(?string $ip = null): static
    {
        if (null === $this->confirmedAt) {
            $this->confirmedAt = new \DateTimeImmutable();
            $this->confirmedIp = null === $ip ? null : mb_substr($ip, 0, 45);
        }

        return $this;
    }

    /** Просрочено ли ознакомление (срок прошёл, подтверждения нет). */
    public function isOverdue(?\DateTimeImmutable $today = null): bool
    {
        if (null !== $this->confirmedAt || null === $this->dueAt) {
            return false;
        }

        return $this->dueAt < ($today ?? new \DateTimeImmutable('today'));
    }

    /** Дней до срока (отрицательное — просрочено); null — срок не задан. */
    public function getDaysLeft(?\DateTimeImmutable $today = null): ?int
    {
        if (null === $this->dueAt) {
            return null;
        }

        return (int) ($today ?? new \DateTimeImmutable('today'))->diff($this->dueAt)->format('%r%a');
    }

    public function getQuizAttempts(): int
    {
        return $this->quizAttempts;
    }

    public function addQuizAttempt(): static
    {
        ++$this->quizAttempts;

        return $this;
    }

    public function getQuizScore(): ?int
    {
        return $this->quizScore;
    }

    public function getQuizTotal(): ?int
    {
        return $this->quizTotal;
    }

    /** Есть ли результат проверки знаний по этой записи. */
    public function hasQuizResult(): bool
    {
        return null !== $this->quizTotal && $this->quizTotal > 0;
    }

    public function setQuizResult(int $score, int $total): static
    {
        $this->quizScore = max(0, $score);
        $this->quizTotal = max(0, $total);

        return $this;
    }

    public function getRemindedAt(): ?\DateTimeImmutable
    {
        return $this->remindedAt;
    }

    public function markReminded(): static
    {
        $this->remindedAt = new \DateTimeImmutable();

        return $this;
    }
}
