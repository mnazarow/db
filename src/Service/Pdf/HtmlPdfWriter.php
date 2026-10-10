<?php

declare(strict_types=1);

namespace App\Service\Pdf;

/**
 * Страница документа (HTML из редактора портала) в PDF: заголовки, абзацы, маркированные и нумерованные
 * списки (в том числе списки Quill 2 — <ol><li data-list="bullet">), цитаты, код, таблицы, картинки,
 * вставленные в страницу (data:), и разделители.
 *
 * Оформление внутри абзаца (курсив, подчёркивание, цвет) упрощается до обычного текста; абзац, целиком
 * набранный полужирным, остаётся полужирным. У ссылок после текста печатается адрес.
 */
final class HtmlPdfWriter
{
    private const SIZE = 10.0;

    /** @var array<string, float> */
    private const HEADINGS = ['h1' => 15.0, 'h2' => 13.0, 'h3' => 11.5, 'h4' => 10.5, 'h5' => 10.0, 'h6' => 10.0];

    private const BLOCKS = ['p', 'div', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'ul', 'ol', 'li', 'blockquote', 'pre', 'table', 'hr', 'img',
        'section', 'article', 'header', 'footer', 'figure', 'figcaption', 'dl', 'dt', 'dd', 'address', 'center', 'main', 'nav', 'aside'];

    private const MAX_TABLE_COLUMNS = 8;

    /** Картинка data: больше этого не встраивается. */
    private const MAX_IMAGE_BYTES = 15 * 1024 * 1024;

    private const LIST_INDENT = 16.0;

    public function __construct(
        private readonly PdfDocument $pdf,
        private readonly ?string $baseUrl = null,
    ) {
    }

    public function write(string $html): void
    {
        if ('' === trim(strip_tags($html, '<img><hr><table>'))) {
            $this->pdf->write('Страница пуста.', self::SIZE, PdfDocument::REGULAR, PdfDocument::MUTED);

            return;
        }
        $dom = new \DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8"?><html><body><div id="docportal-root">'.$html.'</div></body></html>', \LIBXML_NONET | \LIBXML_COMPACT);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $root = $dom->getElementById('docportal-root');
        if (null === $root) {
            $this->pdf->write(trim(html_entity_decode(strip_tags($html), \ENT_QUOTES | \ENT_HTML5, 'UTF-8')), self::SIZE);

            return;
        }
        $this->flow($root, 0.0, self::SIZE, PdfDocument::REGULAR, PdfDocument::BLACK);
    }

    /**
     * Содержимое элемента: подряд идущие строчные узлы — один абзац, блочные — каждый по своим правилам.
     *
     * @param array{0: float, 1: float, 2: float} $color
     */
    private function flow(\DOMNode $parent, float $indent, float $size, string $style, array $color): void
    {
        $text = '';
        $images = [];
        foreach ($parent->childNodes as $node) {
            if ($node instanceof \DOMElement && \in_array(strtolower($node->tagName), self::BLOCKS, true)) {
                $this->paragraph($text, $images, $indent, $size, $style, $color);
                $text = '';
                $images = [];
                $this->block($node, $indent, $color);
                continue;
            }
            $text .= $this->inline($node, $images);
        }
        $this->paragraph($text, $images, $indent, $size, $style, $color);
    }

    /** @param array{0: float, 1: float, 2: float} $color */
    private function block(\DOMElement $element, float $indent, array $color): void
    {
        $tag = strtolower($element->tagName);
        if (isset(self::HEADINGS[$tag])) {
            $this->pdf->space('h1' === $tag ? 10 : 7);
            $this->pdf->ensureSpace(self::HEADINGS[$tag] * 3.2); // заголовок не остаётся один внизу страницы
            $this->flow($element, $indent, self::HEADINGS[$tag], PdfDocument::BOLD, PdfDocument::BRAND);
            $this->pdf->space(3);

            return;
        }
        match ($tag) {
            'p' => $this->paragraphElement($element, $indent, $color),
            'ul', 'ol' => $this->listBlock($element, $indent, $color),
            'li' => $this->hanging('•', $this->text($element), $indent, $color),
            'blockquote' => $this->quote($element, $indent),
            'pre' => $this->pre($element, $indent),
            'table' => $this->table($element, $indent),
            'hr' => $this->rule(),
            'img' => $this->image($element, $indent),
            default => $this->flow($element, $indent, self::SIZE, PdfDocument::REGULAR, $color),
        };
    }

