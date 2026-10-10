<?php

declare(strict_types=1);

namespace App\Service\Pdf;

/**
 * Простой генератор PDF для листов подписания и сборников: страницы A4, текст со встроенным шрифтом
 * TrueType (кириллица, подмножество шрифта), перенос строк, таблицы с повтором шапки, прямоугольники,
 * линии, ссылки, изображения JPEG/PNG и QR-коды.
 *
 * Координаты — в пунктах от левого ВЕРХНЕГО угла страницы (так удобнее вести текст сверху вниз);
 * в PDF они переводятся в систему с началом внизу.
 */
final class PdfDocument
{
    public const A4_WIDTH = 595.28;
    public const A4_HEIGHT = 841.89;

    public const REGULAR = 'regular';
    public const BOLD = 'bold';

    public const BLACK = [0.11, 0.12, 0.12];
    public const MUTED = [0.37, 0.39, 0.44];
    public const BRAND = [0.06, 0.26, 0.51];

    public float $marginLeft = 56.7;
    public float $marginRight = 42.5;
    public float $marginTop = 48.0;
    public float $marginBottom = 56.7;

    /** Текущая позиция по вертикали (от верха страницы). */
    public float $y = 0.0;

    /** @var list<array{content: string, annots: list<string>}> */
    private array $pages = [];
    private int $page = -1;

    /** @var array<string, array{font: TrueTypeFont, glyphs: array<int, int>, key: string}> */
    private array $fonts;

    /** @var list<array{data: string, width: int, height: int, components: int}> */
    private array $images = [];

    /** @var array<string, string> */
    private array $info = [];

    /** @var array<string, array<int, int>> ширины символов (в единицах шрифта) по начертанию — для переноса строк */
    private array $advanceCache = [];

    /** @var (callable(PdfDocument, int, int): void)|null */
    private $footer = null;

    /** @var (callable(PdfDocument, int): void)|null */
    private $header = null;

    public function __construct(TrueTypeFont $regular, TrueTypeFont $bold)
    {
        $this->fonts = [
            self::REGULAR => ['font' => $regular, 'glyphs' => [], 'key' => 'F1'],
            self::BOLD => ['font' => $bold, 'glyphs' => [], 'key' => 'F2'],
        ];
    }

    public function setInfo(string $title, string $author = '', string $subject = ''): void
    {
        $this->info = array_filter(['Title' => $title, 'Author' => $author, 'Subject' => $subject, 'Creator' => 'Портал документации'], static fn (string $v): bool => '' !== $v);
    }

    /** Нижний колонтитул: вызывается для каждой страницы при сборке файла (номер страницы и их число уже известны). */
    public function setFooter(?callable $footer): void
    {
        $this->footer = $footer;
    }

    /** Верхний колонтитул: вызывается при создании каждой страницы, кроме первой. */
    public function setHeader(?callable $header): void
    {
        $this->header = $header;
    }

    public function addPage(): void
    {
        $this->pages[] = ['content' => '', 'annots' => []];
        $this->page = \count($this->pages) - 1;
        $this->y = $this->marginTop;
        if (null !== $this->header && $this->page > 0) {
            ($this->header)($this, $this->page + 1);
        }
    }

    public function pageCount(): int
    {
        return \count($this->pages);
    }

    public function contentWidth(): float
    {
        return self::A4_WIDTH - $this->marginLeft - $this->marginRight;
    }

    /** Переход на новую страницу, если до нижнего поля меньше $height. */
    public function ensureSpace(float $height): void
    {
        if ($this->page < 0 || $this->y + $height > self::A4_HEIGHT - $this->marginBottom) {
            $this->addPage();
        }
    }

    // ---- Текст ----------------------------------------------------------------------------------------

    public function textWidth(string $text, float $size, string $style = self::REGULAR): float
    {
        $units = 0;
        foreach (self::codepoints($text) as $codepoint) {
            $units += $this->advanceOf($codepoint, $style);
        }

        return $units * $size / $this->fonts[$style]['font']->unitsPerEm;
    }

