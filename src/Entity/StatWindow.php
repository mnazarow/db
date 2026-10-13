<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Итоги за периоды статистики (7, 30, 90, 365 дней и всё время, period = 0) по завершившимся дням: просмотры и
 * скачивания каждого документа (kind = d) и активность каждого сотрудника (kind = u). Сегодняшние события
 * добавляются вживую — рейтинги и разрез по разделам точны и не требуют подсчёта по всему журналу.
 */
#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'stat_window')]
#[ORM\Index(name: 'idx_stat_window_score', columns: ['period', 'kind', 'score'])]
class StatWindow
{
    public const KIND_DOCUMENT = 'd';
    public const KIND_USER = 'u';

    #[ORM\Id]
    #[ORM\Column(type: Types::SMALLINT)]
    private int $period;

    #[ORM\Id]
    #[ORM\Column(length: 1)]
    private string $kind;

    #[ORM\Id]
    #[ORM\Column(name: 'ref_id')]
    private int $refId;

    #[ORM\Column]
    private int $views = 0;

    #[ORM\Column]
    private int $downloads = 0;

    /** Изменения (для сотрудников): всё, кроме просмотров, скачиваний и уведомлений о сроке. */
    #[ORM\Column]
    private int $changes = 0;

    /** Сумма показателей — для сортировки рейтинга по индексу. */
    #[ORM\Column]
    private int $score = 0;

    #[ORM\Column(name: 'last_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastAt = null;

    public function __construct(int $period, string $kind, int $refId)
    {
        $this->period = $period;
        $this->kind = $kind;
        $this->refId = $refId;
    }

    public function getPeriod(): int
    {
        return $this->period;
    }

    public function getKind(): string
    {
        return $this->kind;
    }

    public function getRefId(): int
    {
        return $this->refId;
    }

    public function getViews(): int
    {
        return $this->views;
    }

    public function getDownloads(): int
    {
        return $this->downloads;
    }

    public function getChanges(): int
    {
        return $this->changes;
    }

    public function getScore(): int
    {
        return $this->score;
    }

    public function getLastAt(): ?\DateTimeImmutable
    {
        return $this->lastAt;
    }
}
