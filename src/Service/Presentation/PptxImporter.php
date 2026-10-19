<?php

declare(strict_types=1);

namespace App\Service\Presentation;

use App\Entity\Presentation;
use App\Entity\User;

/**
 * Импорт слайдов из PowerPoint (.pptx) в редактор: надписи (с оформлением абзацев и фрагментов), фигуры,
 * линии, изображения (PNG, JPEG, GIF; с обрезкой), таблицы, диаграммы (данные из кэша диаграммы),
 * фон, заметки докладчика, скрытые слайды. Слайды 4:3 и другие размеры вписываются в 1280×720.
 * Неподдерживаемое (видео, SmartArt, EMF/WMF-рисунки) пропускается с предупреждением.
 */
final class PptxImporter
{
    private const NS_A = 'http://schemas.openxmlformats.org/drawingml/2006/main';
    private const NS_P = 'http://schemas.openxmlformats.org/presentationml/2006/main';
    private const NS_R = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
    private const NS_C = 'http://schemas.openxmlformats.org/drawingml/2006/chart';
    private const EMU = 9525;
    public const MAX_BYTES = 200 * 1024 * 1024;
    public const MAX_SLIDES = 150;

    private \ZipArchive $zip;
    private float $scale = 1.0;
    private float $offX = 0.0;
    private float $offY = 0.0;
    /** @var array<string, string> схема цветов темы: dk1, lt1, accent1… → RRGGBB */
    private array $theme = [];
    /** @var list<string> */
    private array $warnings = [];
    /** @var array<string, string> медиафайл → src (asset:…) */
    private array $imported = [];
    private ?Presentation $presentation = null;
    private ?User $user = null;

    public function __construct(private readonly AssetStore $assets, private readonly DeckValidator $validator)
    {
    }

    /**
     * @return array{slides: list<array<string, mixed>>, warnings: list<string>}
     *
     * @throws \InvalidArgumentException не файл PowerPoint
     */
    public function import(string $path, Presentation $presentation, ?User $user): array
    {
        if (!class_exists(\ZipArchive::class)) {
            throw new \RuntimeException('На сервере нет расширения PHP zip — импорт PowerPoint недоступен.');
        }
        if ((int) @filesize($path) > self::MAX_BYTES) {
            throw new \InvalidArgumentException('Файл больше 200 МБ.');
        }
        $this->zip = new \ZipArchive();
        if (true !== $this->zip->open($path, \ZipArchive::RDONLY)) {
            throw new \InvalidArgumentException('Это не файл PowerPoint (.pptx).');
        }
        $this->presentation = $presentation;
        $this->user = $user;
        $this->warnings = [];
        $this->imported = [];
        try {
            $pres = $this->xml('ppt/presentation.xml');
            if (null === $pres) {
                throw new \InvalidArgumentException('Это не презентация PowerPoint (.pptx): нет ppt/presentation.xml. Файлы .ppt сначала сохраните в формате .pptx.');
            }
            $sz = $pres->getElementsByTagNameNS(self::NS_P, 'sldSz')->item(0);
            $w = $sz instanceof \DOMElement ? (int) $sz->getAttribute('cx') / self::EMU : 1280;
            $h = $sz instanceof \DOMElement ? (int) $sz->getAttribute('cy') / self::EMU : 720;
            $this->scale = min(DeckValidator::WIDTH / max(1, $w), DeckValidator::HEIGHT / max(1, $h));
            $this->offX = (DeckValidator::WIDTH - $w * $this->scale) / 2;
            $this->offY = (DeckValidator::HEIGHT - $h * $this->scale) / 2;
            $this->theme = $this->themeColors();
            $rels = $this->rels('ppt/presentation.xml');
            $slides = [];
            foreach ($pres->getElementsByTagNameNS(self::NS_P, 'sldId') as $sldId) {
                if (\count($slides) >= self::MAX_SLIDES) {
                    $this->warnings[] = 'Импортированы первые '.self::MAX_SLIDES.' слайдов.';
                    break;
                }
                /** @var \DOMElement $sldId */
                $rid = $sldId->getAttributeNS(self::NS_R, 'id');
                if (!isset($rels[$rid])) {
                    continue;
                }
                $slides[] = $this->slide($rels[$rid]['target']);
            }
        } finally {
            $this->zip->close();
        }
        if ([] === $slides) {
            throw new \InvalidArgumentException('В файле нет слайдов.');
        }

        return ['slides' => $slides, 'warnings' => array_values(array_unique($this->warnings))];
    }

    /** @return array<string, mixed> */
    private function slide(string $path): array
    {
        $doc = $this->xml($path);
        $slide = ['id' => DeckValidator::newId('s'), 'layout' => null, 'background' => ['color' => '#FFFFFF', 'image' => null, 'fit' => 'cover'], 'transition' => 'none', 'hidden' => false, 'notes' => '', 'elements' => []];
        if (null === $doc) {
            return $slide;
        }
        $root = $doc->documentElement;
        if ($root instanceof \DOMElement && '0' === $root->getAttribute('show')) {
            $slide['hidden'] = true;
        }
        $rels = $this->rels($path);
        $layoutPath = null;
        foreach ($rels as $r) {
            if (str_ends_with($r['type'], '/slideLayout')) {
                $layoutPath = $r['target'];
            }
            if (str_ends_with($r['type'], '/notesSlide')) {
                $slide['notes'] = $this->notes($r['target']);
            }
        }
        $placeholders = null !== $layoutPath ? $this->placeholderGeometry($layoutPath) : [];
        // Фон: слайд, иначе макет, иначе мастер.
        $bg = $this->first($doc, self::NS_P, 'bg');
        if (null === $bg && null !== $layoutPath) {
            $ld = $this->xml($layoutPath);
            $bg = null !== $ld ? $this->first($ld, self::NS_P, 'bg') : null;
        }
        if (null !== $bg) {
            $color = $this->fillColor($bg);
            if (null !== $color) {
                $slide['background']['color'] = substr($color, 0, 7);
            }
            $blip = $this->firstIn($bg, self::NS_A, 'blip');
            if (null !== $blip) {
                $slide['background']['image'] = $this->media($blip->getAttributeNS(self::NS_R, 'embed'), $rels);
            }
        }
        if ($root instanceof \DOMElement && null !== ($tr = $this->firstIn($root, self::NS_P, 'transition'))) {
            foreach ($tr->childNodes as $c) {
                if ($c instanceof \DOMElement) {
                    $slide['transition'] = match ($c->localName) {
                        'fade' => 'fade',
                        'push', 'wipe', 'split', 'pull' => 'slide',
                        'cover' => 'cover',
                        'zoom' => 'zoom',
                        default => 'fade',
                    };
                    break;
                }
            }
        }
        $tree = $this->first($doc, self::NS_P, 'spTree');
        if (null !== $tree) {
            $this->tree($tree, $slide['elements'], $rels, $placeholders, null);
        }

        return $slide;
    }

