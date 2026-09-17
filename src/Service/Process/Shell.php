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
     * @param list<string>          $argv программа и её аргументы
     * @param array<string, string> $env  дополнительные переменные окружения
     *
     * @return array{code: int, out: string, err: string}
     */
    public static function run(array $argv, int $timeoutSeconds = 60, ?string $cwd = null, array $env = []): array
    {
        $bin = self::resolve($argv[0] ?? '');
        if (null === $bin) {
            return ['code' => 127, 'out' => '', 'err' => 'Программа не найдена: '.($argv[0] ?? '')];
        }
        $argv[0] = $bin;
        $command = implode(' ', array_map('escapeshellarg', $argv));
        if (self::exists('timeout')) {
            $command = 'timeout -k 5 '.max(1, $timeoutSeconds).' '.$command;
        }
        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $environment = [] === $env ? null : array_merge(self::environment(), $env);
        $process = @proc_open($command, $descriptors, $pipes, $cwd, $environment);
        if (!\is_resource($process)) {
            return ['code' => 127, 'out' => '', 'err' => 'Не удалось запустить: '.$argv[0]];
        }
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return ['code' => proc_close($process), 'out' => $out, 'err' => $err];
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
