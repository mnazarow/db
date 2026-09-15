<?php

declare(strict_types=1);

namespace App\Security\Ldap;

/**
 * Параметры подключения к Active Directory / LDAP (переменные LDAP_* в .env).
 */
final class LdapSettings
{
    public function __construct(
        public readonly bool $enabled,
        public readonly string $host,
        public readonly int $port,
        public readonly string $encryption,
        public readonly string $baseDn,
        public readonly string $bindDn,
        public readonly string $bindPassword,
        public readonly string $upnSuffix,
        public readonly string $uidKey,
        public readonly string $filter,
        public readonly string $nameAttr,
        public readonly string $mailAttr,
        public readonly string $adminGroup,
        public readonly string $userGroup,
    ) {
    }

    public function isExtensionLoaded(): bool
    {
        return \extension_loaded('ldap');
    }

    public function usesServiceAccount(): bool
    {
        return '' !== trim($this->bindDn);
    }

    /** Имя для привязки к домену от лица пользователя: user@domain (или DOMAIN\user, если суффикс задан как DOMAIN\). */
    public function bindNameFor(string $username): string
    {
        $suffix = trim($this->upnSuffix);
        if ('' === $suffix) {
            return $username;
        }
        if (str_ends_with($suffix, '\\')) {
            return $suffix.$username;
        }

        return $username.'@'.ltrim($suffix, '@');
    }

    /** Фильтр поиска с подставленным логином (экранированным по правилам LDAP). */
    public function searchFilter(string $escapedUsername): string
    {
        $filter = '' !== trim($this->filter) ? $this->filter : '({uid_key}={username})';

        return str_replace(['{uid_key}', '{username}'], [$this->uidKey, $escapedUsername], $filter);
    }

    /** @return array<string, mixed> сводка настроек для страницы «Настройки» (без пароля) */
    public function summary(): array
    {
        return [
            'enabled' => $this->enabled,
            'extension' => $this->isExtensionLoaded(),
            'host' => $this->host,
            'port' => $this->port,
            'encryption' => $this->encryption,
            'base_dn' => $this->baseDn,
            'bind_dn' => $this->bindDn,
            'upn_suffix' => $this->upnSuffix,
            'uid_key' => $this->uidKey,
            'filter' => $this->filter,
            'name_attr' => $this->nameAttr,
            'mail_attr' => $this->mailAttr,
            'admin_group' => $this->adminGroup,
            'user_group' => $this->userGroup,
        ];
    }
}
