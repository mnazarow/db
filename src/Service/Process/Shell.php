<?php

declare(strict_types=1);

namespace App\Service\Process;

/**
 * Запуск внешних программ (LibreOffice, tesseract, pdftoppm) с ограничением времени.
 *
 * Аргументы передаются массивом и экранируются: в именах файлов документов встречаются
 * пробелы, кавычки и кириллица, и подстановки оболочки здесь недопустимы.
 */
final class Shell
{
    /** @var array<string, string|null> кэш поиска программ в PATH */
    private static array $found = [];

    /** Полный путь к программе или null, если её нет. */
    public static function resolve(string $bin): ?string
    {
        if ('' === $bin) {
            return null;
        }
        if (str_contains($bin, '/')) {
            return is_file($bin) && is_executable($bin) ? $bin : null;
        }
        if (!\array_key_exists($bin, self::$found)) {
            self::$found[$bin] = self::which($bin);
        }

        return self::$found[$bin];
    }

    public static function exists(string $bin): bool
    {
        return null !== self::resolve($bin);
    }

    /**
     * Запускает программу и возвращает код возврата и вывод.
     *
     * Вывод и поток ошибок читаются одновременно: если программа много пишет в поток ошибок
     * (LibreOffice, tesseract), последовательное чтение приводило бы к взаимной блокировке до тайм-аута.
     * Вывод ограничен $maxOutputBytes: после этого программа останавливается, а truncated = true.
     *
     * @param list<string>          $argv программа и её аргументы
     * @param array<string, string> $env  дополнительные переменные окружения
     *
     * @return array{code: int, out: string, err: string, truncated: bool}
     */
    public static function run(array $argv, int $timeoutSeconds = 60, ?string $cwd = null, array $env = [], int $maxOutputBytes = 67_108_864): array
    {
        $bin = self::resolve($argv[0] ?? '');
        if (null === $bin) {
            return ['code' => 127, 'out' => '', 'err' => 'Программа не найдена: '.($argv[0] ?? ''), 'truncated' => false];
        }
        $argv[0] = $bin;
        $argv = array_map('strval', $argv);
        // Программа запускается без оболочки (массив аргументов): остановка процесса останавливает саму
        // программу (или timeout, который передаёт сигнал ей), а не только промежуточный /bin/sh.
        $timeout = self::resolve('timeout');
        $command = null !== $timeout ? [$timeout, '-k', '5', (string) max(1, $timeoutSeconds), ...$argv] : $argv;
        $descriptors = [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $environment = [] === $env ? null : array_merge(self::environment(), $env);
        $process = @proc_open($command, $descriptors, $pipes, $cwd, $environment);
        if (!\is_resource($process)) {
            return ['code' => 127, 'out' => '', 'err' => 'Не удалось запустить: '.$argv[0], 'truncated' => false];
        }
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $out = '';
        $err = '';
        $truncated = false;
        $open = [1 => $pipes[1], 2 => $pipes[2]];
        // Запасной предел на случай, если программы timeout нет: время работы + 10 секунд.
        $deadline = microtime(true) + max(1, $timeoutSeconds) + 10;
        while ([] !== $open) {
            $read = array_values($open);
            $write = null;
            $except = null;
            if (false === @stream_select($read, $write, $except, 1)) {
                break;
            }
            foreach ($read as $stream) {
                $key = $stream === $pipes[1] ? 1 : 2;
                $chunk = fread($stream, 65536);
                if (false === $chunk || ('' === $chunk && feof($stream))) {
                    unset($open[$key]);
                    continue;
                }
                if (1 === $key) {
                    $out .= $chunk;
                    if (\strlen($out) > $maxOutputBytes) {
                        $out = substr($out, 0, $maxOutputBytes);
                        $truncated = true;
                    }
                } elseif (\strlen($err) < 65536) {
                    $err .= $chunk;
                }
            }
            if ($truncated || microtime(true) > $deadline) {
                // Остального вывода не ждём: останавливаем программу.
                self::stop($process);
                break;
            }
        }
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($process);

        return ['code' => $truncated ? 0 : $code, 'out' => $out, 'err' => $err, 'truncated' => $truncated];
    }

    /**
     * Останавливает процесс: сначала SIGTERM (timeout передаёт его программе), через 5 секунд — SIGKILL.
     *
     * @param resource $process
     */
    private static function stop($process): void
    {
        @proc_terminate($process, 15);
        $until = microtime(true) + 5;
        while (microtime(true) < $until) {
            $status = @proc_get_status($process);
            if (false === $status || !$status['running']) {
                return;
            }
            usleep(100_000);
        }
        @proc_terminate($process, 9);
    }

    /** @return array<string, string> */
    private static function environment(): array
    {
        $env = [];
        foreach (getenv() as $name => $value) {
            if (\is_string($name) && \is_string($value)) {
                $env[$name] = $value;
            }
        }

        return $env;
    }

    private static function which(string $bin): ?string
    {
        $path = (string) (getenv('PATH') ?: '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin');
        foreach (explode(\PATH_SEPARATOR, $path) as $dir) {
            if ('' === $dir) {
                continue;
            }
            $candidate = rtrim($dir, '/').'/'.$bin;
            if (is_file($candidate) && is_executable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }
}
