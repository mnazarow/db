<?php

declare(strict_types=1);

namespace App\Service\Xlsx;

/**
 * Чтение книг Excel (.xlsx) без внешних библиотек: листы, значения ячеек (общие и встроенные строки,
 * числа, даты по формату ячейки, закэшированные результаты формул) и списки проверки данных.
 *
 * Результат — массив листов:
 *   ['name' => 'Радиаторы', 'hidden' => false, 'rows' => [1 => [1 => 'Материал', 2 => 'Тип'], …],
 *    'lists' => [2 => ['Каталоги и брошюры', …]], 'dates' => [5 => true]]
 * Номера строк и столбцов начинаются с 1; даты — в виде ГГГГ-ММ-ДД.
 */
final class XlsxReader
{
    /** Защита от «zip-бомб» и слишком больших книг. */
    private const MAX_PART_BYTES = 64 * 1024 * 1024;
    private const MAX_CELLS = 500000;

    /** Последний столбец листа Excel (XFD). */
    private const MAX_COLUMN = 16384;

    private const NS_MAIN = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
    private const NS_REL = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

    /** Встроенные форматы Excel, означающие дату или время. */
    private const DATE_FORMAT_IDS = [14, 15, 16, 17, 18, 19, 20, 21, 22, 27, 28, 29, 30, 31, 32, 33, 34, 35, 36, 45, 46, 47, 50, 51, 52, 53, 54, 55, 56, 57, 58];

    /**
     * @return list<array{name: string, hidden: bool, rows: array<int, array<int, string>>, lists: array<int, list<string>>, dates: array<int, bool>}>
     *
     * @throws \RuntimeException если файл не является книгой Excel
     */
    public function read(string $path): array
    {
        $zip = new \ZipArchive();
        if (true !== $zip->open($path, \ZipArchive::RDONLY)) {
            throw new \RuntimeException('Файл не похож на книгу Excel (.xlsx).');
        }
        try {
            $workbook = $this->xml($zip, 'xl/workbook.xml') ?? throw new \RuntimeException('В файле нет книги Excel (xl/workbook.xml). Сохраните таблицу в формате .xlsx.');
            $rels = $this->relationships($zip, 'xl/_rels/workbook.xml.rels');
            $strings = $this->sharedStrings($zip);
            $dateStyles = $this->dateStyles($zip);
            $date1904 = '1' === (string) ($workbook->workbookPr['date1904'] ?? '') || 'true' === (string) ($workbook->workbookPr['date1904'] ?? '');

            $sheets = [];
            $cells = 0;
            foreach ($workbook->sheets->sheet ?? [] as $sheet) {
                $rid = (string) $sheet->attributes(self::NS_REL)['id'];
                $target = $rels[$rid] ?? null;
                if (null === $target) {
                    continue;
                }
                $xml = $this->xml($zip, self::resolve('xl/', $target));
                if (null === $xml) {
                    continue;
                }
                $parsed = $this->sheet($xml, $strings, $dateStyles, $date1904, $cells);
                $sheets[] = [
                    'name' => (string) $sheet['name'],
                    'hidden' => \in_array((string) ($sheet['state'] ?? 'visible'), ['hidden', 'veryHidden'], true),
                    ...$parsed,
                ];
            }

            return $sheets;
        } finally {
            $zip->close();
        }
    }

    /** Буквенное имя столбца → номер: «A» → 1, «AA» → 27. */
    public static function columnIndex(string $letters): int
    {
        $index = 0;
        foreach (str_split(strtoupper($letters)) as $char) {
            $index = $index * 26 + (\ord($char) - 64);
        }

        return $index;
    }

