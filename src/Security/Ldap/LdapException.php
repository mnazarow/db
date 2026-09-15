<?php

declare(strict_types=1);

namespace App\Security\Ldap;

/**
 * Ошибка подключения к каталогу или его настройки (не путать с неверным паролем).
 */
final class LdapException extends \RuntimeException
{
}