    /**
     * @param list<array<string, mixed>>                     $out
     * @param array<string, array{type: string, target: string}> $rels
     * @param array<string, array{x: float, y: float, w: float, h: float}> $ph
     * @param array{ox: float, oy: float, sx: float, sy: float}|null $group пересчёт координат группы
     */
    private function tree(\DOMElement $tree, array &$out, array $rels, array $ph, ?array $group): void
    {
        foreach ($tree->childNodes as $node) {
            if (!$node instanceof \DOMElement || self::NS_P !== $node->namespaceURI) {
                continue;
            }
            if (\count($out) >= DeckValidator::MAX_ELEMENTS) {
                $this->warnings[] = 'На слайде слишком много элементов — лишние пропущены.';

                return;
            }
            switch ($node->localName) {
                case 'grpSp':
                    $g = $this->groupTransform($node, $group);
                    $this->tree($node, $out, $rels, $ph, $g);
                    break;
                case 'sp':
                    $el = $this->shape($node, $ph, $group);
                    if (null !== $el) {
                        $out[] = $el;
                    }
                    break;
                case 'pic':
                    $el = $this->picture($node, $rels, $group);
                    if (null !== $el) {
                        $out[] = $el;
                    }
                    break;
                case 'cxnSp':
                    $el = $this->connector($node, $group);
                    if (null !== $el) {
                        $out[] = $el;
                    }
                    break;
                case 'graphicFrame':
                    $el = $this->graphicFrame($node, $rels, $group);
                    if (null !== $el) {
                        $out[] = $el;
                    }
                    break;
                case 'AlternateContent':
                    break;
            }
        }
    }

    // ---- Элементы ---------------------------------------------------------------------------------

    /**
     * @param array<string, array{x: float, y: float, w: float, h: float}> $ph
     * @param array{ox: float, oy: float, sx: float, sy: float}|null $group
     *
     * @return array<string, mixed>|null
     */
    private function shape(\DOMElement $sp, array $ph, ?array $group): ?array
    {
        $spPr = $this->firstChild($sp, self::NS_P, 'spPr');
        $geo = null !== $spPr ? $this->xfrm($spPr, $group) : null;
        $phEl = $this->firstIn($sp, self::NS_P, 'ph');
        $phType = $phEl?->getAttribute('type') ?: ($phEl ? 'body' : null);
        if (null === $geo && null !== $phEl) {
            $key = ($phEl->getAttribute('type') ?: 'body').':'.$phEl->getAttribute('idx');
            $g = $ph[$key] ?? $ph[($phEl->getAttribute('type') ?: 'body').':'] ?? null;
            if (null !== $g) {
                $geo = ['x' => $g['x'], 'y' => $g['y'], 'w' => $g['w'], 'h' => $g['h'], 'rotation' => 0.0, 'flipH' => false, 'flipV' => false];
            }
        }
        if (null === $geo) {
            return null;
        }
        $prst = $spPr ? $this->firstChild($spPr, self::NS_A, 'prstGeom')?->getAttribute('prst') : null;
        $fill = null !== $spPr ? $this->ownFill($spPr) : null;
        $ln = null !== $spPr ? $this->firstChild($spPr, self::NS_A, 'ln') : null;
        $stroke = null;
        $strokeWidth = 0.0;
        if (null !== $ln && null === $this->firstChild($ln, self::NS_A, 'noFill')) {
            $stroke = $this->fillColor($ln);
            $strokeWidth = null !== $stroke ? max(0.5, (int) ($ln->getAttribute('w') ?: 12700) / self::EMU * $this->scale) : 0.0;
        }
        $txBody = $this->firstChild($sp, self::NS_P, 'txBody');
        $text = null !== $txBody ? $this->text($txBody, $phType) : null;
        $isText = null !== $sp->getElementsByTagNameNS(self::NS_P, 'cNvSpPr')->item(0) && '1' === $sp->getElementsByTagNameNS(self::NS_P, 'cNvSpPr')->item(0)->getAttribute('txBox');
        $shapeKind = \in_array($prst, DeckValidator::SHAPES, true) ? $prst : 'rect';
        if (null === $text && null === $fill && null === $stroke) {
            return null;
        }
        $el = [
            'id' => DeckValidator::newId(),
            'type' => ($isText || null !== $phType || (null === $fill && null === $stroke)) && 'rect' === $shapeKind ? 'text' : 'shape',
            'x' => $geo['x'], 'y' => $geo['y'], 'w' => $geo['w'], 'h' => $geo['h'], 'rotation' => $geo['rotation'],
            'name' => $sp->getElementsByTagNameNS(self::NS_P, 'cNvPr')->item(0)?->getAttribute('name'),
            'fill' => $fill,
            'stroke' => $stroke,
            'strokeWidth' => $strokeWidth,
        ];
        if ('shape' === $el['type']) {
            $el['shape'] = $shapeKind;
        }
        if ('roundRect' === $prst) {
            $el['type'] = 'shape';
            $el['shape'] = 'rect';
            $el['radius'] = min($geo['w'], $geo['h']) * 0.1667;
        }

        return $el + ($text ?? ['html' => '', 'color' => '#262626']);
    }

