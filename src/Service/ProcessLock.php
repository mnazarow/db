<?php

declare(strict_types=1);

namespace App\Service;

use Doctrine\DBAL\Connection;

/**
 * Блокировка «одна рассылка за раз» для заданий, которые запускают и cron, и кнопка в панели администратора.
 *
 * Блокировка — в базе данных (GET_LOCK MySQL/MariaDB): в Docker задание cron и веб-интерфейс работают в разных
 * контейнерах и файловых блокировок друг друга не видят. На базе без GET_LOCK (SQLite в разработке и тестах) —
 * файл в var/. Блокировка снимается вызовом возвращённой функции или сама при завершении процесса.
 */
final class ProcessLock
{
    public function __construct(
        private readonly Connection $connection,
        private readonly string $projectDir = '',
    ) {
    }

    /**
     * Захватить блокировку без ожидания.
     *
     * @return (\Closure(): void)|null функция снятия блокировки; null — она уже у другого процесса
     */
    public function acquire(string $name): ?\Closure
    {
        $lockName = $this->lockName($name);
        try {
            if (1 !== (int) $this->connection->fetchOne('SELECT GET_LOCK(?, 0)', [$lockName])) {
                return null;
            }

            return function () use ($lockName): void {
                try {
                    $this->connection->fetchOne('SELECT RELEASE_LOCK(?)', [$lockName]);
                } catch (\Throwable) {
                    // Блокировка снимется сама при закрытии соединения.
                }
            };
        } catch (\Throwable) {
            // База без GET_LOCK — блокировка файлом.
        }
        $file = @fopen(rtrim('' !== $this->projectDir ? $this->projectDir.'/var' : sys_get_temp_dir(), '/').'/'.$name.'.lock', 'c');
        if (false === $file) {
            return null;
        }
        if (!flock($file, \LOCK_EX | \LOCK_NB)) {
            fclose($file);

            return null;
        }

        return static function () use ($file): void {
            flock($file, \LOCK_UN);
            fclose($file);
        };
    }

    /** В имени — имя базы: несколько порталов на одном сервере MySQL друг другу не мешают (не длиннее 64 символов). */
    private function lockName(string $name): string
    {
        $database = '';
        try {
            $database = (string) $this->connection->getDatabase();
        } catch (\Throwable) {
        }

        return mb_substr('docportal:'.$name.':'.$database, 0, 64);
    }
}
