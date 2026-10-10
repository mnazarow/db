<?php

declare(strict_types=1);

namespace App\Service\Xlsx;

/**
 * Лист книги Excel для XlsxWriter: ячейки, ширины столбцов, закреплённая строка, объединения,
 * списки проверки данных, условное форматирование, гиперссылки и кольцевые диаграммы.
 */
final class XlsxSheet
{
    /** @var array<int, array<int, array{value: mixed, style: int, formula: ?string}>> строка => столбец => ячейка (с 1) */
    private array $cells = [];

    /** @var array<int, float> столбец (с 1) => ширина в символах */
    private array $widths = [];

    private bool $freezeTopRow = false;

    /** @var list<string> */
    private array $merges = [];

    /** @var list<array{range: string, options: list<string>}> */
    private array $validations = [];

    /** @var list<array{range: string, formula: string, dxf: int}> */
    private array $conditions = [];

    /** @var list<array{cell: string, url: string}> */
    private array $links = [];

    private ?string $autoFilter = null;

    /** @var list<array{row: int, col: int, title: string, categories: string, values: string, labels: list<string>, numbers: list<int|float>, colors: list<string>, width: int, height: int}> */
    private array $charts = [];

    /** visible, hidden или veryHidden (скрыт так, что показать его можно только из редактора VBA). */
    private string $state = 'visible';

    public function __construct(private readonly string $name)
    {
    }

    public function getName(): string
    {
        return $this->name;
    }

    /** Скрывает лист (служебные данные, которые не нужно показывать в Excel). */
    public function hide(bool $veryHidden = false): self
    {
        $this->state = $veryHidden ? 'veryHidden' : 'hidden';

        return $this;
    }

    public function getState(): string
    {
        return $this->state;
    }

    /**
     * @param mixed       $value   строка, число, \DateTimeInterface или null
     * @param string|null $formula формула без знака «=» (значение — закэшированный результат)
     */
    public function set(int $row, int $col, mixed $value, int $style = XlsxWriter::STYLE_DEFAULT, ?string $formula = null): self
    {
        $this->cells[$row][$col] = ['value' => $value, 'style' => $style, 'formula' => $formula];

        return $this;
    }

    public function width(int $col, float $width): self
    {
        $this->widths[$col] = $width;

        return $this;
    }

    public function freezeTopRow(): self
    {
        $this->freezeTopRow = true;

        return $this;
    }

    public function merge(string $range): self
    {
        $this->merges[] = $range;

        return $this;
    }

    /** @param list<string> $options */
    public function listValidation(string $range, array $options): self
    {
        if ([] !== $options) {
            $this->validations[] = ['range' => $range, 'options' => $options];
        }

        return $this;
    }

    public function condition(string $range, string $formula, int $dxf): self
    {
        $this->conditions[] = ['range' => $range, 'formula' => $formula, 'dxf' => $dxf];

        return $this;
    }

    public function link(int $row, int $col, string $url): self
    {
        if (1 === preg_match('~^https?://~i', $url)) {
            $this->links[] = ['cell' => XlsxWriter::cellName($row, $col), 'url' => $url];
        }

        return $this;
    }

    public function autoFilter(string $range): self
    {
        $this->autoFilter = $range;

        return $this;
    }

    /**
     * Кольцевая диаграмма по диапазонам листа (как на листе «Сводная по категориям»).
     *
     * @param int                 $row        левый верхний угол — строка (с 1)
     * @param int                 $col        левый верхний угол — столбец (с 1)
     * @param string              $categories диапазон подписей, например 'Сводная'!$A$4:$A$7
     * @param string              $values     диапазон значений
     * @param list<string>        $labels     подписи (кэш для просмотра без пересчёта)
     * @param list<int|float>     $numbers    значения (кэш)
     * @param list<string>        $colors     цвета секторов RRGGBB
     * @param int                 $width      ширина, EMU (914400 на дюйм)
     * @param int                 $height     высота, EMU
     */
    public function doughnut(int $row, int $col, string $title, string $categories, string $values, array $labels, array $numbers, array $colors, int $width = 3829050, int $height = 1781175): self
    {
        $this->charts[] = [
            'row' => $row, 'col' => $col, 'title' => $title, 'categories' => $categories, 'values' => $values,
            'labels' => array_values($labels), 'numbers' => array_values($numbers), 'colors' => array_values($colors),
            'width' => $width, 'height' => $height,
        ];

        return $this;
    }

    /** @return list<array{row: int, col: int, title: string, categories: string, values: string, labels: list<string>, numbers: list<int|float>, colors: list<string>, width: int, height: int}> */
    public function getCharts(): array
    {
        return $this->charts;
    }