    /**
     * @param array<string, array{type: string, target: string}> $rels
     * @param array{ox: float, oy: float, sx: float, sy: float}|null $group
     *
     * @return array<string, mixed>|null
     */
    private function picture(\DOMElement $pic, array $rels, ?array $group): ?array
    {
        $spPr = $this->firstChild($pic, self::NS_P, 'spPr');
        $geo = null !== $spPr ? $this->xfrm($spPr, $group) : null;
        $blip = $this->firstIn($pic, self::NS_A, 'blip');
        if (null === $geo || null === $blip) {
            return null;
        }
        $src = $this->media($blip->getAttributeNS(self::NS_R, 'embed'), $rels);
        if (null === $src) {
            return null;
        }
        $el = [
            'id' => DeckValidator::newId(), 'type' => 'image', 'src' => $src,
            'x' => $geo['x'], 'y' => $geo['y'], 'w' => $geo['w'], 'h' => $geo['h'], 'rotation' => $geo['rotation'],
            'flipH' => $geo['flipH'], 'flipV' => $geo['flipV'], 'fit' => 'fill',
            'alt' => (string) $pic->getElementsByTagNameNS(self::NS_P, 'cNvPr')->item(0)?->getAttribute('descr'),
        ];
        $alpha = $this->firstChild($blip, self::NS_A, 'alphaModFix');
        if (null !== $alpha) {
            $el['opacity'] = (int) $alpha->getAttribute('amt') / 100000;
        }
        $prst = null !== $spPr ? $this->firstChild($spPr, self::NS_A, 'prstGeom')?->getAttribute('prst') : null;
        if ('ellipse' === $prst) {
            $el['mask'] = 'circle';
        } elseif ('roundRect' === $prst) {
            $el['radius'] = min($geo['w'], $geo['h']) * 0.1667;
        }
        // Обрезка (srcRect) — через «Заполнить» с увеличением и точкой фокуса.
        $rect = $this->firstIn($pic, self::NS_A, 'srcRect');
        $info = $this->imported[$src.'#size'] ?? null;
        if (null !== $rect && null !== $info) {
            [$iw, $ih] = array_map('intval', explode('x', $info));
            $l = (int) $rect->getAttribute('l') / 100000;
            $t = (int) $rect->getAttribute('t') / 100000;
            $r = (int) $rect->getAttribute('r') / 100000;
            $b = (int) $rect->getAttribute('b') / 100000;
            $vw = max(0.01, 1 - $l - $r);
            $vh = max(0.01, 1 - $t - $b);
            if ($iw > 0 && $ih > 0 && ($l > 0 || $t > 0 || $r > 0 || $b > 0)) {
                $base = max($geo['w'] / $iw, $geo['h'] / $ih);
                $s = max($geo['w'] / ($iw * $vw), $geo['h'] / ($ih * $vh));
                $dw = $iw * $s;
                $dh = $ih * $s;
                $el['fit'] = 'cover';
                $el['zoom'] = max(1, min(5, $s / $base));
                $el['focusX'] = $dw > $geo['w'] ? min(1, max(0, $l * $dw / ($dw - $geo['w']))) : 0.5;
                $el['focusY'] = $dh > $geo['h'] ? min(1, max(0, $t * $dh / ($dh - $geo['h']))) : 0.5;
            }
        }

        return $el;
    }

    /**
     * @param array{ox: float, oy: float, sx: float, sy: float}|null $group
     *
     * @return array<string, mixed>|null
     */
    private function connector(\DOMElement $cxn, ?array $group): ?array
    {
        $spPr = $this->firstChild($cxn, self::NS_P, 'spPr');
        $geo = null !== $spPr ? $this->xfrm($spPr, $group) : null;
        if (null === $geo) {
            return null;
        }
        $x1 = $geo['flipH'] ? $geo['x'] + $geo['w'] : $geo['x'];
        $x2 = $geo['flipH'] ? $geo['x'] : $geo['x'] + $geo['w'];
        $y1 = $geo['flipV'] ? $geo['y'] + $geo['h'] : $geo['y'];
        $y2 = $geo['flipV'] ? $geo['y'] : $geo['y'] + $geo['h'];
        $ln = $this->firstChild($spPr, self::NS_A, 'ln');
        $color = null !== $ln ? $this->fillColor($ln) : null;
        $arrow = static function (?\DOMElement $e): string {
            $t = null !== $e ? $e->getAttribute('type') : 'none';

            return match ($t) {
                'triangle', 'stealth' => 'triangle',
                'arrow' => 'arrow',
                'oval' => 'oval',
                'diamond' => 'diamond',
                default => 'none',
            };
        };
        $dash = null !== $ln ? $this->firstChild($ln, self::NS_A, 'prstDash')?->getAttribute('val') : null;

        return [
            'id' => DeckValidator::newId(), 'type' => 'line', 'x1' => $x1, 'y1' => $y1, 'x2' => $x2, 'y2' => $y2,
            'stroke' => $color ?? '#0F4382',
            'strokeWidth' => null !== $ln ? max(0.5, (int) ($ln->getAttribute('w') ?: 12700) / self::EMU * $this->scale) : 1.5,
            'dash' => null === $dash || 'solid' === $dash ? 'solid' : (str_contains($dash, 'Dot') || 'dot' === $dash ? 'dot' : 'dash'),
            'start' => $arrow(null !== $ln ? $this->firstChild($ln, self::NS_A, 'headEnd') : null),
            'end' => $arrow(null !== $ln ? $this->firstChild($ln, self::NS_A, 'tailEnd') : null),
        ];
    }

