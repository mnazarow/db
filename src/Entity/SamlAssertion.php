<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Использованные утверждения SAML: номер утверждения (хеш «провайдер|ID») хранится до конца срока его
 * действия — тот же ответ провайдера второй раз не примется, в том числе одновременно на двух серверах.
 */
#[ORM\Entity]
#[ORM\Table(name: 'saml_assertion')]
#[ORM\Index(name: 'idx_saml_assertion_expires', columns: ['expires_at'])]
class SamlAssertion
{
    #[ORM\Id]
    #[ORM\Column(length: 64)]
    private string $id;

    #[ORM\Column(name: 'expires_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $expiresAt;

    public function __construct(string $id, \DateTimeImmutable $expiresAt)
    {
        $this->id = $id;
        $this->expiresAt = $expiresAt;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getExpiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }
}