    /**
     * XML листа; $rels заполняется связями листа (гиперссылки, рисунок с диаграммами).
     *
     * @param array<string, array{type: string, target: string}> $rels
     * @param string|null                                         $drawing путь к рисунку листа относительно листа
     */
    public function toXml(array &$rels, ?string $drawing = null): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'."\n"
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">';
        if ($this->freezeTopRow) {
            $xml .= '<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/><selection pane="bottomLeft" activeCell="A2" sqref="A2"/></sheetView></sheetViews>';
        } else {
            $xml .= '<sheetViews><sheetView workbookViewId="0"/></sheetViews>';
        }
        $xml .= '<sheetFormatPr defaultRowHeight="15.75"/>';
        if ([] !== $this->widths) {
            ksort($this->widths);
            $xml .= '<cols>';
            foreach ($this->widths as $col => $width) {
                $xml .= \sprintf('<col min="%d" max="%d" width="%.2F" customWidth="1"/>', $col, $col, $width);
            }
            $xml .= '</cols>';
        }
        $xml .= '<sheetData>';
        ksort($this->cells);
        foreach ($this->cells as $row => $cols) {
            ksort($cols);
            $xml .= '<row r="'.$row.'">';
            foreach ($cols as $col => $cell) {
                $xml .= $this->cellXml($row, $col, $cell);
            }
            $xml .= '</row>';
        }
        $xml .= '</sheetData>';
        if (null !== $this->autoFilter) {
            $xml .= '<autoFilter ref="'.XlsxWriter::escape($this->autoFilter).'"/>';
        }
        if ([] !== $this->merges) {
            $xml .= '<mergeCells count="'.\count($this->merges).'">';
            foreach ($this->merges as $range) {
                $xml .= '<mergeCell ref="'.XlsxWriter::escape($range).'"/>';
            }
            $xml .= '</mergeCells>';
        }
        $priority = 1;
        foreach ($this->conditions as $condition) {
            $xml .= \sprintf('<conditionalFormatting sqref="%s"><cfRule type="expression" dxfId="%d" priority="%d"><formula>%s</formula></cfRule></conditionalFormatting>',
                XlsxWriter::escape($condition['range']), $condition['dxf'], $priority++, XlsxWriter::escape($condition['formula']));
        }
        $validations = array_filter($this->validations, static fn (array $v): bool => mb_strlen(implode(',', $v['options'])) <= 250);
        if ([] !== $validations) {
            $xml .= '<dataValidations count="'.\count($validations).'">';
            foreach ($validations as $validation) {
                $list = implode(',', array_map(static fn (string $o): string => str_replace([',', '"'], [' ', "'"], $o), $validation['options']));
                $xml .= '<dataValidation type="list" allowBlank="1" showErrorMessage="1" sqref="'.XlsxWriter::escape($validation['range']).'"><formula1>'.XlsxWriter::escape('"'.$list.'"').'</formula1></dataValidation>';
            }
            $xml .= '</dataValidations>';
        }
        if ([] !== $this->links) {
            $xml .= '<hyperlinks>';
            foreach ($this->links as $link) {
                $id = 'rId'.(\count($rels) + 1);
                $rels[$id] = ['type' => 'hyperlink', 'target' => $link['url']];
                $xml .= '<hyperlink ref="'.$link['cell'].'" r:id="'.$id.'"/>';
            }
            $xml .= '</hyperlinks>';
        }
        $xml .= '<pageMargins left="0.5" right="0.5" top="0.75" bottom="0.75" header="0.3" footer="0.3"/>';
        if (null !== $drawing) {
            $id = 'rId'.(\count($rels) + 1);
            $rels[$id] = ['type' => 'drawing', 'target' => $drawing];
            $xml .= '<drawing r:id="'.$id.'"/>';
        }

        return $xml.'</worksheet>';
    }

    /** @param array{value: mixed, style: int, formula: ?string} $cell */
    private function cellXml(int $row, int $col, array $cell): string
    {
        $ref = XlsxWriter::cellName($row, $col);
        $style = $cell['style'] > 0 ? ' s="'.$cell['style'].'"' : '';
        $value = $cell['value'];
        $formula = null !== $cell['formula'] ? '<f>'.XlsxWriter::escape($cell['formula']).'</f>' : '';
        if ($value instanceof \DateTimeInterface) {
            $serial = XlsxWriter::serial($value);
            if (null !== $serial) {
                return \sprintf('<c r="%s"%s>%s<v>%s</v></c>', $ref, $style, $formula, $serial);
            }
            // Excel не хранит даты раньше 1 марта 1900 года как даты — пишем текстом.
            $value = $value->format('d.m.Y');
        }
        if (\is_int($value) || (\is_float($value) && is_finite($value))) {
            return \sprintf('<c r="%s"%s>%s<v>%s</v></c>', $ref, $style, $formula, (string) $value);
        }
        if (\is_float($value)) {
            $value = (string) $value; // INF и NAN числом в Excel не записать
        }
        if (null === $value || '' === $value) {
            return '' !== $formula
                ? \sprintf('<c r="%s"%s t="str">%s<v></v></c>', $ref, $style, $formula)
                : \sprintf('<c r="%s"%s/>', $ref, $style);
        }
        if ('' !== $formula) {
            return \sprintf('<c r="%s"%s t="str">%s<v>%s</v></c>', $ref, $style, $formula, XlsxWriter::escape((string) $value));
        }

        return \sprintf('<c r="%s"%s t="inlineStr"><is><t xml:space="preserve">%s</t></is></c>', $ref, $style, XlsxWriter::escape((string) $value));
    }
}
