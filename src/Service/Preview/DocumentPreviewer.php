<?php

declare(strict_types=1);

namespace App\Service\Preview;

use App\Entity\DocumentVersion;
use App\Service\FileStorage;
use App\Service\Process\Shell;
use Psr\Log\LoggerInterface;

/**
 * Предпросмотр офисных файлов в браузере: LibreOffice в пакетном режиме переводит файл в PDF,
 * результат кладётся в кэш и отдаётся встроенным просмотрщиком браузера.
 *
 * Отдельный сервер документов (ONLYOFFICE, Р7) не нужен — достаточно пакета libreoffice
 * на том же сервере. Правки в браузере при этом невозможны: это именно просмотр.
 */
final class DocumentPreviewer
{
    /** Форматы, которые LibreOffice переводит в PDF. */
    public const CONVERTIBLE = ['doc', 'docx', 'odt', 'rtf', 'xls', 'xlsx', 'ods', 'ppt', 'pptx', 'odp', 'odg', 'dotx', 'xltx', 'potx'];

    /** Больше этого файлы не конвертируем: страница ждала бы минутами. */
    public const MAX_INPUT_BYTES = 50 * 1024 * 1024;

    /**
     * Сколько помнить неудачную конвертацию (повреждённый или защищённый паролем файл, тайм-аут):
     * иначе каждый просмотр страницы снова запускал бы LibreOffice на минуты.
     */
    public const FAILURE_TTL_SECONDS = 12 * 3600;

    /** Временные каталоги конвертации старше этого остались от прерванных процессов — их можно удалять. */
    private const STALE_WORK_SECONDS = 3600;

    private ?bool $available = null;

    public function __construct(
        private readonly FileStorage $storage,
        private readonly LoggerInterface $logger,
        private readonly string $previewDir,
        private readonly string $sofficeBin = 'soffice',
        private readonly int $timeoutSeconds = 120,
    ) {
    }

    /** Установлен ли LibreOffice. */
    public function isAvailable(): bool
    {
        return $this->available ??= Shell::exists($this->sofficeBin);
    }

    public function binary(): string
    {
        return Shell::resolve($this->sofficeBin) ?? $this->sofficeBin;
    }

    /** Можно ли показать эту версию в браузере через конвертацию (PDF и картинки показываются и без неё). */
    public function supports(DocumentVersion $version): bool
    {
        return $version->isFile() && \in_array(mb_strtolower($version->getExtension()), self::CONVERTIBLE, true);
    }

    /** Готов ли предпросмотр (без запуска конвертации) — чтобы не ждать в шаблоне. */
    public function isReady(DocumentVersion $version): bool
    {
        $path = $this->cachePath($version);

        return null !== $path && is_file($path);
    }

    /** Недавняя конвертация этой версии не удалась — повторять её пока не нужно. */
    public function hasFailed(DocumentVersion $version): bool
    {
        $path = $this->cachePath($version);

        return null !== $path && $this->failedRecently($path);
    }

    /**
     * Путь к PDF-предпросмотру версии; при необходимости запускает конвертацию.
     * Возвращает null, если предпросмотр невозможен (нет LibreOffice, формат не тот, ошибка).
     */
    public function pdf(DocumentVersion $version): ?string
    {
        if (!$this->supports($version) || !$this->isAvailable()) {
            return null;
        }
        $cache = $this->cachePath($version);
        $source = $this->storage->absolutePath($version);
        if (null === $cache || null === $source || !is_file($source)) {
            return null;
        }
        if (is_file($cache)) {
            return $cache;
        }
        if ($this->failedRecently($cache)) {
            return null;
        }
        if (filesize($source) > self::MAX_INPUT_BYTES) {
            $this->logger->info('Предпросмотр пропущен: файл слишком большой', ['version' => $version->getId(), 'bytes' => filesize($source)]);

            return null;
        }

        return $this->convert($source, $cache, $version);
    }

    /** Удаляет готовые предпросмотры документа (при удалении документа или версии). */
    public function forget(int $documentId): void
    {
        $this->removeTree($this->previewDir.'/'.$documentId);
    }

    /**
     * Полностью очищает кэш предпросмотров (файлы соберутся заново при обращении), вместе с отметками
     * о неудачных конвертациях и временными каталогами прерванных процессов.
     */
    public function clear(): void
    {
        foreach (glob($this->previewDir.'/*', \GLOB_ONLYDIR) ?: [] as $dir) {
            $this->removeTree($dir);
        }
    }

