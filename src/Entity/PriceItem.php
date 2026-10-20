<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\PriceItemRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Позиция прайс-листа в редакции: артикул, наименование, группа (раздел прайса), единица, цены по столбцам
 * цен редакции (число в виде строки «1234.5» или текст «по запросу») и дополнительные столбцы.
 * search_text — строка для поиска в нижнем регистре (артикул, наименование, группа, доп. столбцы).
 */
#[ORM\Entity(repositoryClass: PriceItemRepository::class)]
#[ORM\Table(name: 'price_item')]
#[ORM\Index(name: 'idx_price_item_revision', columns: ['revision_id', 'position'])]
#[ORM\Index(name: 'idx_price_item_sku', columns: ['revision_id', 'sku'])]
class PriceItem
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: PriceRevision::class)]
    #[ORM\JoinColumn(name: 'revision_id', nullable: false, onDelete: 'CASCADE')]
    private PriceRevision $revision;

    #[ORM\Column]
    private int $position = 0;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $sku = null;

    #[ORM\Column(length: 1000)]
    private string $name = '';

    #[ORM\Column(name: 'group_name', length: 255, nullable: true)]
    private ?string $groupName = null;

    #[ORM\Column(length: 32, nullable: true)]
    private ?string $unit = null;

    /** Первая числовая цена — для сортировки и ответов ИИ. */
    #[ORM\Column(type: Types::DECIMAL, precision: 15, scale: 2, nullable: true)]
    private ?string $price = null;

    /** @var list<string> цены по столбцам цен редакции */
    #[ORM\Column(type: Types::JSON)]
    private array $prices = [];

    /** @var list<string> значения дополнительных столбцов */
    #[ORM\Column(type: Types::JSON)]
    private array $extra = [];

    #[ORM\Column(name: 'search_text', length: 1500)]
    private string $searchText = '';

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getRevision(): PriceRevision
    {
        return $this->revision;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function getSku(): ?string
    {
        return $this->sku;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getGroupName(): ?string
    {
        return $this->groupName;
    }

    public function getUnit(): ?string
    {
        return $this->unit;
    }

    public function getPrice(): ?string
    {
        return $this->price;
    }

    /** @return list<string> */
    public function getPrices(): array
    {
        return $this->prices;
    }

    /** @return list<string> */
    public function getExtra(): array
    {
        return $this->extra;
    }
}
