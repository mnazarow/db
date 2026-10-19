<?php

declare(strict_types=1);

namespace App\Service\Presentation;

/**
 * Формат слайдов презентации («колода», JSON) и его проверка: всё, что присылает редактор, приводится
 * к известному набору полей и значений — так выгрузка в PowerPoint, сборка собрания и показ работают
 * с предсказуемыми данными, а в тексте слайдов не бывает скриптов и чужой разметки.
 *
 * Колода: {format: "docportal/deck", version: 1, size: {width: 1280, height: 720},
 *          meta: {title, responsible}, slides: [слайд]}.
 * Слайд: {id, layout, name, background: {color, image, fit}, transition, hidden, notes, elements: [элемент]}.
 * Элемент: общие поля id, type, x, y, w, h (пиксели слайда 1280×720), rotation, opacity, locked, group,
 * name, bind, anim {type, order, delay, duration}, link {slide | url}, shadow {blur, x, y, color} и поля типа:
 * - text, shape: html (абзацы <p data-list data-level style="text-align">, жирный, курсив, подчёркивание,
 *   зачёркивание, цвет, размер, ссылки), fontFamily, fontSize (пт), fontWeight, italic, color, align, valign,
 *   lineHeight (как «множитель» PowerPoint), letterSpacing (пт), caps, spaceBefore (пт), padding, fill,
 *   gradient {angle, from, to}, stroke, strokeWidth, dash, radius; у shape — ещё shape (вид фигуры);
 * - line: x1, y1, x2, y2, stroke, strokeWidth, dash, start, end (наконечники);
 * - image: src (asset:КЛЮЧ | brand:ИМЯ | пусто — место для изображения), fit, focusX, focusY, zoom, radius,
 *   mask (none | circle), border {color, width}, alt;
 * - table: rows [[{text, bold, italic, color, fill, align}]], cols (ширины), style {...};
 * - chart: chart (column | bar | line | area | pie | doughnut), categories, series [{name, values, color}],
 *   legend, labels, title, stacked, fontSize;
 * - qr: text, color, bg.
 * Цвета — #RRGGBB или #RRGGBBAA.
 */
final class DeckValidator
{
    public const FORMAT = 'docportal/deck';
    public const VERSION = 1;
    public const WIDTH = 1280;
    public const HEIGHT = 720;

    public const MAX_JSON_BYTES = 8 * 1024 * 1024;
    public const MAX_SLIDES = 300;
    public const MAX_ELEMENTS = 400;

    public const TYPES = ['text', 'shape', 'line', 'image', 'table', 'chart', 'qr'];

    public const SHAPES = ['rect', 'roundRect', 'ellipse', 'triangle', 'rtTriangle', 'diamond', 'pentagon', 'hexagon', 'octagon',
        'star5', 'star6', 'rightArrow', 'leftArrow', 'upArrow', 'downArrow', 'leftRightArrow', 'chevron', 'homePlate',
        'parallelogram', 'trapezoid', 'plus', 'heart', 'donut', 'wedgeRectCallout', 'wedgeRoundRectCallout', 'cloud', 'can', 'cube', 'lightningBolt', 'moon', 'sun'];

    public const FONTS = ['DIN Condensed Light', 'DIN Condensed', 'Roboto', 'Arial', 'Georgia', 'Times New Roman', 'Courier New'];

    public const CHARTS = ['column', 'bar', 'line', 'area', 'pie', 'doughnut'];
    public const ANIMATIONS = ['fade', 'fade-up', 'fade-down', 'fade-left', 'fade-right', 'zoom', 'wipe'];
    public const TRANSITIONS = ['none', 'fade', 'slide', 'zoom', 'cover'];
    public const ARROWS = ['none', 'arrow', 'triangle', 'oval', 'diamond'];
    public const BINDS = ['title', 'responsible', 'meetingTitle', 'meetingDate'];

    private const ID_RE = '/^[A-Za-z0-9_-]{1,40}$/';
    private const COLOR_RE = '/^#[0-9A-Fa-f]{6}(?:[0-9A-Fa-f]{2})?$/';

