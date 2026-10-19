<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\PresentationVersionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Сохранённая редакция презентации для «Истории версий»: снимок слайдов при автосохранении (не чаще раза
 * в 10 минут), отправке на модерацию, решении, восстановлении и импорте.
 */
#[ORM\Entity(repositoryClass: PresentationVersionRepository::class)]
#[ORM\Table(name: 'presentation_version')]
#[ORM\Index(name: 'idx_presentation_version', columns: ['presentation_id', 'created_at'])]
class PresentationVersion
{
    public const KIND_AUTO = 'auto';
    public const KIND_SUBMIT = 'submit';
    public const KIND_APPROVE = 'approve';
    public const KIND_REJECT = 'reject';
    public const KIND_RESTORE = 'restore';
    public const KIND_IMPORT = 'import';

    public const LABELS = [
        self::KIND_AUTO => 'Автосохранение',
        self::KIND_SUBMIT => 'Отправлено на модерацию',
        self::KIND_APPROVE => 'Одобрено',
        self::KIND_REJECT => 'Возвращено на доработку',
        self::KIND_RESTORE => 'До восстановления версии',
        self::KIND_IMPORT => 'До импорта из PowerPoint',
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Presentation::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Presentation $presentation;

    #[ORM\Column(type: Types::INTEGER)]
    private int $revision;

    #[ORM\Column(length: 16)]
    private string $kind;

    #[ORM\Column(type: Types::TEXT, length: 4294967295)]
    private string $content;

    #[ORM\Column(name: 'slide_count', type: Types::SMALLINT)]
    private int $slideCount;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'created_by_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $createdBy;

    public function __construct(Presentation $presentation, string $kind, ?User $by)
    {
        $this->presentation = $presentation;
        $this->revision = $presentation->getRevision();
        $this->kind = \array_key_exists($kind, self::LABELS) ? $kind : self::KIND_AUTO;
        $this->content = $presentation->getContent();
        $this->slideCount = $presentation->getSlideCount();
        $this->createdBy = $by;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPresentation(): Presentation
    {
        return $this->presentation;
    }

    public function getRevision(): int
    {
        return $this->revision;
    }

    public function getKind(): string
    {
        return $this->kind;
    }

    public function getLabel(): string
    {
        return self::LABELS[$this->kind] ?? $this->kind;
    }

    public function getContent(): string
    {
        return $this->content;
    }

    public function getSlideCount(): int
    {
        return $this->slideCount;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getCreatedBy(): ?User
    {
        return $this->createdBy;
    }
}
