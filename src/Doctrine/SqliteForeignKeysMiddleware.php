<?php

declare(strict_types=1);

namespace App\Doctrine;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsMiddleware;
use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;

/**
 * SQLite (локальный запуск автотестов) по умолчанию не проверяет внешние ключи и не выполняет
 * ON DELETE CASCADE / SET NULL — в отличие от MySQL и MariaDB, на которых работает портал. Без этого
 * в тестовой базе оставались бы «осиротевшие» строки, которых на настоящем сервере не бывает.
 * На других базах данных ничего не делает.
 */
#[AsMiddleware]
final class SqliteForeignKeysMiddleware implements Middleware
{
    public function wrap(Driver $driver): Driver
    {
        return new class($driver) extends AbstractDriverMiddleware {
            public function connect(#[\SensitiveParameter] array $params): Connection
            {
                $connection = parent::connect($params);
                // Драйвер может быть уже обёрнут другими middleware — смотрим на параметры подключения.
                if (str_contains((string) ($params['driver'] ?? ''), 'sqlite')) {
                    $connection->exec('PRAGMA foreign_keys = ON');
                }

                return $connection;
            }
        };
    }
}
