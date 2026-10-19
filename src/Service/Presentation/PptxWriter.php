<?php

declare(strict_types=1);

namespace App\Service\Presentation;

use App\Entity\PresentationAsset;
use App\Repository\PresentationAssetRepository;
use App\Service\Xlsx\XlsxWriter;

/**
 * Выгрузка презентации в PowerPoint (.pptx). Основа — шаблон из образца «Презентация к собранию»
 * (resources/presentation/template.pptx: его мастер, макеты и тема с фирменными цветами и шрифтами
 * DIN Condensed Light / Roboto), слайды пишутся «как в образце»: надписи с шрифтами и цветами,
 * изображения с обрезкой, фигуры, линии со стрелками, таблицы, диаграммы PowerPoint с данными в Excel,
 * QR-коды, заметки докладчика, скрытые слайды, переходы и анимация появления, ссылки.
 *
 * Координаты: 1 px слайда 1280×720 = 9525 EMU (13,33″ × 7,5″), шрифты — в пунктах.
 */
final class PptxWriter
{
    private const EMU = 9525;
    private const NS = 'xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main"';
    private const REL = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/';
    private const BLANK_LAYOUT = 'slideLayout12.xml';
    /** Размер шрифта PowerPoint «одинарный интервал» ≈ 1,2 размера шрифта; в редакторе line-height = lineHeight × 1,2. */
    private const BULLET_INDENT = 144000;
    private const NUMBER_INDENT = 342900;
    private const LEVEL_INDENT = 342900;

    /** @var array<string, array{name: string, ext: string}> медиафайлы пакета по ключу источника */
    private array $media = [];
    /** @var array<string, string> имя медиафайла => содержимое */
    private array $mediaData = [];
    /** @var list<array{xml: string, xlsx: string}> */
    private array $charts = [];
    /** @var array<string, PresentationAsset> */
    private array $assetsByToken = [];

    public function __construct(
        private readonly BrandAssets $brand,
        private readonly AssetStore $store,
        private readonly PresentationAssetRepository $assets,
        private readonly string $projectDir,
    ) {
    }

    /**
     * Записать .pptx во временный файл и вернуть путь к нему (удаляет вызывающий).
     *
     * @param array<string, mixed> $deck колода после DeckValidator::normalize()
     */
    public function write(array $deck, string $title, string $author = '', string $company = 'ВОДОКОМФОРТ'): string
    {
        $this->media = [];
        $this->mediaData = [];
        $this->charts = [];
        $this->assetsByToken = $this->assets->findByTokens(DeckValidator::assetTokens($deck));

        $template = $this->readTemplate();
        $slides = array_values($deck['slides'] ?? []);
        $index = [];
        foreach ($slides as $i => $s) {
            $index[(string) $s['id']] = $i + 1;
        }
        $parts = [];
        $notesCount = 0;
        $hidden = 0;
        foreach ($slides as $i => $slide) {
            $n = $i + 1;
            [$xml, $rels] = $this->slideXml($slide, $index);
            $notes = trim((string) ($slide['notes'] ?? ''));
            if ('' !== $notes) {
                ++$notesCount;
                $rels[] = ['type' => 'notesSlide', 'target' => '../notesSlides/notesSlide'.$n.'.xml'];
                $parts['ppt/notesSlides/notesSlide'.$n.'.xml'] = $this->notesXml($notes);
                $parts['ppt/notesSlides/_rels/notesSlide'.$n.'.xml.rels'] = $this->relsXml([
                    ['type' => 'notesMaster', 'target' => '../notesMasters/notesMaster1.xml'],
                    ['type' => 'slide', 'target' => '../slides/slide'.$n.'.xml'],
                ]);
            }
            if ($slide['hidden'] ?? false) {
                ++$hidden;
            }
            $parts['ppt/slides/slide'.$n.'.xml'] = $xml;
            $parts['ppt/slides/_rels/slide'.$n.'.xml.rels'] = $this->relsXml($rels);
        }
        foreach ($this->charts as $k => $chart) {
            $c = $k + 1;
            $parts['ppt/charts/chart'.$c.'.xml'] = $chart['xml'];
            $parts['ppt/charts/_rels/chart'.$c.'.xml.rels'] = $this->relsXml([['type' => 'package', 'target' => '../embeddings/Microsoft_Excel_Worksheet'.$c.'.xlsx']]);
            $parts['ppt/embeddings/Microsoft_Excel_Worksheet'.$c.'.xlsx'] = $chart['xlsx'];
        }
        foreach ($this->mediaData as $name => $data) {
            $parts['ppt/media/'.$name] = $data;
        }

        // presentation.xml: список слайдов и страница заметок; связи — в presentation.xml.rels.
        $presRels = $template['ppt/_rels/presentation.xml.rels'];
        $sldIds = '';
        foreach ($slides as $i => $_) {
            $rid = 'rIdS'.($i + 1);
            $sldIds .= '<p:sldId id="'.(256 + $i).'" r:id="'.$rid.'"/>';
            $presRels = str_replace('</Relationships>', '<Relationship Id="'.$rid.'" Type="'.self::REL.'slide" Target="slides/slide'.($i + 1).'.xml"/></Relationships>', $presRels);
        }
        $pres = $template['ppt/presentation.xml'];
        $pres = preg_replace('#<p:notesMasterIdLst>.*?</p:notesMasterIdLst>#s', '', $pres) ?? $pres;
        $notesRid = 1 === preg_match('#Id="([^"]+)" Type="[^"]*/notesMaster"#', $presRels, $m) ? $m[1] : null;
        $insert = (null !== $notesRid ? '<p:notesMasterIdLst><p:notesMasterId r:id="'.$notesRid.'"/></p:notesMasterIdLst>' : '').('' !== $sldIds ? '<p:sldIdLst>'.$sldIds.'</p:sldIdLst>' : '');
        $pres = str_replace('</p:sldMasterIdLst>', '</p:sldMasterIdLst>'.$insert, $pres);
        $template['ppt/presentation.xml'] = $pres;
        $template['ppt/_rels/presentation.xml.rels'] = $presRels;

        $ct = $template['[Content_Types].xml'];
        $defaults = ['png' => 'image/png', 'jpeg' => 'image/jpeg', 'jpg' => 'image/jpeg', 'gif' => 'image/gif', 'svg' => 'image/svg+xml', 'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'];
        $add = '';
        foreach ($defaults as $ext => $type) {
            if (!str_contains($ct, 'Extension="'.$ext.'"')) {
                $add .= '<Default Extension="'.$ext.'" ContentType="'.$type.'"/>';
            }
        }
        foreach (array_keys($parts) as $name) {
            $type = match (true) {
                str_starts_with($name, 'ppt/slides/slide') => 'application/vnd.openxmlformats-officedocument.presentationml.slide+xml',
                str_starts_with($name, 'ppt/notesSlides/notesSlide') => 'application/vnd.openxmlformats-officedocument.presentationml.notesSlide+xml',
                str_starts_with($name, 'ppt/charts/chart') => 'application/vnd.openxmlformats-officedocument.drawingml.chart+xml',
                default => null,
            };
            if (null !== $type && !str_contains($name, '/_rels/')) {
                $add .= '<Override PartName="/'.$name.'" ContentType="'.$type.'"/>';
            }
        }
        $template['[Content_Types].xml'] = str_replace('</Types>', $add.'</Types>', $ct);
        $template['docProps/core.xml'] = $this->coreXml($title, $author);
        $template['docProps/app.xml'] = $this->appXml(\count($slides), $notesCount, $hidden, $company);

        $path = tempnam(sys_get_temp_dir(), 'dp-pptx-');
        if (false === $path) {
            throw new \RuntimeException('Не удалось создать временный файл.');
        }
        $zip = new \ZipArchive();
        if (true !== $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE)) {
            throw new \RuntimeException('Не удалось создать файл PowerPoint.');
        }
        $all = $template + $parts;
        $zip->addFromString('[Content_Types].xml', $all['[Content_Types].xml']);
        unset($all['[Content_Types].xml']);
        foreach ($all as $name => $data) {
            $zip->addFromString($name, $data);
            if (str_starts_with($name, 'ppt/media/') && !str_ends_with($name, '.svg')) {
                $zip->setCompressionName($name, \ZipArchive::CM_STORE);
            }
        }
        $zip->close();

