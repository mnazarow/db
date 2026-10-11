<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\MaterialParameterRepository;
use App\Service\Material\MaterialState;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Параметр (столбец) таблицы материалов раздела верхнего уровня: «Версия», «Дата обнов.»,
 * «Требования PM», «Дизайн-требования», «Ссылка», «Комментарий» и добавленные модератором.
 *
 * Роль связывает столбец с поведением портала: по «Требованиям PM» и «Дизайн-требованиям»
 * считается состояние, «Дата обнов.» и «Ссылка» заполняются при загрузке файла из строки.
 */
#[ORM\Entity(repositoryClass: MaterialParameterRepository::class)]
#[ORM\Table(name: 'material_parameter')]
#[ORM\Index(name: 'idx_material_parameter_section', columns: ['section_id', 'position'])]
class MaterialParameter
{
    public const KIND_TEXT = 'text';
    public const KIND_NUMBER = 'number';
    public const KIND_DATE = 'date';
    public const KIND_LIST = 'list';
    public const KIND_LINK = 'link';

    public const KINDS = [self::KIND_TEXT, self::KIND_NUMBER, self::KIND_DATE, self::KIND_LIST, self::KIND_LINK];

    public const KIND_LABELS = [
        self::KIND_TEXT => 'Текст',
        self::KIND_NUMBER => 'Число',
        self::KIND_DATE => 'Дата',
        self::KIND_LIST => 'Список значений',
        self::KIND_LINK => 'Ссылка',
    ];

    public const ROLE_VERSION = 'version';
    public const ROLE_UPDATED = 'updated';
    public const ROLE_PM = 'pm';
    public const ROLE_DESIGN = 'design';
    public const ROLE_LINK = 'link';
    public const ROLE_COMMENT = 'comment';

    public const ROLE_HINTS = [
        self::ROLE_VERSION => 'попадает в комментарий к версии документа при загрузке файла',
        self::ROLE_UPDATED => 'заполняется датой загрузки файла',
        self::ROLE_PM => 'участвует в расчёте состояния',
        self::ROLE_DESIGN => 'участвует в расчёте состояния',
        self::ROLE_LINK => 'заполняется ссылкой на документ портала, если пусто',
        self::ROLE_COMMENT => '',
    ];

    /** Роли, для которых список значений фиксирован: на эти значения опирается формула состояния. */
    public const LOCKED_OPTION_ROLES = [self::ROLE_PM, self::ROLE_DESIGN];

    /**
     * Столбцы по умолчанию — как на листах «Продуктовые материалы»: название, вид, список значений,
     * роль, ширина (в символах, как в Excel).
     *
     * @var list<array{0: string, 1: string, 2: list<string>|null, 3: string, 4: int}>
     */
    public const DEFAULTS = [
        ['Версия', self::KIND_TEXT, null, self::ROLE_VERSION, 11],
        ['Дата обнов.', self::KIND_DATE, null, self::ROLE_UPDATED, 14],
        ['Требования PM', self::KIND_LIST, [MaterialState::CHECK_NONE, MaterialState::CHECK_OK, MaterialState::CHECK_FAIL, MaterialState::CHECK_ABSENT], self::ROLE_PM, 22],
        ['Дизайн-требования', self::KIND_LIST, [MaterialState::CHECK_OK, MaterialState::CHECK_FAIL, MaterialState::CHECK_ABSENT, MaterialState::CHECK_NONE], self::ROLE_DESIGN, 24],
        ['Ссылка', self::KIND_LINK, null, self::ROLE_LINK, 30],
        ['Комментарий', self::KIND_TEXT, null, self::ROLE_COMMENT, 26],
    ];

