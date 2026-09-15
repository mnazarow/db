<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entity\User;
use App\Security\Ldap\LdapSettings;
use PHPUnit\Framework\TestCase;

final class LdapSettingsTest extends TestCase
{
    private function settings(string $suffix = 'example.local', string $bindDn = '', string $filter = ''): LdapSettings
    {
        return new LdapSettings(true, 'dc', 389, 'none', 'DC=example,DC=local', $bindDn, '', $suffix, 'sAMAccountName', $filter, 'displayName', 'mail', '', '');
    }

    public function testBindName(): void
    {
        self::assertSame('ivanov@example.local', $this->settings()->bindNameFor('ivanov'));
        self::assertSame('EXAMPLE\\ivanov', $this->settings('EXAMPLE\\')->bindNameFor('ivanov'));
        self::assertSame('ivanov', $this->settings('')->bindNameFor('ivanov'));
    }

    public function testFilter(): void
    {
        self::assertSame('(sAMAccountName=ivanov)', $this->settings()->searchFilter('ivanov'));
        self::assertSame('(&(objectClass=user)(sAMAccountName=ivanov))', $this->settings('', '', '(&(objectClass=user)({uid_key}={username}))')->searchFilter('ivanov'));
        self::assertTrue($this->settings('', 'CN=svc,DC=example,DC=local')->usesServiceAccount());
        self::assertFalse($this->settings()->usesServiceAccount());
    }

    public function testUsernameNormalization(): void
    {
        self::assertSame('ivanov', User::normalizeUsername('  IVANOV '));
        self::assertSame('ivanov', User::normalizeUsername('EXAMPLE\\Ivanov'));
        self::assertSame('ivanov@example.local', User::normalizeUsername('Ivanov@example.local'));
    }
}
