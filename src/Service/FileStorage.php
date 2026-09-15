<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\DocumentVersion;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Mime\MimeTypes;

/**
 * Файловое хранилище версий документов: каталог STORAGE_DIR/<id документа>/<номер версии>-<случайно>.<расширение>.
 * Каталог находится вне public/, файлы отдаются только через контроллер с проверкой прав.
 */
final class FileStorage
{
    /** @var list<string> */
    private array $allowed;

    public function __construct(
        private readonly string $storageDir,
        private readonly int $uploadMaxMb,
        string $allowedExtensions,
    ) {
        $this->allowed = array_values(array_filter(array_map(static fn (string $e) => mb_strtolower(trim($e, " .\t")), explode(',', $allowedExtensions))));
    }

    public function getStorageDir(): string
    {
        return rtrim($this->storageDir, '/');
    }

    public function getUploadMaxMb(): int
    {
        return $this->uploadMaxMb;
    }

    public function getUploadMaxBytes(): int
    {
        return $this->uploadMaxMb * 1024 * 1024;
    }

    /** @return list<string> */
    public function getAllowedExtensions(): array
    {
        return $this->allowed;
    }

    /**
     * Проверяет загруженный файл (размер, расширение) и возвращает список ошибок.
     *
     * @return list<string>
     */
    public function validate(UploadedFile $file): array
    {
        if (!$file->isValid()) {
            return [match ($file->getError()) {
                \UPLOAD_ERR_INI_SIZE, \UPLOAD_ERR_FORM_SIZE => 'Файл превышает лимит размера, установленный на сервере.',
                \UPLOAD_ERR_PARTIAL => 'Файл загружен не полностью — повторите загрузку.',
                \UPLOAD_ERR_NO_FILE => 'Файл не выбран.',
                default => 'Не удалось загрузить файл (код ошибки '.$file->getError().').',
            }];
        }

        return $this->validateAttributes((int) $file->getSize(), mb_strtolower($file->getClientOriginalExtension()));
    }

    /**
     * Проверяет файл на диске сервера (импорт из каталога): существование, размер, расширение.
     *
     * @return list<string>
     */
    public function validatePath(string $path, ?string $originalName = null, bool $anyExtension = false): array
    {
        if (!is_file($path) || !is_readable($path)) {
            return ['Файл не найден или недоступен для чтения: '.$path];
        }
        $ext = mb_strtolower(pathinfo($originalName ?? $path, \PATHINFO_EXTENSION));

        return $this->validateAttributes((int) filesize($path), $ext, $anyExtension);
    }

    public function isExtensionAllowed(string $ext): bool
    {
        return \in_array(mb_strtolower(ltrim($ext, '.')), $this->allowed, true);
    }

    /** @return list<string> */
    private function validateAttributes(int $size, string $ext, bool $anyExtension = false): array
    {
        $errors = [];
        if ($size > $this->getUploadMaxBytes()) {
            $errors[] = \sprintf('Файл слишком большой (%s). Максимальный размер — %d МБ.', self::humanSize($size), $this->uploadMaxMb);
        }
        if (0 === $size) {
            $errors[] = 'Файл пустой.';
        }
        if (!$anyExtension && ('' === $ext || !\in_array($ext, $this->allowed, true))) {
            $errors[] = \sprintf('Файлы с расширением «%s» не принимаются. Разрешены: %s.', $ext ?: 'без расширения', implode(', ', $this->allowed));
        }

        return $errors;
    }

    /**
     * Сохраняет загруженный файл версии в хранилище и заполняет её атрибуты (имя, путь, размер, тип, контрольная сумма).
     */
    public function store(UploadedFile $file, DocumentVersion $version): void
    {
        $mime = $file->getMimeType() ?: $file->getClientMimeType();
        $this->place($file->getPathname(), $file->getClientOriginalName(), $mime, $version, true);
    }

