<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\PresentationCommentRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Замечание к слайду презентации (обычно от модератора): к слайду целиком или к элементу на нём, с ответами;
 * исправленное замечание отмечают решённым.
 */
#[ORM\Entity(repositoryClass: PresentationCommentRepository::class)]
#[ORM\Table(name: 'presentation_comment')]
#[ORM\Index(name: 'idx_presentation_comment', columns: ['presentation_id', 'created_at'])]
class PresentationComment
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Presentation::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Presentation $presentation;

    /** Ответ на замечание (одна ступень). */
    #[ORM\ManyToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(name: 'parent_id', nullable: true, onDelete: 'CASCADE')]
    private ?self $parent;

    #[ORM\Column(name: 'slide_id', length: 64, nullable: true)]
    private ?string $slideId;

    #[ORM\Column(name: 'element_id', length: 64, nullable: true)]
    private ?string $elementId;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'author_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $author;

    #[ORM\Column(name: 'author_name', length: 255)]
    private string $authorName;

    #[ORM\Column(type: Types::TEXT)]
    private string $text;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'resolved_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $resolvedAt = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'resolved_by_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $resolvedBy = null;

    public function __construct(Presentation $presentation, User $author, string $text, ?string $slideId, ?string $elementId = null, ?self $parent = null)
    {
        $this->presentation = $presentation;
        $this->author = $author;
        $this->authorName = $author->getDisplayName();
        $this->text = mb_substr(trim($text), 0, 4000);
        $this->slideId = null !== $parent ? $parent->getSlideId() : self::id($slideId);
        $this->elementId = null !== $parent ? $parent->getElementId() : self::id($elementId);
        $this->parent = $parent;
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

    public function getParent(): ?self
    {
        return $this->parent;
    }

    public function getSlideId(): ?string
    {
        return $this->slideId;
    }

    public function getElementId(): ?string
    {
        return $this->elementId;
    }

    public function getAuthor(): ?User
    {
        return $this->author;
    }

    public function getAuthorName(): string
    {
        return $this->authorName;
    }

    public function getText(): string
    {
        return $this->text;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function isResolved(): bool
    {
        return null !== $this->resolvedAt;
    }

    public function getResolvedAt(): ?\DateTimeImmutable
    {
        return $this->resolvedAt;
    }

    public function getResolvedBy(): ?User
    {
        return $this->resolvedBy;
    }

    public function resolve(?User $by): void
    {
        $this->resolvedAt = new \DateTimeImmutable();
        $this->resolvedBy = $by;
    }

    public function reopen(): void
    {
        $this->resolvedAt = null;
        $this->resolvedBy = null;
    }

    private static function id(?string $id): ?string
    {
        if (null === $id) {
            return null;
        }
        $id = preg_replace('/[^A-Za-z0-9_-]/', '', $id) ?? '';

        return '' === $id ? null : substr($id, 0, 64);
    }
}