    /** @return array{files: int, bytes: int} сколько места занимают готовые предпросмотры */
    public function usage(): array
    {
        $files = 0;
        $bytes = 0;
        foreach (glob($this->previewDir.'/*/*.pdf') ?: [] as $file) {
            ++$files;
            $bytes += (int) @filesize($file);
        }

        return ['files' => $files, 'bytes' => $bytes];
    }

    private function cachePath(DocumentVersion $version): ?string
    {
        $documentId = $version->getDocument()->getId();
        $versionId = $version->getId();
        if (null === $documentId || null === $versionId) {
            return null;
        }
        // В имени — контрольная сумма файла: после замены файла версии старый предпросмотр не подойдёт.
        $checksum = substr((string) $version->getChecksum(), 0, 16) ?: 'nochecksum';

        return \sprintf('%s/%d/%d-%s.pdf', $this->previewDir, $documentId, $versionId, $checksum);
    }

    private function failedRecently(string $cache): bool
    {
        $mtime = @filemtime($cache.'.failed');

        return false !== $mtime && time() - $mtime < self::FAILURE_TTL_SECONDS;
    }

    private function convert(string $source, string $cache, DocumentVersion $version): ?string
    {
        $dir = \dirname($cache);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            $this->logger->warning('Не удалось создать каталог предпросмотра', ['dir' => $dir]);

            return null;
        }
        // Замок: параллельные запросы к одному документу не должны запускать несколько LibreOffice.
        $lock = @fopen($cache.'.lock', 'c');
        if (false !== $lock) {
            flock($lock, \LOCK_EX);
            // Пока ждали замок, другой запрос мог уже подготовить предпросмотр — или убедиться, что это невозможно.
            if (is_file($cache) || $this->failedRecently($cache)) {
                flock($lock, \LOCK_UN);
                fclose($lock);

                return is_file($cache) ? $cache : null;
            }
        }
        $this->removeStaleWork($dir);
        $work = $dir.'/tmp-'.bin2hex(random_bytes(6));
        $profile = $work.'/profile';
        $result = null;
        try {
            if (!@mkdir($work, 0700, true)) {
                return null;
            }
            // Копия с правильным расширением: LibreOffice выбирает фильтр по имени файла,
            // а в хранилище файлы лежат под служебными именами.
            $input = $work.'/'.bin2hex(random_bytes(4)).'.'.mb_strtolower($version->getExtension());
            if (!@copy($source, $input)) {
                return null;
            }
            $run = Shell::run([
                $this->sofficeBin,
                '-env:UserInstallation=file://'.$profile,
                '--headless', '--norestore', '--nolockcheck', '--nodefault', '--nofirststartwizard',
                '--convert-to', 'pdf',
                '--outdir', $work,
                $input,
            ], $this->timeoutSeconds, $work, ['HOME' => $work, 'TMPDIR' => $work]);
            $produced = preg_replace('/\.[^.]+$/', '.pdf', $input);
            if (null === $produced || !is_file($produced) || filesize($produced) < 5) {
                $this->logger->warning('Не удалось подготовить предпросмотр', [
                    'version' => $version->getId(),
                    'code' => $run['code'],
                    'error' => mb_substr(trim($run['err'].' '.$run['out']), 0, 300),
                ]);
                // Отметка о неудаче: повторная попытка — не раньше чем через FAILURE_TTL_SECONDS
                // (или после очистки кэша предпросмотров в панели администратора).
                @touch($cache.'.failed');

                return null;
            }
            // Запись «через временный файл»: читатель не должен увидеть недописанный PDF.
            if (@rename($produced, $cache)) {
                $result = $cache;
                @unlink($cache.'.failed');
            }
        } finally {
            $this->removeTree($work);
            if (false !== $lock) {
                flock($lock, \LOCK_UN);
                fclose($lock);
                @unlink($cache.'.lock');
            }
        }

        return $result;
    }

    /**
     * Удаляет временные каталоги конвертации, оставшиеся от прерванных процессов (например, когда
     * PHP-FPM остановил слишком долгий запрос и блок finally не выполнился).
     */
    private function removeStaleWork(string $dir): void
    {
        foreach (glob($dir.'/tmp-*', \GLOB_ONLYDIR) ?: [] as $work) {
            $mtime = @filemtime($work);
            if (false !== $mtime && time() - $mtime > self::STALE_WORK_SECONDS) {
                $this->removeTree($work);
            }
        }
    }

    private function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($dir);
    }
}
