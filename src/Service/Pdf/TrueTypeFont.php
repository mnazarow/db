<?php

declare(strict_types=1);

namespace App\Service\Pdf;

/**
 * Шрифт TrueType для PDF: метрики, соответствие «символ → глиф» и подмножество шрифта для встраивания.
 *
 * Подмножество сохраняет номера глифов (данные неиспользованных глифов просто выбрасываются), поэтому в PDF
 * действует тождественное соответствие CID → GID, а файл шрифта уменьшается с сотен до десятков килобайт.
 */
final class TrueTypeFont
{
    /** @var array<string, array{offset: int, length: int}> */
    private array $tables = [];

    public readonly int $unitsPerEm;
    public readonly int $ascender;
    public readonly int $descender;
    public readonly int $capHeight;
    public readonly int $italicAngle;
    /** @var array{0: int, 1: int, 2: int, 3: int} xMin, yMin, xMax, yMax */
    public readonly array $bbox;
    public readonly string $postScriptName;
    public readonly bool $bold;

    private int $numGlyphs;
    private int $numberOfHMetrics;
    private int $indexToLocFormat;

    /** @var array<int, int> кэш «код символа → номер глифа» */
    private array $glyphCache = [];

    /** @var array<int, int> кэш ширин глифов */
    private array $widthCache = [];

    /** @var list<array{start: int, end: int, delta: int, rangeOffset: int, rangeOffsetPos: int}>|null сегменты cmap формата 4 */
    private ?array $cmap4 = null;

    /** @var list<array{start: int, end: int, glyph: int}>|null группы cmap формата 12 */
    private ?array $cmap12 = null;

    private function __construct(private readonly string $data)
    {
        $numTables = $this->u16(4);
        for ($i = 0; $i < $numTables; ++$i) {
            $record = 12 + 16 * $i;
            $this->tables[substr($this->data, $record, 4)] = ['offset' => $this->u32($record + 8), 'length' => $this->u32($record + 12)];
        }
        foreach (['head', 'hhea', 'maxp', 'hmtx', 'cmap', 'loca', 'glyf'] as $required) {
            if (!isset($this->tables[$required])) {
                throw new \RuntimeException('В шрифте нет таблицы '.$required);
            }
        }
        $head = $this->tables['head']['offset'];
        $this->unitsPerEm = $this->u16($head + 18);
        $this->bbox = [$this->i16($head + 36), $this->i16($head + 38), $this->i16($head + 40), $this->i16($head + 42)];
        $this->indexToLocFormat = $this->i16($head + 50);
        $hhea = $this->tables['hhea']['offset'];
        $this->ascender = $this->i16($hhea + 4);
        $this->descender = $this->i16($hhea + 6);
        $this->numberOfHMetrics = $this->u16($hhea + 34);
        $this->numGlyphs = $this->u16($this->tables['maxp']['offset'] + 4);
        $capHeight = (int) round($this->ascender * 0.72);
        $bold = false;
        if (isset($this->tables['OS/2'])) {
            $os2 = $this->tables['OS/2']['offset'];
            $bold = $this->u16($os2 + 4) >= 600;
            if ($this->u16($os2) >= 2 && $this->tables['OS/2']['length'] >= 90) {
                $capHeight = $this->i16($os2 + 88);
            }
        }
        $this->capHeight = $capHeight;
        $this->bold = $bold;
        $this->italicAngle = isset($this->tables['post']) ? (int) round($this->i32($this->tables['post']['offset'] + 4) / 65536) : 0;
        $this->postScriptName = $this->readPostScriptName() ?? 'Font';
        $this->parseCmap();
    }

    public static function fromFile(string $path): self
    {
        $data = @file_get_contents($path);
        if (false === $data || \strlen($data) < 12) {
            throw new \RuntimeException('Не удалось прочитать шрифт: '.$path);
        }

        return new self($data);
    }

    /** Номер глифа для символа (0 — в шрифте такого символа нет). */
    public function glyph(int $codepoint): int
    {
        if (isset($this->glyphCache[$codepoint])) {
            return $this->glyphCache[$codepoint];
        }
        $glyph = 0;
        if (null !== $this->cmap12) {
            foreach ($this->cmap12 as $group) {
                if ($codepoint >= $group['start'] && $codepoint <= $group['end']) {
                    $glyph = $group['glyph'] + $codepoint - $group['start'];
                    break;
                }
            }
        } elseif (null !== $this->cmap4 && $codepoint <= 0xFFFF) {
            foreach ($this->cmap4 as $segment) {
                if ($codepoint > $segment['end']) {
                    continue;
                }
                if ($codepoint < $segment['start']) {
                    break;
                }
                if (0 === $segment['rangeOffset']) {
                    $glyph = ($codepoint + $segment['delta']) & 0xFFFF;
                } else {
                    $address = $segment['rangeOffsetPos'] + $segment['rangeOffset'] + 2 * ($codepoint - $segment['start']);
                    $value = $address + 2 <= \strlen($this->data) ? $this->u16($address) : 0;
                    $glyph = 0 === $value ? 0 : ($value + $segment['delta']) & 0xFFFF;
                }
                break;
            }
        }
        if ($glyph >= $this->numGlyphs) {
            $glyph = 0;
        }

        return $this->glyphCache[$codepoint] = $glyph;
    }

