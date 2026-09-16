<?php

declare(strict_types=1);

namespace App\Service\Text;

use App\Entity\DocumentVersion;
use App\Service\FileStorage;
use Psr\Log\LoggerInterface;

/**
 * Извлечение текста из документов — для API (индексация в RAG) и для описаний через LLM.
 *
 * Страницы: текст без разметки. Файлы: txt/md/csv/json/xml/html — как есть; PDF — через pdftotext (poppler-utils);
 * docx/xlsx/pptx и odt/ods/odp — разбор XML внутри zip-архива. Прочие форматы (doc, xls, ppt, rtf, изображения)
 * не поддерживаются. Результат кэшируется на диске по версии документа и контрольной сумме файла.
 */
final class TextExtractor
{
    public const STATUS_OK = 'ok';
    public const STATUS_EMPTY = 'empty';             // текста не найдено (например, PDF из сканов)
    public const STATUS_UNSUPPORTED = 'unsupported'; // формат не поддерживается
    public const STATUS_MISSING = 'missing';         // файл отсутствует в хранилище
    public const STATUS_ERROR = 'error';

    private const PLAIN = ['txt', 'md', 'markdown', 'csv', 'tsv', 'json', 'xml', 'yaml', 'yml', 'log', 'ini', 'conf', 'sql', 'rst'];
    private const HTML = ['html', 'htm', 'xhtml'];
    private const OFFICE = ['docx', 'xlsx', 'pptx', 'odt', 'ods', 'odp'];

    /** Максимальный размер извлекаемого текста (символов). */
    public const MAX_CHARS = 2_000_000;

    private ?bool $pdftotext = null;

    public function __construct(
        private readonly FileStorage $storage,
        private readonly LoggerInterface $logger,
        private readonly string $cacheDir,
        private readonly string $pdftotextBin = 'pdftotext',
    ) {
    }

    /** Поддерживается ли извлечение текста для файла с таким расширением. */
    public function supports(string $extension): bool
    {
        $ext = strtolower(ltrim($extension, '.'));

        return \in_array($ext, self::PLAIN, true) || \in_array($ext, self::HTML, true) || \in_array($ext, self::OFFICE, true) || ('pdf' === $ext && $this->hasPdftotext());
    }

    /** @return list<string> расширения, из которых портал умеет извлекать текст */
    public function supportedExtensions(): array
    {
        $list = array_merge(self::PLAIN, self::HTML, self::OFFICE);
        if ($this->hasPdftotext()) {
            $list[] = 'pdf';
        }
        sort($list);

        return $list;
    }

    public function hasPdftotext(): bool
    {
        if (null === $this->pdftotext) {
            $this->pdftotext = false;
            if (\function_exists('exec')) {
                $bin = str_contains($this->pdftotextBin, '/') ? $this->pdftotextBin : (self::which($this->pdftotextBin) ?? '');
                $this->pdftotext = '' !== $bin && is_executable($bin);
            }
        }

        return $this->pdftotext;
    }

    /**
     * Текст версии документа (с кэшированием).
     *
     * @return array{status: string, text: ?string, chars: int, cached: bool}
     */
    public function extract(DocumentVersion $version): array
    {
        if ($version->isPage()) {
            $text = $version->getPlainText();

            return ['status' => '' === $text ? self::STATUS_EMPTY : self::STATUS_OK, 'text' => '' === $text ? null : $text, 'chars' => mb_strlen($text), 'cached' => false];
        }
        $path = $this->storage->absolutePath($version);
        if (null === $path || !is_file($path)) {
            return ['status' => self::STATUS_MISSING, 'text' => null, 'chars' => 0, 'cached' => false];
        }
        $ext = strtolower($version->getExtension());
        if (!$this->supports($ext)) {
            return ['status' => self::STATUS_UNSUPPORTED, 'text' => null, 'chars' => 0, 'cached' => false];
        }
        $cacheFile = $this->cacheDir.'/'.$version->getId().'-'.substr((string) ($version->getChecksum() ?: md5_file($path)), 0, 16).'.txt';
        if (is_file($cacheFile)) {
            $text = (string) file_get_contents($cacheFile);

            return ['status' => '' === $text ? self::STATUS_EMPTY : self::STATUS_OK, 'text' => '' === $text ? null : $text, 'chars' => mb_strlen($text), 'cached' => true];
        }
        try {
            $text = $this->extractFile($path, $ext) ?? '';
        } catch (\Throwable $e) {
            $this->logger->warning('Не удалось извлечь текст из файла', ['version' => $version->getId(), 'file' => $version->getOriginalName(), 'error' => $e->getMessage()]);

            return ['status' => self::STATUS_ERROR, 'text' => null, 'chars' => 0, 'cached' => false];
        }
        if (!is_dir($this->cacheDir)) {
            @mkdir($this->cacheDir, 0775, true);
        }
        @file_put_contents($cacheFile, $text, \LOCK_EX);

        return ['status' => '' === $text ? self::STATUS_EMPTY : self::STATUS_OK, 'text' => '' === $text ? null : $text, 'chars' => mb_strlen($text), 'cached' => false];
    }

