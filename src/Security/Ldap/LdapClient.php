<?php

declare(strict_types=1);

namespace App\Security\Ldap;

use Psr\Log\LoggerInterface;
use Symfony\Component\Ldap\Entry;
use Symfony\Component\Ldap\Exception\ConnectionException;
use Symfony\Component\Ldap\Exception\InvalidCredentialsException;
use Symfony\Component\Ldap\Ldap;
use Symfony\Component\Ldap\LdapInterface;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;

/**
 * Проверка пароля и получение сведений о пользователе из Active Directory / LDAP.
 *
 * Два режима работы:
 *  1. Со служебной учётной записью (LDAP_BIND_DN): портал ищет пользователя по логину,
 *     затем проверяет пароль привязкой (bind) от имени найденной записи.
 *  2. Без служебной учётной записи: портал привязывается к каталогу от имени входящего
 *     пользователя (логин@LDAP_UPN_SUFFIX) и читает его запись тем же подключением.
 *
 * Членство в группах проверяется по атрибуту memberOf, а для Active Directory — дополнительно
 * запросом с правилом LDAP_MATCHING_RULE_IN_CHAIN (учитывает вложенные группы).
 */
final class LdapClient
{
    private const AD_NESTED_RULE = 'memberOf:1.2.840.113556.1.4.1941:=';

    public function __construct(
        private readonly LdapSettings $settings,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function getSettings(): LdapSettings
    {
        return $this->settings;
    }

    public function isEnabled(): bool
    {
        return $this->settings->enabled;
    }

    public function isAvailable(): bool
    {
        return $this->settings->enabled && $this->settings->isExtensionLoaded();
    }

    /**
     * Проверяет пароль пользователя в каталоге и возвращает его данные.
     *
     * @throws BadCredentialsException при неверном логине/пароле
     * @throws LdapException           при недоступности каталога или ошибке настройки
     */
    public function authenticate(string $username, string $password): LdapUserInfo
    {
        if ('' === $password) {
            throw new BadCredentialsException('Пароль не указан.');
        }
        if (!$this->settings->isExtensionLoaded()) {
            throw new LdapException('Расширение PHP ldap не установлено — вход через домен невозможен.');
        }
        $ldap = $this->connect();
        $escaped = $ldap->escape($username, '', LdapInterface::ESCAPE_FILTER);
        $filter = $this->settings->searchFilter($escaped);

        try {
            if ($this->settings->usesServiceAccount()) {
                $this->bindServiceAccount($ldap);
                $entry = $this->findEntry($ldap, $filter);
                if (null === $entry) {
                    throw new BadCredentialsException('Пользователь не найден в каталоге.');
                }
                try {
                    $ldap->bind($entry->getDn(), $password);
                } catch (InvalidCredentialsException) {
                    throw new BadCredentialsException('Неверный пароль.');
                }
            } else {
                try {
                    $ldap->bind($this->settings->bindNameFor($username), $password);
                } catch (InvalidCredentialsException) {
                    throw new BadCredentialsException('Неверный логин или пароль.');
                }
                $entry = $this->findEntry($ldap, $filter);
                if (null === $entry) {
                    throw new LdapException('Пароль принят, но запись пользователя не найдена по фильтру LDAP_FILTER — проверьте LDAP_BASE_DN и LDAP_FILTER.');
                }
            }
        } catch (ConnectionException $e) {
            $this->logger->error('LDAP: ошибка подключения', ['error' => $e->getMessage()]);
            throw new LdapException('Каталог недоступен: '.$e->getMessage(), 0, $e);
        }

        $info = $this->buildInfo($ldap, $username, $entry);
        if ('' !== trim($this->settings->userGroup) && !$this->isMemberOf($ldap, $entry, $info->groups, $this->settings->userGroup)) {
            throw new BadCredentialsException('Пользователь не состоит в группе, которой разрешён вход в портал.');
        }

        return $info;
    }

    /**
     * Ищет пользователя по логину служебной учётной записью (для предварительного добавления в портал).
     *
     * @throws LdapException
     */
    public function findUser(string $username): ?LdapUserInfo
    {
        if (!$this->settings->usesServiceAccount()) {
            throw new LdapException('Поиск пользователей возможен только при заданной служебной учётной записи (LDAP_BIND_DN).');
        }
        $ldap = $this->connect();
        try {
            $this->bindServiceAccount($ldap);
            $entry = $this->findEntry($ldap, $this->settings->searchFilter($ldap->escape($username, '', LdapInterface::ESCAPE_FILTER)));
        } catch (ConnectionException $e) {
            throw new LdapException('Каталог недоступен: '.$e->getMessage(), 0, $e);
        }

        return null === $entry ? null : $this->buildInfo($ldap, $username, $entry);
    }

    /**
     * Проверка подключения служебной учётной записью (страница настроек, команда app:ldap:test).
     *
     * @return array{ok: bool, message: string}
     */
    public function testConnection(): array
    {
        if (!$this->settings->enabled) {
            return ['ok' => false, 'message' => 'Вход через домен выключен (LDAP_ENABLED=0).'];
        }
        if (!$this->settings->isExtensionLoaded()) {
            return ['ok' => false, 'message' => 'Расширение PHP ldap не установлено.'];
        }
        try {
            $ldap = $this->connect();
            if ($this->settings->usesServiceAccount()) {
                $this->bindServiceAccount($ldap);
                $count = \count($ldap->query($this->settings->baseDn, '(objectClass=*)', ['maxItems' => 1, 'scope' => 'base'])->execute());

                return ['ok' => true, 'message' => \sprintf('Подключение к %s:%d успешно, служебная учётная запись принята, база %s доступна (%d).', $this->settings->host, $this->settings->port, $this->settings->baseDn, $count)];
            }
            $ldap->bind();

            return ['ok' => true, 'message' => \sprintf('Сервер %s:%d отвечает (анонимная привязка). Пароли пользователей будут проверяться привязкой «%s».', $this->settings->host, $this->settings->port, $this->settings->bindNameFor('логин'))];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => 'Ошибка: '.$e->getMessage()];
        }
    }