    /** @param array{0: float, 1: float, 2: float} $color */
    private function paragraphElement(\DOMElement $p, float $indent, array $color): void
    {
        $text = trim($p->textContent);
        $bold = '' !== $text && $text === trim($this->boldText($p));
        // Пустой абзац (<p><br></p>) — пустая строка.
        if ('' === $text && 0 === $p->getElementsByTagName('img')->length) {
            $this->pdf->space(self::SIZE * 0.9);

            return;
        }
        $this->flow($p, $indent, self::SIZE, $bold ? PdfDocument::BOLD : PdfDocument::REGULAR, $color);
        $this->pdf->space(4.5);
    }

    private function boldText(\DOMNode $node): string
    {
        if ($node instanceof \DOMElement && \in_array(strtolower($node->tagName), ['strong', 'b'], true)) {
            return $node->textContent;
        }
        $text = '';
        foreach ($node->childNodes as $child) {
            $text .= $this->boldText($child);
        }

        return $text;
    }

    /**
     * @param list<\DOMElement>                    $images
     * @param array{0: float, 1: float, 2: float} $color
     */
    private function paragraph(string $text, array $images, float $indent, float $size, string $style, array $color): void
    {
        $text = self::tidy($text);
        if ('' !== $text) {
            $this->pdf->write($text, $size, $style, $color, 1.35, $indent);
        }
        foreach ($images as $image) {
            $this->image($image, $indent);
        }
    }

    /**
     * Текст строчных элементов; картинки внутри абзаца откладываются и выводятся после него.
     *
     * @param list<\DOMElement> $images
     */
    private function inline(\DOMNode $node, array &$images): string
    {
        if ($node instanceof \DOMText) {
            return (string) preg_replace('/[\s\x{00A0}]+/u', ' ', $node->data);
        }
        if (!$node instanceof \DOMElement) {
            return '';
        }
        $tag = strtolower($node->tagName);
        if ('br' === $tag) {
            return "\n";
        }
        if ('img' === $tag) {
            $images[] = $node;

            return '';
        }
        if (\in_array($tag, ['script', 'style', 'template'], true)) {
            return '';
        }
        $text = '';
        foreach ($node->childNodes as $child) {
            $text .= $this->inline($child, $images);
        }
        if ('a' === $tag) {
            $href = $this->absoluteUrl(trim($node->getAttribute('href')));
            if (null !== $href && !str_contains($text, $href) && '' !== trim($text)) {
                $text .= ' ('.$href.')';
            }
        }

        return $text;
    }

    /** Текст элемента целиком (ячейки таблиц, пункты списков): блоки внутри — с новой строки. */
    private function text(\DOMNode $node, bool $skipLists = false): string
    {
        $images = [];
        $text = '';
        foreach ($node->childNodes as $child) {
            if ($child instanceof \DOMElement) {
                $tag = strtolower($child->tagName);
                if ($skipLists && \in_array($tag, ['ul', 'ol'], true)) {
                    continue;
                }
                if ((\in_array($tag, self::BLOCKS, true) && 'img' !== $tag) || \in_array($tag, ['tr', 'thead', 'tbody', 'tfoot'], true)) {
                    $text .= "\n".$this->text($child, $skipLists)."\n";
                    continue;
                }
                if (\in_array($tag, ['td', 'th'], true)) {
                    // Ячейки вложенной таблицы — через разделитель, а не слитно.
                    $text .= $this->text($child, $skipLists).' · ';
                    continue;
                }
            }
            $text .= $this->inline($child, $images);
        }

        return self::tidy((string) preg_replace('/ · (\n|$)/u', '$1', $text));
    }