    /**
     * @param array<string, array{type: string, target: string}> $rels
     * @param array{ox: float, oy: float, sx: float, sy: float}|null $group
     *
     * @return array<string, mixed>|null
     */
    private function graphicFrame(\DOMElement $frame, array $rels, ?array $group): ?array
    {
        $xfrm = $this->firstChild($frame, self::NS_P, 'xfrm');
        if (null === $xfrm) {
            return null;
        }
        $geo = $this->xfrmElement($xfrm, $group);
        $tbl = $this->firstIn($frame, self::NS_A, 'tbl');
        if (null !== $tbl) {
            return $this->table($tbl, $geo);
        }
        $chart = $this->firstIn($frame, self::NS_C, 'chart');
        if (null !== $chart) {
            $rid = $chart->getAttributeNS(self::NS_R, 'id');
            if (isset($rels[$rid])) {
                return $this->chart($rels[$rid]['target'], $geo);
            }
        }
        $this->warnings[] = 'Пропущены объекты, которые редактор не поддерживает (SmartArt, внедрённые объекты, видео).';

        return null;
    }

    /**
     * @param array{x: float, y: float, w: float, h: float, rotation: float} $geo
     *
     * @return array<string, mixed>
     */
    private function table(\DOMElement $tbl, array $geo): array
    {
        $cols = [];
        foreach ($tbl->getElementsByTagNameNS(self::NS_A, 'gridCol') as $gc) {
            /** @var \DOMElement $gc */
            $cols[] = max(1, (int) $gc->getAttribute('w') / self::EMU * $this->scale);
        }
        $rows = [];
        $heights = [];
        $fontSize = null;
        $fontColor = null;
        foreach ($tbl->childNodes as $tr) {
            if (!$tr instanceof \DOMElement || 'tr' !== $tr->localName) {
                continue;
            }
            $heights[] = max(1, (int) $tr->getAttribute('h') / self::EMU * $this->scale);
            $row = [];
            foreach ($tr->childNodes as $tc) {
                if (!$tc instanceof \DOMElement || 'tc' !== $tc->localName) {
                    continue;
                }
                $texts = [];
                $bold = false;
                $color = null;
                foreach ($tc->getElementsByTagNameNS(self::NS_A, 'p') as $p) {
                    $line = '';
                    foreach ($p->getElementsByTagNameNS(self::NS_A, 't') as $t) {
                        $line .= $t->textContent;
                    }
                    $texts[] = $line;
                }
                $rPr = $tc->getElementsByTagNameNS(self::NS_A, 'rPr')->item(0);
                if ($rPr instanceof \DOMElement) {
                    $bold = '1' === $rPr->getAttribute('b');
                    $color = $this->fillColor($rPr);
                    if (null === $fontSize && '' !== $rPr->getAttribute('sz')) {
                        $fontSize = (int) $rPr->getAttribute('sz') / 100 * $this->scale;
                    }
                    $fontColor ??= $color;
                }
                $tcPr = $this->firstChild($tc, self::NS_A, 'tcPr');
                $algn = $tc->getElementsByTagNameNS(self::NS_A, 'pPr')->item(0);
                $row[] = [
                    'text' => implode("\n", $texts),
                    'bold' => $bold,
                    'color' => $color,
                    'fill' => null !== $tcPr ? $this->ownFill($tcPr) : null,
                    'align' => $algn instanceof \DOMElement ? (['l' => 'left', 'ctr' => 'center', 'r' => 'right'][$algn->getAttribute('algn')] ?? null) : null,
                    'colspan' => max(1, (int) ($tc->getAttribute('gridSpan') ?: 1)),
                    'rowspan' => max(1, (int) ($tc->getAttribute('rowSpan') ?: 1)),
                    'hidden' => '1' === $tc->getAttribute('hMerge') || '1' === $tc->getAttribute('vMerge'),
                ];
            }
            $rows[] = $row;
        }

        return [
            'id' => DeckValidator::newId(), 'type' => 'table',
            'x' => $geo['x'], 'y' => $geo['y'], 'w' => array_sum($cols) ?: $geo['w'], 'h' => array_sum($heights) ?: $geo['h'],
            'rows' => $rows, 'cols' => $cols, 'heights' => $heights,
            'style' => ['header' => false, 'firstCol' => false, 'fontSize' => $fontSize ?? 14, 'color' => $fontColor ?? '#262626', 'fill' => '#FFFFFF', 'borderColor' => '#BFBFBF'],
        ];
    }

