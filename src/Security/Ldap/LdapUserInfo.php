<?php

declare(strict_types=1);

namespace App\Security\Ldap;

/**
 * Сведения о пользователе, полученные из каталога.
 */
final class LdapUserInfo
{
    /**
     * @param list<string> $groups DN групп, в которых состоит пользователь
     */
    public function __construct(
        public readonly string $username,
        public readonly string $dn,
        public readonly string $displayName,
        public readonly ?string $email,
        public readonly ?string $department,
        public readonly array $groups,
        public readonly bool $isAdmin,
    ) {
    }
}