    /**
     * Строка текста; $y — верх строки (базовая линия ниже на высоту выносных элементов шрифта).
     *
     * @param array{0: float, 1: float, 2: float} $color
     */
    public function text(float $x, float $y, string $text, float $size, string $style = self::REGULAR, array $color = self::BLACK): void
    {
        if ('' === $text) {
            return;
        }
        $entry = &$this->fonts[$style];
        $font = $entry['font'];
        $hex = '';
        foreach (self::codepoints($text) as $codepoint) {
            $glyph = $font->glyph($codepoint);
            if (0 === $glyph && 0x20 !== $codepoint) {
                // Символа нет в шрифте — печатается «?», и при копировании текста это тоже «?».
                $glyph = $font->glyph(0x3F);
                $codepoint = 0x3F;
            }
            $entry['glyphs'][$glyph] ??= $codepoint;
            $hex .= \sprintf('%04X', $glyph);
        }
        $baseline = $y + $size * $font->ascender / $font->unitsPerEm;
        $this->out(\sprintf('BT %s rg /%s %s Tf %s %s Td <%s> Tj ET', self::color($color), $entry['key'], self::num($size), self::num($x), self::num(self::A4_HEIGHT - $baseline), $hex));
    }

    /**
     * Разбивает текст на строки не шире $width: переводы строк сохраняются, переносится по пробелам;
     * слово длиннее строки (ссылка, путь, контрольная сумма, текст без пробелов) режется после «/», «-»,
     * «_», «.», «,», «;», «:», «?», «&», «=», а если их нет — по символам. Время работы линейное.
     *
     * @return list<string>
     */
    public function wrap(string $text, float $size, string $style, float $width): array
    {
        $font = $this->fonts[$style]['font'];
        $limit = max(1.0, $width * $font->unitsPerEm / max(0.1, $size)); // ширина строки в единицах шрифта
        $space = $this->advanceOf(0x20, $style);
        $lines = [];
        foreach (preg_split('/\R/u', $text) ?: [''] as $paragraph) {
            $words = preg_split('/[ \t]+/u', trim($paragraph, " \t")) ?: [];
            $line = '';
            $lineWidth = 0;
            foreach ($words as $word) {
                if ('' === $word) {
                    continue;
                }
                [$chars, $advances] = $this->measure($word, $style);
                $wordWidth = array_sum($advances);
                if ('' !== $line && $lineWidth + $space + $wordWidth <= $limit) {
                    $line .= ' '.$word;
                    $lineWidth += $space + $wordWidth;
                    continue;
                }
                if ('' === $line && $wordWidth <= $limit) {
                    $line = $word;
                    $lineWidth = $wordWidth;
                    continue;
                }
                if ('' !== $line) {
                    $lines[] = $line;
                    $line = '';
                    $lineWidth = 0;
                }
                if ($wordWidth <= $limit) {
                    $line = $word;
                    $lineWidth = $wordWidth;
                    continue;
                }
                // Слово длиннее строки — режется на куски; последний кусок начинает новую строку.
                $pieces = self::splitLong($chars, $advances, $limit);
                $last = array_pop($pieces);
                foreach ($pieces as $piece) {
                    $lines[] = $piece[0];
                }
                $line = $last[0];
                $lineWidth = $last[1];
            }
            $lines[] = $line;
        }

        return $lines;
    }

    /**
     * Куски слова не шире $limit (в единицах шрифта), с разрывом после знаков-разделителей, если они есть.
     *
     * @param list<string> $chars
     * @param list<int>    $advances
     *
     * @return non-empty-list<array{0: string, 1: int}>
     */
    private static function splitLong(array $chars, array $advances, float $limit): array
    {
        $pieces = [];
        $count = \count($chars);
        $start = 0;
        while ($start < $count) {
            $width = 0;
            $breakAfter = -1;
            $i = $start;
            while ($i < $count && ($i === $start || $width + $advances[$i] <= $limit)) {
                $width += $advances[$i];
                // Разрыв после разделителя — если строка при этом заполнена хотя бы на 40 %.
                if ($i > $start && $i < $count - 1 && $width >= $limit * 0.4 && str_contains('/-_.,;:?&=)]}|\\', $chars[$i])) {
                    $breakAfter = $i;
                }
                ++$i;
            }
            // Весь остаток поместился — это последний кусок.
            $end = $i >= $count ? $count : ($breakAfter >= $start ? $breakAfter + 1 : $i);
            $piece = \array_slice($chars, $start, $end - $start);
            $pieces[] = [implode('', $piece), (int) array_sum(\array_slice($advances, $start, $end - $start))];
            $start = $end;
        }

        return $pieces;
    }

