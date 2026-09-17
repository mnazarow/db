<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\AuditEventRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Запись журнала аудита: значимое для безопасности действие (вход, изменение прав,
 * правка настроек, удаление документа, обращение по ключу API).
 *
 * Журнал ведётся в базе, чтобы его можно было смотреть в панели администратора,
 * выгружать для службы информационной безопасности и передавать в SIEM.
 */
#[ORM\Entity(repositoryClass: AuditEventRepository::class)]
#[ORM\Table(name: 'audit_event')]
#[ORM\Index(name: 'idx_audit_time', columns: ['occurred_at'])]
#[ORM\Index(name: 'idx_audit_actor', columns: ['actor_name', 'occurred_at'])]
#[ORM\Index(name: 'idx_audit_level', columns: ['level', 'occurred_at'])]
class AuditEvent
{
    public const LEVEL_INFO = 'info';
    public const LEVEL_WARNING = 'warning';
    public const LEVEL_ERROR = 'error';

    public const LEVELS = [self::LEVEL_INFO, self::LEVEL_WARNING, self::LEVEL_ERROR];

    public const LEVEL_LABELS = [
        self::LEVEL_INFO => 'обычное',
        self::LEVEL_WARNING => 'предупреждение',
        self::LEVEL_ERROR => 'ошибка',
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(name: 'occurred_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $occurredAt;

    #[ORM\Column(length: 16)]
    private string $level = self::LEVEL_INFO;

    /** Что произошло, человеческим языком: «Вход в систему», «Удалён документ». */
    #[ORM\Column(length: 255)]
    private string $action = '';

    /** Логин сотрудника (строкой: запись остаётся и после удаления учётной записи). */
    #[ORM\Column(name: 'actor_name', length: 64, nullable: true)]
    private ?string $actorName = null;

    #[ORM\Column(length: 45, nullable: true)]
    private ?string $ip = null;

    /** @var array<string, mixed> подробности события */
    #[ORM\Column(type: Types::JSON)]
    private array $details = [];

    /**
     * @param array<string, mixed> $details
     */
    public function __construct(string $action, string $level = self::LEVEL_INFO, array $details = [], ?\DateTimeImmutable $occurredAt = null)
    {
        $this->action = mb_substr($action, 0, 255);
        $this->level = \in_array($level, self::LEVELS, true) ? $level : self::LEVEL_INFO;
        $this->occurredAt = $occurredAt ?? new \DateTimeImmutable();
        $this->setDetails($details);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOccurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }

    public function getLevel(): string
    {
        return $this->level;
    }

    public function getLevelLabel(): string
    {
        return self::LEVEL_LABELS[$this->level] ?? $this->level;
    }

    public function getAction(): string
    {
        return $this->action;
    }

    public function getActorName(): ?string
    {
        return $this->actorName;
    }

    public function setActorName(?string $actorName): static
    {
        $actorName = trim((string) $actorName);
        $this->actorName = '' === $actorName ? null : mb_substr($actorName, 0, 64);

        return $this;
    }

    public function getIp(): ?string
    {
        return $this->ip;
    }

    public function setIp(?string $ip): static
    {
        $ip = trim((string) $ip);
        $this->ip = '' === $ip ? null : mb_substr($ip, 0, 45);

        return $this;
    }

    /** @return array<string, mixed> */
    public function getDetails(): array
    {
        return $this->details;
    }

    /** @param array<string, mixed> $details */
    public function setDetails(array $details): static
    {
        $clean = [];
        foreach ($details as $key => $value) {
            if (\in_array($key, ['user', 'by', 'ip'], true)) {
                continue; // эти сведения хранятся в отдельных колонках
            }
            if (\is_scalar($value) || null === $value) {
                $clean[(string) $key] = \is_string($value) ? mb_substr($value, 0, 500) : $value;
            } elseif (\is_array($value)) {
                $clean[(string) $key] = mb_substr(json_encode($value, \JSON_UNESCAPED_UNICODE) ?: '', 0, 500);
            }
        }
        $this->details = $clean;

        return $this;
    }

    /** Подробности одной строкой — для таблицы и выгрузки. */
    public function detailsLine(): string
    {
        $parts = [];
        foreach ($this->details as $key => $value) {
            $parts[] = $key.': '.(\is_bool($value) ? ($value ? 'да' : 'нет') : (string) $value);
        }

        return implode(', ', $parts);
    }
}
