<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\SectionRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\Common\Collections\Criteria;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Раздел документации. Разделы образуют дерево любой вложенности:
 * у раздела может быть родитель (null — раздел верхнего уровня) и любое число подразделов.
 *
 * Для быстрых проверок «входит ли раздел в поддерево» хранится материализованный путь
 * из идентификаторов предков: "/1/5/12/" (включая сам раздел) и глубина вложенности.
 */
#[ORM\Entity(repositoryClass: SectionRepository::class)]
#[ORM\Table(name: 'section')]
#[ORM\Index(name: 'idx_section_parent', columns: ['parent_id', 'position'])]
#[ORM\Index(name: 'idx_section_path', columns: ['path'])]
#[ORM\UniqueConstraint(name: 'uniq_section_slug', columns: ['slug'])]
#[ORM\HasLifecycleCallbacks]
class Section
{
    public const MAX_DEPTH = 10;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: self::class, inversedBy: 'children')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'RESTRICT')]
    private ?Section $parent = null;

    /** @var Collection<int, Section> */
    #[ORM\OneToMany(targetEntity: self::class, mappedBy: 'parent')]
    #[ORM\OrderBy(['position' => 'ASC', 'name' => 'ASC'])]
    private Collection $children;

    #[ORM\Column(length: 128)]
    #[Assert\NotBlank(message: 'Укажите название раздела.')]
    #[Assert\Length(max: 128, maxMessage: 'Название не должно быть длиннее 128 символов.')]
    private string $name = '';

    #[ORM\Column(length: 160)]
    private string $slug = '';

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Assert\Length(max: 4000)]
    private ?string $description = null;

    #[ORM\Column(options: ['default' => 0])]
    private int $position = 0;

    /** Материализованный путь "/id1/id2/.../idN/" (последний — сам раздел). */
    #[ORM\Column(length: 255, options: ['default' => '/'])]
    private string $path = '/';

    #[ORM\Column(options: ['default' => 0])]
    private int $depth = 0;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $createdBy = null;

    /** @var Collection<int, Document> */
    #[ORM\OneToMany(targetEntity: Document::class, mappedBy: 'section')]
    private Collection $documents;

    /** @var Collection<int, SectionModerator> */
    #[ORM\OneToMany(targetEntity: SectionModerator::class, mappedBy: 'section', orphanRemoval: true, cascade: ['persist'])]
    private Collection $moderators;

    public function __construct()
    {
        $this->children = new ArrayCollection();
        $this->documents = new ArrayCollection();
        $this->moderators = new ArrayCollection();
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

    public function getParent(): ?Section
    {
        return $this->parent;
    }

    public function setParent(?Section $parent): static
    {
        $this->parent = $parent;

        return $this;
    }

    /** @return Collection<int, Section> */
    public function getChildren(): Collection
    {
        return $this->children;
    }

    public function hasChildren(): bool
    {
        return !$this->children->isEmpty();
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = trim($name);

        return $this;
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function setSlug(string $slug): static
    {
        $this->slug = $slug;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $description = null === $description ? null : trim($description);
        $this->description = '' === $description ? null : $description;

        return $this;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(int $position): static
    {
        $this->position = $position;

        return $this;
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function setPath(string $path): static
    {
        $this->path = $path;

        return $this;
    }

    public function getDepth(): int
    {
        return $this->depth;
    }

    public function setDepth(int $depth): static
    {
        $this->depth = $depth;

        return $this;
    }

    /** Пересчитывает путь и глубину по текущему родителю (вызывается после persist/flush, когда известен id). */
    public function refreshPath(): void
    {
        $parentPath = null !== $this->parent ? $this->parent->getPath() : '/';
        $this->path = $parentPath.$this->id.'/';
        $this->depth = null !== $this->parent ? $this->parent->getDepth() + 1 : 0;
    }

    /** @return list<int> идентификаторы всех предков и самого раздела (от корня к листу) */
    public function getPathIds(): array
    {
        return array_values(array_map('intval', array_filter(explode('/', $this->path), static fn ($s) => '' !== $s)));
    }

    /** @return list<Section> цепочка от корня до текущего раздела (включительно) */
    public function getBreadcrumbs(): array
    {
        $chain = [];
        $node = $this;
        $guard = 0;
        while (null !== $node && $guard++ < 64) {
            array_unshift($chain, $node);
            $node = $node->getParent();
        }

        return $chain;
    }

    /** Полное название вида «Раздел / Подраздел / Подподраздел». */
    public function getFullName(string $separator = ' / '): string
    {
        return implode($separator, array_map(static fn (Section $s) => $s->getName(), $this->getBreadcrumbs()));
    }

    public function isDescendantOf(Section $other): bool
    {
        return null !== $other->getId() && str_starts_with($this->path, $other->getPath()) && $this->id !== $other->getId();
    }

    public function isSameOrDescendantOf(Section $other): bool
    {
        return null !== $other->getId() && str_starts_with($this->path, $other->getPath());
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function getCreatedBy(): ?User
    {
        return $this->createdBy;
    }

    public function setCreatedBy(?User $createdBy): static
    {
        $this->createdBy = $createdBy;

        return $this;
    }

    /** @return Collection<int, Document> */
    public function getDocuments(): Collection
    {
        return $this->documents;
    }

    /** @return Collection<int, SectionModerator> */
    public function getModerators(): Collection
    {
        return $this->moderators;
    }

    /** @return list<User> */
    public function getModeratorUsers(): array
    {
        $users = [];
        foreach ($this->moderators->matching(Criteria::create()) as $m) {
            $users[] = $m->getUser();
        }

        return $users;
    }

    public function hasModerator(User $user): bool
    {
        foreach ($this->moderators as $m) {
            if ($m->getUser()->getId() === $user->getId()) {
                return true;
            }
        }

        return false;
    }

    public function __toString(): string
    {
        return $this->name;
    }
}