    /**
     * Символы слова и их ширины в единицах шрифта (с учётом замены отсутствующих символов на «?»).
     *
     * @return array{0: list<string>, 1: list<int>}
     */
    private function measure(string $word, string $style): array
    {
        $chars = mb_str_split($word, 1, 'UTF-8');
        $advances = [];
        foreach ($chars as $char) {
            $advances[] = $this->advanceOf(mb_ord($char, 'UTF-8') ?: 0x3F, $style);
        }

        return [$chars, $advances];
    }

    private function advanceOf(int $codepoint, string $style): int
    {
        if (!isset($this->advanceCache[$style][$codepoint])) {
            $font = $this->fonts[$style]['font'];
            $glyph = $font->glyph(0xA0 === $codepoint ? 0x20 : $codepoint);
            if (0 === $glyph && 0x20 !== $codepoint) {
                $glyph = $font->glyph(0x3F);
            }
            $this->advanceCache[$style][$codepoint] = $font->advance($glyph);
        }

        return $this->advanceCache[$style][$codepoint];
    }

    /**
     * Абзац с переносом строк и переходом на новые страницы; курсор сдвигается вниз.
     *
     * @param array{0: float, 1: float, 2: float} $color
     */
    public function write(string $text, float $size = 10.0, string $style = self::REGULAR, array $color = self::BLACK, float $lineHeight = 1.35, float $indent = 0.0, string $align = 'left', ?float $width = null): void
    {
        $width ??= $this->contentWidth() - $indent;
        $step = $size * $lineHeight;
        foreach ($this->wrap($text, $size, $style, $width) as $line) {
            $this->ensureSpace($step);
            $x = $this->marginLeft + $indent;
            if ('center' === $align) {
                $x += ($width - $this->textWidth($line, $size, $style)) / 2;
            } elseif ('right' === $align) {
                $x += $width - $this->textWidth($line, $size, $style);
            }
            $this->text($x, $this->y + ($step - $size) / 2, $line, $size, $style, $color);
            $this->y += $step;
        }
    }

    /** Отступ по вертикали. */
    public function space(float $height): void
    {
        $this->y += $height;
    }

    /**
     * Пары «подпись — значение» в две колонки (реквизиты документа). Пара выше страницы продолжается
     * на следующей.
     *
     * @param array<string, string> $pairs
     */
    public function definitionList(array $pairs, float $size = 9.5, float $labelWidth = 150.0): void
    {
        $valueWidth = $this->contentWidth() - $labelWidth;
        $step = $size * 1.4;
        foreach ($pairs as $label => $value) {
            $labelLines = $this->wrap((string) $label, $size, self::REGULAR, $labelWidth - 8);
            $valueLines = $this->wrap('' !== $value ? $value : '—', $size, self::BOLD, $valueWidth);
            $count = max(\count($labelLines), \count($valueLines));
            if ($count * $step + 3 <= $this->pageSpace()) {
                $this->ensureSpace($count * $step + 3);
            }
            for ($offset = 0; $offset < $count;) {
                $fit = (int) floor(max(0.0, self::A4_HEIGHT - $this->marginBottom - $this->y) / $step);
                if ($fit < 1 || ($this->page < 0)) {
                    $this->addPage();
                    continue;
                }
                $fit = min($fit, $count - $offset);
                for ($i = 0; $i < $fit; ++$i) {
                    $top = $this->y + $i * $step + ($step - $size) / 2;
                    if (isset($labelLines[$offset + $i])) {
                        $this->text($this->marginLeft, $top, $labelLines[$offset + $i], $size, self::REGULAR, self::MUTED);
                    }
                    if (isset($valueLines[$offset + $i])) {
                        $this->text($this->marginLeft + $labelWidth, $top, $valueLines[$offset + $i], $size, self::BOLD);
                    }
                }
                $this->y += $fit * $step;
                $offset += $fit;
                if ($offset < $count) {
                    $this->addPage();
                }
            }
            $this->y += 3;
        }
    }

    /** Высота области текста на чистой странице. */
    private function pageSpace(): float
    {
        return self::A4_HEIGHT - $this->marginTop - $this->marginBottom;
    }