    /**
     * @param array{x: float, y: float, w: float, h: float, rotation: float} $geo
     *
     * @return array<string, mixed>|null
     */
    private function chart(string $path, array $geo): ?array
    {
        $doc = $this->xml($path);
        if (null === $doc) {
            return null;
        }
        $type = null;
        $plot = null;
        foreach (['barChart' => null, 'bar3DChart' => null, 'lineChart' => 'line', 'line3DChart' => 'line', 'areaChart' => 'area', 'pieChart' => 'pie', 'pie3DChart' => 'pie', 'doughnutChart' => 'doughnut'] as $tag => $t) {
            $node = $doc->getElementsByTagNameNS(self::NS_C, $tag)->item(0);
            if ($node instanceof \DOMElement) {
                $plot = $node;
                $type = $t ?? ('bar' === $this->firstIn($node, self::NS_C, 'barDir')?->getAttribute('val') ? 'bar' : 'column');
                break;
            }
        }
        if (null === $plot || null === $type) {
            $this->warnings[] = 'Пропущена диаграмма неподдерживаемого вида.';

            return null;
        }
        $categories = [];
        $series = [];
        foreach ($plot->getElementsByTagNameNS(self::NS_C, 'ser') as $ser) {
            /** @var \DOMElement $ser */
            $name = '';
            $tx = $this->firstIn($ser, self::NS_C, 'tx');
            if (null !== $tx) {
                $v = $tx->getElementsByTagNameNS(self::NS_C, 'v')->item(0);
                $name = null !== $v ? $v->textContent : '';
            }
            if ([] === $categories) {
                $cat = $this->firstIn($ser, self::NS_C, 'cat');
                if (null !== $cat) {
                    foreach ($cat->getElementsByTagNameNS(self::NS_C, 'pt') as $pt) {
                        /** @var \DOMElement $pt */
                        $categories[(int) $pt->getAttribute('idx')] = (string) $pt->getElementsByTagNameNS(self::NS_C, 'v')->item(0)?->textContent;
                    }
                }
            }
            $values = [];
            $val = $this->firstIn($ser, self::NS_C, 'val');
            if (null !== $val) {
                foreach ($val->getElementsByTagNameNS(self::NS_C, 'pt') as $pt) {
                    /** @var \DOMElement $pt */
                    $values[(int) $pt->getAttribute('idx')] = (float) $pt->getElementsByTagNameNS(self::NS_C, 'v')->item(0)?->textContent;
                }
            }
            $spPr = $this->firstChild($ser, self::NS_C, 'spPr');
            $series[] = ['name' => $name, 'values' => $values, 'color' => null !== $spPr ? $this->ownFill($spPr) : null];
        }
        ksort($categories);
        $n = [] === $categories ? (int) max(array_map(static fn (array $s): int => [] === $s['values'] ? 0 : max(array_keys($s['values'])) + 1, $series ?: [['values' => []]])) : max(array_keys($categories)) + 1;
        $cats = [];
        for ($i = 0; $i < $n; ++$i) {
            $cats[] = $categories[$i] ?? (string) ($i + 1);
        }
        foreach ($series as &$s) {
            $v = [];
            for ($i = 0; $i < $n; ++$i) {
                $v[] = $s['values'][$i] ?? null;
            }
            $s['values'] = $v;
        }
        unset($s);

        return [
            'id' => DeckValidator::newId(), 'type' => 'chart', 'chart' => $type,
            'x' => $geo['x'], 'y' => $geo['y'], 'w' => $geo['w'], 'h' => $geo['h'],
            'categories' => $cats, 'series' => $series, 'legend' => 'bottom', 'labels' => null !== $doc->getElementsByTagNameNS(self::NS_C, 'showVal')->item(0) && '1' === $doc->getElementsByTagNameNS(self::NS_C, 'showVal')->item(0)->getAttribute('val'),
        ];
    }

    // ---- Текст -----------------------------------------------------------------------------------

    /** @return array<string, mixed>|null свойства надписи и HTML или null — текста нет */
    private function text(\DOMElement $txBody, ?string $phType): ?array
    {
        $html = '';
        $first = null;
        $hasText = false;
        $bodyPr = $this->firstChild($txBody, self::NS_A, 'bodyPr');
        $anchor = $bodyPr?->getAttribute('anchor');
        $alignDefault = null;
        $lnSpc = null;
        foreach ($txBody->childNodes as $p) {
            if (!$p instanceof \DOMElement || 'p' !== $p->localName) {
                continue;
            }
            $pPr = $this->firstChild($p, self::NS_A, 'pPr');
            $list = null;
            $level = 0;
            $align = null;
            if (null !== $pPr) {
                if (null !== $this->firstChild($pPr, self::NS_A, 'buAutoNum')) {
                    $list = 'number';
                } elseif (null !== $this->firstChild($pPr, self::NS_A, 'buChar')) {
                    $list = 'bullet';
                }
                $level = min(4, (int) $pPr->getAttribute('lvl'));
                $align = ['ctr' => 'center', 'r' => 'right', 'just' => 'justify'][$pPr->getAttribute('algn')] ?? null;
                $sp = $pPr->getElementsByTagNameNS(self::NS_A, 'spcPct')->item(0);
                if ($sp instanceof \DOMElement && null === $lnSpc && $sp->parentNode instanceof \DOMElement && 'lnSpc' === $sp->parentNode->localName) {
                    $lnSpc = (int) $sp->getAttribute('val') / 100000;
                }
            } elseif ('body' === $phType) {
                $list = 'bullet';
            }
            $alignDefault ??= $align;
            $inner = '';
            foreach ($p->childNodes as $r) {
                if (!$r instanceof \DOMElement) {
                    continue;
                }
                if ('br' === $r->localName) {
                    $inner .= '<br>';
                    continue;
                }
                if ('r' !== $r->localName && 'fld' !== $r->localName) {
                    continue;
                }
                $t = $r->getElementsByTagNameNS(self::NS_A, 't')->item(0)?->textContent ?? '';
                if ('' === $t) {
                    continue;
                }
                $hasText = true;
                $rPr = $this->firstChild($r, self::NS_A, 'rPr');
                $style = [];
                $open = '';
                $close = '';
                if (null !== $rPr) {
                    $props = $this->runProps($rPr);
                    $first ??= $props;
                    if (null !== $props['size'] && $props['size'] !== ($first['size'] ?? null)) {
                        $style[] = 'font-size:'.round($props['size'], 1).'pt';
                    }
                    if (null !== $props['color'] && $props['color'] !== ($first['color'] ?? null)) {
                        $style[] = 'color:'.$props['color'];
                    }
                    if (null !== $props['family'] && $props['family'] !== ($first['family'] ?? null)) {
                        $style[] = "font-family:'".$props['family']."'";
                    }
                    foreach (['b' => 'bold', 'i' => 'italic', 'u' => 'underline', 's' => 'strike'] as $tag => $key) {
                        if ($props[$key] && !($first[$key] ?? false)) {
                            $open .= '<'.$tag.'>';
                            $close = '</'.$tag.'>'.$close;
                        }
                    }
                }
                $chunk = $open.DeckValidator::esc($t).$close;
                $inner .= [] !== $style ? '<span style="'.implode(';', $style).'">'.$chunk.'</span>' : $chunk;
            }
            $attrs = (null !== $list ? ' data-list="'.$list.'"' : '').($level > 0 ? ' data-level="'.$level.'"' : '').(null !== $align && $align !== $alignDefault ? ' style="text-align:'.$align.'"' : '');
            $html .= '<p'.$attrs.'>'.('' === $inner ? '<br>' : $inner).'</p>';
        }
        if (!$hasText) {
            return null;
        }
        $isTitle = \in_array($phType, ['title', 'ctrTitle'], true);
        $size = $first['size'] ?? ($isTitle ? 40 * $this->scale : 18 * $this->scale);

        return [
            'html' => $html,
            'fontFamily' => $first['family'] ?? ($isTitle ? 'DIN Condensed Light' : 'Roboto'),
            'fontSize' => round($size, 1),
            'fontWeight' => ($first['bold'] ?? false) ? 700 : 400,
            'italic' => $first['italic'] ?? false,
            'color' => $first['color'] ?? ($isTitle ? SlideLayouts::BLUE : '#262626'),
            'caps' => $first['caps'] ?? $isTitle,
            'align' => $alignDefault ?? 'left',
            'valign' => ['ctr' => 'middle', 'b' => 'bottom'][$anchor] ?? 'top',
            'lineHeight' => $lnSpc ?? 1.0,
            'listColor' => '#104382',
        ];
    }

