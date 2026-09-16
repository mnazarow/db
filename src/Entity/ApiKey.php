<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ApiKeyRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Ключ доступа к REST API (/api/v1) для внешних систем — например, индексатора RAG.
 * Сам токен показывается один раз при создании; в базе хранится только его хэш.
 */
#[ORM\Entity(repositoryClass: ApiKeyRepository::class)]
#[ORM\Table(name: 'api_key')]
#[ORM\UniqueConstraint(name: 'uniq_api_key_hash', columns: ['token_hash'])]
class ApiKey
{
    public const TOKEN_PREFIX = 'dp_';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 128)]
    private string $name;

    /** SHA-256 от токена. */
    #[ORM\Column(name: 'token_hash', length: 64)]
    private string $tokenHash;

    /** Первые символы токена — чтобы отличать ключи в списке. */
    #[ORM\Column(name: 'token_prefix', length: 12)]
    private string $tokenPrefix;

    /** Отдавать ли внутренние документы (иначе — только открытые). */
    #[ORM\Column(name: 'include_internal', options: ['default' => false])]
    private bool $includeInternal = false;

    #[ORM\Column(options: ['default' => true])]
    private bool $enabled = true;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $createdBy = null;

    #[ORM\Column(name: 'last_used_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastUsedAt = null;

    #[ORM\Column(name: 'last_used_ip', length: 45, nullable: true)]
    private ?string $lastUsedIp = null;

    #[ORM\Column(name: 'request_count', options: ['default' => 0])]
    private int $requestCount = 0;

    public function __construct(string $name, string $token, ?User $createdBy = null)
    {
        $this->name = mb_substr(trim($name), 0, 128);
        $this->tokenHash = self::hash($token);
        $this->tokenPrefix = mb_substr($token, 0, 12);
        $this->createdBy = $createdBy;
        $this->createdAt = new \DateTimeImmutable();
    }

    /** Создаёт новый случайный токен вида dp_<48 hex>. */
    public static function generateToken(): string
    {
        return self::TOKEN_PREFIX.bin2hex(random_bytes(24));
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
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
        $this->name = mb_substr(trim($name), 0, 128);

        return $this;
    }

    public function getTokenHash(): string
    {
        return $this->tokenHash;
    }

    public function getTokenPrefix(): string
    {
        return $this->tokenPrefix;
    }

    public function isIncludeInternal(): bool
    {
        return $this->includeInternal;
    }

    public function setIncludeInternal(bool $includeInternal): static
    {
        $this->includeInternal = $includeInternal;

        return $this;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function setEnabled(bool $enabled): static
    {
        $this->enabled = $enabled;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getCreatedBy(): ?User
    {
        return $this->createdBy;
    }

    public function getLastUsedAt(): ?\DateTimeImmutable
    {
        return $this->lastUsedAt;
    }

    public function getLastUsedIp(): ?string
    {
        return $this->lastUsedIp;
    }

    public function getRequestCount(): int
    {
        return $this->requestCount;
    }

    public function touch(?string $ip): static
    {
        $this->lastUsedAt = new \DateTimeImmutable();
        $this->lastUsedIp = null === $ip ? null : mb_substr($ip, 0, 45);
        ++$this->requestCount;

        return $this;
    }
}
