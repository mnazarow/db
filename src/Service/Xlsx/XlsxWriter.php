<?php

declare(strict_types=1);

namespace App\Service\Xlsx;

/**
 * Минимальная запись книг Excel (.xlsx) без внешних библиотек: несколько листов, строки, числа,
 * даты, формулы с закэшированными значениями, объединения, списки проверки данных, условное
 * форматирование, гиперссылки и кольцевые диаграммы. Набор стилей фиксирован — его хватает
 * для выгрузки таблиц материалов.
 */
final class XlsxWriter
{
    public const STYLE_DEFAULT = 0;
    public const STYLE_HEADER = 1;
    public const STYLE_DATE = 2;
    public const STYLE_READY = 3;
    public const STYLE_ACCEPTABLE = 4;
    public const STYLE_MISMATCH = 5;
    public const STYLE_ABSENT = 6;
    public const STYLE_UNKNOWN = 7;
    public const STYLE_TITLE = 8;
    public const STYLE_BLOCK = 9;
    public const STYLE_LINK = 10;
    public const STYLE_PROBLEM = 11;
    public const STYLE_WRAP = 12;
    public const STYLE_MUTED = 13;

    /** Условное форматирование (dxf): заливка и цвет шрифта состояний — как в исходной таблице. */
    public const DXF_READY = 0;
    public const DXF_ACCEPTABLE = 1;
    public const DXF_MISMATCH = 2;
    public const DXF_ABSENT = 3;
    public const DXF_UNKNOWN = 4;
    public const DXF_PROBLEM_COUNT = 5;

    /** @var list<XlsxSheet> */
    private array $sheets = [];

    public function addSheet(string $name): XlsxSheet
    {
        $sheet = new XlsxSheet($this->uniqueName($name));
        $this->sheets[] = $sheet;

        return $sheet;
    }

    /** Название листа по правилам Excel: до 31 символа, без []:*?/\ и уникальное. */
    public static function sheetName(string $name): string
    {
        $name = trim(str_replace(['[', ']', ':', '*', '?', '/', '\\'], ' ', $name));
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '', " '");

