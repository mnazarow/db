<?php

declare(strict_types=1);

namespace App\Service\Presentation;

/**
 * Текст элемента слайда (очищенный HTML из DeckValidator::sanitizeHtml) — в абзацы и фрагменты с оформлением:
 * так его записывает выгрузка в PowerPoint и считает проверка стиля.
 */
final class RichText
{
    /**
     * @return list<array{list: ?string, level: int, align: ?string, runs: list<array<string, mixed>>}>
     */
    public static function parse(string $html): array
    {
        if ('' === trim($html)) {
            return [];
        }
        $doc = new \DOMDocument('1.0', 'UTF-8');
        $prev = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8"?><html><body><div id="root">'.$html.'</div></body></html>', \LIBXML_NONET | \LIBXML_HTML_NODEFDTD | \LIBXML_COMPACT);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        $root = $doc->getElementById('root');
        if (null === $root) {
            return [];
        }
        $out = [];
        foreach ($root->childNodes as $p) {
            if (!$p instanceof \DOMElement || 'p' !== strtolower($p->tagName)) {
                if ($p instanceof \DOMText && '' !== trim($p->data)) {
                    $out[] = ['list' => null, 'level' => 0, 'align' => null, 'runs' => [['text' => $p->data]]];
                }
                continue;
            }
            $list = $p->getAttribute('data-list');
            $level = $p->getAttribute('data-level');
            $align = null;
            if (1 === preg_match('/text-align\s*:\s*(left|center|right|justify)/', $p->getAttribute('style'), $m)) {
                $align = $m[1];
            }
            $runs = [];
            self::walk($p, [], $runs);
            // Лишний перенос в конце абзаца (так contenteditable обозначает пустую строку) — не нужен.
            if ([] !== $runs && isset(end($runs)['br'])) {
                array_pop($runs);
            }
            $out[] = [
                'list' => \in_array($list, ['bullet', 'number'], true) ? $list : null,
                'level' => ctype_digit($level) ? min(4, (int) $level) : 0,
                'align' => $align,
                'runs' => $runs,
            ];
        }

        return $out;
    }

    /** Текст без оформления (для заголовков, проверки и поиска). */
    public static function plain(string $html): string
    {
        $lines = [];
        foreach (self::parse($html) as $p) {
            $t = '';
            foreach ($p['runs'] as $r) {
                $t .= isset($r['br']) ? "\n" : (string) ($r['text'] ?? '');
            }
            $lines[] = $t;
        }

        return trim(implode("\n", $lines));
    }

    /**
     * @param array<string, mixed>             $style
     * @param list<array<string, mixed>> $runs
     */
    private static function walk(\DOMNode $node, array $style, array &$runs): void
    {
        foreach ($node->childNodes as $child) {
            if ($child instanceof \DOMText) {
                if ('' !== $child->data) {
                    $runs[] = ['text' => $child->data] + $style;
                }
                continue;
            }
            if (!$child instanceof \DOMElement) {
                continue;
            }
            $tag = strtolower($child->tagName);
            if ('br' === $tag) {
                $runs[] = ['br' => true] + $style;
                continue;
            }
            $s = $style;
            switch ($tag) {
                case 'b':
                case 'strong':
                    $s['bold'] = true;
                    break;
                case 'i':
                case 'em':
                    $s['italic'] = true;
                    break;
                case 'u':
                    $s['underline'] = true;
                    break;
                case 's':
                    $s['strike'] = true;
                    break;
                case 'sub':
                    $s['baseline'] = -25000;
                    break;
                case 'sup':
                    $s['baseline'] = 30000;
                    break;
                case 'a':
                    $s['href'] = $child->getAttribute('href');
                    break;
            }
            $css = $child->getAttribute('style');
            if ('' !== $css) {
                foreach (explode(';', $css) as $decl) {
                    $kv = explode(':', $decl, 2);
                    if (2 !== \count($kv)) {
                        continue;
                    }
                    $k = strtolower(trim($kv[0]));
                    $v = trim($kv[1]);
                    switch ($k) {
                        case 'color':
                            $s['color'] = DeckValidator::cssColor($v);
                            break;
                        case 'background-color':
                            $s['highlight'] = DeckValidator::cssColor($v);
                            break;
                        case 'font-size':
                            if (1 === preg_match('/^(\d+(?:\.\d+)?)pt$/', $v, $m)) {
                                $s['size'] = (float) $m[1];
                            }
                            break;
                        case 'font-weight':
                            $s['weight'] = (int) $v;
                            $s['bold'] = (int) $v >= 600;
                            break;
                        case 'font-style':
                            $s['italic'] = 'italic' === $v;
                            break;
                        case 'text-decoration':
                            if (str_contains($v, 'underline')) {
                                $s['underline'] = true;
                            }
                            if (str_contains($v, 'line-through')) {
                                $s['strike'] = true;
                            }
                            break;
                        case 'font-family':
                            $s['family'] = trim($v, " '\"");
                            break;
                        case 'letter-spacing':
                            if (1 === preg_match('/^(-?\d+(?:\.\d+)?)pt$/', $v, $m)) {
                                $s['spacing'] = (float) $m[1];
                            }
                            break;
                        case 'text-transform':
                            $s['caps'] = 'uppercase' === $v;
                            break;
                    }
                }
            }
            self::walk($child, $s, $runs);
        }
    }
}