    /** Извлекает текст из файла на диске по расширению; null — формат не поддерживается. */
    public function extractFile(string $path, string $extension): ?string
    {
        $ext = strtolower(ltrim($extension, '.'));
        $text = match (true) {
            \in_array($ext, self::PLAIN, true) => self::toUtf8((string) file_get_contents($path)),
            \in_array($ext, self::HTML, true) => self::htmlToText(self::toUtf8((string) file_get_contents($path))),
            'pdf' === $ext => $this->pdfToText($path),
            'docx' === $ext => $this->docxToText($path),
            'xlsx' === $ext => $this->xlsxToText($path),
            'pptx' === $ext => $this->pptxToText($path),
            \in_array($ext, ['odt', 'ods', 'odp'], true) => $this->openDocumentToText($path),
            default => null,
        };
        if (null === $text) {
            return null;
        }

        return self::normalize($text);
    }

    /** Приводит текст к аккуратному виду: переносы строк, пробелы, ограничение длины. */
    public static function normalize(string $text): string
    {
        $text = str_replace(["\r\n", "\r", "\u{0}"], ["\n", "\n", ''], $text);
        $text = preg_replace('/[ \x{00A0}]+/u', ' ', $text) ?? $text;
        $text = preg_replace('/ ?\t[\t ]*/u', "\t", $text) ?? $text; // табуляции (границы ячеек) сохраняем, но без дублей
        $text = preg_replace('/[ \t]*\n[ \t]*/u', "\n", $text) ?? $text;
        $text = preg_replace("/\n{3,}/u", "\n\n", $text) ?? $text;
        $text = trim($text);
        if (mb_strlen($text) > self::MAX_CHARS) {
            $text = mb_substr($text, 0, self::MAX_CHARS);
        }

        return $text;
    }

    /** Кодировка: UTF-8 (с BOM или без) либо Windows-1251 для старых текстовых файлов. */
    public static function toUtf8(string $raw): string
    {
        if (str_starts_with($raw, "\xEF\xBB\xBF")) {
            return substr($raw, 3);
        }
        if (str_starts_with($raw, "\xFF\xFE") || str_starts_with($raw, "\xFE\xFF")) {
            return (string) mb_convert_encoding(substr($raw, 2), 'UTF-8', str_starts_with($raw, "\xFF\xFE") ? 'UTF-16LE' : 'UTF-16BE');
        }
        if (mb_check_encoding($raw, 'UTF-8')) {
            return $raw;
        }

        return (string) mb_convert_encoding($raw, 'UTF-8', 'Windows-1251');
    }

    public static function htmlToText(string $html): string
    {
        $html = preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', ' ', $html) ?? $html;
        $html = preg_replace('#</(p|div|li|h[1-6]|tr|blockquote|pre|section|article|header|footer|table)>#i', "$0\n", $html) ?? $html;
        $html = preg_replace('#<(br|hr)\s*/?>#i', "\n", $html) ?? $html;
        $html = preg_replace('#</t[dh]>#i', "\t", $html) ?? $html;

        return html_entity_decode(strip_tags($html), \ENT_QUOTES | \ENT_HTML5, 'UTF-8');
    }

    private function pdfToText(string $path): string
    {
        if (!$this->hasPdftotext()) {
            throw new \RuntimeException('pdftotext (poppler-utils) не установлен.');
        }
        $bin = str_contains($this->pdftotextBin, '/') ? $this->pdftotextBin : (self::which($this->pdftotextBin) ?? $this->pdftotextBin);
        $cmd = \sprintf('%s -layout -enc UTF-8 -q %s - 2>/dev/null', escapeshellarg($bin), escapeshellarg($path));
        if (null !== self::which('timeout')) {
            $cmd = 'timeout 120 '.$cmd;
        }
        $output = [];
        $code = 0;
        exec($cmd, $output, $code);
        $text = implode("\n", $output);
        if (0 !== $code && '' === trim($text)) {
            throw new \RuntimeException(\sprintf('pdftotext завершился с кодом %d.', $code));
        }
        // Убираем разрывы страниц и «лесенку» пробелов режима -layout.
        $text = str_replace("\f", "\n\n", $text);

        return preg_replace('/[ ]{2,}/', ' ', $text) ?? $text;
    }