    /** @return array{size: ?float, color: ?string, family: ?string, bold: bool, italic: bool, underline: bool, strike: bool, caps: bool} */
    private function runProps(\DOMElement $rPr): array
    {
        $latin = $this->firstChild($rPr, self::NS_A, 'latin')?->getAttribute('typeface');
        $family = null;
        if (null !== $latin && '' !== $latin && !str_starts_with($latin, '+')) {
            $family = \in_array($latin, DeckValidator::FONTS, true) ? $latin : (str_contains(strtolower($latin), 'din') ? 'DIN Condensed Light' : (str_contains(strtolower($latin), 'roboto') ? 'Roboto' : 'Roboto'));
        }
        $color = $this->fillColor($rPr);

        return [
            'size' => '' !== $rPr->getAttribute('sz') ? (int) $rPr->getAttribute('sz') / 100 * $this->scale : null,
            'color' => null !== $color ? substr($color, 0, 7) : null,
            'family' => $family,
            'bold' => '1' === $rPr->getAttribute('b'),
            'italic' => '1' === $rPr->getAttribute('i'),
            'underline' => '' !== $rPr->getAttribute('u') && 'none' !== $rPr->getAttribute('u'),
            'strike' => '' !== $rPr->getAttribute('strike') && 'noStrike' !== $rPr->getAttribute('strike'),
            'caps' => 'all' === $rPr->getAttribute('cap'),
        ];
    }

    private function notes(string $path): string
    {
        $doc = $this->xml($path);
        if (null === $doc) {
            return '';
        }
        $lines = [];
        foreach ($doc->getElementsByTagNameNS(self::NS_P, 'sp') as $sp) {
            /** @var \DOMElement $sp */
            $ph = $sp->getElementsByTagNameNS(self::NS_P, 'ph')->item(0);
            if (!$ph instanceof \DOMElement || 'body' !== $ph->getAttribute('type')) {
                continue;
            }
            foreach ($sp->getElementsByTagNameNS(self::NS_A, 'p') as $p) {
                $line = '';
                foreach ($p->getElementsByTagNameNS(self::NS_A, 't') as $t) {
                    $line .= $t->textContent;
                }
                $lines[] = $line;
            }
        }

        return trim(implode("\n", $lines));
    }

    // ---- Геометрия, цвета, файлы -----------------------------------------------------------------

    /**
     * @param array{ox: float, oy: float, sx: float, sy: float}|null $group
     *
     * @return array{x: float, y: float, w: float, h: float, rotation: float, flipH: bool, flipV: bool}|null
     */
    private function xfrm(\DOMElement $spPr, ?array $group): ?array
    {
        $xfrm = $this->firstChild($spPr, self::NS_A, 'xfrm');

        return null !== $xfrm ? $this->xfrmElement($xfrm, $group) : null;
    }

    /**
     * @param array{ox: float, oy: float, sx: float, sy: float}|null $group
     *
     * @return array{x: float, y: float, w: float, h: float, rotation: float, flipH: bool, flipV: bool}
     */
    private function xfrmElement(\DOMElement $xfrm, ?array $group): array
    {
        $off = $this->firstChild($xfrm, self::NS_A, 'off');
        $ext = $this->firstChild($xfrm, self::NS_A, 'ext');
        $x = (int) $off?->getAttribute('x');
        $y = (int) $off?->getAttribute('y');
        $w = (int) $ext?->getAttribute('cx');
        $h = (int) $ext?->getAttribute('cy');
        if (null !== $group) {
            $x = $group['ox'] + ($x - $group['cx']) * $group['sx'];
            $y = $group['oy'] + ($y - $group['cy']) * $group['sy'];
            $w *= $group['sx'];
            $h *= $group['sy'];
        }

        return [
            'x' => $this->offX + $x / self::EMU * $this->scale,
            'y' => $this->offY + $y / self::EMU * $this->scale,
            'w' => max(1, $w / self::EMU * $this->scale),
            'h' => max(1, $h / self::EMU * $this->scale),
            'rotation' => (int) $xfrm->getAttribute('rot') / 60000,
            'flipH' => '1' === $xfrm->getAttribute('flipH'),
            'flipV' => '1' === $xfrm->getAttribute('flipV'),
        ];
    }

