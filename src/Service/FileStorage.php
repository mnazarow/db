<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\DocumentVersion;
use Symfony\Component\HttpFoundation\File\UploadedFile;

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
        $errors = [];
        if (!$file->isValid()) {
            $errors[] = match ($file->getError()) {
                \UPLOAD_ERR_INI_SIZE, \UPLOAD_ERR_FORM_SIZE => 'Файл превышает лимит размера, установленный на сервере.',
                \UPLOAD_ERR_PARTIAL => 'Файл загружен не полностью — повторите загрузку.',
                \UPLOAD_ERR_NO_FILE => 'Файл не выбран.',
                default => 'Не удалось загрузить файл (код ошибки '.$file->getError().').',
            };

            return $errors;
        }
        if ($file->getSize() > $this->getUploadMaxBytes()) {
            $errors[] = \sprintf('Файл слишком большой (%s). Максимальный размер — %d МБ.', self::humanSize((int) $file->getSize()), $this->uploadMaxMb);
        }
        if (0 === (int) $file->getSize()) {
            $errors[] = 'Файл пустой.';
        }
        $ext = mb_strtolower($file->getClientOriginalExtension());
        if ('' === $ext || !\in_array($ext, $this->allowed, true)) {
            $errors[] = \sprintf('Файлы с расширением «%s» не принимаются. Разрешены: %s.', $ext ?: 'без расширения', implode(', ', $this->allowed));
        }

        return $errors;
    }

    /**
     * Сохраняет файл версии в хранилище и заполняет её атрибуты (имя, путь, размер, тип, контрольная сумма).
     */
    public function store(UploadedFile $file, DocumentVersion $version): void
    {
        $docId = $version->getDocument()->getId();
        if (null === $docId) {
            throw new \LogicException('Документ должен быть сохранён до загрузки файла.');
        }
        $ext = mb_strtolower($file->getClientOriginalExtension());
        $relDir = (string) $docId;
        $dir = $this->getStorageDir().'/'.$relDir;
        if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new \RuntimeException('Не удалось создать каталог хранилища: '.$dir);
        }
        $name = \sprintf('v%03d-%s.%s', $version->getNumber(), bin2hex(random_bytes(6)), $ext);
        $original = $file->getClientOriginalName();
        $mime = $file->getMimeType() ?: $file->getClientMimeType();
        $size = (int) $file->getSize();
        $checksum = hash_file('sha256', $file->getPathname()) ?: null;
        $file->move($dir, $name);
        @chmod($dir.'/'.$name, 0640);

        $version->setOriginalName($original)
            ->setStoredPath($relDir.'/'.$name)
            ->setMimeType($mime)
            ->setSize($size)
            ->setChecksum($checksum);
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
