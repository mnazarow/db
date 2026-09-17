<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\DocumentLinkRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Связь между документами: «заменяет», «отменяет», «приложение к», «см. также».
 *
 * Связь хранится один раз и показывается с обеих сторон: в карточке нового приказа видно
 * «заменяет ПР-2025-14», в карточке старого — «заменён приказом ПР-2026-01». Так сотрудник
 * с закладкой на устаревший документ сразу видит действующую редакцию.
 */
#[ORM\Entity(repositoryClass: DocumentLinkRepository::class)]
#[ORM\Table(name: 'document_link')]
#[ORM\UniqueConstraint(name: 'uniq_link', columns: ['source_id', 'target_id', 'type'])]
#[ORM\Index(name: 'idx_link_source', columns: ['source_id'])]
#[ORM\Index(name: 'idx_link_target', columns: ['target_id'])]
class DocumentLink
{
    public const REPLACES = 'replaces';
    public const CANCELS = 'cancels';
    public const APPENDIX = 'appendix';
    public const RELATED = 'related';

    public const TYPES = [self::REPLACES, self::CANCELS, self::APPENDIX, self::RELATED];

    /** Как связь читается со стороны документа, у которого она заведена. */
    public const LABELS = [
        self::REPLACES => 'заменяет',
        self::CANCELS => 'отменяет',
        self::APPENDIX => 'приложение к',
        self::RELATED => 'см. также',
    ];

    /** Как та же связь читается со стороны второго документа. */
    public const REVERSE_LABELS = [
        self::REPLACES => 'заменён документом',
        self::CANCELS => 'отменён документом',
        self::APPENDIX => 'приложения',
        self::RELATED => 'см. также',
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Document::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Document $source;

    #[ORM\ManyToOne(targetEntity: Document::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Document $target;

    #[ORM\Column(length: 16)]
    private string $type = self::RELATED;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $note = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $createdBy = null;

    public function __construct(Document $source, Document $target, string $type, ?User $createdBy = null, ?string $note = null)
    {
        if ($source->getId() === $target->getId()) {
            throw new \InvalidArgumentException('Документ нельзя связать с самим собой.');
        }
        $this->source = $source;
        $this->target = $target;
        $this->setType($type);
        $this->createdBy = $createdBy;
        $this->note = null !== $note ? mb_substr(trim($note), 0, 255) ?: null : null;
        $this->createdAt = new \DateTimeImmutable();
    }

    public static function label(string $type, bool $reverse = false): string
    {
        return ($reverse ? self::REVERSE_LABELS : self::LABELS)[$type] ?? $type;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSource(): Document
    {
        return $this->source;
    }

    public function getTarget(): Document
    {
        return $this->target;
    }

    /** Второй документ связи относительно указанного. */
    public function other(Document $document): Document
    {
        return $this->source->getId() === $document->getId() ? $this->target : $this->source;
    }

    /** Читается ли связь в обратную сторону для указанного документа. */
    public function isReverseFor(Document $document): bool
    {
        return $this->source->getId() !== $document->getId();
    }

    public function labelFor(Document $document): string
    {
        return self::label($this->type, $this->isReverseFor($document));
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function setType(string $type): static
    {
        if (!\in_array($type, self::TYPES, true)) {
            throw new \InvalidArgumentException('Неизвестный вид связи: '.$type);
        }
        $this->type = $type;

        return $this;
    }

    public function getNote(): ?string
    {
        return $this->note;
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
