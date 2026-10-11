<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\MaterialRowChangeRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Запись журнала изменений таблицы материалов: кто, когда и что поменял в строке (значение ячейки,
 * добавление и удаление строки, загрузка и отвязка файла, импорт из Excel). Название строки и столбца
 * сохраняются как были: журнал остаётся читаемым после удаления строки или переименования столбца.
 */
#[ORM\Entity(repositoryClass: MaterialRowChangeRepository::class)]
#[ORM\Table(name: 'material_row_change')]
#[ORM\Index(name: 'idx_material_change_section', columns: ['section_id', 'created_at'])]
#[ORM\Index(name: 'idx_material_change_row', columns: ['row_id', 'created_at'])]
class MaterialRowChange
{
    public const CREATE = 'create';
    public const UPDATE = 'update';
    public const DELETE = 'delete';
    public const UPLOAD = 'upload';
    public const DETACH = 'detach';
    public const IMPORT = 'import';

    public const ACTION_LABELS = [
        self::CREATE => 'Строка добавлена',
        self::UPDATE => 'Изменено значение',
        self::DELETE => 'Строка удалена',
        self::UPLOAD => 'Загружен файл',
        self::DETACH => 'Файл отвязан',
        self::IMPORT => 'Импорт из Excel',
    ];

    /** Длинные значения (комментарии) хранятся в журнале с обрезкой. */
    public const MAX_VALUE = 2000;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Section::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Section $section;

    #[ORM\ManyToOne(targetEntity: MaterialRow::class)]
    #[ORM\JoinColumn(name: 'row_id', nullable: true, onDelete: 'SET NULL')]
    private ?MaterialRow $row;

    #[ORM\Column(length: 255)]
    private string $rowTitle;

    #[ORM\Column(length: 16)]
    private string $action;

    /** Поле строки: title, kind, p<id параметра>; для действий без поля — null. */
    #[ORM\Column(length: 64, nullable: true)]
    private ?string $field;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $fieldLabel;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $oldValue;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $newValue;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $user;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $userName;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(MaterialRow $row, string $action, ?User $user, ?string $field = null, ?string $fieldLabel = null, ?string $oldValue = null, ?string $newValue = null)
    {
        $this->section = $row->getSection();
        $this->row = $row;
        $this->rowTitle = mb_substr('' !== $row->getTitle() ? $row->getTitle() : 'без названия', 0, 255);
        $this->action = $action;
        $this->field = null === $field ? null : mb_substr($field, 0, 64);
        $this->fieldLabel = null === $fieldLabel ? null : mb_substr($fieldLabel, 0, 255);
        $this->oldValue = self::clip($oldValue);
        $this->newValue = self::clip($newValue);
        $this->user = $user;
        $this->userName = $user?->getDisplayName();
        $this->createdAt = new \DateTimeImmutable();
    }

    private static function clip(?string $value): ?string
    {
        return null === $value ? null : mb_substr($value, 0, self::MAX_VALUE);
    }

    /** Строка удаляется — ссылка на неё в записи снимается (журнал раздела при этом остаётся). */
    public function detachRow(): void
    {
        $this->row = null;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSection(): Section
    {
        return $this->section;
    }

    public function getRow(): ?MaterialRow
    {
        return $this->row;
    }

    public function getRowTitle(): string
    {
        return $this->rowTitle;
    }

    public function getAction(): string
    {
        return $this->action;
    }

    public function getActionLabel(): string
    {
        return self::ACTION_LABELS[$this->action] ?? $this->action;
    }

    public function getField(): ?string
    {
        return $this->field;
    }

    public function getFieldLabel(): ?string
    {
        return $this->fieldLabel;
    }

    public function getOldValue(): ?string
    {
        return $this->oldValue;
    }

    public function getNewValue(): ?string
    {
        return $this->newValue;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function getUserName(): ?string
    {
        return $this->userName;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