    /**
     * Таблица с переносом текста в ячейках; шапка повторяется на каждой странице, строка выше
     * страницы продолжается на следующей (с шапкой).
     *
     * @param list<array{title: string, width: float, align?: string}> $columns ширины — доли (сумма 1) или пункты
     * @param list<list<string>>                                        $rows
     * @param array{size?: float, zebra?: bool, header?: bool}           $options header: false — без шапки
     */
    public function table(array $columns, array $rows, array $options = []): void
    {
        $withHeader = $options['header'] ?? true;
        $size = $options['size'] ?? 9.0;
        $padding = 4.0;
        $step = $size * 1.3;
        $total = array_sum(array_column($columns, 'width'));
        $widths = array_map(fn (array $c): float => $total <= 1.0001 ? $c['width'] * $this->contentWidth() : $c['width'], $columns);
        $tableWidth = array_sum($widths);
        $headerLines = [];
        $headerHeight = 0.0;
        if ($withHeader) {
            foreach ($columns as $i => $column) {
                $headerLines[$i] = $this->wrap($column['title'], $size - 0.5, self::BOLD, $widths[$i] - 2 * $padding);
                $headerHeight = max($headerHeight, \count($headerLines[$i]) * $step + 2 * $padding);
            }
        }
        $drawHeader = function () use ($columns, $widths, $size, $padding, $step, $withHeader, $headerLines, $headerHeight, $tableWidth): void {
            // Шапка не остаётся одна внизу страницы: под ней должна поместиться хотя бы строка.
            $this->ensureSpace($headerHeight + $step + 2 * $padding);
            if (!$withHeader) {
                $this->line($this->marginLeft, $this->y, $this->marginLeft + $tableWidth, $this->y, 0.4, [0.87, 0.87, 0.87]);

                return;
            }
            $this->rect($this->marginLeft, $this->y, $tableWidth, $headerHeight, [0.91, 0.94, 0.97]);
            $x = $this->marginLeft;
            foreach ($columns as $i => $column) {
                foreach ($headerLines[$i] as $n => $line) {
                    $this->text($x + $padding, $this->y + $padding + $n * $step + ($step - $size) / 2, $line, $size - 0.5, self::BOLD, self::BRAND);
                }
                $x += $widths[$i];
            }
            $this->y += $headerHeight;
        };
        $drawHeader();
        foreach ($rows as $r => $row) {
            $lines = [];
            $count = 1;
            foreach ($columns as $i => $column) {
                $lines[$i] = $this->wrap((string) ($row[$i] ?? ''), $size, self::REGULAR, $widths[$i] - 2 * $padding);
                $count = max($count, \count($lines[$i]));
            }
            $height = $count * $step + 2 * $padding;
            if ($this->y + $height > self::A4_HEIGHT - $this->marginBottom && $height <= $this->pageSpace() - $headerHeight) {
                // Строка целиком поместится на следующей странице — переносим её туда.
                $this->addPage();
                $drawHeader();
            }
            // Строка печатается кусками: обычно одним, а строка выше страницы — по частям на нескольких.
            for ($offset = 0; $offset < $count;) {
                $fit = (int) floor((self::A4_HEIGHT - $this->marginBottom - $this->y - 2 * $padding) / $step);
                if ($fit < 1) {
                    $this->addPage();
                    $drawHeader();
                    continue;
                }
                $fit = min($fit, $count - $offset);
                $sliceHeight = $fit * $step + 2 * $padding;
                if (($options['zebra'] ?? true) && 1 === $r % 2) {
                    $this->rect($this->marginLeft, $this->y, $tableWidth, $sliceHeight, [0.975, 0.98, 0.985]);
                }
                $x = $this->marginLeft;
                foreach ($columns as $i => $column) {
                    for ($n = 0; $n < $fit; ++$n) {
                        $line = $lines[$i][$offset + $n] ?? null;
                        if (null === $line || '' === $line) {
                            continue;
                        }
                        $dx = 'right' === ($column['align'] ?? 'left') ? $widths[$i] - 2 * $padding - $this->textWidth($line, $size) : 0.0;
                        $this->text($x + $padding + $dx, $this->y + $padding + $n * $step + ($step - $size) / 2, $line, $size);
                    }
                    $x += $widths[$i];
                }
                $this->y += $sliceHeight;
                $offset += $fit;
                if ($offset < $count) {
                    $this->addPage();
                    $drawHeader();
                } else {
                    $this->line($this->marginLeft, $this->y, $this->marginLeft + $tableWidth, $this->y, 0.4, [0.87, 0.87, 0.87]);
                }
            }
        }
    }