    /**
     * Список. Нумерация — как в редакторе портала: маркированные пункты между нумерованными её не
     * сбрасывают, уровни нумеруются «1.», «a.», «i.» по кругу.
     *
     * @param array{0: float, 1: float, 2: float} $color
     */
    private function listBlock(\DOMElement $list, float $indent, array $color, int $depth = 0): void
    {
        $ordered = 'ol' === strtolower($list->tagName);
        $counters = [];
        foreach ($list->childNodes as $item) {
            if (!$item instanceof \DOMElement) {
                continue;
            }
            if ('li' !== strtolower($item->tagName)) {
                $this->block($item, $indent + self::LIST_INDENT, $color);
                continue;
            }
            // Quill 2: вид пункта — в data-list, вложенность — в классе ql-indent-N.
            $type = $item->getAttribute('data-list');
            $level = preg_match('/\bql-indent-(\d+)\b/', $item->getAttribute('class'), $m) ? min(8, (int) $m[1]) : 0;
            $counters = \array_slice($counters, 0, $level + 1, true);
            $isOrdered = '' !== $type ? 'ordered' === $type : $ordered;
            if ($isOrdered) {
                $counters[$level] = ($counters[$level] ?? 0) + 1;
            }
            $prefix = match (true) {
                $isOrdered => self::marker($counters[$level], $depth + $level),
                'checked' === $type => '☑',
                'unchecked' === $type => '☐',
                default => 0 === ($depth + $level) % 2 ? '•' : '◦',
            };
            $itemIndent = $indent + $level * self::LIST_INDENT;
            $this->hanging($prefix, $this->text($item, true), $itemIndent, $color);
            foreach (self::nestedLists($item) as $nested) {
                $this->listBlock($nested, $itemIndent + self::LIST_INDENT, $color, $depth + $level + 1);
            }
        }
        $this->pdf->space(4);
    }

    /** Номер пункта: 1., a., i. — в зависимости от глубины. */
    private static function marker(int $number, int $depth): string
    {
        return match ($depth % 3) {
            1 => self::alpha($number),
            2 => self::roman($number),
            default => (string) $number,
        }.'.';
    }

    private static function alpha(int $number): string
    {
        $text = '';
        for ($n = max(1, $number); $n > 0; $n = intdiv($n - 1, 26)) {
            $text = \chr(97 + ($n - 1) % 26).$text;
        }

        return $text;
    }

    private static function roman(int $number): string
    {
        $text = '';
        foreach (['m' => 1000, 'cm' => 900, 'd' => 500, 'cd' => 400, 'c' => 100, 'xc' => 90, 'l' => 50, 'xl' => 40, 'x' => 10, 'ix' => 9, 'v' => 5, 'iv' => 4, 'i' => 1] as $symbol => $value) {
            for (; $number >= $value; $number -= $value) {
                $text .= $symbol;
            }
        }

        return '' !== $text ? $text : '0';
    }

    /**
     * Вложенные списки пункта — в том числе внутри абзаца или блока пункта (ближайший пункт-предок — этот).
     *
     * @return list<\DOMElement>
     */
    private static function nestedLists(\DOMElement $item): array
    {
        $lists = [];
        foreach ($item->getElementsByTagName('*') as $element) {
            if (\in_array(strtolower($element->tagName), ['ul', 'ol'], true) && self::closest($element, 'li') === $item) {
                $lists[] = $element;
            }
        }

        return $lists;
    }

    /** Ближайший предок с этим тегом. */
    private static function closest(\DOMNode $node, string $tag): ?\DOMElement
    {
        for ($parent = $node->parentNode; null !== $parent; $parent = $parent->parentNode) {
            if ($parent instanceof \DOMElement && strtolower($parent->tagName) === $tag) {
                return $parent;
            }
        }

        return null;
    }

