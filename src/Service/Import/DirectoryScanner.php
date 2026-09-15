<?php

declare(strict_types=1);

namespace App\Service\Import;

use App\Entity\Section;
use App\Service\FileStorage;

/**
 * Сканирует каталог на диске и строит план импорта: папки → разделы, файлы → документы.
 *
 * Правила:
 *  - папки и файлы обходятся в естественном порядке имён (1, 2, 10, а не 1, 10, 2);
 *  - скрытые и служебные элементы (.*, ~$*, Thumbs.db, desktop.ini, __MACOSX) пропускаются;
 *  - символические ссылки не разворачиваются;
 *  - файл README.txt / README.md / описание.txt в папке становится описанием раздела;
 *  - если вложенность папок глубже, чем допускает портал, файлы глубоких папок
 *    попадают в самый глубокий допустимый раздел.
 */
final class DirectoryScanner
{
    /** Имена файлов (без расширения, в нижнем регистре), содержимое которых становится описанием раздела. */
    public const DESCRIPTION_STEMS = ['readme', 'описание', 'description', '_описание'];
    public const DESCRIPTION_EXTENSIONS = ['txt', 'md'];

    private const SYSTEM_NAMES = ['thumbs.db', 'desktop.ini', '__macosx', '.ds_store'];

    public function __construct(private readonly FileStorage $storage)
    {
    }

    /**
     * @param int $targetDepth глубина целевого раздела (-1 — верхний уровень, т.е. новые разделы получат глубину 0)
     */
    public function scan(string $dir, ImportOptions $options, int $targetDepth = -1): ImportPlan
    {
        $real = realpath($dir);
        if (false === $real || !is_dir($real)) {
            throw new \InvalidArgumentException('Каталог не найден: '.$dir);
        }
        if (!is_readable($real)) {
            throw new \InvalidArgumentException('Нет прав на чтение каталога: '.$dir);
        }
        $plan = new ImportPlan($real, basename($real));
        $maxDepth = Section::MAX_DEPTH - 1; // максимальная допустимая глубина раздела

        // Глубина раздела, соответствующего корню импорта.
        $rootDepth = $options->rootAsSection ? $targetDepth + 1 : $targetDepth;
        $rootHasSection = $options->rootAsSection || null !== $options->targetSectionId;

        if ($options->rootAsSection) {
            $entry = ['type' => 'dir', 'path' => '', 'name' => self::sectionName(basename($real)), 'dir' => null, 'depth' => 0, 'skip' => null, 'descFile' => null];
            if ($rootDepth > $maxDepth) {
                throw new \InvalidArgumentException(\sprintf('Целевой раздел уже находится на максимальной глубине (%d уровней) — создать в нём раздел нельзя.', Section::MAX_DEPTH));
            }
            $plan->entries[] = $entry;
            $this->walk($plan, $options, $real, '', \count($plan->entries) - 1, 0, $rootDepth, $maxDepth, $rootHasSection, '');
        } else {
            $this->walk($plan, $options, $real, '', null, 0, $rootDepth, $maxDepth, $rootHasSection, '');
        }

        return $plan;
    }