        return $path;
    }

    /** @return array<string, string> */
    private function readTemplate(): array
    {
        $file = $this->projectDir.'/resources/presentation/template.pptx';
        $zip = new \ZipArchive();
        if (true !== $zip->open($file, \ZipArchive::RDONLY)) {
            throw new \RuntimeException('Не найден шаблон презентации: '.$file);
        }
        $out = [];
        for ($i = 0; $i < $zip->numFiles; ++$i) {
            $name = (string) $zip->getNameIndex($i);
            $out[$name] = (string) $zip->getFromIndex($i);
        }
        $zip->close();

        return $out;
    }

    // ---- Слайд ---------------------------------------------------------------------------------------

    /**
     * @param array<string, mixed> $slide
     * @param array<string, int>   $index
     *
     * @return array{0: string, 1: list<array{type: string, target: string, external?: bool}>}
     */
    private function slideXml(array $slide, array $index): array
    {
        $ctx = new SlideContext($index);
        $ctx->rel('slideLayout', '../slideLayouts/'.self::BLANK_LAYOUT);
        $bg = $this->background($slide['background'] ?? [], $ctx);
        $tree = '';
        $animated = [];
        foreach ($slide['elements'] ?? [] as $el) {
            $id = $ctx->nextId();
            $xml = match ($el['type']) {
                'text', 'shape' => $this->shapeXml($el, $id, $ctx),
                'image' => $this->pictureXml($el, $id, $ctx),
                'qr' => $this->qrXml($el, $id, $ctx),
                'line' => $this->lineXml($el, $id),
                'table' => $this->tableXml($el, $id),
                'chart' => $this->chartXml($el, $id, $ctx),
                default => '',
            };
            if ('' === $xml) {
                continue;
            }
            $tree .= $xml;
            if (null !== ($el['anim'] ?? null)) {
                $animated[] = ['id' => $id, 'anim' => $el['anim'], 'text' => \in_array($el['type'], ['text', 'shape'], true)];
            }
        }
        $show = ($slide['hidden'] ?? false) ? ' show="0"' : '';
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<p:sld '.self::NS.$show.'><p:cSld>'.$bg.'<p:spTree><p:nvGrpSpPr><p:cNvPr id="1" name=""/><p:cNvGrpSpPr/><p:nvPr/></p:nvGrpSpPr>'
            .'<p:grpSpPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="0" cy="0"/><a:chOff x="0" y="0"/><a:chExt cx="0" cy="0"/></a:xfrm></p:grpSpPr>'
            .$tree.'</p:spTree></p:cSld><p:clrMapOvr><a:masterClrMapping/></p:clrMapOvr>'
            .$this->transitionXml((string) ($slide['transition'] ?? 'none'))
            .$this->timingXml($animated)
            .'</p:sld>';

        return [$xml, $ctx->rels];
    }

    /** @param array<string, mixed> $bg */
    private function background(array $bg, SlideContext $ctx): string
    {
        $src = $bg['image'] ?? null;
        if (\is_string($src) && null !== ($img = $this->image($src))) {
            $rid = $ctx->rel('image', '../media/'.$img['name']);
            $rect = '';
            if ('cover' === ($bg['fit'] ?? 'cover') && $img['width'] > 0 && $img['height'] > 0) {
                $geo = self::fitGeometry(DeckValidator::WIDTH, DeckValidator::HEIGHT, $img['width'], $img['height'], 'cover', 0.5, 0.5, 1.0);
                $rect = self::srcRect($geo);
            }

            return '<p:bg><p:bgPr><a:blipFill dpi="0" rotWithShape="1"><a:blip r:embed="'.$rid.'"/>'.($rect ?: '<a:srcRect/>').'<a:stretch><a:fillRect/></a:stretch></a:blipFill><a:effectLst/></p:bgPr></p:bg>';
        }
        $color = (string) ($bg['color'] ?? '#FFFFFF');

        return '<p:bg><p:bgPr>'.self::solid($color).'<a:effectLst/></p:bgPr></p:bg>';
    }

    private function transitionXml(string $t): string
    {
        return match ($t) {
            'fade' => '<p:transition spd="med"><p:fade/></p:transition>',
            'slide' => '<p:transition spd="med"><p:push dir="l"/></p:transition>',
            'cover' => '<p:transition spd="med"><p:cover dir="l"/></p:transition>',
            'zoom' => '<p:transition spd="med"><p:zoom/></p:transition>',
            default => '',
        };
    }

    /**
     * Анимация появления: элементы с одинаковым порядком появляются вместе, по щелчку.
     *
     * @param list<array{id: int, anim: array<string, mixed>, text: bool}> $animated
     */
    private function timingXml(array $animated): string
    {
        if ([] === $animated) {
            return '';
        }
        usort($animated, static fn (array $a, array $b): int => [$a['anim']['order'], $a['id']] <=> [$b['anim']['order'], $b['id']]);
        $steps = [];
        foreach ($animated as $a) {
            $steps[(int) $a['anim']['order']][] = $a;
        }
        $ctn = 3;
        $clicks = '';
        foreach ($steps as $group) {
            $effects = '';
            foreach ($group as $k => $a) {
                $type = (string) $a['anim']['type'];
                $dur = (int) round((float) $a['anim']['duration'] * 1000);
                $delay = (int) round((float) $a['anim']['delay'] * 1000);
                $wipe = 'wipe' === $type;
                $preset = $wipe ? 22 : 10;
                $subtype = $wipe ? 8 : 0;
                $filter = $wipe ? 'wipe(left)' : 'fade';
                $outer = $ctn++;
                $set = $ctn++;
                $eff = $ctn++;
                $effects .= '<p:par><p:cTn id="'.$outer.'" presetID="'.$preset.'" presetClass="entr" presetSubtype="'.$subtype.'" fill="hold"'.($a['text'] ? ' grpId="0"' : '').' nodeType="'.(0 === $k ? 'clickEffect' : 'withEffect').'">'
                    .'<p:stCondLst><p:cond delay="'.$delay.'"/></p:stCondLst><p:childTnLst>'
                    .'<p:set><p:cBhvr><p:cTn id="'.$set.'" dur="1" fill="hold"><p:stCondLst><p:cond delay="0"/></p:stCondLst></p:cTn><p:tgtEl><p:spTgt spid="'.$a['id'].'"/></p:tgtEl><p:attrNameLst><p:attrName>style.visibility</p:attrName></p:attrNameLst></p:cBhvr><p:to><p:strVal val="visible"/></p:to></p:set>'
                    .'<p:animEffect transition="in" filter="'.$filter.'"><p:cBhvr><p:cTn id="'.$eff.'" dur="'.$dur.'"/><p:tgtEl><p:spTgt spid="'.$a['id'].'"/></p:tgtEl></p:cBhvr></p:animEffect>'
                    .'</p:childTnLst></p:cTn></p:par>';
            }
            $p1 = $ctn++;
            $p2 = $ctn++;
            $clicks .= '<p:par><p:cTn id="'.$p1.'" fill="hold"><p:stCondLst><p:cond delay="indefinite"/></p:stCondLst><p:childTnLst>'
                .'<p:par><p:cTn id="'.$p2.'" fill="hold"><p:stCondLst><p:cond delay="0"/></p:stCondLst><p:childTnLst>'.$effects.'</p:childTnLst></p:cTn></p:par>'
                .'</p:childTnLst></p:cTn></p:par>';
        }
        $bld = '';
        foreach ($animated as $a) {
            if ($a['text']) {
                $bld .= '<p:bldP spid="'.$a['id'].'" grpId="0"/>';
            }
        }

        return '<p:timing><p:tnLst><p:par><p:cTn id="1" dur="indefinite" restart="never" nodeType="tmRoot"><p:childTnLst>'
            .'<p:seq concurrent="1" nextAc="seek"><p:cTn id="2" dur="indefinite" nodeType="mainSeq"><p:childTnLst>'.$clicks.'</p:childTnLst></p:cTn>'
            .'<p:prevCondLst><p:cond evt="onPrev" delay="0"><p:tgtEl><p:sldTgt/></p:tgtEl></p:cond></p:prevCondLst>'
            .'<p:nextCondLst><p:cond evt="onNext" delay="0"><p:tgtEl><p:sldTgt/></p:tgtEl></p:cond></p:nextCondLst></p:seq>'
            .'</p:childTnLst></p:cTn></p:par></p:tnLst>'.('' !== $bld ? '<p:bldLst>'.$bld.'</p:bldLst>' : '').'</p:timing>';
    }

    // ---- Надписи и фигуры ---------------------------------------------------------------------------

    /** @param array<string, mixed> $el */
    private function shapeXml(array $el, int $id, SlideContext $ctx): string
    {
        $isShape = 'shape' === $el['type'];
        $html = (string) ($el['html'] ?? '');
        $paragraphs = RichText::parse($html);
        $fill = $el['fill'] ?? null;
        $hasFill = null !== $fill || null !== ($el['gradient'] ?? null);
        $hasStroke = null !== ($el['stroke'] ?? null) && (float) ($el['strokeWidth'] ?? 0) > 0;
        if ([] === $paragraphs && !$hasFill && !$hasStroke) {
            return ''; // пустая надпись — только подсказка в редакторе
        }
        $opacity = (float) ($el['opacity'] ?? 1);
        $geom = $isShape ? (string) ($el['shape'] ?? 'rect') : 'rect';
        $radius = (float) ($el['radius'] ?? 0);
        if ('rect' === $geom && $radius > 0) {
            $geom = 'roundRect';
        }
        $av = '<a:avLst/>';
        if ('roundRect' === $geom) {
            $min = max(1.0, min((float) $el['w'], (float) $el['h']));
            $adj = $radius > 0 ? (int) min(50000, round($radius / $min * 100000)) : 16667;
            $av = '<a:avLst><a:gd name="adj" fmla="val '.$adj.'"/></a:avLst>';
        }
        $spPr = '<p:spPr>'.self::xfrm($el).'<a:prstGeom prst="'.$geom.'">'.$av.'</a:prstGeom>'
            .$this->fillXml($el, $opacity)
            .($hasStroke ? self::ln((string) $el['stroke'], (float) $el['strokeWidth'], (string) ($el['dash'] ?? 'solid'), $opacity) : '<a:ln><a:noFill/></a:ln>')
            .self::shadow($el['shadow'] ?? null, $opacity)
            .'</p:spPr>';
        $pad = (int) round((float) ($el['padding'] ?? 0) * self::EMU);
        $anchor = ['top' => 't', 'middle' => 'ctr', 'bottom' => 'b'][$el['valign'] ?? 'top'] ?? 't';
        $body = '<p:txBody><a:bodyPr wrap="square" lIns="'.$pad.'" tIns="'.$pad.'" rIns="'.$pad.'" bIns="'.$pad.'" anchor="'.$anchor.'" rtlCol="0"><a:noAutofit/></a:bodyPr><a:lstStyle/>'
            .$this->paragraphsXml($paragraphs, $el, $opacity, $ctx).'</p:txBody>';
        $name = self::x((string) ($el['name'] ?? ($isShape ? 'Фигура '.$id : 'Надпись '.$id)));

        return '<p:sp><p:nvSpPr><p:cNvPr id="'.$id.'" name="'.$name.'">'.$this->linkXml($el, $ctx).'</p:cNvPr><p:cNvSpPr'.($isShape ? '' : ' txBox="1"').'/><p:nvPr/></p:nvSpPr>'
            .$spPr.$body.'</p:sp>';
    }

    /**
     * @param list<array{list: ?string, level: int, align: ?string, runs: list<array<string, mixed>>}> $paragraphs
     * @param array<string, mixed>                                                                         $el
     */
    private function paragraphsXml(array $paragraphs, array $el, float $opacity, SlideContext $ctx): string
    {
        if ([] === $paragraphs) {
            return '<a:p><a:endParaRPr lang="ru-RU" sz="'.self::sz((float) ($el['fontSize'] ?? 16)).'" dirty="0"/></a:p>';
        }
        $out = '';
        $align = ['left' => 'l', 'center' => 'ctr', 'right' => 'r', 'justify' => 'just'];
        $lh = (int) round((float) ($el['lineHeight'] ?? 1) * 100000);
        $before = (int) round((float) ($el['spaceBefore'] ?? 0) * 100);
        $listColor = (string) ($el['listColor'] ?? '#104382');
        foreach ($paragraphs as $p) {
            $algn = $align[$p['align'] ?? $el['align'] ?? 'left'] ?? 'l';
            $level = (int) $p['level'];
            $bullet = '<a:buNone/>';
            $mar = '';
            if ('bullet' === $p['list'] || 'number' === $p['list']) {
                $indent = 'bullet' === $p['list'] ? self::BULLET_INDENT : self::NUMBER_INDENT;
                $marL = $indent + $level * self::LEVEL_INDENT;
                $mar = ' marL="'.$marL.'" indent="-'.$indent.'"';
                $bullet = '<a:buClr>'.self::clr($listColor, $opacity).'</a:buClr><a:buSzPct val="120000"/>'
                    .('bullet' === $p['list'] ? '<a:buFont typeface="Arial"/><a:buChar char="•"/>' : '<a:buFont typeface="+mj-lt"/><a:buAutoNum type="arabicPeriod"/>');
            } elseif ($level > 0) {
                $mar = ' marL="'.($level * self::LEVEL_INDENT).'" indent="0"';
            }
            $out .= '<a:p><a:pPr algn="'.$algn.'"'.$mar.'><a:lnSpc><a:spcPct val="'.$lh.'"/></a:lnSpc><a:spcBef><a:spcPts val="'.$before.'"/></a:spcBef>'.$bullet.'</a:pPr>';
            foreach ($p['runs'] as $r) {
                $rPr = $this->runProps($r, $el, $opacity, $ctx);
                if (isset($r['br'])) {
                    $out .= '<a:br>'.$rPr.'</a:br>';
                } else {
                    $text = (string) ($r['text'] ?? '');
                    if ('' === $text) {
                        continue;
                    }
                    $out .= '<a:r>'.$rPr.'<a:t>'.self::x($text).'</a:t></a:r>';
                }
            }
            $out .= '<a:endParaRPr lang="ru-RU" sz="'.self::sz((float) ($el['fontSize'] ?? 16)).'" dirty="0"/></a:p>';
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $r
     * @param array<string, mixed> $el
     */
    private function runProps(array $r, array $el, float $opacity, SlideContext $ctx): string
    {
        $size = (float) ($r['size'] ?? $el['fontSize'] ?? 16);
        $family = (string) ($r['family'] ?? $el['fontFamily'] ?? 'Roboto');
        $weight = (int) ($r['weight'] ?? $el['fontWeight'] ?? 400);
        $bold = ($r['bold'] ?? false) || $weight >= 600;
        $italic = ($r['italic'] ?? null) ?? (bool) ($el['italic'] ?? false);
        $attrs = ' lang="ru-RU" sz="'.self::sz($size).'"'.($bold ? ' b="1"' : ' b="0"').($italic ? ' i="1"' : '');
        if ($r['underline'] ?? false) {
            $attrs .= ' u="sng"';
        }
        if ($r['strike'] ?? false) {
            $attrs .= ' strike="sngStrike"';
        }
        if (($r['caps'] ?? null) ?? (bool) ($el['caps'] ?? false)) {
            $attrs .= ' cap="all"';
        }
        $spacing = (float) ($r['spacing'] ?? $el['letterSpacing'] ?? 0);
        if (0.0 !== $spacing) {
            $attrs .= ' spc="'.(int) round($spacing * 100).'"';
        }
        if (isset($r['baseline'])) {
            $attrs .= ' baseline="'.(int) $r['baseline'].'"';
        }
        $color = (string) ($r['color'] ?? $el['color'] ?? '#262626');
        $inner = '<a:solidFill>'.self::clr($color, $opacity).'</a:solidFill>';
        if (isset($r['highlight']) && \is_string($r['highlight'])) {
            $inner .= '<a:highlight>'.self::clr($r['highlight'], 1).'</a:highlight>';
        }
        $face = self::x($family);
        $inner .= '<a:latin typeface="'.$face.'"/><a:ea typeface="'.$face.'"/><a:cs typeface="'.$face.'"/>';
        if (isset($r['href']) && \is_string($r['href']) && '' !== $r['href']) {
            $inner .= '<a:hlinkClick r:id="'.$ctx->rel('hyperlink', $r['href'], true).'"/>';
        }

        return '<a:rPr'.$attrs.' dirty="0">'.$inner.'</a:rPr>';
    }

    /** @param array<string, mixed> $el */
    private function fillXml(array $el, float $opacity): string
    {
        $g = $el['gradient'] ?? null;
        if (\is_array($g)) {
            $ang = (int) round(fmod(((float) $g['angle'] - 90 + 360), 360) * 60000);

            return '<a:gradFill rotWithShape="1"><a:gsLst><a:gs pos="0">'.self::clr((string) $g['from'], $opacity).'</a:gs><a:gs pos="100000">'.self::clr((string) $g['to'], $opacity).'</a:gs></a:gsLst><a:lin ang="'.$ang.'" scaled="0"/></a:gradFill>';
        }
        $fill = $el['fill'] ?? null;

        return \is_string($fill) ? '<a:solidFill>'.self::clr($fill, $opacity).'</a:solidFill>' : '<a:noFill/>';
    }

    /** @param array<string, mixed> $el */
    private function linkXml(array $el, SlideContext $ctx): string
    {
        $link = $el['link'] ?? null;
        if (!\is_array($link)) {
            return '';
        }
        if (isset($link['url'])) {
            return '<a:hlinkClick r:id="'.$ctx->rel('hyperlink', (string) $link['url'], true).'"/>';
        }
        if (isset($link['slide']) && null !== ($n = $ctx->slideNumber((string) $link['slide']))) {
            return '<a:hlinkClick r:id="'.$ctx->rel('slide', 'slide'.$n.'.xml').'" action="ppaction://hlinksldjump"/>';
        }

        return '';
    }

    // ---- Изображения ------------------------------------------------------------------------------

    /** @param array<string, mixed> $el */
    private function pictureXml(array $el, int $id, SlideContext $ctx): string
    {
        $src = $el['src'] ?? null;
        $img = \is_string($src) ? $this->image($src) : null;
        if (null === $img) {
            return ''; // место для изображения без изображения
        }
        $geo = self::fitGeometry((float) $el['w'], (float) $el['h'], $img['width'], $img['height'], (string) ($el['fit'] ?? 'cover'), (float) ($el['focusX'] ?? 0.5), (float) ($el['focusY'] ?? 0.5), (float) ($el['zoom'] ?? 1));
        $frame = $el;
        $frame['x'] = (float) $el['x'] + $geo['x'];
        $frame['y'] = (float) $el['y'] + $geo['y'];
        $frame['w'] = $geo['w'];
        $frame['h'] = $geo['h'];
        // Поворот — вокруг центра элемента: сдвиг рамки внутри элемента поворачиваем вместе с ним.
        if (0.0 !== (float) ($el['rotation'] ?? 0)) {
            $cx = (float) $el['x'] + (float) $el['w'] / 2;
            $cy = (float) $el['y'] + (float) $el['h'] / 2;
            $fx = $frame['x'] + $geo['w'] / 2 - $cx;
            $fy = $frame['y'] + $geo['h'] / 2 - $cy;
            $a = deg2rad((float) $el['rotation']);
            $rx = $fx * cos($a) - $fy * sin($a);
            $ry = $fx * sin($a) + $fy * cos($a);
            $frame['x'] = $cx + $rx - $geo['w'] / 2;
            $frame['y'] = $cy + $ry - $geo['h'] / 2;
        }
        $rid = $ctx->rel('image', '../media/'.$img['name']);
        $svg = '';
        if (null !== $img['svg']) {
            $sid = $ctx->rel('image', '../media/'.$img['svg']);
            $svg = '<a:extLst><a:ext uri="{96DAC541-7B7A-43D3-8B79-37D633B846F1}"><asvg:svgBlip xmlns:asvg="http://schemas.microsoft.com/office/drawing/2016/SVG/main" r:embed="'.$sid.'"/></a:ext></a:extLst>';
        }
        $opacity = (float) ($el['opacity'] ?? 1);
        $alpha = $opacity < 1 ? '<a:alphaModFix amt="'.(int) round($opacity * 100000).'"/>' : '';
        $geom = 'rect';
        $av = '<a:avLst/>';
        if ('circle' === ($el['mask'] ?? 'none')) {
            $geom = 'ellipse';
        } elseif ((float) ($el['radius'] ?? 0) > 0) {
            $geom = 'roundRect';
            $min = max(1.0, min($geo['w'], $geo['h']));
            $av = '<a:avLst><a:gd name="adj" fmla="val '.(int) min(50000, round((float) $el['radius'] / $min * 100000)).'"/></a:avLst>';
        }
        $border = \is_array($el['border'] ?? null) ? self::ln((string) $el['border']['color'], (float) $el['border']['width'], 'solid', $opacity) : '';
        $name = self::x((string) ($el['name'] ?? 'Рисунок '.$id));
        $descr = '' !== (string) ($el['alt'] ?? '') ? ' descr="'.self::x((string) $el['alt']).'"' : '';

        return '<p:pic><p:nvPicPr><p:cNvPr id="'.$id.'" name="'.$name.'"'.$descr.'>'.$this->linkXml($el, $ctx).'</p:cNvPr><p:cNvPicPr><a:picLocks noChangeAspect="1"/></p:cNvPicPr><p:nvPr/></p:nvPicPr>'
            .'<p:blipFill><a:blip r:embed="'.$rid.'">'.$alpha.$svg.'</a:blip>'.(self::srcRect($geo) ?: '<a:srcRect/>').'<a:stretch><a:fillRect/></a:stretch></p:blipFill>'
            .'<p:spPr>'.self::xfrm($frame).'<a:prstGeom prst="'.$geom.'">'.$av.'</a:prstGeom>'.$border.self::shadow($el['shadow'] ?? null, $opacity).'</p:spPr></p:pic>';
    }

    /** @param array<string, mixed> $el */
    private function qrXml(array $el, int $id, SlideContext $ctx): string
    {
        $text = (string) ($el['text'] ?? '');
        if ('' === $text) {
            return '';
        }
        try {
            $png = QrImage::png($text, (string) ($el['color'] ?? '#0F4382'), (string) ($el['bg'] ?? '#FFFFFF'));
        } catch (\InvalidArgumentException) {
            return '';
        }
        $key = 'qr:'.sha1($png);
        if (!isset($this->media[$key])) {
            $name = 'image'.(\count($this->mediaData) + 1).'.png';
            $this->media[$key] = ['name' => $name, 'ext' => 'png'];
            $this->mediaData[$name] = $png;
        }
        $rid = $ctx->rel('image', '../media/'.$this->media[$key]['name']);
        $side = min((float) $el['w'], (float) $el['h']);
        $frame = $el;
        $frame['x'] = (float) $el['x'] + ((float) $el['w'] - $side) / 2;
        $frame['y'] = (float) $el['y'] + ((float) $el['h'] - $side) / 2;
        $frame['w'] = $side;
        $frame['h'] = $side;

        return '<p:pic><p:nvPicPr><p:cNvPr id="'.$id.'" name="QR-код '.$id.'" descr="'.self::x($text).'">'.$this->linkXml($el, $ctx).'</p:cNvPr><p:cNvPicPr><a:picLocks noChangeAspect="1"/></p:cNvPicPr><p:nvPr/></p:nvPicPr>'
            .'<p:blipFill><a:blip r:embed="'.$rid.'"/><a:srcRect/><a:stretch><a:fillRect/></a:stretch></p:blipFill>'
            .'<p:spPr>'.self::xfrm($frame).'<a:prstGeom prst="rect"><a:avLst/></a:prstGeom></p:spPr></p:pic>';
    }

    /**
     * Файл изображения в пакете (растровый и, если есть, векторный вариант).
     *
     * @return array{name: string, svg: ?string, width: int, height: int}|null
     */
    private function image(string $src): ?array
    {
        if (isset($this->media[$src])) {
            $m = $this->media[$src];

            return ['name' => $m['name'], 'svg' => $m['svg'] ?? null, 'width' => $m['width'] ?? 0, 'height' => $m['height'] ?? 0];
        }
        $svgPath = null;
        if (str_starts_with($src, 'brand:')) {
            $key = substr($src, 6);
            $path = $this->brand->rasterPath($key);
            $svgPath = $this->brand->svgPath($key);
            $info = BrandAssets::ASSETS[$key] ?? null;
            if (null === $path || null === $info || !is_file($path)) {
                return null;
            }
            $ext = 'image/jpeg' === $info['mime'] ? 'jpg' : 'png';
            [$w, $h] = [$info['width'], $info['height']];
        } elseif (str_starts_with($src, 'asset:')) {
            $asset = $this->assetsByToken[substr($src, 6)] ?? null;
            if (null === $asset) {
                return null;
            }
            $path = $this->store->path($asset);
            if (!is_file($path)) {
                return null;
            }
            $ext = $asset->getExtension();
            [$w, $h] = [$asset->getWidth(), $asset->getHeight()];
        } else {
            return null;
        }
        $n = \count($this->mediaData) + 1;
        $name = 'image'.$n.'.'.$ext;
        $this->mediaData[$name] = (string) file_get_contents($path);
        $svgName = null;
        if (null !== $svgPath && is_file($svgPath)) {
            $svgName = 'image'.($n + 1).'.svg';
            $this->mediaData[$svgName] = (string) file_get_contents($svgPath);
        }
        $this->media[$src] = ['name' => $name, 'ext' => $ext, 'svg' => $svgName, 'width' => $w, 'height' => $h];

        return ['name' => $name, 'svg' => $svgName, 'width' => $w, 'height' => $h];
    }

    /**
     * Где изображение внутри элемента: видимая часть (x, y, w, h относительно элемента) и обрезка (доли).
     * Та же математика — в редакторе (deck-render.js, fitGeometry), чтобы PowerPoint показывал то же.
     *
     * @return array{x: float, y: float, w: float, h: float, l: float, t: float, r: float, b: float}
     */
    public static function fitGeometry(float $w, float $h, int $iw, int $ih, string $fit, float $fx, float $fy, float $zoom): array
    {
        if ($iw <= 0 || $ih <= 0 || 'fill' === $fit) {
            return ['x' => 0, 'y' => 0, 'w' => $w, 'h' => $h, 'l' => 0, 't' => 0, 'r' => 0, 'b' => 0];
        }
        $scale = ('contain' === $fit ? min($w / $iw, $h / $ih) : max($w / $iw, $h / $ih)) * max(1.0, $zoom);
        $dw = $iw * $scale;
        $dh = $ih * $scale;
        $ox = ($w - $dw) * ('contain' === $fit && $dw <= $w ? 0.5 : $fx);
        $oy = ($h - $dh) * ('contain' === $fit && $dh <= $h ? 0.5 : $fy);
        $x0 = max(0.0, $ox);
        $y0 = max(0.0, $oy);
        $x1 = min($w, $ox + $dw);
        $y1 = min($h, $oy + $dh);

        return [
            'x' => $x0, 'y' => $y0, 'w' => max(1.0, $x1 - $x0), 'h' => max(1.0, $y1 - $y0),
            'l' => ($x0 - $ox) / $dw, 't' => ($y0 - $oy) / $dh, 'r' => ($ox + $dw - $x1) / $dw, 'b' => ($oy + $dh - $y1) / $dh,
        ];
    }

    /** @param array{l: float, t: float, r: float, b: float} $geo */
    private static function srcRect(array $geo): string
    {
        $v = static fn (float $f): int => (int) round($f * 100000);
        if ($v($geo['l']) <= 0 && $v($geo['t']) <= 0 && $v($geo['r']) <= 0 && $v($geo['b']) <= 0) {
            return '';
        }

        return '<a:srcRect l="'.max(0, $v($geo['l'])).'" t="'.max(0, $v($geo['t'])).'" r="'.max(0, $v($geo['r'])).'" b="'.max(0, $v($geo['b'])).'"/>';
    }

    // ---- Линии ------------------------------------------------------------------------------------

    /** @param array<string, mixed> $el */
    private function lineXml(array $el, int $id): string
    {
        $x1 = (float) $el['x1'];
        $y1 = (float) $el['y1'];
        $x2 = (float) $el['x2'];
        $y2 = (float) $el['y2'];
        $flip = ($x2 < $x1 ? ' flipH="1"' : '').($y2 < $y1 ? ' flipV="1"' : '');
        $opacity = (float) ($el['opacity'] ?? 1);
        $ln = self::ln((string) $el['stroke'], (float) $el['strokeWidth'], (string) ($el['dash'] ?? 'solid'), $opacity, (string) ($el['start'] ?? 'none'), (string) ($el['end'] ?? 'none'));

        return '<p:cxnSp><p:nvCxnSpPr><p:cNvPr id="'.$id.'" name="'.self::x((string) ($el['name'] ?? 'Линия '.$id)).'"/><p:cNvCxnSpPr/><p:nvPr/></p:nvCxnSpPr>'
            .'<p:spPr><a:xfrm'.$flip.'><a:off x="'.self::emu(min($x1, $x2)).'" y="'.self::emu(min($y1, $y2)).'"/><a:ext cx="'.self::emu(abs($x2 - $x1)).'" cy="'.self::emu(abs($y2 - $y1)).'"/></a:xfrm>'
            .'<a:prstGeom prst="line"><a:avLst/></a:prstGeom>'.$ln.self::shadow($el['shadow'] ?? null, $opacity).'</p:spPr></p:cxnSp>';
    }

    // ---- Таблицы ----------------------------------------------------------------------------------

    /** @param array<string, mixed> $el */
    private function tableXml(array $el, int $id): string
    {
        $rows = $el['rows'] ?? [];
        $st = $el['style'] ?? [];
        $cols = $el['cols'] ?? [];
        $heights = $el['heights'] ?? [];
        $totalW = array_sum($cols) ?: 1;
        $totalH = array_sum($heights) ?: 1;
        $widths = array_map(static fn ($c): int => self::emu((float) $c / $totalW * (float) $el['w']), $cols);
        $grid = '';
        foreach ($widths as $w) {
            $grid .= '<a:gridCol w="'.$w.'"/>';
        }
        $border = (string) ($st['borderColor'] ?? '#BFBFBF');
        $bw = (float) ($st['borderWidth'] ?? 1);
        $pad = self::emu((float) ($st['padding'] ?? 8));
        $trs = '';
        foreach ($rows as $ri => $row) {
            $h = self::emu((float) ($heights[$ri] ?? 1) / $totalH * (float) $el['h']);
            $trs .= '<a:tr h="'.$h.'">';
            foreach ($row as $ci => $cell) {
                $header = ($st['header'] ?? true) && 0 === $ri;
                $first = ($st['firstCol'] ?? false) && 0 === $ci && !$header;
                $fill = $cell['fill'] ?? ($header ? $st['headerFill'] : ($first ? $st['firstColFill'] : (($st['banded'] ?? false) && 0 === $ri % 2 ? $st['bandFill'] : $st['fill'])));
                $color = $cell['color'] ?? ($header ? $st['headerColor'] : ($first ? $st['firstColColor'] : $st['color']));
                $align = ['left' => 'l', 'center' => 'ctr', 'right' => 'r'][$cell['align'] ?? $st['align'] ?? 'center'] ?? 'ctr';
                $span = '';
                if ((int) ($cell['colspan'] ?? 1) > 1) {
                    $span .= ' gridSpan="'.(int) $cell['colspan'].'"';
                }
                if ((int) ($cell['rowspan'] ?? 1) > 1) {
                    $span .= ' rowSpan="'.(int) $cell['rowspan'].'"';
                }
                if ($cell['hidden'] ?? false) {
                    $span .= ' hMerge="1"';
                }
                $paras = '';
                foreach (explode("\n", (string) ($cell['text'] ?? '')) as $line) {
                    $rPr = '<a:rPr lang="ru-RU" sz="'.self::sz((float) ($st['fontSize'] ?? 16)).'"'.(($cell['bold'] ?? false) ? ' b="1"' : '').(($cell['italic'] ?? false) ? ' i="1"' : '').' dirty="0">'
                        .'<a:solidFill>'.self::clr((string) $color, (float) ($el['opacity'] ?? 1)).'</a:solidFill>'
                        .'<a:latin typeface="'.self::x((string) ($st['fontFamily'] ?? 'Roboto')).'"/><a:ea typeface="'.self::x((string) ($st['fontFamily'] ?? 'Roboto')).'"/></a:rPr>';
                    $paras .= '<a:p><a:pPr marL="0" algn="'.$align.'"/>'.('' !== $line ? '<a:r>'.$rPr.'<a:t>'.self::x($line).'</a:t></a:r>' : '').'<a:endParaRPr lang="ru-RU" sz="'.self::sz((float) ($st['fontSize'] ?? 16)).'"/></a:p>';
                }
                $line = '<a:solidFill>'.self::clr($border, 1).'</a:solidFill><a:prstDash val="solid"/>';
                $lnw = (int) round($bw * self::EMU);
                $lines = $bw > 0
                    ? '<a:lnL w="'.$lnw.'">'.$line.'</a:lnL><a:lnR w="'.$lnw.'">'.$line.'</a:lnR><a:lnT w="'.$lnw.'">'.$line.'</a:lnT><a:lnB w="'.$lnw.'">'.$line.'</a:lnB>'
                    : '<a:lnL><a:noFill/></a:lnL><a:lnR><a:noFill/></a:lnR><a:lnT><a:noFill/></a:lnT><a:lnB><a:noFill/></a:lnB>';
                $trs .= '<a:tc'.$span.'><a:txBody><a:bodyPr/><a:lstStyle/>'.$paras.'</a:txBody>'
                    .'<a:tcPr marL="'.$pad.'" marR="'.$pad.'" marT="'.(int) ($pad / 2).'" marB="'.(int) ($pad / 2).'" anchor="ctr">'.$lines
                    .(\is_string($fill) ? '<a:solidFill>'.self::clr($fill, (float) ($el['opacity'] ?? 1)).'</a:solidFill>' : '<a:noFill/>').'</a:tcPr></a:tc>';
            }
            $trs .= '</a:tr>';
        }

        return '<p:graphicFrame><p:nvGraphicFramePr><p:cNvPr id="'.$id.'" name="'.self::x((string) ($el['name'] ?? 'Таблица '.$id)).'"/><p:cNvGraphicFramePr><a:graphicFrameLocks noGrp="1"/></p:cNvGraphicFramePr><p:nvPr/></p:nvGraphicFramePr>'
            .'<p:xfrm><a:off x="'.self::emu((float) $el['x']).'" y="'.self::emu((float) $el['y']).'"/><a:ext cx="'.array_sum($widths).'" cy="'.self::emu((float) $el['h']).'"/></p:xfrm>'
            .'<a:graphic><a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/table"><a:tbl><a:tblPr firstRow="'.(($st['header'] ?? true) ? 1 : 0).'" bandRow="'.(($st['banded'] ?? false) ? 1 : 0).'"/>'
            .'<a:tblGrid>'.$grid.'</a:tblGrid>'.$trs.'</a:tbl></a:graphicData></a:graphic></p:graphicFrame>';
    }

    // ---- Диаграммы --------------------------------------------------------------------------------

    /** @param array<string, mixed> $el */
    private function chartXml(array $el, int $id, SlideContext $ctx): string
    {
        $n = \count($this->charts) + 1;
        $this->charts[] = ['xml' => $this->chartSpace($el), 'xlsx' => $this->chartWorkbook($el)];
        $rid = $ctx->rel('chart', '../charts/chart'.$n.'.xml');

        return '<p:graphicFrame><p:nvGraphicFramePr><p:cNvPr id="'.$id.'" name="'.self::x((string) ($el['name'] ?? 'Диаграмма '.$id)).'"/><p:cNvGraphicFramePr/><p:nvPr/></p:nvGraphicFramePr>'
            .'<p:xfrm><a:off x="'.self::emu((float) $el['x']).'" y="'.self::emu((float) $el['y']).'"/><a:ext cx="'.self::emu((float) $el['w']).'" cy="'.self::emu((float) $el['h']).'"/></p:xfrm>'
            .'<a:graphic><a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/chart"><c:chart xmlns:c="http://schemas.openxmlformats.org/drawingml/2006/chart" r:id="'.$rid.'"/></a:graphicData></a:graphic></p:graphicFrame>';
    }

    /** @param array<string, mixed> $el */
    private function chartSpace(array $el): string
    {
        $type = (string) ($el['chart'] ?? 'column');
        $cats = $el['categories'] ?? [];
        $series = $el['series'] ?? [];
        $palette = SlideLayouts::CHART_COLORS;
        $font = self::sz((float) ($el['fontSize'] ?? 12));
        $textColor = (string) ($el['color'] ?? '#383838');
        $txPr = '<c:txPr><a:bodyPr/><a:lstStyle/><a:p><a:pPr><a:defRPr sz="'.$font.'" b="0"><a:solidFill>'.self::clr($textColor, 1).'</a:solidFill><a:latin typeface="Roboto"/><a:ea typeface="Roboto"/></a:defRPr></a:pPr><a:endParaRPr lang="ru-RU"/></a:p></c:txPr>';
        $nCat = \count($cats);
        $catRef = '<c:cat><c:strRef><c:f>Sheet1!$A$2:$A$'.($nCat + 1).'</c:f><c:strCache><c:ptCount val="'.$nCat.'"/>';
        foreach ($cats as $i => $c) {
            $catRef .= '<c:pt idx="'.$i.'"><c:v>'.self::x((string) $c).'</c:v></c:pt>';
        }
        $catRef .= '</c:strCache></c:strRef></c:cat>';
        $pie = \in_array($type, ['pie', 'doughnut'], true);
        $stacked = (bool) ($el['stacked'] ?? false) && !$pie;
        $labels = (bool) ($el['labels'] ?? false);
        $pointColors = $el['pointColors'] ?? [];
        $sers = '';
        foreach ($series as $si => $s) {
            $col = XlsxWriter::column($si + 2);
            $color = (string) ($s['color'] ?? $palette[$si % \count($palette)]);
            $vals = '<c:val><c:numRef><c:f>Sheet1!$'.$col.'$2:$'.$col.'$'.($nCat + 1).'</c:f><c:numCache><c:formatCode>General</c:formatCode><c:ptCount val="'.$nCat.'"/>';
            foreach ($s['values'] as $i => $v) {
                if (null !== $v) {
                    $vals .= '<c:pt idx="'.$i.'"><c:v>'.self::number((float) $v).'</c:v></c:pt>';
                }
            }
            $vals .= '</c:numCache></c:numRef></c:val>';
            $tx = '<c:tx><c:strRef><c:f>Sheet1!$'.$col.'$1</c:f><c:strCache><c:ptCount val="1"/><c:pt idx="0"><c:v>'.self::x((string) $s['name']).'</c:v></c:pt></c:strCache></c:strRef></c:tx>';
            $dLbls = '';
            if ($labels) {
                $pos = match (true) {
                    $pie => 'bestFit',
                    'line' === $type => 't',
                    'area' === $type => null,
                    $stacked => 'ctr',
                    default => 'outEnd',
                };
                $dLbls = '<c:dLbls><c:spPr><a:noFill/><a:ln><a:noFill/></a:ln></c:spPr>'.$txPr.(null !== $pos ? '<c:dLblPos val="'.$pos.'"/>' : '')
                    .'<c:showLegendKey val="0"/><c:showVal val="1"/><c:showCatName val="0"/><c:showSerName val="0"/><c:showPercent val="0"/><c:showBubbleSize val="0"/></c:dLbls>';
            }
            if ($pie) {
                $dPt = '';
                foreach (array_keys($cats) as $i) {
                    $pc = $pointColors[$i] ?? $palette[$i % \count($palette)];
                    $dPt .= '<c:dPt><c:idx val="'.$i.'"/><c:bubble3D val="0"/><c:spPr><a:solidFill>'.self::clr((string) $pc, 1).'</a:solidFill><a:ln w="19050"><a:solidFill><a:srgbClr val="FFFFFF"/></a:solidFill></a:ln></c:spPr></c:dPt>';
                }
                $sers .= '<c:ser><c:idx val="'.$si.'"/><c:order val="'.$si.'"/>'.$tx.$dPt.$dLbls.$catRef.$vals.'</c:ser>';
            } elseif ('line' === $type) {
                $sers .= '<c:ser><c:idx val="'.$si.'"/><c:order val="'.$si.'"/>'.$tx.'<c:spPr><a:ln w="38100" cap="rnd"><a:solidFill>'.self::clr($color, 1).'</a:solidFill><a:round/></a:ln></c:spPr>'
                    .'<c:marker><c:symbol val="circle"/><c:size val="7"/><c:spPr><a:solidFill>'.self::clr($color, 1).'</a:solidFill><a:ln><a:noFill/></a:ln></c:spPr></c:marker>'
                    .$dLbls.$catRef.$vals.'<c:smooth val="0"/></c:ser>';
            } elseif ('area' === $type) {
                $sers .= '<c:ser><c:idx val="'.$si.'"/><c:order val="'.$si.'"/>'.$tx.'<c:spPr><a:solidFill>'.self::clr($color, 0.85).'</a:solidFill></c:spPr>'.$dLbls.$catRef.$vals.'</c:ser>';
            } else {
                $sers .= '<c:ser><c:idx val="'.$si.'"/><c:order val="'.$si.'"/>'.$tx.'<c:spPr><a:solidFill>'.self::clr($color, 1).'</a:solidFill></c:spPr><c:invertIfNegative val="0"/>'.$dLbls.$catRef.$vals.'</c:ser>';
            }
        }
        $grid = (string) ($el['gridColor'] ?? '#D9D9D9');
        $axes = '';
        $plot = match ($type) {
            'pie' => '<c:pieChart><c:varyColors val="1"/>'.$sers.'<c:firstSliceAng val="0"/></c:pieChart>',
            'doughnut' => '<c:doughnutChart><c:varyColors val="1"/>'.$sers.'<c:firstSliceAng val="0"/><c:holeSize val="55"/></c:doughnutChart>',
            'line' => '<c:lineChart><c:grouping val="standard"/><c:varyColors val="0"/>'.$sers.'<c:marker val="1"/><c:axId val="111"/><c:axId val="222"/></c:lineChart>',
            'area' => '<c:areaChart><c:grouping val="'.($stacked ? 'stacked' : 'standard').'"/><c:varyColors val="0"/>'.$sers.'<c:axId val="111"/><c:axId val="222"/></c:areaChart>',
            default => '<c:barChart><c:barDir val="'.('bar' === $type ? 'bar' : 'col').'"/><c:grouping val="'.($stacked ? 'stacked' : 'clustered').'"/><c:varyColors val="0"/>'.$sers
                .'<c:gapWidth val="80"/>'.($stacked ? '<c:overlap val="100"/>' : '<c:overlap val="-10"/>').'<c:axId val="111"/><c:axId val="222"/></c:barChart>',
        };
        if (!$pie) {
            $horizontal = 'bar' === $type;
            $axes = '<c:catAx><c:axId val="111"/><c:scaling><c:orientation val="'.($horizontal ? 'maxMin' : 'minMax').'"/></c:scaling><c:delete val="0"/><c:axPos val="'.($horizontal ? 'l' : 'b').'"/>'
                .'<c:numFmt formatCode="General" sourceLinked="0"/><c:majorTickMark val="none"/><c:minorTickMark val="none"/><c:tickLblPos val="nextTo"/>'
                .'<c:spPr><a:ln w="9525"><a:solidFill>'.self::clr($grid, 1).'</a:solidFill></a:ln></c:spPr>'.$txPr
                .'<c:crossAx val="222"/><c:crosses val="autoZero"/><c:auto val="1"/><c:lblAlgn val="ctr"/><c:lblOffset val="100"/><c:noMultiLvlLbl val="0"/></c:catAx>'
                .'<c:valAx><c:axId val="222"/><c:scaling><c:orientation val="minMax"/></c:scaling><c:delete val="0"/><c:axPos val="'.($horizontal ? 'b' : 'l').'"/>'
                .'<c:majorGridlines><c:spPr><a:ln w="9525"><a:solidFill>'.self::clr($grid, 1).'</a:solidFill></a:ln></c:spPr></c:majorGridlines>'
                .'<c:numFmt formatCode="General" sourceLinked="1"/><c:majorTickMark val="none"/><c:minorTickMark val="none"/><c:tickLblPos val="nextTo"/>'
                .'<c:spPr><a:ln><a:noFill/></a:ln></c:spPr>'.$txPr.'<c:crossAx val="111"/><c:crosses val="'.($horizontal ? 'max' : 'autoZero').'"/><c:crossBetween val="between"/></c:valAx>';
        }
        $title = (string) ($el['title'] ?? '');
        $titleXml = '' !== $title
            ? '<c:title><c:tx><c:rich><a:bodyPr/><a:lstStyle/><a:p><a:pPr><a:defRPr sz="'.self::sz((float) ($el['fontSize'] ?? 12) * 1.3).'" b="0"><a:solidFill>'.self::clr(SlideLayouts::BLUE, 1).'</a:solidFill><a:latin typeface="Roboto"/></a:defRPr></a:pPr><a:r><a:rPr lang="ru-RU" sz="'.self::sz((float) ($el['fontSize'] ?? 12) * 1.3).'" b="0"><a:solidFill>'.self::clr(SlideLayouts::BLUE, 1).'</a:solidFill><a:latin typeface="Roboto"/></a:rPr><a:t>'.self::x($title).'</a:t></a:r></a:p></c:rich></c:tx><c:overlay val="0"/></c:title><c:autoTitleDeleted val="0"/>'
            : '<c:autoTitleDeleted val="1"/>';
        $legend = (string) ($el['legend'] ?? 'bottom');
        $legendXml = 'none' === $legend ? '' : '<c:legend><c:legendPos val="'.['top' => 't', 'bottom' => 'b', 'right' => 'r'][$legend].'"/><c:overlay val="0"/>'.$txPr.'</c:legend>';

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<c:chartSpace xmlns:c="http://schemas.openxmlformats.org/drawingml/2006/chart" xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<c:date1904 val="0"/><c:lang val="ru-RU"/><c:roundedCorners val="0"/>'
            .'<c:chart>'.$titleXml.'<c:plotArea><c:layout/>'.$plot.$axes.'<c:spPr><a:noFill/><a:ln><a:noFill/></a:ln></c:spPr></c:plotArea>'.$legendXml
            .'<c:plotVisOnly val="1"/><c:dispBlanksAs val="gap"/></c:chart>'
            .'<c:spPr><a:noFill/><a:ln><a:noFill/></a:ln></c:spPr>'.$txPr
            .'<c:externalData r:id="rId1"><c:autoUpdate val="0"/></c:externalData>'
            .'</c:chartSpace>';
    }

    /** Данные диаграммы в книге Excel — их можно поправить в PowerPoint («Изменить данные»). */
    private function chartWorkbook(array $el): string
    {
        $xlsx = new XlsxWriter();
        $sheet = $xlsx->addSheet('Sheet1');
        foreach ($el['series'] ?? [] as $si => $s) {
            $sheet->set(1, $si + 2, (string) $s['name']);
        }
        foreach ($el['categories'] ?? [] as $i => $c) {
            $sheet->set($i + 2, 1, (string) $c);
            foreach ($el['series'] ?? [] as $si => $s) {
                $v = $s['values'][$i] ?? null;
                if (null !== $v) {
                    $sheet->set($i + 2, $si + 2, (float) $v);
                }
            }
        }
        $tmp = tempnam(sys_get_temp_dir(), 'dp-xlsx-');
        if (false === $tmp) {
            return '';
        }
        try {
            $xlsx->save($tmp);

            return (string) file_get_contents($tmp);
        } finally {
            @unlink($tmp);
        }
    }

    // ---- Заметки и свойства -----------------------------------------------------------------------

    private function notesXml(string $notes): string
    {
        $paras = '';
        foreach (preg_split('/\r\n|\r|\n/', $notes) ?: [] as $line) {
            $paras .= '<a:p>'.('' !== $line ? '<a:r><a:rPr lang="ru-RU" dirty="0"/><a:t>'.self::x($line).'</a:t></a:r>' : '').'<a:endParaRPr lang="ru-RU" dirty="0"/></a:p>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<p:notes '.self::NS.'><p:cSld><p:spTree><p:nvGrpSpPr><p:cNvPr id="1" name=""/><p:cNvGrpSpPr/><p:nvPr/></p:nvGrpSpPr><p:grpSpPr/>'
            .'<p:sp><p:nvSpPr><p:cNvPr id="2" name="Образ слайда 1"/><p:cNvSpPr><a:spLocks noGrp="1" noRot="1" noChangeAspect="1"/></p:cNvSpPr><p:nvPr><p:ph type="sldImg"/></p:nvPr></p:nvSpPr><p:spPr/></p:sp>'
            .'<p:sp><p:nvSpPr><p:cNvPr id="3" name="Заметки 2"/><p:cNvSpPr><a:spLocks noGrp="1"/></p:cNvSpPr><p:nvPr><p:ph type="body" idx="1"/></p:nvPr></p:nvSpPr><p:spPr/><p:txBody><a:bodyPr/><a:lstStyle/>'.$paras.'</p:txBody></p:sp>'
            .'</p:spTree></p:cSld><p:clrMapOvr><a:masterClrMapping/></p:clrMapOvr></p:notes>';
    }

    /** @param list<array{type: string, target: string, external?: bool}> $rels */
    private function relsXml(array $rels): string
    {
        $out = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
        foreach ($rels as $i => $r) {
            $type = 'package' === $r['type'] ? 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/package' : self::REL.$r['type'];
            $out .= '<Relationship Id="rId'.($i + 1).'" Type="'.$type.'" Target="'.self::x($r['target']).'"'.(($r['external'] ?? false) ? ' TargetMode="External"' : '').'/>';
        }

        return $out.'</Relationships>';
    }

    private function coreXml(string $title, string $author): string
    {
        $now = gmdate('Y-m-d\TH:i:s\Z');

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:dcmitype="http://purl.org/dc/dcmitype/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
            .'<dc:title>'.self::x($title).'</dc:title><dc:creator>'.self::x($author).'</dc:creator><cp:lastModifiedBy>'.self::x($author).'</cp:lastModifiedBy>'
            .'<dcterms:created xsi:type="dcterms:W3CDTF">'.$now.'</dcterms:created><dcterms:modified xsi:type="dcterms:W3CDTF">'.$now.'</dcterms:modified>'
            .'</cp:coreProperties>';
    }

    private function appXml(int $slides, int $notes, int $hidden, string $company): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties" xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes">'
            .'<Application>Microsoft Office PowerPoint</Application><PresentationFormat>Широкоэкранный</PresentationFormat>'
            .'<Slides>'.$slides.'</Slides><Notes>'.$notes.'</Notes><HiddenSlides>'.$hidden.'</HiddenSlides><MMClips>0</MMClips><ScaleCrop>false</ScaleCrop>'
            .'<Company>'.self::x($company).'</Company><LinksUpToDate>false</LinksUpToDate><SharedDoc>false</SharedDoc><HyperlinksChanged>false</HyperlinksChanged><AppVersion>16.0000</AppVersion></Properties>';
    }

    // ---- Помощники ------------------------------------------------------------------------------

    /** @param array<string, mixed> $el */
    private static function xfrm(array $el): string
    {
        $rot = (float) ($el['rotation'] ?? 0);
        $attrs = '';
        if (0.0 !== $rot) {
            $attrs .= ' rot="'.(int) round(fmod($rot + 360, 360) * 60000).'"';
        }
        if ($el['flipH'] ?? false) {
            $attrs .= ' flipH="1"';
        }
        if ($el['flipV'] ?? false) {
            $attrs .= ' flipV="1"';
        }

        return '<a:xfrm'.$attrs.'><a:off x="'.self::emu((float) $el['x']).'" y="'.self::emu((float) $el['y']).'"/><a:ext cx="'.self::emu(max(1, (float) $el['w'])).'" cy="'.self::emu(max(1, (float) $el['h'])).'"/></a:xfrm>';
    }

    private static function ln(string $color, float $width, string $dash, float $opacity, string $start = 'none', string $end = 'none'): string
    {
        $dashXml = match ($dash) {
            'dash' => '<a:prstDash val="dash"/>',
            'dot' => '<a:prstDash val="sysDot"/>',
            default => '<a:prstDash val="solid"/>',
        };
        $arrow = static fn (string $kind, string $tag): string => 'none' === $kind ? '' : '<a:'.$tag.' type="'.['arrow' => 'arrow', 'triangle' => 'triangle', 'oval' => 'oval', 'diamond' => 'diamond'][$kind].'" w="med" len="med"/>';

        return '<a:ln w="'.(int) round($width * self::EMU).'"><a:solidFill>'.self::clr($color, $opacity).'</a:solidFill>'.$dashXml.'<a:round/>'.$arrow($start, 'headEnd').$arrow($end, 'tailEnd').'</a:ln>';
    }

    private static function shadow(mixed $s, float $opacity): string
    {
        if (!\is_array($s)) {
            return '';
        }
        $x = (float) ($s['x'] ?? 0);
        $y = (float) ($s['y'] ?? 4);
        $dist = (int) round(sqrt($x * $x + $y * $y) * self::EMU);
        $dir = (int) round(fmod(rad2deg(atan2($y, $x)) + 360, 360) * 60000);

        return '<a:effectLst><a:outerShdw blurRad="'.(int) round((float) ($s['blur'] ?? 12) * self::EMU).'" dist="'.$dist.'" dir="'.$dir.'" algn="ctr" rotWithShape="0">'.self::clr((string) ($s['color'] ?? '#00000040'), $opacity).'</a:outerShdw></a:effectLst>';
    }

    private static function solid(string $color): string
    {
        return '<a:solidFill>'.self::clr($color, 1).'</a:solidFill>';
    }

    /** Цвет #RRGGBB[AA] с учётом непрозрачности элемента. */
    private static function clr(string $color, float $opacity): string
    {
        $c = strtoupper(ltrim($color, '#'));
        if (1 !== preg_match('/^[0-9A-F]{6}([0-9A-F]{2})?$/', $c)) {
            $c = '000000';
        }
        $alpha = (8 === \strlen($c) ? hexdec(substr($c, 6, 2)) / 255 : 1.0) * max(0.0, min(1.0, $opacity));
        $val = substr($c, 0, 6);

        return $alpha < 0.999 ? '<a:srgbClr val="'.$val.'"><a:alpha val="'.(int) round($alpha * 100000).'"/></a:srgbClr>' : '<a:srgbClr val="'.$val.'"/>';
    }

    private static function emu(float $px): int
    {
        return (int) round($px * self::EMU);
    }

    private static function sz(float $pt): int
    {
        return (int) max(100, min(400000, round($pt * 100)));
    }

    private static function number(float $v): string
    {
        return rtrim(rtrim(\sprintf('%.6F', $v), '0'), '.');
    }

    /** Текст для XML: без недопустимых символов, с экранированием. */
    private static function x(string $s): string
    {
        $s = preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $s) ?? '';

        return htmlspecialchars($s, \ENT_QUOTES | \ENT_XML1, 'UTF-8');
    }
}
