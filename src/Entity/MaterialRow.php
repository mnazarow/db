<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\MaterialRowRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Строка таблицы материалов: материал (каталог, паспорт, опросный лист…) раздела верхнего уровня.
 *
 * Название и тип — постоянные столбцы, значения остальных параметров хранятся в JSON по ключу
 * «p<id параметра>». К строке можно загрузить файл: он становится документом раздела,
 * строка ссылается на этот документ.
 */
#[ORM\Entity(repositoryClass: MaterialRowRepository::class)]
#[ORM\Table(name: 'material_row')]
#[ORM\Index(name: 'idx_material_row_section', columns: ['section_id', 'position'])]
#[ORM\Index(name: 'idx_material_row_document', columns: ['document_id'])]
class MaterialRow
{
    public const MAX_TITLE = 255;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Section::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Section $section;

    /** «Материал» — название строки. */
    #[ORM\Column(length: self::MAX_TITLE)]
    private string $title = '';

    #[ORM\ManyToOne(targetEntity: DocumentKind::class)]
    #[ORM\JoinColumn(name: 'kind_id', nullable: true, onDelete: 'SET NULL')]
    private ?DocumentKind $kind = null;

    /** @var array<string, string> значения параметров: «p<id>» => значение */
    #[ORM\Column(name: 'param_values', type: Types::JSON)]
    private array $values = [];

    /** Документ, загруженный из этой строки. */
    #[ORM\ManyToOne(targetEntity: Document::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Document $document = null;

    #[ORM\Column(options: ['default' => 0])]
    private int $position = 0;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(length: 128, nullable: true)]
    private ?string $updatedByName = null;

    public function __construct(Section $section, string $title = '', int $position = 0)
    {
        $this->section = $section;
        $this->setTitle($title);
        $this->position = $position;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSection(): Section
    {
        return $this->section;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): static
    {
        $title = trim(preg_replace('/\s+/u', ' ', $title) ?? '');
        $this->title = mb_substr($title, 0, self::MAX_TITLE);

        return $this;
    }

    public function getKind(): ?DocumentKind
    {
        return $this->kind;
    }

    public function setKind(?DocumentKind $kind): static
    {
        $this->kind = $kind;

        return $this;
    }

    /** @return array<string, string> */
    public function getValues(): array
    {
        return $this->values;
    }

    public function getValue(MaterialParameter|string|null $parameter): string
    {
        if (null === $parameter) {
            return '';
        }
        $key = $parameter instanceof MaterialParameter ? $parameter->key() : $parameter;

        return (string) ($this->values[$key] ?? '');
    }

    public function setValue(MaterialParameter|string $parameter, string $value): static
    {
        $key = $parameter instanceof MaterialParameter ? $parameter->key() : $parameter;
        if ('' === $value) {
            unset($this->values[$key]);
        } else {
            $this->values[$key] = $value;
        }

        return $this;
    }

    public function removeValue(string $key): static
    {
        unset($this->values[$key]);

        return $this;
    }

    public function getDocument(): ?Document
    {
        return $this->document;
    }

    public function setDocument(?Document $document): static
    {
        $this->document = $document;

        return $this;
    }

    /**
     * Есть ли у материала файл: к строке привязан документ раздела, и он не в архиве. По этому
     * считаются «С файлами» и «Отсутствует».
     */
    public function hasFile(): bool
    {
        return null !== $this->document && !$this->document->isArchived();
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

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function getUpdatedByName(): ?string
    {
        return $this->updatedByName;
    }

    public function touch(?User $by): static
    {
        $this->updatedAt = new \DateTimeImmutable();
        if (null !== $by) {
            $this->updatedByName = $by->getDisplayName();
        }

        return $this;
    }
}