    private function connect(): LdapInterface
    {
        try {
            return Ldap::create('ext_ldap', [
                'host' => $this->settings->host,
                'port' => $this->settings->port,
                'encryption' => \in_array($this->settings->encryption, ['ssl', 'tls'], true) ? $this->settings->encryption : 'none',
                'options' => ['protocol_version' => 3, 'referrals' => false, 'network_timeout' => 10],
            ]);
        } catch (\Throwable $e) {
            throw new LdapException('Не удалось инициализировать подключение к каталогу: '.$e->getMessage(), 0, $e);
        }
    }

    private function bindServiceAccount(LdapInterface $ldap): void
    {
        try {
            $ldap->bind($this->settings->bindDn, $this->settings->bindPassword);
        } catch (InvalidCredentialsException $e) {
            $this->logger->error('LDAP: служебная учётная запись отклонена', ['dn' => $this->settings->bindDn]);
            throw new LdapException('Служебная учётная запись LDAP_BIND_DN отклонена каталогом. Проверьте LDAP_BIND_DN и LDAP_BIND_PASSWORD.', 0, $e);
        }
    }

    private function findEntry(LdapInterface $ldap, string $filter): ?Entry
    {
        $attrs = array_values(array_unique(array_filter([$this->settings->uidKey, $this->settings->nameAttr, $this->settings->mailAttr, 'memberOf', 'department', 'cn', 'distinguishedName'])));
        $result = $ldap->query($this->settings->baseDn, $filter, ['filter' => $attrs, 'maxItems' => 2])->execute();
        if (0 === \count($result)) {
            return null;
        }
        if (\count($result) > 1) {
            $this->logger->warning('LDAP: по фильтру найдено несколько записей, используется первая', ['filter' => $filter]);
        }

        return $result[0];
    }

    private function buildInfo(LdapInterface $ldap, string $username, Entry $entry): LdapUserInfo
    {
        $first = static fn (Entry $e, string $attr): ?string => ($e->hasAttribute($attr) && [] !== $e->getAttribute($attr)) ? trim((string) $e->getAttribute($attr)[0]) : null;
        $name = $first($entry, $this->settings->nameAttr) ?? $first($entry, 'cn') ?? $username;
        $groups = array_map('strval', $entry->hasAttribute('memberOf') ? ($entry->getAttribute('memberOf') ?? []) : []);
        $isAdmin = '' !== trim($this->settings->adminGroup) && $this->isMemberOf($ldap, $entry, $groups, $this->settings->adminGroup);

        return new LdapUserInfo(
            $username,
            $entry->getDn(),
            '' !== $name ? $name : $username,
            $first($entry, $this->settings->mailAttr),
            $first($entry, 'department'),
            $groups,
            $isAdmin,
        );
    }

    /**
     * @param list<string> $memberOf
     */
    private function isMemberOf(LdapInterface $ldap, Entry $entry, array $memberOf, string $groupDn): bool
    {
        $needle = mb_strtolower(trim($groupDn));
        foreach ($memberOf as $dn) {
            if (mb_strtolower(trim($dn)) === $needle) {
                return true;
            }
        }
        // Active Directory: проверка вложенных групп одним запросом.
        try {
            $filter = '(&(distinguishedName='.$ldap->escape($entry->getDn(), '', LdapInterface::ESCAPE_FILTER).')('.self::AD_NESTED_RULE.$ldap->escape($groupDn, '', LdapInterface::ESCAPE_FILTER).'))';
            $result = $ldap->query($this->settings->baseDn, $filter, ['filter' => ['cn'], 'maxItems' => 1])->execute();

            return \count($result) > 0;
        } catch (\Throwable $e) {
            $this->logger->debug('LDAP: проверка вложенных групп не поддерживается', ['error' => $e->getMessage()]);

            return false;
        }
    }
}