    /**
     * Пункт списка: маркер слева, текст с висячим отступом.
     *
     * @param array{0: float, 1: float, 2: float} $color
     */
    private function hanging(string $prefix, string $text, float $indent, array $color): void
    {
        $size = self::SIZE;
        $step = $size * 1.35;
        $prefixWidth = max(self::LIST_INDENT, $this->pdf->textWidth($prefix, $size) + 6);
        $lines = $this->pdf->wrap('' !== $text ? $text : ' ', $size, PdfDocument::REGULAR, $this->pdf->contentWidth() - $indent - $prefixWidth);
        foreach ($lines as $i => $line) {
            $this->pdf->ensureSpace($step);
            $top = $this->pdf->y + ($step - $size) / 2;
            if (0 === $i) {
                $this->pdf->text($this->pdf->marginLeft + $indent, $top, $prefix, $size, PdfDocument::REGULAR, $color);
            }
            $this->pdf->text($this->pdf->marginLeft + $indent + $prefixWidth, $top, $line, $size, PdfDocument::REGULAR, $color);
            $this->pdf->y += $step;
        }
        $this->pdf->space(1.5);
    }

    private function quote(\DOMElement $quote, float $indent): void
    {
        $this->pdf->space(2);
        $this->flow($quote, $indent + 14, self::SIZE, PdfDocument::REGULAR, PdfDocument::MUTED);
        $this->pdf->space(4);
    }

    private function pre(\DOMElement $pre, float $indent): void
    {
        $size = 8.5;
        $step = $size * 1.3;
        foreach (preg_split('/\R/u', rtrim($pre->textContent)) ?: [] as $source) {
            $source = str_replace("\t", '    ', $source);
            foreach ($this->pdf->wrap('' !== $source ? $source : ' ', $size, PdfDocument::REGULAR, $this->pdf->contentWidth() - $indent - 8) as $line) {
                $this->pdf->ensureSpace($step);
                // Отступы в начале строки кода сохраняются: wrap() их срезает, поэтому сдвигаем вручную.
                $lead = '' !== $line && preg_match('/^( +)/', $source, $m) && str_starts_with(ltrim($source), $line) ? $this->pdf->textWidth($m[1], $size) : 0.0;
                $this->pdf->text($this->pdf->marginLeft + $indent + 8 + $lead, $this->pdf->y + ($step - $size) / 2, $line, $size, PdfDocument::REGULAR, PdfDocument::BLACK);
                $this->pdf->y += $step;
            }
        }
        $this->pdf->space(5);
    }

    private function table(\DOMElement $table, float $indent): void
    {
        $rows = [];
        $header = false;
        $index = 0;
        foreach ($table->getElementsByTagName('tr') as $tr) {
            if (self::closest($tr, 'table') !== $table) {
                continue; // строка вложенной таблицы — она войдёт в текст своей ячейки
            }
            $cells = [];
            $allHeaders = true;
            foreach ($tr->childNodes as $cell) {
                if ($cell instanceof \DOMElement && \in_array(strtolower($cell->tagName), ['td', 'th'], true)) {
                    $cells[] = $this->text($cell);
                    $allHeaders = $allHeaders && 'th' === strtolower($cell->tagName);
                }
            }
            if ([] === $cells) {
                continue;
            }
            if (0 === $index && $allHeaders) {
                $header = true;
            }
            ++$index;
            $rows[] = $cells;
        }
        if ([] === $rows) {
            return;
        }
        $count = max(array_map('count', $rows));
        if ($count > self::MAX_TABLE_COLUMNS) {
            // Слишком широкая таблица не помещается на A4 — строки печатаются через разделитель.
            foreach ($rows as $cells) {
                $this->pdf->write(implode(' · ', array_filter($cells, static fn (string $c): bool => '' !== $c)), 9.0, PdfDocument::REGULAR, PdfDocument::BLACK, 1.3, $indent);
            }
            $this->pdf->space(6);

            return;
        }
        // Ширина столбца — по длине самого длинного слова и среднего текста в нём.
        $weights = array_fill(0, $count, 1.0);
        foreach ($rows as $cells) {
            foreach (array_pad($cells, $count, '') as $i => $cell) {
                $longestWord = max(array_map('mb_strlen', preg_split('/\s+/u', $cell) ?: ['']));
                $weights[$i] = max($weights[$i], min(40, mb_strlen($cell)) * 0.6, $longestWord * 1.0);
            }
        }
        $sum = array_sum($weights);
        $columns = [];
        foreach ($weights as $i => $weight) {
            $columns[] = ['title' => $header ? ($rows[0][$i] ?? '') : '', 'width' => $weight / $sum];
        }
        $body = $header ? \array_slice($rows, 1) : $rows;
        $body = array_map(static fn (array $cells): array => array_pad($cells, $count, ''), $body);
        $this->pdf->space(2);
        $this->pdf->table($columns, $body, ['size' => 9.0, 'header' => $header]);
        $this->pdf->space(8);
    }

