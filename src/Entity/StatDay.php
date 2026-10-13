<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Ежедневные итоги журнала событий: сколько событий каждого типа было в каждый завершившийся день (сегодняшние
 * считаются вживую). Пересчитывает App\Service\StatsAggregator (ночью и по требованию); для графиков и сводки
 * статистики не нужно перебирать сотни тысяч событий.
 */
#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'stat_day')]
class StatDay
{
    #[ORM\Id]
    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $day;

    #[ORM\Id]
    #[ORM\Column(length: 24)]
    private string $type;

    #[ORM\Column]
    private int $cnt = 0;

    public function __construct(\DateTimeImmutable $day, string $type, int $cnt)
    {
        $this->day = $day;
        $this->type = $type;
        $this->cnt = $cnt;
    }

    public function getDay(): \DateTimeImmutable
    {
        return $this->day;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getCnt(): int
    {
        return $this->cnt;
    }
}