    // ---- Графика ----------------------------------------------------------------------------------------

    /**
     * @param array{0: float, 1: float, 2: float}|null $fill
     * @param array{0: float, 1: float, 2: float}|null $stroke
     */
    public function rect(float $x, float $y, float $width, float $height, ?array $fill = null, ?array $stroke = null, float $lineWidth = 0.6): void
    {
        $op = null !== $fill && null !== $stroke ? 'B' : (null !== $fill ? 'f' : 'S');
        $this->out(\sprintf('q %s%s%s w %s %s %s %s re %s Q',
            null !== $fill ? self::color($fill).' rg ' : '',
            null !== $stroke ? self::color($stroke).' RG ' : '',
            self::num($lineWidth), self::num($x), self::num(self::A4_HEIGHT - $y - $height), self::num($width), self::num($height), $op));
    }

    /** @param array{0: float, 1: float, 2: float} $color */
    public function line(float $x1, float $y1, float $x2, float $y2, float $width = 0.6, array $color = [0.8, 0.8, 0.8]): void
    {
        $this->out(\sprintf('q %s RG %s w %s %s m %s %s l S Q', self::color($color), self::num($width),
            self::num($x1), self::num(self::A4_HEIGHT - $y1), self::num($x2), self::num(self::A4_HEIGHT - $y2)));
    }

    /** Ссылка на адрес в прямоугольнике. */
    public function link(float $x, float $y, float $width, float $height, string $url): void
    {
        $rect = \sprintf('%s %s %s %s', self::num($x), self::num(self::A4_HEIGHT - $y - $height), self::num($x + $width), self::num(self::A4_HEIGHT - $y));
        $this->pages[$this->page]['annots'][] = '<< /Type /Annot /Subtype /Link /Rect ['.$rect.'] /Border [0 0 0] /A << /S /URI /URI '.self::literal($url).' >> >>';
    }

    /**
     * QR-код (матрица из QrCode::matrix()): тёмные модули — закрашенные квадраты.
     *
     * @param list<list<bool>> $matrix
     */
    public function qr(array $matrix, float $x, float $y, float $size): void
    {
        $count = \count($matrix);
        if (0 === $count) {
            return;
        }
        $module = $size / $count;
        $ops = ['q 0 0 0 rg'];
        foreach ($matrix as $row => $cells) {
            $start = null;
            foreach ([...$cells, false] as $col => $dark) {
                if ($dark && null === $start) {
                    $start = $col;
                } elseif (!$dark && null !== $start) {
                    $ops[] = \sprintf('%s %s %s %s re', self::num($x + $start * $module), self::num(self::A4_HEIGHT - $y - ($row + 1) * $module),
                        self::num(($col - $start) * $module), self::num($module));
                    $start = null;
                }
            }
        }
        $ops[] = 'f Q';
        $this->out(implode("\n", $ops));
    }

    /** Картинки крупнее (в пикселях) не раскодируются: на это ушли бы сотни мегабайт памяти. */
    public const MAX_DECODE_PIXELS = 36_000_000;

    /** Перекодированные картинки уменьшаются до этого размера по длинной стороне (печать A4 — около 200 dpi). */
    private const MAX_IMAGE_SIDE = 2400;

    /**
     * Размер, который займёт изображение при вписывании в прямоугольник (null — файл не распознан).
     *
     * @return array{width: float, height: float}|null
     */
    public static function fitImage(string $bytes, float $maxWidth, float $maxHeight): ?array
    {
        $info = @getimagesizefromstring($bytes);
        if (false === $info || $info[0] < 1 || $info[1] < 1) {
            return null;
        }
        // Не крупнее 1,5 пункта на пиксель: маленькие картинки не растягиваются на всю страницу.
        $scale = min($maxWidth / $info[0], $maxHeight / $info[1], 1.5);

        return ['width' => $info[0] * $scale, 'height' => $info[1] * $scale];
    }