    /**
     * Сохраняет в хранилище файл, уже лежащий на диске сервера (импорт из каталога).
     * При $move = true исходный файл переносится, иначе копируется.
     */
    public function storeFromPath(string $path, string $originalName, DocumentVersion $version, bool $move = false): void
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new \RuntimeException('Файл не найден или недоступен для чтения: '.$path);
        }
        $this->place($path, $originalName, self::guessMimeType($path), $version, $move);
    }

    private function place(string $sourcePath, string $originalName, ?string $mime, DocumentVersion $version, bool $move): void
    {
        $docId = $version->getDocument()->getId();
        if (null === $docId) {
            throw new \LogicException('Документ должен быть сохранён до загрузки файла.');
        }
        $ext = mb_strtolower(pathinfo($originalName, \PATHINFO_EXTENSION));
        $relDir = (string) $docId;
        $dir = $this->getStorageDir().'/'.$relDir;
        if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new \RuntimeException('Не удалось создать каталог хранилища: '.$dir);
        }
        $name = \sprintf('v%03d-%s%s', $version->getNumber(), bin2hex(random_bytes(6)), '' !== $ext ? '.'.$ext : '');
        $size = (int) filesize($sourcePath);
        $checksum = hash_file('sha256', $sourcePath) ?: null;
        $target = $dir.'/'.$name;
        $done = $move ? @rename($sourcePath, $target) : @copy($sourcePath, $target);
        if (!$done && $move) {
            // rename не работает между файловыми системами — копируем и удаляем исходник.
            $done = @copy($sourcePath, $target) && @unlink($sourcePath);
        }
        if (!$done) {
            @unlink($target);
            throw new \RuntimeException('Не удалось записать файл в хранилище: '.$target);
        }
        @chmod($target, 0640);

        $version->setOriginalName($originalName)
            ->setStoredPath($relDir.'/'.$name)
            ->setMimeType($mime)
            ->setSize($size)
            ->setChecksum($checksum);
    }

    /** Определяет MIME-тип файла по содержимому (с запасным вариантом по расширению). */
    public static function guessMimeType(string $path): ?string
    {
        try {
            $mime = MimeTypes::getDefault()->guessMimeType($path);
        } catch (\Throwable) {
            $mime = null;
        }
        if (null === $mime || '' === $mime) {
            $ext = mb_strtolower(pathinfo($path, \PATHINFO_EXTENSION));
            $mime = MimeTypes::getDefault()->getMimeTypes($ext)[0] ?? 'application/octet-stream';
        }

        return $mime;
    }

    /** Копирует файл существующей версии в новую версию (восстановление старой версии). */
    public function copyVersionFile(DocumentVersion $from, DocumentVersion $to): void
    {
        $src = $this->absolutePath($from);
        if (null === $src || !is_file($src)) {
            throw new \RuntimeException('Файл исходной версии не найден в хранилище.');
        }
        $ext = $from->getExtension();
        $relDir = (string) $to->getDocument()->getId();
        $dir = $this->getStorageDir().'/'.$relDir;
        if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new \RuntimeException('Не удалось создать каталог хранилища: '.$dir);
        }
        $name = \sprintf('v%03d-%s%s', $to->getNumber(), bin2hex(random_bytes(6)), '' !== $ext ? '.'.$ext : '');
        if (!copy($src, $dir.'/'.$name)) {
            throw new \RuntimeException('Не удалось скопировать файл версии.');
        }
        $to->setOriginalName($from->getOriginalName())
            ->setStoredPath($relDir.'/'.$name)
            ->setMimeType($from->getMimeType())
            ->setSize($from->getSize())
            ->setChecksum($from->getChecksum());
    }

    public function absolutePath(DocumentVersion $version): ?string
    {
        $rel = $version->getStoredPath();
        if (null === $rel || '' === $rel || str_contains($rel, '..')) {
            return null;
        }

        return $this->getStorageDir().'/'.$rel;
    }

    public function exists(DocumentVersion $version): bool
    {
        $path = $this->absolutePath($version);

        return null !== $path && is_file($path);
    }

    public function delete(DocumentVersion $version): void
    {
        $path = $this->absolutePath($version);
        if (null !== $path && is_file($path)) {
            @unlink($path);
        }
    }

    /** Удаляет каталог документа целиком (после удаления документа). */
    public function deleteDocumentDir(int $documentId): void
    {
        $dir = $this->getStorageDir().'/'.$documentId;
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $f) {
            if ('.' !== $f && '..' !== $f) {
                @unlink($dir.'/'.$f);
            }
        }
        @rmdir($dir);
    }

    /** Файлы, которые можно показать в браузере (предпросмотр). */
    public static function isInlineViewable(?string $mime, string $ext): bool
    {
        return 'pdf' === $ext || \in_array($ext, ['png', 'jpg', 'jpeg', 'gif', 'svg', 'txt', 'md', 'csv'], true)
            || (null !== $mime && (str_starts_with($mime, 'image/') || 'application/pdf' === $mime || 'text/plain' === $mime));
    }

    public static function humanSize(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes.' Б';
        }
        $units = ['КБ', 'МБ', 'ГБ', 'ТБ'];
        $value = $bytes / 1024;
        $i = 0;
        while ($value >= 1024 && $i < \count($units) - 1) {
            $value /= 1024;
            ++$i;
        }

        return number_format($value, $value >= 100 || $i === 0 ? 0 : 1, ',', ' ').' '.$units[$i];
    }
}