    /**
     * Проверить и привести колоду к формату. Неизвестные поля отбрасываются, числа ограничиваются
     * допустимыми пределами, текст очищается.
     *
     * @param array{title?: string, responsible?: string} $meta заголовок и ответственный презентации (для привязанных полей)
     *
     * @return array<string, mixed>
     *
     * @throws \InvalidArgumentException колода не того формата
     */
    public function normalize(mixed $deck, array $meta = []): array
    {
        if (!\is_array($deck)) {
            throw new \InvalidArgumentException('Слайды презентации не прочитаны: ожидается объект JSON.');
        }
        if (isset($deck['format']) && self::FORMAT !== $deck['format']) {
            throw new \InvalidArgumentException('Неизвестный формат презентации.');
        }
        $slides = $deck['slides'] ?? null;
        if (!\is_array($slides) || !array_is_list($slides)) {
            throw new \InvalidArgumentException('В презентации нет списка слайдов.');
        }
        if (\count($slides) > self::MAX_SLIDES) {
            throw new \InvalidArgumentException(\sprintf('Слишком много слайдов: %d (не больше %d).', \count($slides), self::MAX_SLIDES));
        }
        $inMeta = \is_array($deck['meta'] ?? null) ? $deck['meta'] : [];
        $title = self::str($meta['title'] ?? $inMeta['title'] ?? '', 255);
        $responsible = self::str($meta['responsible'] ?? $inMeta['responsible'] ?? '', 255);
        $out = [
            'format' => self::FORMAT,
            'version' => self::VERSION,
            'size' => ['width' => self::WIDTH, 'height' => self::HEIGHT],
            'meta' => ['title' => $title, 'responsible' => $responsible],
            'slides' => [],
        ];
        $ids = [];
        foreach ($slides as $slide) {
            if (!\is_array($slide)) {
                continue;
            }
            $s = $this->slide($slide, $out['meta']);
            while (isset($ids[$s['id']])) {
                $s['id'] = self::newId('s');
            }
            $ids[$s['id']] = true;
            $out['slides'][] = $s;
        }
        // Ссылки на слайды — только на существующие.
        foreach ($out['slides'] as &$s) {
            foreach ($s['elements'] as &$e) {
                if (isset($e['link']['slide']) && !isset($ids[$e['link']['slide']])) {
                    $e['link'] = null;
                }
            }
            unset($e);
        }
        unset($s);

        return $out;
    }

    /** @return array<string, mixed> пустая колода с одним слайдом из макета */
    public function emptyDeck(string $title, string $responsible, array $slides = []): array
    {
        return $this->normalize(['format' => self::FORMAT, 'meta' => ['title' => $title, 'responsible' => $responsible], 'slides' => $slides]);
    }