    /**
     * Изображение (JPEG — как есть, PNG/GIF/WebP/BMP — перекодируется в JPEG); вписывается в прямоугольник с
     * сохранением пропорций. Возвращает высоту нарисованного изображения или null, если файл не распознан.
     */
    public function image(string $bytes, float $x, float $y, float $maxWidth, float $maxHeight): ?float
    {
        $fit = self::fitImage($bytes, $maxWidth, $maxHeight);
        $info = @getimagesizefromstring($bytes);
        if (null === $fit || false === $info) {
            return null;
        }
        if (\IMAGETYPE_JPEG !== $info[2]) {
            $bytes = self::toJpeg($bytes, $info[0], $info[1]);
            if (null === $bytes) {
                return null;
            }
            $info = @getimagesizefromstring($bytes) ?: $info;
        }
        $components = (int) ($info['channels'] ?? 3);
        $this->images[] = ['data' => $bytes, 'width' => (int) $info[0], 'height' => (int) $info[1], 'components' => \in_array($components, [1, 3, 4], true) ? $components : 3];
        $this->out(\sprintf('q %s 0 0 %s %s %s cm /Im%d Do Q', self::num($fit['width']), self::num($fit['height']), self::num($x), self::num(self::A4_HEIGHT - $y - $fit['height']), \count($this->images)));

        return $fit['height'];
    }

    private static function toJpeg(string $bytes, int $width, int $height): ?string
    {
        if (!\function_exists('imagecreatefromstring') || $width * $height > self::MAX_DECODE_PIXELS) {
            return null;
        }
        $source = @imagecreatefromstring($bytes);
        if (false === $source) {
            return null;
        }
        $scale = min(1.0, self::MAX_IMAGE_SIDE / max(imagesx($source), imagesy($source)));
        $targetWidth = max(1, (int) round(imagesx($source) * $scale));
        $targetHeight = max(1, (int) round(imagesy($source) * $scale));
        // Прозрачные области — на белом фоне.
        $canvas = imagecreatetruecolor($targetWidth, $targetHeight);
        imagefill($canvas, 0, 0, (int) imagecolorallocate($canvas, 255, 255, 255));
        imagecopyresampled($canvas, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, imagesx($source), imagesy($source));
        imagedestroy($source);
        ob_start();
        imagejpeg($canvas, null, 88);
        $jpeg = (string) ob_get_clean();
        imagedestroy($canvas);

        return '' !== $jpeg ? $jpeg : null;
    }

    // ---- Сборка файла -----------------------------------------------------------------------------------

