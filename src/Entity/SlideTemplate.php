<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\SlideTemplateRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Фирменный слайд библиотеки («Подготовка презентаций» → «Библиотека слайдов»): удачный слайд, который
 * маркетолог сохранил как общий шаблон, — сотрудники вставляют его копию в свои презентации из галереи
 * «Новый слайд». Слайд хранится в формате колоды (DeckValidator), изображения — в общем хранилище слайдов.
 */
#[ORM\Entity(repositoryClass: SlideTemplateRepository::class)]
#[ORM\Table(name: 'slide_template')]
#[ORM\Index(name: 'idx_slide_template_category', columns: ['category', 'position'])]
class SlideTemplate
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 200)]
    private string $title;

    #[ORM\Column(length: 100)]
    private string $category;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    /** Слайд (JSON одного слайда колоды). */
    #[ORM\Column(type: Types::TEXT, length: 16777215)]
    private string $content;

    #[ORM\Column(type: Types::INTEGER)]
    private int $position = 0;

    /** Сколько раз слайд вставляли в презентации. */
    #[ORM\Column(name: 'use_count', type: Types::INTEGER)]
    private int $useCount = 0;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'created_by_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $createdBy;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    /** @param array<string, mixed> $slide */
    public function __construct(string $title, string $category, array $slide, ?User $createdBy)
    {
        $this->title = $title;
        $this->category = $category;
        $this->createdBy = $createdBy;
        $this->createdAt = $this->updatedAt = new \DateTimeImmutable();
        $this->setSlide($slide);
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

    public function getCategory(): string
    {
        return $this->category;
    }

    public function setCategory(string $category): self
    {
        $this->category = $category;
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

    /** @return array<string, mixed> */
    public function getSlide(): array
    {
        $slide = json_decode($this->content, true);

        return \is_array($slide) ? $slide : ['id' => 's1', 'elements' => []];
    }

    /** @param array<string, mixed> $slide */
    public function setSlide(array $slide): self
    {
        $this->content = json_encode($slide, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR | \JSON_PRESERVE_ZERO_FRACTION);
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

    public function getUseCount(): int
    {
        return $this->useCount;
    }

    public function countUse(): self
    {
        ++$this->useCount;

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

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