    /** @param array<string, mixed> $deck */
    public static function encode(array $deck): string
    {
        return json_encode($deck, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR | \JSON_PRESERVE_ZERO_FRACTION);
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function decode(?string $json): ?array
    {
        if (null === $json || '' === $json) {
            return null;
        }
        try {
            $data = json_decode($json, true, 64, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return \is_array($data) ? $data : null;
    }

    /**
     * Первый показываемый слайд — для миниатюр в списках.
     *
     * @param array<string, mixed> $deck
     */
    public static function preview(array $deck): ?string
    {
        foreach ($deck['slides'] ?? [] as $slide) {
            if (!($slide['hidden'] ?? false)) {
                return self::encode($slide);
            }
        }

        return isset($deck['slides'][0]) ? self::encode($deck['slides'][0]) : null;
    }

    /**
     * Ключи загруженных изображений, на которые ссылается колода.
     *
     * @param array<string, mixed> $deck
     *
     * @return list<string>
     */
    public static function assetTokens(array $deck): array
    {
        $out = [];
        foreach ($deck['slides'] ?? [] as $slide) {
            $bg = $slide['background']['image'] ?? null;
            if (\is_string($bg) && str_starts_with($bg, 'asset:')) {
                $out[substr($bg, 6)] = true;
            }
            foreach ($slide['elements'] ?? [] as $e) {
                $src = $e['src'] ?? null;
                if (\is_string($src) && str_starts_with($src, 'asset:')) {
                    $out[substr($src, 6)] = true;
                }
            }
        }

        return array_keys($out);
    }

    public static function newId(string $prefix = 'e'): string
    {
        return $prefix.bin2hex(random_bytes(5));
    }

    /**
     * Очистка текста элемента: только абзацы, переносы строк, жирный, курсив, подчёркивание, зачёркивание,
     * надстрочные и подстрочные знаки, цвет, размер, шрифт и межбуквенный интервал фрагмента, ссылки http(s)
     * и mailto. Блоки (div, li, заголовки) становятся абзацами, списки — абзацами с data-list.
     */
    public function sanitizeHtml(string $html): string
    {
        if (\strlen($html) > 200000) {
            $html = substr($html, 0, 200000);
        }
        $html = trim($html);
        if ('' === $html) {
            return '';
        }
        $doc = new \DOMDocument('1.0', 'UTF-8');
        $prev = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8"?><html><body><div id="root">'.$html.'</div></body></html>', \LIBXML_NONET | \LIBXML_HTML_NODEFDTD | \LIBXML_COMPACT);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        $root = $doc->getElementById('root');
        if (null === $root) {
            return '';
        }
        $blocks = [];
        $current = null;
        $flush = static function () use (&$blocks, &$current): void {
            if (null !== $current) {
                $blocks[] = $current;
                $current = null;
            }
        };
        $this->collectBlocks($root, $blocks, $current, $flush, ['list' => null, 'level' => 0, 'align' => null]);
        $flush();
        $out = '';
        foreach ($blocks as $b) {
            $attrs = '';
            if (null !== $b['list']) {
                $attrs .= ' data-list="'.$b['list'].'"';
            }
            if ($b['level'] > 0) {
                $attrs .= ' data-level="'.$b['level'].'"';
            }
            if (null !== $b['align']) {
                $attrs .= ' style="text-align:'.$b['align'].'"';
            }
            $inner = trim($b['html']);
            $inner = preg_replace('#(?:<br>)+$#', '', $inner) ?? $inner;
            $out .= '<p'.$attrs.'>'.('' === $inner ? '<br>' : $inner).'</p>';
        }
        // Пустой текст — пустая строка, а не «<p><br></p>».
        if ('<p><br></p>' === $out) {
            return '';
        }

        return $out;
    }

    /**
     * @param array{list: ?string, level: int, align: ?string} $ctx
     * @param list<array{list: ?string, level: int, align: ?string, html: string}> $blocks
     * @param array{list: ?string, level: int, align: ?string, html: string}|null $current
     */
    private function collectBlocks(\DOMNode $node, array &$blocks, ?array &$current, \Closure $flush, array $ctx): void
    {
        foreach (iterator_to_array($node->childNodes) as $child) {
            if ($child instanceof \DOMElement) {
                $tag = strtolower($child->tagName);
                if (\in_array($tag, ['p', 'div', 'li', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'blockquote', 'pre'], true)) {
                    $flush();
                    $c = $ctx;
                    $list = $child->getAttribute('data-list');
                    if (\in_array($list, ['bullet', 'number'], true)) {
                        $c['list'] = $list;
                    } elseif ('li' === $tag && null === $c['list']) {
                        $c['list'] = 'bullet';
                    }
                    $level = $child->getAttribute('data-level');
                    if ('' !== $level && ctype_digit($level)) {
                        $c['level'] = min(4, (int) $level);
                    }
                    $align = self::styleValue($child->getAttribute('style'), 'text-align');
                    if (null !== $align && \in_array($align, ['left', 'center', 'right', 'justify'], true)) {
                        $c['align'] = 'left' === $align ? null : $align;
                    }
                    if ($this->hasBlockChildren($child)) {
                        $this->collectBlocks($child, $blocks, $current, $flush, $c);
                        $flush();
                    } else {
                        $current = ['list' => $c['list'], 'level' => $c['level'], 'align' => $c['align'], 'html' => $this->inline($child)];
                        $flush();
                    }
                    continue;
                }
                if ('ul' === $tag || 'ol' === $tag) {
                    $flush();
                    $c = $ctx;
                    if (null !== $ctx['list']) {
                        $c['level'] = min(4, $ctx['level'] + 1);
                    }
                    $c['list'] = 'ol' === $tag ? 'number' : 'bullet';
                    $this->collectBlocks($child, $blocks, $current, $flush, $c);
                    $flush();
                    continue;
                }
                if (\in_array($tag, ['script', 'style', 'template', 'iframe', 'object', 'embed', 'svg', 'math', 'img', 'video', 'audio', 'canvas', 'input', 'button', 'select', 'textarea', 'form', 'head', 'title', 'meta', 'link'], true)) {
                    continue;
                }
                if ('br' === $tag) {
                    $current ??= ['list' => $ctx['list'], 'level' => $ctx['level'], 'align' => $ctx['align'], 'html' => ''];
                    $current['html'] .= '<br>';
                    continue;
                }
            }
            $current ??= ['list' => $ctx['list'], 'level' => $ctx['level'], 'align' => $ctx['align'], 'html' => ''];
            $current['html'] .= $child instanceof \DOMElement ? $this->inlineElement($child) : ($child instanceof \DOMText ? self::esc($child->data) : '');
        }
    }

    private function hasBlockChildren(\DOMElement $el): bool
    {
        foreach ($el->childNodes as $c) {
            if ($c instanceof \DOMElement && \in_array(strtolower($c->tagName), ['p', 'div', 'li', 'ul', 'ol', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'blockquote', 'pre'], true)) {
                return true;
            }
        }

        return false;
    }

    private function inline(\DOMNode $node): string
    {
        $out = '';
        foreach ($node->childNodes as $child) {
            if ($child instanceof \DOMText) {
                $out .= self::esc($child->data);
            } elseif ($child instanceof \DOMElement) {
                $out .= $this->inlineElement($child);
            }
        }

        return $out;
    }

    private function inlineElement(\DOMElement $el): string
    {
        $tag = strtolower($el->tagName);
        $inner = $this->inline($el);
        $map = ['strong' => 'b', 'b' => 'b', 'em' => 'i', 'i' => 'i', 'u' => 'u', 'ins' => 'u', 's' => 's', 'strike' => 's', 'del' => 's', 'sub' => 'sub', 'sup' => 'sup'];
        $styled = $this->styleSpan($el->getAttribute('style'), $el->getAttribute('color'));
        if (isset($map[$tag])) {
            $t = $map[$tag];
            $html = '' === $inner ? '' : '<'.$t.'>'.$inner.'</'.$t.'>';

            return null !== $styled && '' !== $html ? '<span style="'.$styled.'">'.$html.'</span>' : $html;
        }
        if ('br' === $tag) {
            return '<br>';
        }
        if ('a' === $tag) {
            $href = trim($el->getAttribute('href'));
            if (1 === preg_match('#^(https?://|mailto:)[^\s"<>]{1,2000}$#i', $href) && '' !== $inner) {
                return '<a href="'.self::esc($href).'">'.$inner.'</a>';
            }

            return $inner;
        }
        if (\in_array($tag, ['script', 'style', 'svg', 'math', 'img', 'iframe', 'object', 'embed'], true)) {
            return '';
        }
        // span, font и прочее — только допустимое оформление.
        if (null !== $styled && '' !== $inner) {
            return '<span style="'.$styled.'">'.$inner.'</span>';
        }

        return $inner;
    }

    /** Допустимое оформление фрагмента текста: цвет, размер (пт), насыщенность, начертание, украшения, шрифт, интервал. */
    private function styleSpan(string $style, string $fontColor = ''): ?string
    {
        $parts = [];
        $color = self::styleValue($style, 'color') ?? ('' !== $fontColor ? $fontColor : null);
        if (null !== $color && null !== ($c = self::cssColor($color))) {
            $parts[] = 'color:'.$c;
        }
        $bg = self::styleValue($style, 'background-color');
        if (null !== $bg && null !== ($c = self::cssColor($bg)) && !\in_array(strtolower($c), ['#ffffff00', '#00000000'], true)) {
            $parts[] = 'background-color:'.$c;
        }
        $size = self::styleValue($style, 'font-size');
        if (null !== $size && 1 === preg_match('/^(\d+(?:\.\d+)?)(pt|px)$/', $size, $m)) {
            $pt = 'px' === $m[2] ? (float) $m[1] * 0.75 : (float) $m[1];
            $parts[] = 'font-size:'.self::num(max(4, min(400, $pt))).'pt';
        }
        $weight = self::styleValue($style, 'font-weight');
        if (null !== $weight) {
            $w = 'bold' === $weight ? 700 : ('normal' === $weight ? 400 : (ctype_digit($weight) ? (int) $weight : null));
            if (null !== $w && $w >= 100 && $w <= 900) {
                $parts[] = 'font-weight:'.((int) (round($w / 100) * 100));
            }
        }
        $fs = self::styleValue($style, 'font-style');
        if ('italic' === $fs || 'normal' === $fs) {
            $parts[] = 'font-style:'.$fs;
        }
        $deco = self::styleValue($style, 'text-decoration') ?? self::styleValue($style, 'text-decoration-line');
        if (null !== $deco) {
            $d = [];
            foreach (['underline', 'line-through'] as $k) {
                if (str_contains($deco, $k)) {
                    $d[] = $k;
                }
            }
            if ([] !== $d) {
                $parts[] = 'text-decoration:'.implode(' ', $d);
            }
        }
        $family = self::styleValue($style, 'font-family');
        if (null !== $family) {
            $f = trim(explode(',', $family)[0], " \t'\"");
            if (\in_array($f, self::FONTS, true)) {
                $parts[] = "font-family:'".$f."'";
            }
        }
        $ls = self::styleValue($style, 'letter-spacing');
        if (null !== $ls && 1 === preg_match('/^(-?\d+(?:\.\d+)?)(pt|px)$/', $ls, $m)) {
            $pt = 'px' === $m[2] ? (float) $m[1] * 0.75 : (float) $m[1];
            $parts[] = 'letter-spacing:'.self::num(max(-20, min(100, $pt))).'pt';
        }
        $tt = self::styleValue($style, 'text-transform');
        if ('uppercase' === $tt || 'none' === $tt) {
            $parts[] = 'text-transform:'.$tt;
        }

        return [] === $parts ? null : implode(';', $parts);
    }

    private static function styleValue(string $style, string $prop): ?string
    {
        if ('' === $style) {
            return null;
        }
        $value = null;
        foreach (explode(';', $style) as $decl) {
            $kv = explode(':', $decl, 2);
            if (2 === \count($kv) && strtolower(trim($kv[0])) === $prop) {
                $value = strtolower(trim(str_replace('!important', '', $kv[1])));
            }
        }

        return null === $value || '' === $value ? null : $value;
    }

    /** CSS-цвет (#rgb, #rrggbb, #rrggbbaa, rgb(), rgba()) → #RRGGBB[AA]. */
    public static function cssColor(string $value): ?string
    {
        $value = strtolower(trim($value));
        if (1 === preg_match('/^#([0-9a-f]{3})$/', $value, $m)) {
            return '#'.strtoupper($m[1][0].$m[1][0].$m[1][1].$m[1][1].$m[1][2].$m[1][2]);
        }
        if (1 === preg_match('/^#([0-9a-f]{6}|[0-9a-f]{8})$/', $value, $m)) {
            return '#'.strtoupper($m[1]);
        }
        if (1 === preg_match('/^rgba?\(\s*(\d{1,3})\s*,\s*(\d{1,3})\s*,\s*(\d{1,3})\s*(?:,\s*([0-9.]+)\s*)?\)$/', $value, $m)) {
            $hex = \sprintf('#%02X%02X%02X', min(255, (int) $m[1]), min(255, (int) $m[2]), min(255, (int) $m[3]));
            if (isset($m[4]) && '' !== $m[4]) {
                $a = max(0.0, min(1.0, (float) $m[4]));
                if ($a < 1.0) {
                    $hex .= \sprintf('%02X', (int) round($a * 255));
                }
            }

            return $hex;
        }
        $named = ['black' => '#000000', 'white' => '#FFFFFF', 'red' => '#FF0000', 'transparent' => '#FFFFFF00'];

        return $named[$value] ?? null;
    }

    // ---- Слайды и элементы -----------------------------------------------------------------------------

    /**
     * @param array<string, mixed> $s
     * @param array{title: string, responsible: string} $meta
     *
     * @return array<string, mixed>
     */
    private function slide(array $s, array $meta): array
    {
        $id = self::idOr($s['id'] ?? null, 's');
        $bg = \is_array($s['background'] ?? null) ? $s['background'] : [];
        $elements = [];
        $list = \is_array($s['elements'] ?? null) && array_is_list($s['elements']) ? $s['elements'] : [];
        if (\count($list) > self::MAX_ELEMENTS) {
            throw new \InvalidArgumentException(\sprintf('На слайде слишком много элементов: %d (не больше %d).', \count($list), self::MAX_ELEMENTS));
        }
        $ids = [];
        foreach ($list as $e) {
            if (!\is_array($e) || !\in_array($e['type'] ?? null, self::TYPES, true)) {
                continue;
            }
            $el = $this->element($e, $meta);
            while (isset($ids[$el['id']])) {
                $el['id'] = self::newId();
            }
            $ids[$el['id']] = true;
            $elements[] = $el;
        }

        return [
            'id' => $id,
            'layout' => self::keyOr($s['layout'] ?? null),
            'name' => self::strOrNull($s['name'] ?? null, 120),
            'background' => [
                'color' => self::colorOr($bg['color'] ?? null, '#FFFFFF'),
                'image' => self::src($bg['image'] ?? null),
                'fit' => self::oneOf($bg['fit'] ?? null, ['cover', 'contain'], 'cover'),
            ],
            'transition' => self::oneOf($s['transition'] ?? null, self::TRANSITIONS, 'none'),
            'hidden' => true === ($s['hidden'] ?? false),
            'notes' => self::str($s['notes'] ?? '', 20000, true),
            'elements' => $elements,
        ];
    }

    /**
     * @param array<string, mixed> $e
     * @param array{title: string, responsible: string} $meta
     *
     * @return array<string, mixed>
     */
    private function element(array $e, array $meta): array
    {
        $type = (string) $e['type'];
        $out = [
            'id' => self::idOr($e['id'] ?? null, 'e'),
            'type' => $type,
            'x' => self::numIn($e['x'] ?? 0, -4000, 5280, 0),
            'y' => self::numIn($e['y'] ?? 0, -4000, 4720, 0),
            'w' => self::numIn($e['w'] ?? 100, 1, 6000, 100),
            'h' => self::numIn($e['h'] ?? 100, 1, 6000, 100),
            'rotation' => self::numIn($e['rotation'] ?? 0, -360, 360, 0),
            'opacity' => self::numIn($e['opacity'] ?? 1, 0, 1, 1),
            'locked' => true === ($e['locked'] ?? false),
            'group' => self::idOrNull($e['group'] ?? null),
            'name' => self::strOrNull($e['name'] ?? null, 120),
            'bind' => self::oneOf($e['bind'] ?? null, self::BINDS, null),
            'anim' => self::anim($e['anim'] ?? null),
            'link' => self::link($e['link'] ?? null),
            'shadow' => self::shadow($e['shadow'] ?? null),
        ];
        switch ($type) {
            case 'text':
            case 'shape':
                $out += $this->textProps($e);
                if ('shape' === $type) {
                    $out['shape'] = self::oneOf($e['shape'] ?? null, self::SHAPES, 'rect');
                }
                $out['fill'] = self::colorOrNull($e['fill'] ?? null) ?? ('shape' === $type && !\array_key_exists('fill', $e) ? '#0F4382' : null);
                $out['gradient'] = self::gradient($e['gradient'] ?? null);
                $out['stroke'] = self::colorOrNull($e['stroke'] ?? null);
                $out['strokeWidth'] = self::numIn($e['strokeWidth'] ?? 0, 0, 50, 0);
                $out['dash'] = self::oneOf($e['dash'] ?? null, ['solid', 'dash', 'dot'], 'solid');
                $out['radius'] = self::numIn($e['radius'] ?? 0, 0, 2000, 0);
                // Привязанные поля шапки: текст — из заголовка и ответственного презентации.
                if ('title' === $out['bind']) {
                    $out['html'] = '<p>'.self::esc($meta['title']).'</p>';
                } elseif ('responsible' === $out['bind']) {
                    $out['html'] = '' === $meta['responsible'] ? '' : self::responsibleHtml($meta['responsible']);
                }
                break;
            case 'line':
                $x1 = self::numIn($e['x1'] ?? $out['x'], -4000, 5280, 0);
                $y1 = self::numIn($e['y1'] ?? $out['y'], -4000, 4720, 0);
                $x2 = self::numIn($e['x2'] ?? ($out['x'] + $out['w']), -4000, 5280, 100);
                $y2 = self::numIn($e['y2'] ?? $out['y'], -4000, 4720, 0);
                $out = array_merge($out, [
                    'x1' => $x1, 'y1' => $y1, 'x2' => $x2, 'y2' => $y2,
                    'x' => min($x1, $x2), 'y' => min($y1, $y2), 'w' => max(1, abs($x2 - $x1)), 'h' => max(1, abs($y2 - $y1)),
                    'rotation' => 0,
                    'stroke' => self::colorOr($e['stroke'] ?? null, '#0F4382'),
                    'strokeWidth' => self::numIn($e['strokeWidth'] ?? 2, 0.25, 50, 2),
                    'dash' => self::oneOf($e['dash'] ?? null, ['solid', 'dash', 'dot'], 'solid'),
                    'start' => self::oneOf($e['start'] ?? null, self::ARROWS, 'none'),
                    'end' => self::oneOf($e['end'] ?? null, self::ARROWS, 'none'),
                ]);
                break;
            case 'image':
                $border = \is_array($e['border'] ?? null) ? $e['border'] : null;
                $out += [
                    'src' => self::src($e['src'] ?? null),
                    'fit' => self::oneOf($e['fit'] ?? null, ['cover', 'contain', 'fill'], 'cover'),
                    'focusX' => self::numIn($e['focusX'] ?? 0.5, 0, 1, 0.5),
                    'focusY' => self::numIn($e['focusY'] ?? 0.5, 0, 1, 0.5),
                    'zoom' => self::numIn($e['zoom'] ?? 1, 1, 5, 1),
                    'radius' => self::numIn($e['radius'] ?? 0, 0, 2000, 0),
                    'mask' => self::oneOf($e['mask'] ?? null, ['none', 'circle'], 'none'),
                    'border' => null !== $border && null !== self::colorOrNull($border['color'] ?? null) && self::numIn($border['width'] ?? 0, 0, 50, 0) > 0
                        ? ['color' => self::colorOrNull($border['color']), 'width' => self::numIn($border['width'], 0, 50, 1)] : null,
                    'alt' => self::str($e['alt'] ?? '', 500),
                    'flipH' => true === ($e['flipH'] ?? false),
                    'flipV' => true === ($e['flipV'] ?? false),
                ];
                break;
            case 'table':
                $out += $this->table($e);
                break;
            case 'chart':
                $out += $this->chart($e);
                break;
            case 'qr':
                $out += [
                    'text' => self::str($e['text'] ?? '', 200),
                    'color' => self::colorOr($e['color'] ?? null, '#0F4382'),
                    'bg' => self::colorOrNull($e['bg'] ?? null) ?? '#FFFFFF',
                ];
                break;
        }

        return $out;
    }

    public static function responsibleHtml(string $name): string
    {
        return '<p><span style="color:#808080">Ответственный: </span><span style="color:#262626">'.self::esc($name).'</span></p>';
    }

    /**
     * @param array<string, mixed> $e
     *
     * @return array<string, mixed>
     */
    private function textProps(array $e): array
    {
        return [
            'html' => $this->sanitizeHtml((string) (\is_string($e['html'] ?? null) ? $e['html'] : '')),
            'placeholder' => self::str($e['placeholder'] ?? '', 200),
            'fontFamily' => self::oneOf($e['fontFamily'] ?? null, self::FONTS, 'Roboto'),
            'fontSize' => self::numIn($e['fontSize'] ?? 16, 4, 400, 16),
            'fontWeight' => (int) self::oneOf((string) ($e['fontWeight'] ?? '400'), ['100', '200', '300', '400', '500', '600', '700', '800', '900'], '400'),
            'italic' => true === ($e['italic'] ?? false),
            'color' => self::colorOr($e['color'] ?? null, '#262626'),
            'align' => self::oneOf($e['align'] ?? null, ['left', 'center', 'right', 'justify'], 'left'),
            'valign' => self::oneOf($e['valign'] ?? null, ['top', 'middle', 'bottom'], 'top'),
            'lineHeight' => self::numIn($e['lineHeight'] ?? 1.0, 0.5, 4, 1.0),
            'letterSpacing' => self::numIn($e['letterSpacing'] ?? 0, -20, 100, 0),
            'spaceBefore' => self::numIn($e['spaceBefore'] ?? 0, 0, 200, 0),
            'caps' => true === ($e['caps'] ?? false),
            'padding' => self::numIn($e['padding'] ?? 0, 0, 200, 0),
            'listColor' => self::colorOrNull($e['listColor'] ?? null),
            'autoFit' => true === ($e['autoFit'] ?? false),
        ];
    }

    /**
     * @param array<string, mixed> $e
     *
     * @return array<string, mixed>
     */
    private function table(array $e): array
    {
        $rows = [];
        $src = \is_array($e['rows'] ?? null) && array_is_list($e['rows']) ? \array_slice($e['rows'], 0, 60) : [];
        $cols = 0;
        foreach ($src as $r) {
            $cols = max($cols, \is_array($r) ? min(20, \count($r)) : 0);
        }
        $cols = max(1, $cols);
        foreach ($src as $r) {
            $row = [];
            $r = \is_array($r) && array_is_list($r) ? $r : [];
            for ($c = 0; $c < $cols; ++$c) {
                $cell = $r[$c] ?? [];
                if (!\is_array($cell)) {
                    $cell = ['text' => \is_scalar($cell) ? (string) $cell : ''];
                }
                $row[] = [
                    'text' => self::str($cell['text'] ?? '', 2000, true),
                    'bold' => true === ($cell['bold'] ?? false),
                    'italic' => true === ($cell['italic'] ?? false),
                    'color' => self::colorOrNull($cell['color'] ?? null),
                    'fill' => self::colorOrNull($cell['fill'] ?? null),
                    'align' => self::oneOf($cell['align'] ?? null, ['left', 'center', 'right'], null),
                    'colspan' => (int) self::numIn($cell['colspan'] ?? 1, 1, 20, 1),
                    'rowspan' => (int) self::numIn($cell['rowspan'] ?? 1, 1, 60, 1),
                    'hidden' => true === ($cell['hidden'] ?? false),
                ];
            }
            $rows[] = $row;
        }
        if ([] === $rows) {
            $rows = [[['text' => '', 'bold' => false, 'italic' => false, 'color' => null, 'fill' => null, 'align' => null, 'colspan' => 1, 'rowspan' => 1, 'hidden' => false]]];
        }
        $widths = [];
        $w = \is_array($e['cols'] ?? null) ? $e['cols'] : [];
        for ($c = 0; $c < $cols; ++$c) {
            $widths[] = self::numIn($w[$c] ?? 1, 0.01, 6000, 1);
        }
        $heights = [];
        $h = \is_array($e['heights'] ?? null) ? $e['heights'] : [];
        foreach (array_keys($rows) as $i) {
            $heights[] = self::numIn($h[$i] ?? 1, 0.01, 6000, 1);
        }
        $st = \is_array($e['style'] ?? null) ? $e['style'] : [];

        return [
            'rows' => $rows,
            'cols' => $widths,
            'heights' => $heights,
            'style' => [
                'header' => false !== ($st['header'] ?? true),
                'firstCol' => true === ($st['firstCol'] ?? false),
                'banded' => true === ($st['banded'] ?? false),
                'headerFill' => self::colorOr($st['headerFill'] ?? null, '#034185'),
                'headerColor' => self::colorOr($st['headerColor'] ?? null, '#FFFFFF'),
                'firstColFill' => self::colorOr($st['firstColFill'] ?? null, '#034185'),
                'firstColColor' => self::colorOr($st['firstColColor'] ?? null, '#FFFFFF'),
                'bandFill' => self::colorOr($st['bandFill'] ?? null, '#F2F4F8'),
                'fill' => self::colorOrNull($st['fill'] ?? null) ?? '#FFFFFF',
                'borderColor' => self::colorOr($st['borderColor'] ?? null, '#BFBFBF'),
                'borderWidth' => self::numIn($st['borderWidth'] ?? 1, 0, 10, 1),
                'fontFamily' => self::oneOf($st['fontFamily'] ?? null, self::FONTS, 'Roboto'),
                'fontSize' => self::numIn($st['fontSize'] ?? 16, 4, 120, 16),
                'color' => self::colorOr($st['color'] ?? null, '#262626'),
                'align' => self::oneOf($st['align'] ?? null, ['left', 'center', 'right'], 'center'),
                'padding' => self::numIn($st['padding'] ?? 8, 0, 60, 8),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $e
     *
     * @return array<string, mixed>
     */
    private function chart(array $e): array
    {
        $cats = [];
        foreach (\array_slice(\is_array($e['categories'] ?? null) ? array_values($e['categories']) : [], 0, 100) as $c) {
            $cats[] = self::str(\is_scalar($c) ? (string) $c : '', 120);
        }
        $series = [];
        foreach (\array_slice(\is_array($e['series'] ?? null) ? array_values($e['series']) : [], 0, 12) as $i => $s) {
            if (!\is_array($s)) {
                continue;
            }
            $values = [];
            $src = \is_array($s['values'] ?? null) ? array_values($s['values']) : [];
            foreach (array_keys($cats) as $k) {
                $v = $src[$k] ?? null;
                $values[] = is_numeric($v) && is_finite((float) $v) ? round((float) $v, 6) : null;
            }
            $series[] = [
                'name' => self::str($s['name'] ?? ('Ряд '.($i + 1)), 120),
                'values' => $values,
                'color' => self::colorOrNull($s['color'] ?? null),
            ];
        }
        if ([] === $series) {
            $series[] = ['name' => 'Ряд 1', 'values' => array_fill(0, \count($cats), null), 'color' => null];
        }
        $points = [];
        foreach (\array_slice(\is_array($e['pointColors'] ?? null) ? array_values($e['pointColors']) : [], 0, 100) as $c) {
            $points[] = self::colorOrNull($c);
        }

        return [
            'chart' => self::oneOf($e['chart'] ?? null, self::CHARTS, 'column'),
            'categories' => $cats,
            'series' => $series,
            'pointColors' => $points,
            'legend' => self::oneOf($e['legend'] ?? null, ['none', 'top', 'bottom', 'right'], 'bottom'),
            'labels' => true === ($e['labels'] ?? false),
            'title' => self::strOrNull($e['title'] ?? null, 200),
            'stacked' => true === ($e['stacked'] ?? false),
            'fontSize' => self::numIn($e['fontSize'] ?? 12, 6, 48, 12),
            'color' => self::colorOr($e['color'] ?? null, '#383838'),
            'gridColor' => self::colorOr($e['gridColor'] ?? null, '#D9D9D9'),
        ];
    }

    // ---- Значения ---------------------------------------------------------------------------------------

    /** @return array{type: string, order: int, delay: float, duration: float}|null */
    private static function anim(mixed $a): ?array
    {
        if (!\is_array($a) || !\in_array($a['type'] ?? null, self::ANIMATIONS, true)) {
            return null;
        }

        return [
            'type' => $a['type'],
            'order' => (int) self::numIn($a['order'] ?? 0, 0, 99, 0),
            'delay' => self::numIn($a['delay'] ?? 0, 0, 30, 0),
            'duration' => self::numIn($a['duration'] ?? 0.6, 0.1, 10, 0.6),
        ];
    }

    /** @return array{slide: string}|array{url: string}|null */
    private static function link(mixed $l): ?array
    {
        if (!\is_array($l)) {
            return null;
        }
        if (isset($l['slide']) && \is_string($l['slide']) && 1 === preg_match(self::ID_RE, $l['slide'])) {
            return ['slide' => $l['slide']];
        }
        if (isset($l['url']) && \is_string($l['url']) && 1 === preg_match('#^(https?://|mailto:)[^\s"<>]{1,2000}$#i', trim($l['url']))) {
            return ['url' => trim($l['url'])];
        }

        return null;
    }

    /** @return array{blur: float, x: float, y: float, color: string}|null */
    private static function shadow(mixed $s): ?array
    {
        if (!\is_array($s)) {
            return null;
        }

        return [
            'blur' => self::numIn($s['blur'] ?? 12, 0, 200, 12),
            'x' => self::numIn($s['x'] ?? 0, -200, 200, 0),
            'y' => self::numIn($s['y'] ?? 4, -200, 200, 4),
            'color' => self::colorOr($s['color'] ?? null, '#00000040'),
        ];
    }

    /** @return array{angle: float, from: string, to: string}|null */
    private static function gradient(mixed $g): ?array
    {
        if (!\is_array($g) || null === self::colorOrNull($g['from'] ?? null) || null === self::colorOrNull($g['to'] ?? null)) {
            return null;
        }

        return ['angle' => self::numIn($g['angle'] ?? 180, 0, 360, 180), 'from' => self::colorOrNull($g['from']), 'to' => self::colorOrNull($g['to'])];
    }

    private static function src(mixed $src): ?string
    {
        if (!\is_string($src)) {
            return null;
        }
        if (1 === preg_match('/^asset:[a-f0-9]{24}$/', $src)) {
            return $src;
        }
        if (1 === preg_match('/^brand:([a-z0-9-]{1,40})$/', $src, $m) && isset(BrandAssets::ASSETS[$m[1]])) {
            return $src;
        }

        return null;
    }

    private static function numIn(mixed $v, float $min, float $max, float $default): float
    {
        if (!is_numeric($v) || !is_finite((float) $v)) {
            return $default;
        }

        return round(max($min, min($max, (float) $v)), 2);
    }

    private static function num(float $v): string
    {
        return rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.');
    }

    /** @param list<string|null> $allowed */
    private static function oneOf(mixed $v, array $allowed, ?string $default): ?string
    {
        return \is_string($v) && \in_array($v, $allowed, true) ? $v : $default;
    }

    private static function colorOrNull(mixed $v): ?string
    {
        return \is_string($v) && 1 === preg_match(self::COLOR_RE, $v) ? strtoupper($v) : null;
    }

    private static function colorOr(mixed $v, string $default): string
    {
        return self::colorOrNull($v) ?? $default;
    }

    private static function idOr(mixed $v, string $prefix): string
    {
        return \is_string($v) && 1 === preg_match(self::ID_RE, $v) ? $v : self::newId($prefix);
    }

    private static function idOrNull(mixed $v): ?string
    {
        return \is_string($v) && 1 === preg_match(self::ID_RE, $v) ? $v : null;
    }

    private static function keyOr(mixed $v): ?string
    {
        return \is_string($v) && 1 === preg_match('/^[a-z0-9-]{1,40}$/', $v) ? $v : null;
    }

    private static function str(mixed $v, int $max, bool $multiline = false): string
    {
        if (!\is_scalar($v)) {
            return '';
        }
        $v = (string) $v;
        if (!mb_check_encoding($v, 'UTF-8')) {
            $v = mb_convert_encoding($v, 'UTF-8', 'UTF-8');
        }
        $v = preg_replace($multiline ? '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u' : '/[\x00-\x1F\x7F]/u', $multiline ? '' : ' ', $v) ?? '';

        return mb_substr($multiline ? $v : trim($v), 0, $max);
    }

    private static function strOrNull(mixed $v, int $max): ?string
    {
        $s = self::str($v, $max);

        return '' === $s ? null : $s;
    }

    public static function esc(string $s): string
    {
        return htmlspecialchars($s, \ENT_QUOTES | \ENT_SUBSTITUTE | \ENT_HTML5, 'UTF-8');
    }
}