    public function output(): string
    {
        if ([] === $this->pages) {
            $this->addPage();
        }
        if (null !== $this->footer) {
            $count = \count($this->pages);
            foreach (array_keys($this->pages) as $index) {
                $this->page = $index;
                ($this->footer)($this, $index + 1, $count);
            }
        }

        $objects = [];
        $add = static function (string $body) use (&$objects): int {
            $objects[] = $body;

            return \count($objects);
        };
        $reserve = static function () use (&$objects): int {
            $objects[] = '';

            return \count($objects);
        };
        $catalogId = $reserve();
        $pagesId = $reserve();

        $fontRefs = [];
        foreach ($this->fonts as $style => $entry) {
            if ([] === $entry['glyphs']) {
                continue;
            }
            $fontRefs[$entry['key']] = $this->embedFont($entry['font'], $entry['glyphs'], $add);
        }
        $imageRefs = [];
        foreach ($this->images as $i => $image) {
            $space = 1 === $image['components'] ? '/DeviceGray' : (4 === $image['components'] ? '/DeviceCMYK' : '/DeviceRGB');
            $imageRefs['Im'.($i + 1)] = $add(\sprintf("<< /Type /XObject /Subtype /Image /Width %d /Height %d /ColorSpace %s /BitsPerComponent 8 /Filter /DCTDecode%s /Length %d >>\nstream\n%s\nendstream",
                $image['width'], $image['height'], $space, 4 === $image['components'] ? ' /Decode [1 0 1 0 1 0 1 0]' : '', \strlen($image['data']), $image['data']));
        }
        $resources = '<< /ProcSet [/PDF /Text /ImageC /ImageB]';
        if ([] !== $fontRefs) {
            $resources .= ' /Font <<'.implode('', array_map(static fn (string $k, int $id): string => ' /'.$k.' '.$id.' 0 R', array_keys($fontRefs), $fontRefs)).' >>';
        }
        if ([] !== $imageRefs) {
            $resources .= ' /XObject <<'.implode('', array_map(static fn (string $k, int $id): string => ' /'.$k.' '.$id.' 0 R', array_keys($imageRefs), $imageRefs)).' >>';
        }
        $resources .= ' >>';

        $kids = [];
        foreach ($this->pages as $page) {
            $stream = (string) gzcompress($page['content'], 6);
            $contentId = $add(\sprintf("<< /Length %d /Filter /FlateDecode >>\nstream\n%s\nendstream", \strlen($stream), $stream));
            $annots = '';
            if ([] !== $page['annots']) {
                $ids = array_map($add, $page['annots']);
                $annots = ' /Annots ['.implode(' ', array_map(static fn (int $id): string => $id.' 0 R', $ids)).']';
            }
            $kids[] = $add(\sprintf('<< /Type /Page /Parent %d 0 R /MediaBox [0 0 %s %s] /Resources %s /Contents %d 0 R%s >>',
                $pagesId, self::num(self::A4_WIDTH), self::num(self::A4_HEIGHT), $resources, $contentId, $annots));
        }
        $objects[$pagesId - 1] = \sprintf('<< /Type /Pages /Kids [%s] /Count %d >>', implode(' ', array_map(static fn (int $id): string => $id.' 0 R', $kids)), \count($kids));
        $objects[$catalogId - 1] = \sprintf('<< /Type /Catalog /Pages %d 0 R >>', $pagesId);
        $info = ['Producer' => self::literal('docportal'), 'CreationDate' => self::literal(self::pdfDate())];
        foreach ($this->info as $key => $value) {
            $info[$key] = self::textString($value);
        }
        $infoId = $add('<<'.implode('', array_map(static fn (string $k, string $v): string => ' /'.$k.' '.$v, array_keys($info), $info)).' >>');

        $pdf = "%PDF-1.7\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];
        foreach ($objects as $index => $body) {
            $offsets[] = \strlen($pdf);
            $pdf .= ($index + 1)." 0 obj\n".$body."\nendobj\n";
        }
        $xref = \strlen($pdf);
        $pdf .= 'xref'."\n0 ".(\count($objects) + 1)."\n0000000000 65535 f \n";
        foreach ($offsets as $offset) {
            $pdf .= \sprintf("%010d 00000 n \n", $offset);
        }
        $id = md5($pdf);
        $pdf .= \sprintf("trailer\n<< /Size %d /Root %d 0 R /Info %d 0 R /ID [<%s> <%s>] >>\nstartxref\n%d\n%%%%EOF\n", \count($objects) + 1, $catalogId, $infoId, $id, $id, $xref);

        return $pdf;
    }

    /**
     * @param array<int, int>         $glyphs глиф → символ (для ToUnicode)
     * @param callable(string): int  $add
     */
    private function embedFont(TrueTypeFont $font, array $glyphs, callable $add): int
    {
        ksort($glyphs);
        $subset = $font->subset(array_keys($glyphs));
        $compressed = (string) gzcompress($subset, 6);
        $tag = '';
        foreach (str_split(substr(md5(implode(',', array_keys($glyphs)).$font->postScriptName), 0, 6)) as $char) {
            $tag .= \chr(65 + hexdec($char) % 26);
        }
        $name = $tag.'+'.$font->postScriptName;
        $fileId = $add(\sprintf("<< /Length %d /Length1 %d /Filter /FlateDecode >>\nstream\n%s\nendstream", \strlen($compressed), \strlen($subset), $compressed));
        $scale = 1000 / $font->unitsPerEm;
        $flags = 32 | ($font->italicAngle ? 64 : 0) | ($font->bold ? 262144 : 0);
        $descriptorId = $add(\sprintf('<< /Type /FontDescriptor /FontName /%s /Flags %d /FontBBox [%d %d %d %d] /ItalicAngle %d /Ascent %d /Descent %d /CapHeight %d /StemV %d /FontFile2 %d 0 R >>',
            $name, $flags, (int) round($font->bbox[0] * $scale), (int) round($font->bbox[1] * $scale), (int) round($font->bbox[2] * $scale), (int) round($font->bbox[3] * $scale),
            $font->italicAngle, (int) round($font->ascender * $scale), (int) round($font->descender * $scale), (int) round($font->capHeight * $scale), $font->bold ? 140 : 80, $fileId));
        // Ширины: последовательные глифы — одной группой.
        $w = '';
        $run = [];
        $start = null;
        $previous = null;
        foreach (array_keys($glyphs) as $glyph) {
            if (null !== $previous && $glyph === $previous + 1) {
                $run[] = $font->advance1000($glyph);
            } else {
                if (null !== $start) {
                    $w .= $start.' ['.implode(' ', $run).'] ';
                }
                $start = $glyph;
                $run = [$font->advance1000($glyph)];
            }
            $previous = $glyph;
        }
        if (null !== $start) {
            $w .= $start.' ['.implode(' ', $run).']';
        }
        $cidId = $add(\sprintf('<< /Type /Font /Subtype /CIDFontType2 /BaseFont /%s /CIDSystemInfo << /Registry (Adobe) /Ordering (Identity) /Supplement 0 >> /FontDescriptor %d 0 R /DW %d /W [%s] /CIDToGIDMap /Identity >>',
            $name, $descriptorId, $font->advance1000(0), $w));
        $toUnicode = self::toUnicode($glyphs);
        $toUnicodeStream = (string) gzcompress($toUnicode, 6);
        $toUnicodeId = $add(\sprintf("<< /Length %d /Filter /FlateDecode >>\nstream\n%s\nendstream", \strlen($toUnicodeStream), $toUnicodeStream));

        return $add(\sprintf('<< /Type /Font /Subtype /Type0 /BaseFont /%s /Encoding /Identity-H /DescendantFonts [%d 0 R] /ToUnicode %d 0 R >>', $name, $cidId, $toUnicodeId));
    }