    /** Названия столбцов из Excel, по которым импорт узнаёт роль. */
    public const ROLE_ALIASES = [
        self::ROLE_VERSION => ['версия', 'ревизия', 'редакция'],
        self::ROLE_UPDATED => ['дата обнов.', 'дата обнов', 'дата обновления', 'обновлено', 'дата'],
        self::ROLE_PM => ['требования pm', 'требования рм', 'требования pm-а', 'pm'],
        self::ROLE_DESIGN => ['дизайн-требования', 'дизайн требования', 'требования дизайна', 'дизайн'],
        self::ROLE_LINK => ['ссылка', 'url', 'адрес'],
        self::ROLE_COMMENT => ['комментарий', 'примечание', 'комментарии'],
    ];

    public const MAX_NAME = 100;
    public const MAX_TEXT = 2000;
    public const MAX_OPTIONS = 50;
    public const MAX_OPTION = 200;

    /** Больше знаков в числе не бывает (номера и артикулы — в текстовых столбцах). */
    public const MAX_NUMBER = 30;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Section::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Section $section;

    #[ORM\Column(length: self::MAX_NAME)]
    private string $name = '';

    #[ORM\Column(length: 12)]
    private string $kind = self::KIND_TEXT;

    /** @var list<string> варианты для столбца-списка */
    #[ORM\Column(type: Types::JSON)]
    private array $options = [];

    #[ORM\Column(length: 16, nullable: true)]
    private ?string $role = null;

    #[ORM\Column(options: ['default' => 0])]
    private int $position = 0;

    /** Ширина столбца в символах (как в Excel); null — по содержимому. */
    #[ORM\Column(nullable: true)]
    private ?int $width = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(Section $section, string $name, string $kind = self::KIND_TEXT, array $options = [], ?string $role = null, int $position = 0, ?int $width = null)
    {
        $this->section = $section;
        $this->setName($name);
        $this->setKind($kind);
        $this->setOptions($options);
        $this->role = $role;
        $this->position = $position;
        $this->setWidth($width);
        $this->createdAt = new \DateTimeImmutable();
    }

