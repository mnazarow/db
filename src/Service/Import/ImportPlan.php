<?php

declare(strict_types=1);

namespace App\Service\Import;

/**
 * План импорта: результат сканирования каталога — упорядоченный список папок и файлов
 * с решением по каждому элементу (импортировать / пропустить и почему).
 *
 * Элемент плана — массив:
 *  - type: 'dir' | 'file'
 *  - path: путь относительно импортируемого каталога ('' — сам каталог)
 *  - name: имя папки или файла
 *  - dir:  ключ папки-родителя ('' — корень; null — у корневого элемента)
 *  - depth: относительная глубина папки (0 — корень)
 *  - size: размер файла в байтах
 *  - skip: причина пропуска (см. SKIP_*) или null
 *  - descFile: имя файла с описанием раздела (для папок)
 *  - result: итог выполнения/предпросмотра (см. RESULT_*), message
 */
final class ImportPlan
{
    public const SKIP_EXT = 'ext';
    public const SKIP_BIG = 'big';
    public const SKIP_EMPTY = 'empty';
    public const SKIP_SYSTEM = 'system';
    public const SKIP_LINK = 'link';
    public const SKIP_UNREADABLE = 'unreadable';
    public const SKIP_DESC = 'desc';
    public const SKIP_DEPTH = 'depth';
    public const SKIP_NO_SECTION = 'no_section';

    public const SKIP_LABELS = [
        self::SKIP_EXT => 'расширение не входит в список разрешённых',
        self::SKIP_BIG => 'файл больше лимита размера',
        self::SKIP_EMPTY => 'пустой файл',
        self::SKIP_SYSTEM => 'скрытый или служебный файл',
        self::SKIP_LINK => 'символическая ссылка',
        self::SKIP_UNREADABLE => 'нет прав на чтение',
        self::SKIP_DESC => 'файл описания раздела',
        self::SKIP_DEPTH => 'превышена глубина вложенности разделов — содержимое попадёт в ближайший раздел',
        self::SKIP_NO_SECTION => 'файл в корне каталога, а целевой раздел не выбран',
    ];

    public const RESULT_SECTION_NEW = 'section_new';
    public const RESULT_SECTION_EXISTS = 'section_exists';
    public const RESULT_DOC_NEW = 'doc_new';
    public const RESULT_VERSION_NEW = 'version_new';
    public const RESULT_UNCHANGED = 'unchanged';
    public const RESULT_EXISTS = 'exists';
    public const RESULT_SKIPPED = 'skipped';
    public const RESULT_ERROR = 'error';

    public const RESULT_LABELS = [
        self::RESULT_SECTION_NEW => 'новый раздел',
        self::RESULT_SECTION_EXISTS => 'раздел уже есть',
        self::RESULT_DOC_NEW => 'новый документ',
        self::RESULT_VERSION_NEW => 'новая версия',
        self::RESULT_UNCHANGED => 'без изменений',
        self::RESULT_EXISTS => 'уже есть (обновление отключено)',
        self::RESULT_SKIPPED => 'пропущен',
        self::RESULT_ERROR => 'ошибка',
    ];

    /**
     * @param list<array<string, mixed>> $entries
     */
    public function __construct(
        public readonly string $dir,
        public readonly string $label,
        public array $entries = [],
    ) {
    }

    /** @return array{dirs: int, dirsSkipped: int, files: int, filesSkipped: int, bytes: int, skipped: array<string, int>} */
    public function summary(): array
    {
        $s = ['dirs' => 0, 'dirsSkipped' => 0, 'files' => 0, 'filesSkipped' => 0, 'bytes' => 0, 'skipped' => []];
        foreach ($this->entries as $e) {
            $skip = $e['skip'] ?? null;
            if ('dir' === $e['type']) {
                if (null === $skip) {
                    ++$s['dirs'];
                } else {
                    ++$s['dirsSkipped'];
                    $s['skipped'][$skip] = ($s['skipped'][$skip] ?? 0) + 1;
                }
                continue;
            }
            if (null === $skip) {
                ++$s['files'];
                $s['bytes'] += (int) ($e['size'] ?? 0);
            } else {
                ++$s['filesSkipped'];
                $s['skipped'][$skip] = ($s['skipped'][$skip] ?? 0) + 1;
            }
        }

        return $s;
    }

    public function count(): int
    {
        return \count($this->entries);
    }

    public function absolutePath(array $entry): string
    {
        return '' === $entry['path'] ? $this->dir : $this->dir.'/'.$entry['path'];
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['dir' => $this->dir, 'label' => $this->label, 'entries' => $this->entries];
    }

    /** @param array<string, mixed> $a */
    public static function fromArray(array $a): self
    {
        return new self((string) $a['dir'], (string) ($a['label'] ?? basename((string) $a['dir'])), array_values((array) ($a['entries'] ?? [])));
    }
}