    private function docxToText(string $path): string
    {
        $zip = self::openZip($path);
        $parts = [];
        // Основной текст, затем колонтитулы и сноски (по возрастанию номера).
        $names = self::zipEntries($zip, static fn (string $n): bool => 'word/document.xml' === $n || (bool) preg_match('#^word/(header|footer|footnotes|endnotes)\d*\.xml$#', $n));
        usort($names, static fn (string $a, string $b): int => ('word/document.xml' === $a ? -1 : ('word/document.xml' === $b ? 1 : strcmp($a, $b))));
        foreach ($names as $name) {
            $xml = (string) $zip->getFromName($name);
            $xml = preg_replace('#<w:tab/>#', "\t", $xml) ?? $xml;
            $xml = preg_replace('#<w:(br|cr)\b[^>]*/>#', "\n", $xml) ?? $xml;
            // Ячейки таблиц: абзацы внутри ячейки — через пробел, ячейки — через табуляцию, строки — с новой строки.
            $xml = preg_replace_callback('#<w:tc\b[^>]*>(.*?)</w:tc>#s', static fn (array $m): string => str_replace('</w:p>', ' ', $m[1])."\t", $xml) ?? $xml;
            $xml = preg_replace('#</w:tr>#', "\n", $xml) ?? $xml;
            $xml = preg_replace('#</w:p>#', "\n", $xml) ?? $xml;
            $parts[] = self::xmlText($xml);
        }
        $zip->close();

        return implode("\n\n", array_filter(array_map('trim', $parts)));
    }

    private function xlsxToText(string $path): string
    {
        $zip = self::openZip($path);
        $shared = [];
        $sst = $zip->getFromName('xl/sharedStrings.xml');
        if (\is_string($sst) && preg_match_all('#<si>(.*?)</si>#s', $sst, $m)) {
            foreach ($m[1] as $si) {
                $shared[] = self::xmlText($si);
            }
        }
        $sheetNames = [];
        $workbook = $zip->getFromName('xl/workbook.xml');
        if (\is_string($workbook) && preg_match_all('#<sheet\b[^>]*\bname="([^"]*)"[^>]*\br:id="([^"]+)"#', $workbook, $m, \PREG_SET_ORDER)) {
            $rels = (string) $zip->getFromName('xl/_rels/workbook.xml.rels');
            foreach ($m as $sheet) {
                if (preg_match('#<Relationship\b[^>]*\bId="'.preg_quote($sheet[2], '#').'"[^>]*\bTarget="([^"]+)"#', $rels, $r)
                    || preg_match('#<Relationship\b[^>]*\bTarget="([^"]+)"[^>]*\bId="'.preg_quote($sheet[2], '#').'"#', $rels, $r)) {
                    $target = ltrim($r[1], '/');
                    $sheetNames[str_starts_with($target, 'xl/') ? $target : 'xl/'.$target] = html_entity_decode($sheet[1], \ENT_QUOTES | \ENT_XML1, 'UTF-8');
                }
            }
        }
        $entries = self::zipEntries($zip, static fn (string $n): bool => (bool) preg_match('#^xl/worksheets/sheet\d+\.xml$#', $n));
        natsort($entries);
        $out = [];
        foreach ($entries as $entry) {
            $xml = (string) $zip->getFromName($entry);
            $rows = [];
            if (preg_match_all('#<row\b[^>]*>(.*?)</row>#s', $xml, $rm)) {
                foreach ($rm[1] as $rowXml) {
                    $cells = [];
                    if (preg_match_all('#<c\b([^>]*?)(?:/>|>(.*?)</c>)#s', $rowXml, $cm, \PREG_SET_ORDER)) {
                        foreach ($cm as $cell) {
                            $attrs = $cell[1];
                            $inner = $cell[2] ?? '';
                            $type = preg_match('#\bt="([^"]+)"#', $attrs, $tm) ? $tm[1] : '';
                            if ('s' === $type) {
                                $value = preg_match('#<v>(.*?)</v>#s', $inner, $vm) ? ($shared[(int) $vm[1]] ?? '') : '';
                            } elseif ('inlineStr' === $type) {
                                $value = self::xmlText($inner);
                            } else {
                                $value = preg_match('#<v>(.*?)</v>#s', $inner, $vm) ? html_entity_decode($vm[1], \ENT_QUOTES | \ENT_XML1, 'UTF-8') : '';
                            }
                            $cells[] = trim($value);
                        }
                    }
                    $line = rtrim(implode("\t", $cells));
                    if ('' !== trim($line)) {
                        $rows[] = $line;
                    }
                }
            }
            if ([] !== $rows) {
                $title = $sheetNames[$entry] ?? null;
                $out[] = (null !== $title ? '['.$title."]\n" : '').implode("\n", $rows);
            }
        }
        $zip->close();

        return implode("\n\n", $out);
    }

