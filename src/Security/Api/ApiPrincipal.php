<?php

declare(strict_types=1);

namespace App\Security\Api;

use App\Entity\ApiKey;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * «Пользователь» REST API — внешняя система, предъявившая ключ доступа.
 * Не является пользователем портала: не имеет ROLE_USER и не попадает под правила гостевого доступа.
 */
final class ApiPrincipal implements UserInterface
{
    public const ROLE = 'ROLE_API';

    public function __construct(private readonly ApiKey $key)
    {
    }

    public function getKey(): ApiKey
    {
        return $this->key;
    }

    /** Отдавать ли внутренние документы (иначе — только открытые). */
    public function includesInternal(): bool
    {
        return $this->key->isIncludeInternal();
    }

    public function getRoles(): array
    {
        return [self::ROLE];
    }

    #[\Deprecated] // ключ API в объекте не хранится в открытом виде — стирать нечего (требование Symfony 7.3+)
    public function eraseCredentials(): void
    {
    }

    public function getUserIdentifier(): string
    {
        return 'api-key:'.$this->key->getId();
    }
}