    /** Роль по названию столбца из Excel («Требования PM» → pm). */
    public static function roleForName(string $name): ?string
    {
        $name = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $name) ?? ''));
        foreach (self::ROLE_ALIASES as $role => $aliases) {
            if (\in_array($name, $aliases, true)) {
                return $role;
            }
        }

        return null;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    /** Ключ значения в строке таблицы. */
    public function key(): string
    {
        return 'p'.(int) $this->id;
    }

    public function getSection(): Section
    {
        return $this->section;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '');
        $this->name = mb_substr('' !== $name ? $name : 'Параметр', 0, self::MAX_NAME);

        return $this;
    }

    public function getKind(): string
    {
        return $this->kind;
    }

    public function getKindLabel(): string
    {
        return self::KIND_LABELS[$this->kind] ?? $this->kind;
    }

    public function setKind(string $kind): static
    {
        $this->kind = \in_array($kind, self::KINDS, true) ? $kind : self::KIND_TEXT;

        return $this;
    }

    public function isList(): bool
    {
        return self::KIND_LIST === $this->kind;
    }

    /** @return list<string> */
    public function getOptions(): array
    {
        return $this->options;
    }

    /** @param iterable<string>|string $options список или текст «по одному значению в строке» */
    public function setOptions(iterable|string $options): static
    {
        if (\is_string($options)) {
            $options = preg_split('/\R/u', $options) ?: [];
        }
        $clean = [];
        foreach ($options as $option) {
            $option = self::cleanOption((string) $option);
            if ('' !== $option && !\in_array($option, $clean, true)) {
                $clean[] = $option;
            }
        }
        $this->options = \array_slice($clean, 0, self::MAX_OPTIONS);

        return $this;
    }

    /** Значение списка в едином виде: без переводов строк и повторных пробелов, не длиннее MAX_OPTION. */
    public static function cleanOption(string $option): string
    {
        return mb_substr(trim(preg_replace('/\s+/u', ' ', $option) ?? ''), 0, self::MAX_OPTION);
    }

    public function addOption(string $option): static
    {
        return $this->setOptions([...$this->options, $option]);
    }

    public function getRole(): ?string
    {
        return $this->role;
    }

    public function setRole(?string $role): static
    {
        $this->role = $role;

        return $this;
    }

    public function getRoleHint(): string
    {
        return null !== $this->role ? (self::ROLE_HINTS[$this->role] ?? '') : '';
    }

    public function hasLockedOptions(): bool
    {
        return \in_array($this->role, self::LOCKED_OPTION_ROLES, true);
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

    public function getWidth(): ?int
    {
        return $this->width;
    }

    public function setWidth(?int $width): static
    {
        $this->width = null !== $width && $width > 0 ? max(6, min($width, 80)) : null;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * Приводит введённое значение к хранимому виду и проверяет его.
     *
     * @throws \DomainException с понятным сообщением
     */
    public function normalize(?string $value): string
    {
        $value = str_replace(["\r\n", "\r"], "\n", (string) $value);
        $value = trim(preg_replace('/[^\P{C}\n]+/u', '', $value) ?? '');
        if ('' === $value) {
            return '';
        }

        switch ($this->kind) {
            case self::KIND_NUMBER:
                $number = str_replace([' ', "\u{00A0}", "\u{202F}", ','], ['', '', '', '.'], $value);
                if (1 !== preg_match('/^-?\d+(\.\d+)?$/', $number)) {
                    throw new \DomainException(\sprintf('«%s»: нужно число.', $this->name));
                }
                if (\strlen($number) > self::MAX_NUMBER) {
                    throw new \DomainException(\sprintf('«%s»: слишком длинное число — для номеров и артикулов нужен текстовый столбец.', $this->name));
                }

                return $number;
            case self::KIND_DATE:
                return self::normalizeDate($value) ?? throw new \DomainException(\sprintf('«%s»: нужна дата (ДД.ММ.ГГГГ).', $this->name));
            case self::KIND_LIST:
                // Значения из Excel бывают с переводом строки (Alt+Enter) или двойным пробелом — сравниваем
                // в том же виде, в каком хранятся варианты списка.
                $value = mb_strtolower(self::cleanOption($value));
                foreach ($this->options as $option) {
                    if (mb_strtolower($option) === $value) {
                        return $option;
                    }
                }
                throw new \DomainException(\sprintf('«%s»: выберите значение из списка.', $this->name));
            case self::KIND_LINK:
                if (1 === preg_match('~^www\.~i', $value)) {
                    $value = 'https://'.$value;
                }
                if (1 !== preg_match('~^(https?://|/)\S+$~i', $value)) {
                    throw new \DomainException(\sprintf('«%s»: ссылка должна начинаться с http:// или https://.', $this->name));
                }

                return mb_substr($value, 0, self::MAX_TEXT);
            default:
                return mb_substr($value, 0, self::MAX_TEXT);
        }
    }

    /** Дата в формате Y-m-d из «2026-08-02», «02.08.2026» или «2.8.26». */
    public static function normalizeDate(string $value): ?string
    {
        $value = trim($value);
        if (1 === preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})/', $value, $m)) {
            [$year, $month, $day] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } elseif (1 === preg_match('/^(\d{1,2})[.\/](\d{1,2})[.\/](\d{2,4})$/', $value, $m)) {
            [$day, $month, $year] = [(int) $m[1], (int) $m[2], (int) $m[3]];
            if ($year < 100) {
                $year += 2000;
            }
        } else {
            return null;
        }

        return checkdate($month, $day, $year) ? \sprintf('%04d-%02d-%02d', $year, $month, $day) : null;
    }

    /** Значение для показа: даты — ДД.ММ.ГГГГ, дробные числа — с запятой. */
    public function display(string $value): string
    {
        if ('' === $value) {
            return '';
        }
        if (self::KIND_DATE === $this->kind && 1 === preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m)) {
            return $m[3].'.'.$m[2].'.'.$m[1];
        }
        if (self::KIND_NUMBER === $this->kind && 1 === preg_match('/^-?\d+\.\d+$/', $value)) {
            return str_replace('.', ',', $value);
        }

        return $value;
    }
}