    /**
     * Пересчёт координат детей группы в координаты слайда (EMU).
     *
     * @param array{ox: float, oy: float, sx: float, sy: float, cx: float, cy: float}|null $parent
     *
     * @return array{ox: float, oy: float, sx: float, sy: float, cx: float, cy: float}|null
     */
    private function groupTransform(\DOMElement $grp, ?array $parent): ?array
    {
        $pr = $this->firstChild($grp, self::NS_P, 'grpSpPr');
        $xfrm = null !== $pr ? $this->firstChild($pr, self::NS_A, 'xfrm') : null;
        if (null === $xfrm) {
            return $parent;
        }
        $off = $this->firstChild($xfrm, self::NS_A, 'off');
        $ext = $this->firstChild($xfrm, self::NS_A, 'ext');
        $chOff = $this->firstChild($xfrm, self::NS_A, 'chOff');
        $chExt = $this->firstChild($xfrm, self::NS_A, 'chExt');
        $ox = (float) $off?->getAttribute('x');
        $oy = (float) $off?->getAttribute('y');
        $ew = (float) $ext?->getAttribute('cx');
        $eh = (float) $ext?->getAttribute('cy');
        $cw = (float) ($chExt?->getAttribute('cx') ?: $ew);
        $ch = (float) ($chExt?->getAttribute('cy') ?: $eh);
        if (null !== $parent) {
            $ox = $parent['ox'] + ($ox - $parent['cx']) * $parent['sx'];
            $oy = $parent['oy'] + ($oy - $parent['cy']) * $parent['sy'];
            $ew *= $parent['sx'];
            $eh *= $parent['sy'];
        }

        return [
            'ox' => $ox, 'oy' => $oy,
            'sx' => $cw > 0 ? $ew / $cw : 1.0, 'sy' => $ch > 0 ? $eh / $ch : 1.0,
            'cx' => (float) $chOff?->getAttribute('x'), 'cy' => (float) $chOff?->getAttribute('y'),
        ];
    }

    /** @return array<string, array{x: float, y: float, w: float, h: float}> положение заполнителей макета (и мастера) по «тип:индекс» */
    private function placeholderGeometry(string $layoutPath): array
    {
        $out = [];
        $paths = [$layoutPath];
        foreach ($this->rels($layoutPath) as $r) {
            if (str_ends_with($r['type'], '/slideMaster')) {
                $paths[] = $r['target'];
            }
        }
        foreach (array_reverse($paths) as $path) {
            $doc = $this->xml($path);
            if (null === $doc) {
                continue;
            }
            foreach ($doc->getElementsByTagNameNS(self::NS_P, 'sp') as $sp) {
                /** @var \DOMElement $sp */
                $ph = $sp->getElementsByTagNameNS(self::NS_P, 'ph')->item(0);
                $spPr = $this->firstChild($sp, self::NS_P, 'spPr');
                if (!$ph instanceof \DOMElement || null === $spPr || null === ($g = $this->xfrm($spPr, null))) {
                    continue;
                }
                $type = $ph->getAttribute('type') ?: 'body';
                $out[$type.':'.$ph->getAttribute('idx')] = $g;
                $out[$type.':'] ??= $g;
                if ('ctrTitle' === $type) {
                    $out['title:'] ??= $g;
                }
            }
        }

        return $out;
    }

    /** Цвет заливки элемента (solidFill) с учётом цветов темы и lumMod/lumOff. */
    private function fillColor(\DOMElement $el): ?string
    {
        $solid = $this->firstChild($el, self::NS_A, 'solidFill') ?? $el->getElementsByTagNameNS(self::NS_A, 'solidFill')->item(0);
        if (!$solid instanceof \DOMElement) {
            return null;
        }

        return $this->color($solid);
    }

    private function ownFill(\DOMElement $spPr): ?string
    {
        $solid = $this->firstChild($spPr, self::NS_A, 'solidFill');
        if (null !== $solid) {
            return $this->color($solid);
        }
        $grad = $this->firstChild($spPr, self::NS_A, 'gradFill');
        if (null !== $grad) {
            $gs = $grad->getElementsByTagNameNS(self::NS_A, 'gs')->item(0);

            return $gs instanceof \DOMElement ? $this->color($gs) : null;
        }

        return null;
    }

    private function color(\DOMElement $holder): ?string
    {
        foreach ($holder->childNodes as $c) {
            if (!$c instanceof \DOMElement) {
                continue;
            }
            $hex = match ($c->localName) {
                'srgbClr' => strtoupper($c->getAttribute('val')),
                'schemeClr' => $this->theme[['tx1' => 'dk1', 'bg1' => 'lt1', 'tx2' => 'dk2', 'bg2' => 'lt2'][$c->getAttribute('val')] ?? $c->getAttribute('val')] ?? null,
                'sysClr' => strtoupper($c->getAttribute('lastClr') ?: '000000'),
                'prstClr' => ['black' => '000000', 'white' => 'FFFFFF', 'red' => 'FF0000'][$c->getAttribute('val')] ?? '000000',
                default => null,
            };
            if (null === $hex || 1 !== preg_match('/^[0-9A-F]{6}$/', $hex)) {
                continue;
            }
            $lumMod = $this->firstChild($c, self::NS_A, 'lumMod');
            $lumOff = $this->firstChild($c, self::NS_A, 'lumOff');
            if (null !== $lumMod || null !== $lumOff) {
                $hex = self::lum($hex, null !== $lumMod ? (int) $lumMod->getAttribute('val') / 100000 : 1, null !== $lumOff ? (int) $lumOff->getAttribute('val') / 100000 : 0);
            }
            $alpha = $this->firstChild($c, self::NS_A, 'alpha');
            $a = null !== $alpha ? (int) $alpha->getAttribute('val') / 100000 : 1;

            return '#'.$hex.($a < 1 ? \sprintf('%02X', (int) round($a * 255)) : '');
        }

        return null;
    }