    /** Ширина глифа в единицах шрифта. */
    public function advance(int $glyph): int
    {
        if (isset($this->widthCache[$glyph])) {
            return $this->widthCache[$glyph];
        }
        $hmtx = $this->tables['hmtx']['offset'];
        $index = min($glyph, $this->numberOfHMetrics - 1);

        return $this->widthCache[$glyph] = $this->u16($hmtx + 4 * $index);
    }

    /** Ширина в тысячных долях кегля (как в массиве W шрифта PDF). */
    public function advance1000(int $glyph): int
    {
        return (int) round($this->advance($glyph) * 1000 / $this->unitsPerEm);
    }

    /** Есть ли в шрифте символ. */
    public function has(int $codepoint): bool
    {
        return 0 !== $this->glyph($codepoint);
    }

    /**
     * Подмножество шрифта для встраивания: остаются только данные указанных глифов (и глифов, из которых
     * они составлены); номера глифов сохраняются.
     *
     * @param list<int> $glyphs
     */
    public function subset(array $glyphs): string
    {
        $keep = [0 => true];
        foreach ($glyphs as $glyph) {
            if ($glyph > 0 && $glyph < $this->numGlyphs) {
                $keep[$glyph] = true;
            }
        }
        // Составные глифы ссылаются на другие глифы — их тоже нужно сохранить.
        $queue = array_keys($keep);
        while ([] !== $queue) {
            $glyph = array_pop($queue);
            foreach ($this->components($glyph) as $component) {
                if (!isset($keep[$component]) && $component < $this->numGlyphs) {
                    $keep[$component] = true;
                    $queue[] = $component;
                }
            }
        }

        $glyf = '';
        $loca = [];
        for ($glyph = 0; $glyph < $this->numGlyphs; ++$glyph) {
            $loca[] = \strlen($glyf);
            if (isset($keep[$glyph])) {
                [$start, $end] = $this->glyphRange($glyph);
                $chunk = substr($this->data, $this->tables['glyf']['offset'] + $start, $end - $start);
                $glyf .= $chunk.str_repeat("\0", (4 - \strlen($chunk) % 4) % 4);
            }
        }
        $loca[] = \strlen($glyf);

        $tables = [];
        foreach (['cmap', 'cvt ', 'fpgm', 'hhea', 'hmtx', 'maxp', 'name', 'OS/2', 'prep'] as $tag) {
            if (isset($this->tables[$tag])) {
                $tables[$tag] = $this->table($tag);
            }
        }
        $head = $this->table('head');
        $head = substr_replace($head, "\0\0\0\0", 8, 4);          // checkSumAdjustment — пересчитывается ниже
        $head = substr_replace($head, pack('n', 1), 50, 2);       // indexToLocFormat: длинный формат loca
        $tables['head'] = $head;
        $tables['loca'] = pack('N*', ...$loca);
        $tables['glyf'] = $glyf;
        // post формата 3: без имён глифов (они не нужны для показа и печати).
        $post = isset($this->tables['post']) ? substr($this->table('post'), 0, 32) : str_repeat("\0", 32);
        $tables['post'] = substr_replace(str_pad($post, 32, "\0"), pack('N', 0x00030000), 0, 4);
        ksort($tables, \SORT_STRING);

        return self::assemble($tables);
    }

    /** @param array<string, string> $tables */
    private static function assemble(array $tables): string
    {
        $count = \count($tables);
        $entrySelector = (int) floor(log($count, 2));
        $searchRange = (2 ** $entrySelector) * 16;
        $header = pack('Nnnnn', 0x00010000, $count, $searchRange, $entrySelector, $count * 16 - $searchRange);
        $offset = 12 + 16 * $count;
        $directory = '';
        $body = '';
        foreach ($tables as $tag => $content) {
            $padded = $content.str_repeat("\0", (4 - \strlen($content) % 4) % 4);
            $directory .= str_pad($tag, 4).pack('NNN', self::checksum($padded), $offset, \strlen($content));
            $body .= $padded;
            $offset += \strlen($padded);
        }
        $font = $header.$directory.$body;
        // checkSumAdjustment = 0xB1B0AFBA − сумма всего файла.
        $adjustment = (0xB1B0AFBA - self::checksum($font)) & 0xFFFFFFFF;
        $headOffset = strpos($directory, 'head');
        $tableOffset = unpack('N', substr($directory, $headOffset + 8, 4))[1];

        return substr_replace($font, pack('N', $adjustment), $tableOffset + 8, 4);
    }

    private static function checksum(string $data): int
    {
        $data .= str_repeat("\0", (4 - \strlen($data) % 4) % 4);
        $sum = 0;
        foreach (unpack('N*', $data) ?: [] as $value) {
            $sum = ($sum + $value) & 0xFFFFFFFF;
        }

        return $sum;
    }