    private function rule(): void
    {
        $this->pdf->space(4);
        $this->pdf->ensureSpace(8);
        $this->pdf->line($this->pdf->marginLeft, $this->pdf->y, PdfDocument::A4_WIDTH - $this->pdf->marginRight, $this->pdf->y, 0.6);
        $this->pdf->space(8);
    }

    private function image(\DOMElement $img, float $indent): void
    {
        $bytes = self::dataUri(trim($img->getAttribute('src')));
        $alt = trim($img->getAttribute('alt'));
        $maxWidth = $this->pdf->contentWidth() - $indent;
        $maxHeight = PdfDocument::A4_HEIGHT - $this->pdf->marginTop - $this->pdf->marginBottom - 10;
        $fit = null !== $bytes ? PdfDocument::fitImage($bytes, $maxWidth, $maxHeight) : null;
        if (null === $bytes || null === $fit) {
            $this->pdf->write('[Изображение'.('' !== $alt ? ': '.$alt : '').' — смотрите в портале]', 9.0, PdfDocument::REGULAR, PdfDocument::MUTED, 1.35, $indent);

            return;
        }
        $this->pdf->ensureSpace($fit['height'] + 4);
        $drawn = $this->pdf->image($bytes, $this->pdf->marginLeft + $indent, $this->pdf->y + 2, $maxWidth, $maxHeight);
        if (null === $drawn) {
            $this->pdf->write('[Изображение не удалось встроить — смотрите в портале]', 9.0, PdfDocument::REGULAR, PdfDocument::MUTED, 1.35, $indent);

            return;
        }
        $this->pdf->y += $drawn + 8;
    }

    /** Содержимое картинки, вставленной в страницу как data: (адреса во внешней сети не загружаются). */
    private static function dataUri(string $src): ?string
    {
        if (1 !== preg_match('~^data:image/(png|jpe?g|gif|webp|bmp);base64,~i', $src, $m)) {
            return null;
        }
        $payload = substr($src, \strlen($m[0]));
        if (\strlen($payload) > self::MAX_IMAGE_BYTES * 4 / 3 + 8) {
            return null;
        }
        $bytes = base64_decode((string) preg_replace('/\s+/', '', $payload), true);

        return false === $bytes || '' === $bytes ? null : $bytes;
    }

    private function absoluteUrl(string $href): ?string
    {
        if ('' === $href || str_starts_with($href, '#') || str_starts_with(strtolower($href), 'javascript:')) {
            return null;
        }
        if (1 === preg_match('~^(https?|mailto|tel):~i', $href)) {
            return preg_replace('~^(mailto|tel):~i', '', $href);
        }
        if (str_starts_with($href, '/') && !str_starts_with($href, '//') && null !== $this->baseUrl) {
            return rtrim($this->baseUrl, '/').$href;
        }

        return null;
    }

    /** Пробелы и переводы строк: без повторов и пробелов по краям строк. */
    private static function tidy(string $text): string
    {
        $text = (string) preg_replace('/[ \t\x{00A0}]+/u', ' ', $text);
        $text = (string) preg_replace('/ *\n */u', "\n", $text);
        $text = (string) preg_replace("/\n{3,}/u", "\n\n", $text);

        return trim($text);
    }
}