    private static function lum(string $hex, float $mod, float $off): string
    {
        [$r, $g, $b] = array_map(static fn (string $p): float => hexdec($p) / 255, str_split($hex, 2));
        $max = max($r, $g, $b);
        $min = min($r, $g, $b);
        $l = ($max + $min) / 2;
        $d = $max - $min;
        $h = 0.0;
        $s = 0.0;
        if ($d > 0) {
            $s = $l > 0.5 ? $d / (2 - $max - $min) : $d / ($max + $min);
            $h = match ($max) {
                $r => ($g - $b) / $d + ($g < $b ? 6 : 0),
                $g => ($b - $r) / $d + 2,
                default => ($r - $g) / $d + 4,
            } / 6;
        }
        $l = max(0, min(1, $l * $mod + $off));
        $hue = static function (float $p, float $q, float $t): float {
            if ($t < 0) {
                ++$t;
            }
            if ($t > 1) {
                --$t;
            }

            return match (true) {
                $t < 1 / 6 => $p + ($q - $p) * 6 * $t,
                $t < 1 / 2 => $q,
                $t < 2 / 3 => $p + ($q - $p) * (2 / 3 - $t) * 6,
                default => $p,
            };
        };
        if (0.0 === $s) {
            $r = $g = $b = $l;
        } else {
            $q = $l < 0.5 ? $l * (1 + $s) : $l + $s - $l * $s;
            $p = 2 * $l - $q;
            $r = $hue($p, $q, $h + 1 / 3);
            $g = $hue($p, $q, $h);
            $b = $hue($p, $q, $h - 1 / 3);
        }

        return \sprintf('%02X%02X%02X', (int) round($r * 255), (int) round($g * 255), (int) round($b * 255));
    }

    /** @return array<string, string> */
    private function themeColors(): array
    {
        $out = ['dk1' => '000000', 'lt1' => 'FFFFFF', 'dk2' => '0F4382', 'lt2' => 'E6E9F0', 'accent1' => '0F4382'];
        foreach ($this->rels('ppt/presentation.xml') as $r) {
            if (!str_ends_with($r['type'], '/slideMaster')) {
                continue;
            }
            foreach ($this->rels($r['target']) as $mr) {
                if (str_ends_with($mr['type'], '/theme')) {
                    $doc = $this->xml($mr['target']);
                    $scheme = null !== $doc ? $this->first($doc, self::NS_A, 'clrScheme') : null;
                    if (null !== $scheme) {
                        foreach ($scheme->childNodes as $c) {
                            if ($c instanceof \DOMElement) {
                                $v = $this->color($c);
                                if (null !== $v) {
                                    $out[$c->localName] = substr($v, 1, 6);
                                }
                            }
                        }
                    }
                    break 2;
                }
            }
        }

        return $out;
    }

    /**
     * Изображение из пакета — в хранилище портала.
     *
     * @param array<string, array{type: string, target: string}> $rels
     */
    private function media(string $rid, array $rels): ?string
    {
        if (!isset($rels[$rid]) || null === $this->presentation) {
            return null;
        }
        $target = $rels[$rid]['target'];
        if (isset($this->imported[$target])) {
            return $this->imported[$target];
        }
        $data = $this->zip->getFromName($target);
        if (false === $data) {
            return null;
        }
        $tmp = tempnam(sys_get_temp_dir(), 'dp-img-');
        if (false === $tmp) {
            return null;
        }
        try {
            file_put_contents($tmp, $data);
            $asset = $this->assets->store($tmp, basename($target), $this->presentation, $this->user);
        } catch (\InvalidArgumentException) {
            $this->warnings[] = 'Пропущены изображения в форматах, которые не показывает браузер (EMF, WMF, TIFF) — замените их на PNG или JPEG.';

            return $this->imported[$target] = '';
        } finally {
            @unlink($tmp);
        }
        $src = 'asset:'.$asset->getToken();
        $this->imported[$src.'#size'] = $asset->getWidth().'x'.$asset->getHeight();

        return $this->imported[$target] = $src;
    }

    private function xml(string $name): ?\DOMDocument
    {
        $data = $this->zip->getFromName($name);
        if (false === $data || '' === $data) {
            return null;
        }
        $doc = new \DOMDocument();
        $prev = libxml_use_internal_errors(true);
        $ok = $doc->loadXML($data, \LIBXML_NONET | \LIBXML_COMPACT);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        return $ok ? $doc : null;
    }

    /** @return array<string, array{type: string, target: string}> */
    private function rels(string $part): array
    {
        $dir = \dirname($part);
        $relsPath = ('.' === $dir ? '' : $dir.'/').'_rels/'.basename($part).'.rels';
        $doc = $this->xml($relsPath);
        if (null === $doc) {
            return [];
        }
        $out = [];
        foreach ($doc->getElementsByTagName('Relationship') as $r) {
            /** @var \DOMElement $r */
            if ('External' === $r->getAttribute('TargetMode')) {
                continue;
            }
            $out[$r->getAttribute('Id')] = ['type' => $r->getAttribute('Type'), 'target' => self::resolve($dir, $r->getAttribute('Target'))];
        }

        return $out;
    }

    private static function resolve(string $base, string $target): string
    {
        if (str_starts_with($target, '/')) {
            return ltrim($target, '/');
        }
        $parts = '.' === $base || '' === $base ? [] : explode('/', $base);
        foreach (explode('/', $target) as $p) {
            if ('..' === $p) {
                array_pop($parts);
            } elseif ('.' !== $p && '' !== $p) {
                $parts[] = $p;
            }
        }

        return implode('/', $parts);
    }

    private function first(\DOMDocument $doc, string $ns, string $name): ?\DOMElement
    {
        $n = $doc->getElementsByTagNameNS($ns, $name)->item(0);

        return $n instanceof \DOMElement ? $n : null;
    }

    private function firstIn(\DOMElement $el, string $ns, string $name): ?\DOMElement
    {
        $n = $el->getElementsByTagNameNS($ns, $name)->item(0);

        return $n instanceof \DOMElement ? $n : null;
    }

    private function firstChild(\DOMElement $el, string $ns, string $name): ?\DOMElement
    {
        foreach ($el->childNodes as $c) {
            if ($c instanceof \DOMElement && $c->localName === $name && $c->namespaceURI === $ns) {
                return $c;
            }
        }

        return null;
    }
}