        return '' !== $name ? mb_substr($name, 0, 31) : 'Лист';
    }

    /** Ссылка на лист в формуле: 'Имя листа'!$C:$C. */
    public static function sheetRef(string $name): string
    {
        return "'".str_replace("'", "''", $name)."'";
    }

    public static function column(int $col): string
    {
        $name = '';
        while ($col > 0) {
            $mod = ($col - 1) % 26;
            $name = \chr(65 + $mod).$name;
            $col = intdiv($col - 1, 26);
        }

        return $name;
    }

    public static function cellName(int $row, int $col): string
    {
        return self::column($col).$row;
    }

    /** Дата → порядковый номер дня Excel (1900-система). */
    public static function serial(\DateTimeInterface $date): string
    {
        $base = new \DateTimeImmutable('1899-12-30', new \DateTimeZone('UTC'));
        $day = new \DateTimeImmutable($date->format('Y-m-d'), new \DateTimeZone('UTC'));

        return (string) (int) $base->diff($day)->format('%a');
    }

    public static function escape(string $value): string
    {
        // Управляющие символы недопустимы в XML 1.0.
        $value = preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $value) ?? '';

        return htmlspecialchars($value, \ENT_QUOTES | \ENT_XML1, 'UTF-8');
    }

    /**
     * @throws \RuntimeException если не удалось записать файл
     */
    public function save(string $path): void
    {
        if ([] === $this->sheets) {
            $this->addSheet('Лист1');
        }
        $zip = new \ZipArchive();
        if (true !== $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE)) {
            throw new \RuntimeException('Не удалось создать файл Excel: '.$path);
        }
        $count = \count($this->sheets);
        // Диаграммы: у листа с диаграммами — свой рисунок (drawingN.xml), диаграммы нумеруются по книге.
        $drawings = [];
        $charts = 0;
        foreach ($this->sheets as $index => $sheet) {
            if ([] !== $sheet->getCharts()) {
                $drawings[$index] = \count($drawings) + 1;
                $charts += \count($sheet->getCharts());
            }
        }
        $zip->addFromString('[Content_Types].xml', $this->contentTypes($count, \count($drawings), $charts));
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'."\n"
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            .'<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>'
            .'<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/>'
            .'</Relationships>');
        $zip->addFromString('docProps/core.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'."\n"
            .'<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
            .'<dc:creator>Портал документации</dc:creator><dcterms:created xsi:type="dcterms:W3CDTF">'.gmdate('Y-m-d\TH:i:s\Z').'</dcterms:created>'
            .'</cp:coreProperties>');
        $zip->addFromString('docProps/app.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'."\n"
            .'<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties"><Application>Портал документации</Application></Properties>');
        $zip->addFromString('xl/workbook.xml', $this->workbook());
        $zip->addFromString('xl/_rels/workbook.xml.rels', $this->workbookRels($count));
        $zip->addFromString('xl/styles.xml', self::styles());
        $chart = 0;
        foreach ($this->sheets as $index => $sheet) {
            $drawing = null;
            if (isset($drawings[$index])) {
                $number = $drawings[$index];
                $drawing = '../drawings/drawing'.$number.'.xml';
                $anchors = $drawingRels = '';
                foreach ($sheet->getCharts() as $i => $spec) {
                    ++$chart;
                    $rid = 'rId'.($i + 1);
                    $drawingRels .= '<Relationship Id="'.$rid.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/chart" Target="../charts/chart'.$chart.'.xml"/>';
                    $anchors .= self::chartAnchor($spec, $rid, $i + 2);
                    $zip->addFromString('xl/charts/chart'.$chart.'.xml', self::chartXml($spec));
                }
                $zip->addFromString('xl/drawings/drawing'.$number.'.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'."\n"
                    .'<xdr:wsDr xmlns:xdr="http://schemas.openxmlformats.org/drawingml/2006/spreadsheetDrawing" xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" xmlns:c="http://schemas.openxmlformats.org/drawingml/2006/chart">'
                    .$anchors.'</xdr:wsDr>');
                $zip->addFromString('xl/drawings/_rels/drawing'.$number.'.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'."\n"
                    .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'.$drawingRels.'</Relationships>');
            }
            $rels = [];
            $zip->addFromString('xl/worksheets/sheet'.($index + 1).'.xml', $sheet->toXml($rels, $drawing));
            if ([] !== $rels) {
                $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'."\n".'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
                foreach ($rels as $id => $rel) {
                    $xml .= 'hyperlink' === $rel['type']
                        ? '<Relationship Id="'.$id.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/hyperlink" Target="'.self::escape($rel['target']).'" TargetMode="External"/>'
                        : '<Relationship Id="'.$id.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/drawing" Target="'.self::escape($rel['target']).'"/>';
                }
                $zip->addFromString('xl/worksheets/_rels/sheet'.($index + 1).'.xml.rels', $xml.'</Relationships>');
            }
        }
        if (!$zip->close()) {
            throw new \RuntimeException('Не удалось записать файл Excel: '.$path);
        }
    }

    private function uniqueName(string $name): string
    {
        $base = self::sheetName($name);
        $taken = array_map(static fn (XlsxSheet $s): string => mb_strtolower($s->getName()), $this->sheets);
        $candidate = $base;
        for ($i = 2; \in_array(mb_strtolower($candidate), $taken, true); ++$i) {
            $suffix = ' ('.$i.')';
            $candidate = mb_substr($base, 0, 31 - mb_strlen($suffix)).$suffix;
        }

        return $candidate;
    }

    /**
     * Привязка диаграммы к ячейке (левый верхний угол) с заданным размером.
     *
     * @param array{row: int, col: int, width: int, height: int} $spec
     */
    private static function chartAnchor(array $spec, string $rid, int $id): string
    {
        return '<xdr:oneCellAnchor>'
            .'<xdr:from><xdr:col>'.($spec['col'] - 1).'</xdr:col><xdr:colOff>0</xdr:colOff><xdr:row>'.($spec['row'] - 1).'</xdr:row><xdr:rowOff>0</xdr:rowOff></xdr:from>'
            .'<xdr:ext cx="'.$spec['width'].'" cy="'.$spec['height'].'"/>'
            .'<xdr:graphicFrame macro=""><xdr:nvGraphicFramePr><xdr:cNvPr id="'.$id.'" name="Диаграмма '.($id - 1).'"/><xdr:cNvGraphicFramePr/></xdr:nvGraphicFramePr>'
            .'<xdr:xfrm><a:off x="0" y="0"/><a:ext cx="0" cy="0"/></xdr:xfrm>'
            .'<a:graphic><a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/chart"><c:chart r:id="'.$rid.'"/></a:graphicData></a:graphic>'
            .'</xdr:graphicFrame><xdr:clientData/></xdr:oneCellAnchor>';
    }

    /**
     * Кольцевая диаграмма: заголовок (если задан), секторы своих цветов, подписи-проценты, легенда справа.
     * Значения и подписи кэшируются, чтобы диаграмма была видна и без пересчёта формул.
     *
     * @param array{title: string, categories: string, values: string, labels: list<string>, numbers: list<int|float>, colors: list<string>} $spec
     */
    private static function chartXml(array $spec): string
    {
        $points = '';
        foreach ($spec['colors'] as $i => $color) {
            $points .= '<c:dPt><c:idx val="'.$i.'"/><c:bubble3D val="0"/><c:spPr><a:solidFill><a:srgbClr val="'.$color.'"/></a:solidFill>'
                .'<a:ln w="12700"><a:solidFill><a:srgbClr val="FFFFFF"/></a:solidFill></a:ln></c:spPr></c:dPt>';
        }
        $labels = '<c:ptCount val="'.\count($spec['labels']).'"/>';
        foreach ($spec['labels'] as $i => $label) {
            $labels .= '<c:pt idx="'.$i.'"><c:v>'.self::escape($label).'</c:v></c:pt>';
        }
        $numbers = '<c:formatCode>General</c:formatCode><c:ptCount val="'.\count($spec['numbers']).'"/>';
        foreach ($spec['numbers'] as $i => $number) {
            $numbers .= '<c:pt idx="'.$i.'"><c:v>'.$number.'</c:v></c:pt>';
        }
        $text = static fn (int $size, bool $bold, string $color): string => '<a:defRPr sz="'.$size.'" b="'.($bold ? 1 : 0).'"><a:solidFill><a:srgbClr val="'.$color.'"/></a:solidFill><a:latin typeface="Arial"/></a:defRPr>';

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'."\n"
            .'<c:chartSpace xmlns:c="http://schemas.openxmlformats.org/drawingml/2006/chart" xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<c:roundedCorners val="0"/>'
            .'<c:chart>'
            .('' !== $spec['title']
                ? '<c:title><c:tx><c:rich><a:bodyPr/><a:lstStyle/><a:p><a:pPr>'.$text(1200, true, '0F4382').'</a:pPr><a:r><a:rPr lang="ru-RU" sz="1200" b="1"><a:solidFill><a:srgbClr val="0F4382"/></a:solidFill><a:latin typeface="Arial"/></a:rPr><a:t>'.self::escape($spec['title']).'</a:t></a:r></a:p></c:rich></c:tx><c:overlay val="0"/></c:title><c:autoTitleDeleted val="0"/>'
                : '<c:autoTitleDeleted val="1"/>')
            .'<c:plotArea><c:layout/><c:doughnutChart><c:varyColors val="1"/>'
            .'<c:ser><c:idx val="0"/><c:order val="0"/><c:tx><c:v>Общее состояние</c:v></c:tx>'.$points
            .'<c:dLbls><c:numFmt formatCode="0%" sourceLinked="0"/><c:spPr><a:noFill/><a:ln><a:noFill/></a:ln></c:spPr><c:txPr><a:bodyPr/><a:lstStyle/><a:p><a:pPr>'.$text(900, true, '1D1E1E').'</a:pPr><a:endParaRPr lang="ru-RU"/></a:p></c:txPr>'
            .'<c:showLegendKey val="0"/><c:showVal val="0"/><c:showCatName val="0"/><c:showSerName val="0"/><c:showPercent val="1"/><c:showBubbleSize val="0"/><c:showLeaderLines val="1"/></c:dLbls>'
            .'<c:cat><c:strRef><c:f>'.self::escape($spec['categories']).'</c:f><c:strCache>'.$labels.'</c:strCache></c:strRef></c:cat>'
            .'<c:val><c:numRef><c:f>'.self::escape($spec['values']).'</c:f><c:numCache>'.$numbers.'</c:numCache></c:numRef></c:val>'
            .'</c:ser>'
            .'<c:dLbls><c:showLegendKey val="0"/><c:showVal val="0"/><c:showCatName val="0"/><c:showSerName val="0"/><c:showPercent val="1"/><c:showBubbleSize val="0"/><c:showLeaderLines val="1"/></c:dLbls>'
            .'<c:firstSliceAng val="0"/><c:holeSize val="50"/></c:doughnutChart>'
            .'<c:spPr><a:noFill/><a:ln><a:noFill/></a:ln></c:spPr></c:plotArea>'
            .'<c:legend><c:legendPos val="r"/><c:overlay val="0"/><c:txPr><a:bodyPr/><a:lstStyle/><a:p><a:pPr>'.$text(900, false, '1D1E1E').'</a:pPr><a:endParaRPr lang="ru-RU"/></a:p></c:txPr></c:legend>'
            .'<c:plotVisOnly val="1"/><c:dispBlanksAs val="gap"/>'
            .'</c:chart>'
            .'<c:spPr><a:solidFill><a:srgbClr val="FFFFFF"/></a:solidFill><a:ln><a:noFill/></a:ln></c:spPr>'
            .'</c:chartSpace>';
    }

    private function contentTypes(int $count, int $drawings = 0, int $charts = 0): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'."\n"
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            .'<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            .'<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>'
            .'<Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>';
        for ($i = 1; $i <= $count; ++$i) {
            $xml .= '<Override PartName="/xl/worksheets/sheet'.$i.'.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        }
        for ($i = 1; $i <= $drawings; ++$i) {
            $xml .= '<Override PartName="/xl/drawings/drawing'.$i.'.xml" ContentType="application/vnd.openxmlformats-officedocument.drawing+xml"/>';
        }
        for ($i = 1; $i <= $charts; ++$i) {
            $xml .= '<Override PartName="/xl/charts/chart'.$i.'.xml" ContentType="application/vnd.openxmlformats-officedocument.drawingml.chart+xml"/>';
        }

        return $xml.'</Types>';
    }

    private function workbook(): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'."\n"
            .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<bookViews><workbookView activeTab="0"/></bookViews><sheets>';
        foreach ($this->sheets as $index => $sheet) {
            $xml .= \sprintf('<sheet name="%s" sheetId="%d" r:id="rId%d"/>', self::escape($sheet->getName()), $index + 1, $index + 1);
        }

        // Формулы пересчитываются при открытии: закэшированные значения нужны только для просмотрщиков.
        return $xml.'</sheets><calcPr calcId="191029" fullCalcOnLoad="1"/></workbook>';
    }

    private function workbookRels(int $count): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'."\n".'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
        for ($i = 1; $i <= $count; ++$i) {
            $xml .= '<Relationship Id="rId'.$i.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet'.$i.'.xml"/>';
        }
        $xml .= '<Relationship Id="rId'.($count + 1).'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';

        return $xml.'</Relationships>';
    }

    private static function styles(): string
    {
        // Шрифты: 0 обычный, 1 жирный, 2 тёмно-зелёный, 3 тёмно-красный, 4 заголовок, 5 белый жирный, 6 ссылка, 7 жирный красный, 8 серый.
        $fonts = [
            '<font><sz val="10"/><name val="Arial"/><family val="2"/></font>',
            '<font><b/><sz val="10"/><name val="Arial"/><family val="2"/></font>',
            '<font><sz val="10"/><color rgb="FF11734B"/><name val="Arial"/><family val="2"/></font>',
            '<font><sz val="10"/><color rgb="FFB80222"/><name val="Arial"/><family val="2"/></font>',
            '<font><b/><sz val="13"/><color rgb="FF0F4382"/><name val="Arial"/><family val="2"/></font>',
            '<font><b/><sz val="10"/><color rgb="FFFFFFFF"/><name val="Arial"/><family val="2"/></font>',
            '<font><u/><sz val="10"/><color rgb="FF1155CC"/><name val="Arial"/><family val="2"/></font>',
            '<font><b/><sz val="10"/><color rgb="FFB80222"/><name val="Arial"/><family val="2"/></font>',
            '<font><sz val="10"/><color rgb="FF6E7480"/><name val="Arial"/><family val="2"/></font>',
        ];
        // Заливки: 0 и 1 обязательные, 2 серая шапка, 3 зелёная, 4 жёлтая, 5 красная, 6 тёмно-серая, 7 светло-серая, 8 синяя.
        $fill = static fn (string $rgb): string => '<fill><patternFill patternType="solid"><fgColor rgb="FF'.$rgb.'"/><bgColor indexed="64"/></patternFill></fill>';
        $fills = [
            '<fill><patternFill patternType="none"/></fill>',
            '<fill><patternFill patternType="gray125"/></fill>',
            $fill('E5E5E5'), $fill('D4EDBC'), $fill('FFD966'), $fill('FFCFC9'), $fill('999999'), $fill('E8EAED'), $fill('0F4382'),
        ];
        $border = '<border><left/><right/><top/><bottom/><diagonal/></border>';
        // cellXfs: numFmtId, fontId, fillId, выравнивание.
        $xf = static fn (int $numFmt, int $font, int $fill, bool $wrap = false, bool $center = false): string => \sprintf(
            '<xf numFmtId="%d" fontId="%d" fillId="%d" borderId="0" xfId="0"%s%s%s%s>%s</xf>',
            $numFmt, $font, $fill,
            $numFmt > 0 ? ' applyNumberFormat="1"' : '', $font > 0 ? ' applyFont="1"' : '', $fill > 0 ? ' applyFill="1"' : '',
            $wrap || $center ? ' applyAlignment="1"' : '',
            $wrap || $center ? '<alignment vertical="center"'.($wrap ? ' wrapText="1"' : '').($center ? ' horizontal="center"' : '').'/>' : '',
        );
        $xfs = [
            $xf(0, 0, 0),          // 0 обычная
            $xf(0, 1, 2, true),    // 1 шапка
            $xf(164, 0, 0),        // 2 дата
            $xf(0, 2, 3),          // 3 Готово
            $xf(0, 0, 4),          // 4 Допустимо
            $xf(0, 3, 5),          // 5 Не соответствует
            $xf(0, 0, 6),          // 6 Отсутствует
            $xf(0, 0, 7),          // 7 Неизвестно
            $xf(0, 4, 0),          // 8 заголовок
            $xf(0, 5, 8, false, true), // 9 шапка блока сводной
            $xf(0, 6, 0),          // 10 ссылка
            $xf(0, 7, 0, false, true), // 11 проблемное число
            $xf(0, 0, 0, true),    // 12 перенос строк
            $xf(0, 8, 0),          // 13 серый текст
        ];
        $dxf = static fn (string $fillRgb, ?string $fontRgb = null): string => '<dxf>'.(null !== $fontRgb ? '<font><color rgb="FF'.$fontRgb.'"/></font>' : '')
            .'<fill><patternFill patternType="solid"><fgColor rgb="FF'.$fillRgb.'"/><bgColor rgb="FF'.$fillRgb.'"/></patternFill></fill></dxf>';
        $dxfs = [
            $dxf('D4EDBC', '11734B'), $dxf('FFD966'), $dxf('FFCFC9', 'B80222'), $dxf('999999'), $dxf('E8EAED'),
            '<dxf><font><b/><color rgb="FFB80222"/></font></dxf>',
        ];

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'."\n"
            .'<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<numFmts count="1"><numFmt numFmtId="164" formatCode="dd.mm.yyyy"/></numFmts>'
            .'<fonts count="'.\count($fonts).'">'.implode('', $fonts).'</fonts>'
            .'<fills count="'.\count($fills).'">'.implode('', $fills).'</fills>'
            .'<borders count="1">'.$border.'</borders>'
            .'<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            .'<cellXfs count="'.\count($xfs).'">'.implode('', $xfs).'</cellXfs>'
            .'<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            .'<dxfs count="'.\count($dxfs).'">'.implode('', $dxfs).'</dxfs>'
            .'</styleSheet>';
    }
}
