<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\PushSubscriptionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Подписка браузера сотрудника на уведомления портала (Web Push): адрес службы уведомлений браузера и ключи
 * шифрования. Одна запись на браузер (устройство); служба отвечает 404/410 — подписка больше не действует и
 * удаляется.
 */
#[ORM\Entity(repositoryClass: PushSubscriptionRepository::class)]
#[ORM\Table(name: 'push_subscription')]
#[ORM\UniqueConstraint(name: 'uniq_push_endpoint', columns: ['endpoint_hash'])]
class PushSubscription
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(type: Types::TEXT)]
    private string $endpoint;

    /** sha256 адреса — для уникальности (адрес длиннее, чем позволяет индекс). */
    #[ORM\Column(name: 'endpoint_hash', length: 64)]
    private string $endpointHash;

    #[ORM\Column(length: 128)]
    private string $p256dh;

    #[ORM\Column(name: 'auth_secret', length: 64)]
    private string $authSecret;

    /** Браузер и система — чтобы сотрудник узнал устройство в списке. */
    #[ORM\Column(length: 128, nullable: true)]
    private ?string $device = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'last_sent_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastSentAt = null;

    /** Неудачных отправок подряд (сеть, служба недоступна); после многих — подписка удаляется. */
    #[ORM\Column(options: ['default' => 0])]
    private int $failures = 0;

    public function __construct(User $user, string $endpoint, string $p256dh, string $authSecret, ?string $device)
    {
        $this->user = $user;
        $this->createdAt = new \DateTimeImmutable();
        $this->update($endpoint, $p256dh, $authSecret, $device);
    }

    public static function hash(string $endpoint): string
    {
        return hash('sha256', $endpoint);
    }

    public function update(string $endpoint, string $p256dh, string $authSecret, ?string $device): static
    {
        $this->endpoint = $endpoint;
        $this->endpointHash = self::hash($endpoint);
        $this->p256dh = $p256dh;
        $this->authSecret = $authSecret;
        $this->device = null !== $device ? mb_substr($device, 0, 128) : null;

        return $this;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function setUser(User $user): static
    {
        $this->user = $user;

        return $this;
    }

    public function getEndpoint(): string
    {
        return $this->endpoint;
    }

    public function getP256dh(): string
    {
        return $this->p256dh;
    }

    public function getAuthSecret(): string
    {
        return $this->authSecret;
    }

    public function getDevice(): ?string
    {
        return $this->device;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getLastSentAt(): ?\DateTimeImmutable
    {
        return $this->lastSentAt;
    }

    public function getFailures(): int
    {
        return $this->failures;
    }

    public function markSent(): static
    {
        $this->lastSentAt = new \DateTimeImmutable();
        $this->failures = 0;

        return $this;
    }

    public function markFailed(): static
    {
        ++$this->failures;

        return $this;
    }
}
