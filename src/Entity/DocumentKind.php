<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\DocumentKindRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Тип файла (вид материала): «Каталоги и брошюры», «Паспорта и руководства», «Опросные листы и ТКП»,
 * «Фотографии», «Прочее» и добавленные администратором.
 *
 * Тип есть у каждого документа портала и у каждой строки таблицы материалов; по типам строится
 * сводная «Несоответствие / Отсутствие по типу». Тип «Прочее» — служебный: удалить его нельзя,
 * в него переводятся документы и строки удаляемых типов.
 */
#[ORM\Entity(repositoryClass: DocumentKindRepository::class)]
#[ORM\Table(name: 'document_kind')]
#[ORM\UniqueConstraint(name: 'uniq_document_kind_name', columns: ['name'])]
class DocumentKind
{
    /** Типы, которые создаются при установке (порядок — как в сводной). */
    public const DEFAULTS = ['Каталоги и брошюры', 'Паспорта и руководства', 'Опросные листы и ТКП', 'Фотографии', 'Прочее'];

    /** Служебный тип: получает документы удалённых типов и документы, тип которых не определён. */
    public const FALLBACK = 'Прочее';

    public const MAX_NAME = 64;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: self::MAX_NAME)]
    private string $name = '';

    #[ORM\Column(options: ['default' => 0])]
    private int $position = 0;

    #[ORM\Column(name: 'is_system', options: ['default' => false])]
    private bool $system = false;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $name = '', int $position = 0, bool $system = false)
    {
        $this->setName($name);
        $this->position = $position;
        $this->system = $system;
        $this->createdAt = new \DateTimeImmutable();
    }

    /** Название в едином виде: пробелы по краям и повторные пробелы убираются, первая буква — прописная. */
    public static function normalizeName(?string $name): string
    {
        $name = trim(preg_replace('/\s+/u', ' ', (string) $name) ?? '');
        if ('' === $name) {
            return '';
        }

        return mb_substr(mb_strtoupper(mb_substr($name, 0, 1)).mb_substr($name, 1), 0, self::MAX_NAME);
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
        $this->name = self::normalizeName($name);

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

    public function isSystem(): bool
    {
        return $this->system;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function __toString(): string
    {
        return $this->name;
    }
}
