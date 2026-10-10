<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\SystemJobRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Последний запуск регулярного задания (проверка сроков, индексация, резервная копия…): для мониторинга
 * (/health/details, /metrics) и панели администратора. Пишется через SystemJobRecorder прямыми запросами,
 * поэтому сущность здесь только для чтения и схемы.
 */
#[ORM\Entity(repositoryClass: SystemJobRepository::class)]
#[ORM\Table(name: 'system_job')]
class SystemJob
{
    public const STATUS_RUNNING = 'running';
    public const STATUS_OK = 'ok';
    public const STATUS_WARNING = 'warning';
    public const STATUS_ERROR = 'error';

    #[ORM\Id]
    #[ORM\Column(length: 64)]
    private string $name;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastStartedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastFinishedAt = null;

    /** Последний успешный (или с предупреждением) запуск. */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastSuccessAt = null;

    #[ORM\Column(length: 16, nullable: true)]
    private ?string $lastStatus = null;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $lastMessage = null;

    #[ORM\Column(nullable: true)]
    private ?int $lastDurationMs = null;

    #[ORM\Column(options: ['default' => 0])]
    private int $runs = 0;

    /** Сколько запусков подряд закончились ошибкой. */
    #[ORM\Column(options: ['default' => 0])]
    private int $failures = 0;

    public function __construct(string $name)
    {
        $this->name = $name;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getLastStartedAt(): ?\DateTimeImmutable
    {
        return $this->lastStartedAt;
    }

    public function getLastFinishedAt(): ?\DateTimeImmutable
    {
        return $this->lastFinishedAt;
    }

    public function getLastSuccessAt(): ?\DateTimeImmutable
    {
        return $this->lastSuccessAt;
    }

    public function getLastStatus(): ?string
    {
        return $this->lastStatus;
    }

    public function getLastMessage(): ?string
    {
        return $this->lastMessage;
    }

    public function getLastDurationMs(): ?int
    {
        return $this->lastDurationMs;
    }

    public function getRuns(): int
    {
        return $this->runs;
    }

    public function getFailures(): int
    {
        return $this->failures;
    }
}