    /**
     * @param int|null $dirIndex   индекс элемента плана для этой папки (null — папка не становится разделом)
     * @param string   $sectionKey ключ папки, в раздел которой попадут файлы этой папки (с учётом «сплющивания» глубоких папок)
     */
    private function walk(ImportPlan $plan, ImportOptions $options, string $abs, string $rel, ?int $dirIndex, int $relDepth, int $sectionDepth, int $maxDepth, bool $hasSection, string $sectionKey): void
    {
        $names = @scandir($abs, \SCANDIR_SORT_NONE);
        if (false === $names) {
            return;
        }
        $files = [];
        $dirs = [];
        foreach ($names as $name) {
            if ('.' === $name || '..' === $name) {
                continue;
            }
            $path = $abs.'/'.$name;
            if (is_link($path)) {
                $plan->entries[] = ['type' => 'file', 'path' => ltrim($rel.'/'.$name, '/'), 'name' => $name, 'dir' => $sectionKey, 'size' => 0, 'skip' => ImportPlan::SKIP_LINK];
                continue;
            }
            if (is_dir($path)) {
                $dirs[] = $name;
            } elseif (is_file($path)) {
                $files[] = $name;
            }
        }
        usort($files, 'strnatcasecmp');
        usort($dirs, 'strnatcasecmp');

        // Файл описания раздела — только у папок, которые становятся разделами.
        $descFile = null;
        if (null !== $dirIndex) {
            foreach ($files as $name) {
                if (self::isDescriptionFile($name)) {
                    $descFile = $name;
                    break;
                }
            }
            $plan->entries[$dirIndex]['descFile'] = $descFile;
        }

        foreach ($files as $name) {
            $path = $abs.'/'.$name;
            $entry = ['type' => 'file', 'path' => ltrim($rel.'/'.$name, '/'), 'name' => $name, 'dir' => $sectionKey, 'size' => (int) @filesize($path), 'skip' => null];
            if ($name === $descFile) {
                $entry['skip'] = ImportPlan::SKIP_DESC;
            } elseif (self::isSystemName($name)) {
                $entry['skip'] = ImportPlan::SKIP_SYSTEM;
            } elseif (!$hasSection) {
                $entry['skip'] = ImportPlan::SKIP_NO_SECTION;
            } elseif (!is_readable($path)) {
                $entry['skip'] = ImportPlan::SKIP_UNREADABLE;
            } elseif (0 === $entry['size']) {
                $entry['skip'] = ImportPlan::SKIP_EMPTY;
            } elseif ($entry['size'] > $this->storage->getUploadMaxBytes()) {
                $entry['skip'] = ImportPlan::SKIP_BIG;
            } elseif (!$options->anyExtension && !$this->storage->isExtensionAllowed(pathinfo($name, \PATHINFO_EXTENSION))) {
                $entry['skip'] = ImportPlan::SKIP_EXT;
            }
            $plan->entries[] = $entry;
        }

        foreach ($dirs as $name) {
            if (self::isSystemName($name)) {
                $plan->entries[] = ['type' => 'dir', 'path' => ltrim($rel.'/'.$name, '/'), 'name' => $name, 'dir' => $sectionKey, 'depth' => $relDepth + 1, 'skip' => ImportPlan::SKIP_SYSTEM, 'descFile' => null];
                continue;
            }
            $childRel = ltrim($rel.'/'.$name, '/');
            $childDepth = $sectionDepth + 1;
            $entry = ['type' => 'dir', 'path' => $childRel, 'name' => self::sectionName($name), 'dir' => $sectionKey, 'depth' => $relDepth + 1, 'skip' => null, 'descFile' => null];
            $tooDeep = $childDepth > $maxDepth;
            if ($tooDeep) {
                $entry['skip'] = ImportPlan::SKIP_DEPTH;
            }
            $plan->entries[] = $entry;
            $index = \count($plan->entries) - 1;
            // Файлы слишком глубокой папки попадают в ближайший допустимый раздел (ключ родителя).
            $this->walk($plan, $options, $abs.'/'.$name, $childRel, $tooDeep ? null : $index, $relDepth + 1, $tooDeep ? $sectionDepth : $childDepth, $maxDepth, true, $tooDeep ? $sectionKey : $childRel);
        }
    }

    public static function isSystemName(string $name): bool
    {
        $lower = mb_strtolower($name);

        return str_starts_with($name, '.') || str_starts_with($name, '~$') || \in_array($lower, self::SYSTEM_NAMES, true);
    }

    public static function isDescriptionFile(string $name): bool
    {
        $stem = mb_strtolower(pathinfo($name, \PATHINFO_FILENAME));
        $ext = mb_strtolower(pathinfo($name, \PATHINFO_EXTENSION));

        return \in_array($stem, self::DESCRIPTION_STEMS, true) && \in_array($ext, self::DESCRIPTION_EXTENSIONS, true);
    }

