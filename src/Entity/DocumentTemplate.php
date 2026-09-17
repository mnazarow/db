<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\DocumentTemplateRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Шаблон документа: заготовка карточки (раздел, вид, теги, срок актуальности, доступ)
 * и текста страницы, а также образец обозначения с автонумерацией.
 *
 * Шаблон не создаёт документ сам — он заполняет форму «Добавить документ», поэтому
 * модератор всегда видит, что именно сохраняет, и может поправить любое поле.
 */
#[ORM\Entity(repositoryClass: DocumentTemplateRepository::class)]
#[ORM\Table(name: 'document_template')]
class DocumentTemplate
{
    /** Подстановки в образце обозначения: год, месяц и порядковый номер. */
    public const PLACEHOLDERS = [
        '{ГОД}' => 'год из четырёх цифр (2026)',
        '{ГГ}' => 'две последние цифры года (26)',
        '{МЕСЯЦ}' => 'месяц двумя цифрами (09)',
        '{N}' => 'порядковый номер (1, 2, 3…)',
        '{NN}' => 'порядковый номер из двух цифр (01)',
        '{NNN}' => 'порядковый номер из трёх цифр (001)',
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 128)]
    private string $name = '';

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(options: ['default' => true])]
    private bool $active = true;

    #[ORM\ManyToOne(targetEntity: Section::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Section $section = null;

    #[ORM\Column(length: 16, options: ['default' => Document::TYPE_FILE])]
    private string $type = Document::TYPE_FILE;

    /** Заготовка названия: подставляется в форму, модератор дописывает подробности. */
    #[ORM\Column(length: 200, nullable: true)]
    private ?string $titlePattern = null;

    /** Образец обозначения с автонумерацией, например ПР-{ГОД}-{NNN}. */
    #[ORM\Column(length: 64, nullable: true)]
    private ?string $codePattern = null;

    /** Текст страницы (для шаблонов вида «страница»). */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $body = null;

    /** @var list<string> */
    #[ORM\Column(type: Types::JSON)]
    private array $tags = [];

    #[ORM\Column(nullable: true)]
    private ?int $validityMonths = null;

    #[ORM\Column(options: ['default' => false])]
    private bool $public = false;

    /** Счётчик автонумерации и год, за который он ведётся. */
    #[ORM\Column(options: ['default' => 0])]
    private int $counter = 0;

    #[ORM\Column(options: ['default' => 0])]
    private int $counterYear = 0;

    #[ORM\Column(nullable: true)]
    private ?int $usageCount = 0;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $createdBy = null;

    public function __construct(string $name = '')
    {
        $this->name = $name;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = mb_substr(trim($name), 0, 128);

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $description = trim((string) $description);
        $this->description = '' !== $description ? $description : null;

        return $this;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function setActive(bool $active): static
    {
        $this->active = $active;

        return $this;
    }

    public function getSection(): ?Section
    {
        return $this->section;
    }

    public function setSection(?Section $section): static
    {
        $this->section = $section;

        return $this;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function setType(string $type): static
    {
        $this->type = \in_array($type, Document::TYPES, true) ? $type : Document::TYPE_FILE;

        return $this;
    }

    public function isPage(): bool
    {
        return Document::TYPE_PAGE === $this->type;
    }

    public function getTitlePattern(): ?string
    {
        return $this->titlePattern;
    }

    public function setTitlePattern(?string $titlePattern): static
    {
        $titlePattern = trim((string) $titlePattern);
        $this->titlePattern = '' !== $titlePattern ? mb_substr($titlePattern, 0, 200) : null;

        return $this;
    }

    public function getCodePattern(): ?string
    {
        return $this->codePattern;
    }

    public function setCodePattern(?string $codePattern): static
    {
        $codePattern = trim((string) $codePattern);
        $this->codePattern = '' !== $codePattern ? mb_substr($codePattern, 0, 64) : null;

        return $this;
    }

    public function getBody(): ?string
    {
        return $this->body;
    }

    public function setBody(?string $body): static
    {
        $body = trim((string) $body);
        $this->body = '' !== $body ? $body : null;

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

    public function getValidityMonths(): ?int
    {
        return $this->validityMonths;
    }

    public function setValidityMonths(?int $validityMonths): static
    {
        $this->validityMonths = null !== $validityMonths && $validityMonths > 0 ? min($validityMonths, 600) : null;

        return $this;
    }

    public function isPublic(): bool
    {
        return $this->public;
    }

    public function setPublic(bool $public): static
    {
        $this->public = $public;

        return $this;
    }

    public function getCounter(): int
    {
        return $this->counter;
    }

    public function getCounterYear(): int
    {
        return $this->counterYear;
    }

    /** Следующий порядковый номер; счётчик начинается заново, когда меняется год. */
    public function takeNumber(int $year): int
    {
        if ($this->counterYear !== $year) {
            $this->counterYear = $year;
            $this->counter = 0;
        }

        return ++$this->counter;
    }

    public function setCounter(int $counter, int $year): static
    {
        $this->counter = max(0, $counter);
        $this->counterYear = $year;

        return $this;
    }

    public function getUsageCount(): int
    {
        return (int) $this->usageCount;
    }

    public function markUsed(): static
    {
        $this->usageCount = $this->getUsageCount() + 1;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
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

    public function __toString(): string
    {
        return $this->name;
    }
}