    /**
     * @param array<int, string> $strings
     * @param array<int, bool>   $dateStyles
     *
     * @return array{rows: array<int, array<int, string>>, lists: array<int, list<string>>, dates: array<int, bool>}
     */
    private function sheet(\SimpleXMLElement $xml, array $strings, array $dateStyles, bool $date1904, int &$cells): array
    {
        $rows = [];
        $dates = [];
        foreach ($xml->sheetData->row ?? [] as $row) {
            $rowIndex = (int) $row['r'];
            $colIndex = 0;
            foreach ($row->c as $cell) {
                $ref = (string) $cell['r'];
                if (1 === preg_match('/^([A-Z]+)(\d+)$/', $ref, $m)) {
                    $colIndex = self::columnIndex($m[1]);
                    $rowIndex = (int) $m[2];
                } else {
                    ++$colIndex;
                }
                if (++$cells > self::MAX_CELLS) {
                    throw new \RuntimeException('Книга слишком большая: больше '.self::MAX_CELLS.' ячеек.');
                }
                $type = (string) ($cell['t'] ?? 'n');
                $style = (int) ($cell['s'] ?? 0);
                $value = match ($type) {
                    's' => $strings[(int) $cell->v] ?? '',
                    'inlineStr' => self::text($cell->is),
                    'str' => (string) $cell->v,
                    'b' => '1' === (string) $cell->v ? 'ИСТИНА' : 'ЛОЖЬ',
                    'e' => '',
                    'd' => substr((string) $cell->v, 0, 10),
                    default => (string) $cell->v,
                };
                if ('n' === $type && '' !== $value && is_numeric($value)) {
                    $date = isset($dateStyles[$style]) ? self::dateFromSerial((float) $value, $date1904) : null;
                    if (null !== $date) {
                        $value = $date;
                        $dates[$colIndex] = true;
                    } else {
                        $value = self::number($value);
                    }
                }
                $value = trim(str_replace(["\r\n", "\r", "\u{00A0}"], ["\n", "\n", ' '], $value));
                if ('' !== $value) {
                    $rows[$rowIndex][$colIndex] = $value;
                }
            }
        }
        ksort($rows);

        return ['rows' => $rows, 'lists' => $this->lists($xml), 'dates' => $dates];
    }

    /**
     * Списки из проверки данных: столбец → допустимые значения (обычные и в расширении x14).
     *
     * @return array<int, list<string>>
     */
    private function lists(\SimpleXMLElement $xml): array
    {
        $lists = [];
        $add = static function (string $sqref, string $formula) use (&$lists): void {
            $formula = trim($formula);
            if (1 !== preg_match('/^"(.*)"$/su', $formula, $m)) {
                return; // списки-ссылки на диапазоны не разбираем — значения возьмутся из данных
            }
            $options = array_values(array_filter(array_map('trim', explode(',', $m[1])), static fn (string $o): bool => '' !== $o));
            foreach (preg_split('/\s+/', trim($sqref)) ?: [] as $range) {
                // Не больше трёх букв (XFD — последний столбец Excel): диапазон из испорченного или
                // специально собранного файла не должен превращаться в миллиарды итераций.
                if (1 === preg_match('/^\$?([A-Z]{1,3})\$?\d*(?::\$?([A-Z]{1,3})\$?\d*)?$/', $range, $r)) {
                    $from = min(self::columnIndex($r[1]), self::MAX_COLUMN);
                    $to = isset($r[2]) && '' !== $r[2] ? min(self::columnIndex($r[2]), self::MAX_COLUMN) : $from;
                    for ($col = $from; $col <= $to; ++$col) {
                        $lists[$col] ??= $options;
                    }
                }
            }
        };
        foreach ($xml->dataValidations->dataValidation ?? [] as $validation) {
            if ('list' === (string) $validation['type']) {
                $add((string) $validation['sqref'], (string) $validation->formula1);
            }
        }
        foreach ($xml->xpath('//*[local-name()="dataValidation" and @type="list"]') ?: [] as $validation) {
            $sqref = (string) ($validation['sqref'] ?? '');
            $formula = '';
            foreach ($validation->xpath('.//*[local-name()="sqref"]') ?: [] as $node) {
                $sqref = (string) $node;
            }
            foreach ($validation->xpath('.//*[local-name()="formula1"]') ?: [] as $node) {
                $formula = trim(strip_tags($node->asXML() ?: ''));
                $formula = html_entity_decode($formula, \ENT_QUOTES | \ENT_XML1, 'UTF-8');
            }
            if ('' !== $sqref && '' !== $formula) {
                $add($sqref, $formula);
            }
        }

        return $lists;
    }

    /** @return array<int, string> */
    private function sharedStrings(\ZipArchive $zip): array
    {
        $xml = $this->xml($zip, 'xl/sharedStrings.xml');
        if (null === $xml) {
            return [];
        }
        $strings = [];
        foreach ($xml->si as $item) {
            $strings[] = self::text($item);
        }

        return $strings;
    }