    /** @param array<int, int> $glyphs */
    private static function toUnicode(array $glyphs): string
    {
        $map = "/CIDInit /ProcSet findresource begin\n12 dict begin\nbegincmap\n/CIDSystemInfo << /Registry (Adobe) /Ordering (UCS) /Supplement 0 >> def\n/CMapName /Adobe-Identity-UCS def\n/CMapType 2 def\n1 begincodespacerange\n<0000> <FFFF>\nendcodespacerange\n";
        foreach (array_chunk($glyphs, 100, true) as $chunk) {
            $map .= \count($chunk)." beginbfchar\n";
            foreach ($chunk as $glyph => $codepoint) {
                $map .= \sprintf("<%04X> <%s>\n", $glyph, strtoupper(bin2hex((string) mb_convert_encoding(mb_chr($codepoint, 'UTF-8'), 'UTF-16BE', 'UTF-8'))));
            }
            $map .= "endbfchar\n";
        }

        return $map."endcmap\nCMapName currentdict /CMap defineresource pop\nend\nend\n";
    }

    // ---- Служебное -----------------------------------------------------------------------------------------

    private function out(string $operations): void
    {
        if ($this->page < 0) {
            $this->addPage();
        }
        $this->pages[$this->page]['content'] .= $operations."\n";
    }

    /** @return list<int> */
    private static function codepoints(string $text): array
    {
        $text = (string) preg_replace('/[\x00-\x08\x0B-\x1F\x7F]/u', '', $text);
        $text = str_replace(["\t", "\u{00A0}"], [' ', ' '], $text);

        return array_map(static fn (string $c): int => mb_ord($c, 'UTF-8') ?: 0x3F, mb_str_split($text, 1, 'UTF-8'));
    }

    /** @param array{0: float, 1: float, 2: float} $color */
    private static function color(array $color): string
    {
        return \sprintf('%s %s %s', self::num($color[0]), self::num($color[1]), self::num($color[2]));
    }

    private static function num(float $value): string
    {
        $text = rtrim(rtrim(\sprintf('%.3F', $value), '0'), '.');

        return '-0' === $text ? '0' : $text;
    }

    /** Строка PDF только из ASCII (адреса, даты). */
    private static function literal(string $value): string
    {
        return '('.strtr($value, ['\\' => '\\\\', '(' => '\\(', ')' => '\\)', "\r" => '\\r', "\n" => '\\n']).')';
    }

    /** Текстовая строка PDF в UTF-16BE (свойства документа на русском). */
    private static function textString(string $value): string
    {
        return '<FEFF'.strtoupper(bin2hex((string) mb_convert_encoding($value, 'UTF-16BE', 'UTF-8'))).'>';
    }

    private static function pdfDate(): string
    {
        $now = new \DateTimeImmutable();
        $offset = $now->format('P');

        return 'D:'.$now->format('YmdHis').substr($offset, 0, 3)."'".substr($offset, 4, 2)."'";
    }
}