    /** Название раздела из имени папки: подчёркивания → пробелы, лишние пробелы убираются, длина ограничивается. */
    public static function sectionName(string $folder): string
    {
        $name = preg_replace('/_+/u', ' ', $folder) ?? $folder;
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? $name);

        return mb_substr('' !== $name ? $name : $folder, 0, 128);
    }

    /** Название документа из имени файла: без расширения, подчёркивания → пробелы. */
    public static function documentTitle(string $fileName): string
    {
        $stem = pathinfo($fileName, \PATHINFO_FILENAME);
        $title = preg_replace('/_+/u', ' ', $stem) ?? $stem;
        $title = trim(preg_replace('/\s+/u', ' ', $title) ?? $title);

        return mb_substr('' !== $title ? $title : $fileName, 0, 255);
    }

    /** Текст описания раздела из файла (без BOM, не длиннее 4000 символов). */
    public static function readDescription(string $path): ?string
    {
        $text = @file_get_contents($path, false, null, 0, 65536);
        if (false === $text) {
            return null;
        }
        $text = preg_replace('/^\xEF\xBB\xBF/', '', $text) ?? $text;
        if (!mb_check_encoding($text, 'UTF-8')) {
            $converted = @iconv('CP1251', 'UTF-8//IGNORE', $text);
            $text = false !== $converted ? $converted : '';
        }
        $text = trim(str_replace("\r\n", "\n", $text));

        return '' === $text ? null : mb_substr($text, 0, 4000);
    }

    /**
     * Подкаталоги каталога импорта с числом папок, файлов и общим размером (для выбора в панели администратора).
     *
     * @return list<array{name: string, dirs: int, files: int, bytes: int, mtime: int}>
     */
    public function listSubdirectories(string $importDir): array
    {
        $out = [];
        $names = @scandir($importDir, \SCANDIR_SORT_NONE) ?: [];
        usort($names, 'strnatcasecmp');
        foreach ($names as $name) {
            $path = $importDir.'/'.$name;
            if ('.' === $name || '..' === $name || self::isSystemName($name) || is_link($path) || !is_dir($path)) {
                continue;
            }
            $stat = $this->countTree($path);
            $out[] = ['name' => $name, 'dirs' => $stat['dirs'], 'files' => $stat['files'], 'bytes' => $stat['bytes'], 'mtime' => (int) @filemtime($path)];
        }

        return $out;
    }

    /** @return array{dirs: int, files: int, bytes: int} */
    public function countTree(string $dir, int $limit = 100000): array
    {
        $stat = ['dirs' => 0, 'files' => 0, 'bytes' => 0];
        $stack = [$dir];
        $seen = 0;
        while (null !== ($current = array_pop($stack))) {
            foreach (@scandir($current, \SCANDIR_SORT_NONE) ?: [] as $name) {
                if ('.' === $name || '..' === $name || self::isSystemName($name)) {
                    continue;
                }
                if (++$seen > $limit) {
                    return $stat;
                }
                $path = $current.'/'.$name;
                if (is_link($path)) {
                    continue;
                }
                if (is_dir($path)) {
                    ++$stat['dirs'];
                    $stack[] = $path;
                } elseif (is_file($path)) {
                    ++$stat['files'];
                    $stat['bytes'] += (int) @filesize($path);
                }
            }
        }

        return $stat;
    }

    /** Файлы непосредственно в каталоге (без подкаталогов). @return array{files: int, bytes: int} */
    public function countRootFiles(string $dir): array
    {
        $stat = ['files' => 0, 'bytes' => 0];
        foreach (@scandir($dir, \SCANDIR_SORT_NONE) ?: [] as $name) {
            $path = $dir.'/'.$name;
            if ('.' === $name || '..' === $name || self::isSystemName($name) || is_link($path) || !is_file($path)) {
                continue;
            }
            ++$stat['files'];
            $stat['bytes'] += (int) @filesize($path);
        }

        return $stat;
    }
}