    /**
     * Номера стилей ячеек, у которых формат — дата.
     *
     * @return array<int, bool>
     */
    private function dateStyles(\ZipArchive $zip): array
    {
        $xml = $this->xml($zip, 'xl/styles.xml');
        if (null === $xml) {
            return [];
        }
        $custom = [];
        foreach ($xml->numFmts->numFmt ?? [] as $format) {
            $custom[(int) $format['numFmtId']] = (string) $format['formatCode'];
        }
        $styles = [];
        $index = 0;
        foreach ($xml->cellXfs->xf ?? [] as $xf) {
            $id = (int) ($xf['numFmtId'] ?? 0);
            if (\in_array($id, self::DATE_FORMAT_IDS, true) || (isset($custom[$id]) && self::isDateFormat($custom[$id]))) {
                $styles[$index] = true;
            }
            ++$index;
        }

        return $styles;
    }

    private static function isDateFormat(string $format): bool
    {
        $format = preg_replace('/"[^"]*"|\[[^\]]*\]|\\\\./u', '', $format) ?? '';

        return 1 === preg_match('/[dy]/i', $format);
    }

    /**
     * Порядковый номер дня Excel → ГГГГ-ММ-ДД; null — это не дата (время без даты, отрицательное или
     * слишком большое число), тогда значение остаётся числом.
     */
    private static function dateFromSerial(float $serial, bool $date1904): ?string
    {
        if ($serial < 1 || $serial > 2958465) { // 2958465 — 31.12.9999
            return null;
        }
        $days = (int) floor($serial);
        if ($date1904) {
            $base = '1904-01-01';
        } elseif ($days < 61) {
            // До 1 марта 1900 года Excel считает дни от 31.12.1899 (у него есть несуществующее 29.02.1900).
            if (60 === $days) {
                return '1900-02-28';
            }
            $base = '1899-12-31';
        } else {
            $base = '1899-12-30';
        }

        return (new \DateTimeImmutable($base, new \DateTimeZone('UTC')))->modify(\sprintf('+%d days', $days))->format('Y-m-d');
    }

    /** Число без лишнего «.0» на конце: «46236.0» → «46236». */
    private static function number(string $value): string
    {
        if (str_contains($value, 'E') || str_contains($value, 'e')) {
            $value = rtrim(rtrim(\sprintf('%.10F', (float) $value), '0'), '.');
        }
        if (str_contains($value, '.')) {
            $value = rtrim(rtrim($value, '0'), '.');
        }

        return $value;
    }

    /** Текст элемента строки: простой <t> или несколько отрезков <r><t>. */
    private static function text(?\SimpleXMLElement $node): string
    {
        if (null === $node) {
            return '';
        }
        if (isset($node->t)) {
            $text = (string) $node->t;
            if (!isset($node->r)) {
                return $text;
            }
        }
        $text = '';
        foreach ($node->r as $run) {
            $text .= (string) $run->t;
        }

        return '' !== $text ? $text : (string) ($node->t ?? '');
    }

    /** @return array<string, string> id связи → путь */
    private function relationships(\ZipArchive $zip, string $name): array
    {
        $xml = $this->xml($zip, $name);
        $rels = [];
        foreach ($xml?->Relationship ?? [] as $rel) {
            $rels[(string) $rel['Id']] = (string) $rel['Target'];
        }

        return $rels;
    }

    private static function resolve(string $base, string $target): string
    {
        if (str_starts_with($target, '/')) {
            return ltrim($target, '/');
        }
        $parts = [];
        foreach (explode('/', $base.$target) as $part) {
            if ('..' === $part) {
                array_pop($parts);
            } elseif ('' !== $part && '.' !== $part) {
                $parts[] = $part;
            }
        }

        return implode('/', $parts);
    }

    private function xml(\ZipArchive $zip, string $name): ?\SimpleXMLElement
    {
        $stat = $zip->statName($name);
        if (false === $stat) {
            return null;
        }
        if ($stat['size'] > self::MAX_PART_BYTES) {
            throw new \RuntimeException('Книга слишком большая для импорта.');
        }
        $content = $zip->getFromName($name);
        if (false === $content || '' === $content) {
            return null;
        }
        $previous = libxml_use_internal_errors(true);
        try {
            $xml = simplexml_load_string($content, \SimpleXMLElement::class, \LIBXML_NONET | \LIBXML_COMPACT);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        if (false === $xml) {
            throw new \RuntimeException('Не удалось прочитать '.$name.': файл повреждён.');
        }
        $xml->registerXPathNamespace('m', self::NS_MAIN);

        return $xml;
    }
}