    private function pptxToText(string $path): string
    {
        $zip = self::openZip($path);
        $entries = self::zipEntries($zip, static fn (string $n): bool => (bool) preg_match('#^ppt/(slides/slide\d+|notesSlides/notesSlide\d+)\.xml$#', $n));
        natsort($entries);
        $slides = [];
        $notes = [];
        foreach ($entries as $entry) {
            $xml = (string) $zip->getFromName($entry);
            $xml = preg_replace('#</a:p>#', "\n", $xml) ?? $xml;
            $xml = preg_replace('#<a:br\b[^>]*/>#', "\n", $xml) ?? $xml;
            $xml = preg_replace('#</a:tc>#', "\t", $xml) ?? $xml;
            // Берём только текстовые узлы <a:t>, чтобы не захватить служебные атрибуты.
            $text = preg_match_all('#<a:t\b[^>]*>(.*?)</a:t>|(\n|\t)#s', $xml, $m) ? self::xmlText(implode('', array_map(static fn ($t, $ws) => '' !== $ws ? $ws : $t.' ', $m[1], $m[2]))) : '';
            $text = trim($text);
            if ('' === $text) {
                continue;
            }
            if (str_starts_with($entry, 'ppt/notesSlides/')) {
                $notes[] = $text;
            } else {
                $slides[] = $text;
            }
        }
        $zip->close();
        $out = [];
        foreach ($slides as $i => $slide) {
            $out[] = \sprintf("[Слайд %d]\n%s", $i + 1, $slide);
        }
        if ([] !== $notes) {
            $out[] = "[Заметки]\n".implode("\n", $notes);
        }

        return implode("\n\n", $out);
    }

    private function openDocumentToText(string $path): string
    {
        $zip = self::openZip($path);
        $xml = (string) $zip->getFromName('content.xml');
        $zip->close();
        $xml = preg_replace('#<text:tab/>#', "\t", $xml) ?? $xml;
        $xml = preg_replace('#<text:line-break/>#', "\n", $xml) ?? $xml;
        $xml = preg_replace('#<text:s(?:\s+text:c="(\d+)")?\s*/>#', ' ', $xml) ?? $xml;
        $xml = preg_replace('#</(text:p|text:h|table:table-row|text:list-item)>#', "\n", $xml) ?? $xml;
        $xml = preg_replace('#</table:table-cell>#', "\t", $xml) ?? $xml;

        return self::xmlText($xml);
    }

    private static function openZip(string $path): \ZipArchive
    {
        if (!class_exists(\ZipArchive::class)) {
            throw new \RuntimeException('Расширение PHP zip не установлено.');
        }
        $zip = new \ZipArchive();
        $code = $zip->open($path, \ZipArchive::RDONLY);
        if (true !== $code) {
            throw new \RuntimeException(\sprintf('Не удалось открыть архив (код %s).', (string) $code));
        }

        return $zip;
    }

    /**
     * @param callable(string): bool $filter
     *
     * @return list<string>
     */
    private static function zipEntries(\ZipArchive $zip, callable $filter): array
    {
        $names = [];
        for ($i = 0; $i < $zip->numFiles; ++$i) {
            $name = $zip->getNameIndex($i);
            if (\is_string($name) && $filter($name)) {
                $names[] = $name;
            }
        }

        return $names;
    }

    /** Убирает теги XML и раскрывает сущности. */
    private static function xmlText(string $xml): string
    {
        return html_entity_decode(strip_tags($xml), \ENT_QUOTES | \ENT_XML1 | \ENT_HTML5, 'UTF-8');
    }

    private static function which(string $bin): ?string
    {
        foreach (explode(\PATH_SEPARATOR, (string) getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin') as $dir) {
            $candidate = rtrim($dir, '/').'/'.$bin;
            if (is_executable($candidate)) {
                return $candidate;
            }
        }
        foreach (['/usr/local/bin', '/usr/bin', '/bin'] as $dir) {
            if (is_executable($dir.'/'.$bin)) {
                return $dir.'/'.$bin;
            }
        }

        return null;
    }
}