    /** @return array{0: int, 1: int} начало и конец данных глифа в таблице glyf */
    private function glyphRange(int $glyph): array
    {
        $loca = $this->tables['loca']['offset'];
        if (0 === $this->indexToLocFormat) {
            return [$this->u16($loca + 2 * $glyph) * 2, $this->u16($loca + 2 * $glyph + 2) * 2];
        }

        return [$this->u32($loca + 4 * $glyph), $this->u32($loca + 4 * $glyph + 4)];
    }

    /** @return list<int> глифы, из которых составлен глиф */
    private function components(int $glyph): array
    {
        [$start, $end] = $this->glyphRange($glyph);
        if ($end - $start < 10) {
            return [];
        }
        $offset = $this->tables['glyf']['offset'] + $start;
        if ($this->i16($offset) >= 0) {
            return []; // простой глиф
        }
        $out = [];
        $position = $offset + 10;
        do {
            $flags = $this->u16($position);
            $out[] = $this->u16($position + 2);
            $position += 4 + ($flags & 0x0001 ? 4 : 2);
            if ($flags & 0x0008) {
                $position += 2;
            } elseif ($flags & 0x0040) {
                $position += 4;
            } elseif ($flags & 0x0080) {
                $position += 8;
            }
        } while (($flags & 0x0020) && $position < $offset + ($end - $start));

        return $out;
    }

    private function parseCmap(): void
    {
        $cmap = $this->tables['cmap']['offset'];
        $count = $this->u16($cmap + 2);
        $format4 = null;
        $format12 = null;
        for ($i = 0; $i < $count; ++$i) {
            $platform = $this->u16($cmap + 4 + 8 * $i);
            $encoding = $this->u16($cmap + 6 + 8 * $i);
            $offset = $cmap + $this->u32($cmap + 8 + 8 * $i);
            $format = $this->u16($offset);
            if (3 === $platform && 10 === $encoding && 12 === $format) {
                $format12 = $offset;
            } elseif (((3 === $platform && 1 === $encoding) || 0 === $platform) && 4 === $format && null === $format4) {
                $format4 = $offset;
            } elseif (0 === $platform && 12 === $format && null === $format12) {
                $format12 = $offset;
            }
        }
        if (null !== $format12) {
            $groups = $this->u32($format12 + 12);
            $this->cmap12 = [];
            for ($i = 0; $i < $groups; ++$i) {
                $base = $format12 + 16 + 12 * $i;
                $this->cmap12[] = ['start' => $this->u32($base), 'end' => $this->u32($base + 4), 'glyph' => $this->u32($base + 8)];
            }

            return;
        }
        if (null === $format4) {
            throw new \RuntimeException('В шрифте нет таблицы символов Unicode (cmap 3/1 или 3/10)');
        }
        $segments = $this->u16($format4 + 6) / 2;
        $ends = $format4 + 14;
        $starts = $ends + 2 * $segments + 2;
        $deltas = $starts + 2 * $segments;
        $offsets = $deltas + 2 * $segments;
        $this->cmap4 = [];
        for ($i = 0; $i < $segments; ++$i) {
            $this->cmap4[] = [
                'end' => $this->u16($ends + 2 * $i),
                'start' => $this->u16($starts + 2 * $i),
                'delta' => $this->i16($deltas + 2 * $i),
                'rangeOffset' => $this->u16($offsets + 2 * $i),
                'rangeOffsetPos' => $offsets + 2 * $i,
            ];
        }
    }

    private function readPostScriptName(): ?string
    {
        if (!isset($this->tables['name'])) {
            return null;
        }
        $name = $this->tables['name']['offset'];
        $count = $this->u16($name + 2);
        $strings = $name + $this->u16($name + 4);
        for ($i = 0; $i < $count; ++$i) {
            $record = $name + 6 + 12 * $i;
            if (6 !== $this->u16($record + 6)) {
                continue;
            }
            $platform = $this->u16($record);
            $raw = substr($this->data, $strings + $this->u16($record + 10), $this->u16($record + 8));
            $value = 3 === $platform || 0 === $platform ? (string) mb_convert_encoding($raw, 'UTF-8', 'UTF-16BE') : $raw;
            $value = (string) preg_replace('/[^A-Za-z0-9-]/', '', $value);
            if ('' !== $value) {
                return $value;
            }
        }

        return null;
    }

    private function table(string $tag): string
    {
        return substr($this->data, $this->tables[$tag]['offset'], $this->tables[$tag]['length']);
    }

    private function u16(int $offset): int
    {
        return unpack('n', $this->data, $offset)[1];
    }

    private function i16(int $offset): int
    {
        $value = $this->u16($offset);

        return $value >= 0x8000 ? $value - 0x10000 : $value;
    }

    private function u32(int $offset): int
    {
        return unpack('N', $this->data, $offset)[1];
    }

    private function i32(int $offset): int
    {
        $value = $this->u32($offset);

        return $value >= 0x80000000 ? $value - 0x100000000 : $value;
    }
}
