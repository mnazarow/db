<?php

declare(strict_types=1);

namespace App\Service\Material;

use App\Entity\MaterialParameter;
use App\Entity\Section;
use App\Service\DocumentKindService;
use App\Service\Xlsx\XlsxSheet;
use App\Service\Xlsx\XlsxWriter;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Выгрузка таблиц материалов в Excel в том же виде, что исходная книга «Продуктовые материалы»:
 * лист «Сводная по категориям» с кольцевыми диаграммами и формулами СЧЁТЕСЛИ и по листу на раздел —
 * «Материал | Тип | Состояние | параметры», состояние — формулой, списки — проверкой данных,
 * цвета — условным форматированием. Такой файл можно поправить в Excel и загрузить обратно.
 */
final class MaterialExporter
{
    public const SUMMARY_SHEET = 'Сводная по категориям';
    public const DOCUMENT_HEADER = 'Документ портала';

    /** Сколько категорий в одном ряду сводной (как в исходном файле). */
    private const PER_ROW = 4;

    /** Строк под кольцевую диаграмму над таблицами блока. */
    private const CHART_ROWS = 9;

    /** Цвета секторов диаграммы «Общее состояние» — как в исходной книге. */
    private const CHART_COLORS = [
        MaterialState::READY => 'B6D7A8',
        MaterialState::ACCEPTABLE => 'FFE599',
        MaterialState::MISMATCH => 'EA9999',
        MaterialState::ABSENT => '999999',
    ];

    public function __construct(
        private readonly MaterialService $materials,
        private readonly MaterialSummary $summary,
        private readonly DocumentKindService $kinds,
        private readonly UrlGeneratorInterface $urls,
    ) {
    }

    /**
     * @param list<Section> $sections
     */
    public function build(array $sections, string $path, bool $withSummary = true): void
    {
        $writer = new XlsxWriter();
        $summarySheet = $withSummary ? $writer->addSheet(self::SUMMARY_SHEET) : null;
        $sheetNames = [];
        foreach ($sections as $section) {
            $sheet = $writer->addSheet($section->getName());
            $sheetNames[(int) $section->getId()] = $sheet->getName();
            $this->category($sheet, $section);
        }
        if (null !== $summarySheet) {
            $this->summarySheet($summarySheet, $sections, $sheetNames);
        }
        $writer->save($path);
    }

    /** @param list<Section> $sections */
    public function response(array $sections, string $title, bool $withSummary = true): BinaryFileResponse
    {
        $path = tempnam(sys_get_temp_dir(), 'materials-');
        if (false === $path) {
            throw new \RuntimeException('Не удалось создать временный файл.');
        }
        $this->build($sections, $path, $withSummary);
        $name = \sprintf('%s %s.xlsx', $title, date('Y-m-d'));
        $response = new BinaryFileResponse($path);
        $response->headers->set('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $response->headers->set('Content-Disposition', HeaderUtils::makeDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $name, 'materials-'.date('Y-m-d').'.xlsx'));
        $response->deleteFileAfterSend(true);

        return $response;
    }

    private function category(XlsxSheet $sheet, Section $section): void
    {
        $parameters = $this->materials->parameters($section);
        $rows = $this->materials->rows($section);
        $kinds = $this->kindsList();

        // Шапка: Материал | Тип | Состояние | параметры… | Документ портала.
        $headers = ['Материал', 'Тип', 'Состояние'];
        $widths = [52, 22, 17];
        foreach ($parameters as $parameter) {
            $headers[] = $parameter->getName();
            $widths[] = $parameter->getWidth() ?? max(12, min(40, mb_strlen($parameter->getName()) + 4));
        }
        $headers[] = self::DOCUMENT_HEADER;
        $widths[] = 34;
        foreach ($headers as $index => $header) {
            $sheet->set(1, $index + 1, $header, XlsxWriter::STYLE_HEADER)->width($index + 1, $widths[$index]);
        }
        $sheet->freezeTopRow();

        $column = [];
        foreach ($parameters as $index => $parameter) {
            $column[$parameter->getRole() ?? $parameter->key()] = XlsxWriter::column($index + 4);
        }
        $pm = $column[MaterialParameter::ROLE_PM] ?? null;
        $design = $column[MaterialParameter::ROLE_DESIGN] ?? null;

        $line = 1;
        foreach ($rows as $row) {
            ++$line;
            $sheet->set($line, 1, $row->getTitle(), XlsxWriter::STYLE_WRAP);
            $sheet->set($line, 2, $row->getKind()?->getName() ?? '');
            $state = $this->materials->state($row);
            $formula = null !== $pm && null !== $design ? self::stateFormula($pm.$line, $design.$line) : null;
            $sheet->set($line, 3, $state, XlsxWriter::STYLE_DEFAULT, $formula);
            foreach ($parameters as $index => $parameter) {
                $value = $row->getValue($parameter);
                $col = $index + 4;
                if ('' === $value) {
                    continue;
                }
                switch ($parameter->getKind()) {
                    case MaterialParameter::KIND_DATE:
                        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
                        $sheet->set($line, $col, false !== $date ? $date : $value, XlsxWriter::STYLE_DATE);
                        break;
                    case MaterialParameter::KIND_NUMBER:
                        $sheet->set($line, $col, is_numeric($value) ? $value + 0 : $value);
                        break;
                    case MaterialParameter::KIND_LINK:
                        $sheet->set($line, $col, $value, XlsxWriter::STYLE_LINK)->link($line, $col, $value);
                        break;
                    default:
                        $sheet->set($line, $col, $value, XlsxWriter::STYLE_WRAP);
                }
            }
            $document = $row->getDocument();
            if (null !== $document) {
                $url = $this->urls->generate('app_document_show', ['id' => $document->getId()], UrlGeneratorInterface::ABSOLUTE_URL);
                $version = $document->getCurrentVersion()?->getNumber();
                $sheet->set($line, \count($headers), $document->getTitle().(null !== $version ? ' (v'.$version.')' : ''), XlsxWriter::STYLE_LINK)->link($line, \count($headers), $url);
            }
        }

        // Как в исходной книге: списки значений и окраска состояния — на запас строк ниже данных.
        $last = max($line + 200, 200);
        $sheet->listValidation('B2:B'.$last, $kinds);
        foreach ($parameters as $index => $parameter) {
            if ($parameter->isList()) {
                $letter = XlsxWriter::column($index + 4);
                $sheet->listValidation($letter.'2:'.$letter.$last, $parameter->getOptions());
            }
        }
        $range = 'C2:C'.$last;
        $sheet->condition($range, '$C2="'.MaterialState::READY.'"', XlsxWriter::DXF_READY)
            ->condition($range, '$C2="'.MaterialState::ACCEPTABLE.'"', XlsxWriter::DXF_ACCEPTABLE)
            ->condition($range, '$C2="'.MaterialState::MISMATCH.'"', XlsxWriter::DXF_MISMATCH)
            ->condition($range, '$C2="'.MaterialState::ABSENT.'"', XlsxWriter::DXF_ABSENT)
            ->condition($range, '$C2="'.MaterialState::UNKNOWN.'"', XlsxWriter::DXF_UNKNOWN);
        $sheet->autoFilter('A1:'.XlsxWriter::column(\count($headers)).max(1, $line));
    }

    /**
     * @param list<Section>     $sections
     * @param array<int, string> $sheetNames
     */
    private function summarySheet(XlsxSheet $sheet, array $sections, array $sheetNames): void
    {
        $kinds = $this->kindsList();
        // Блок категории: название, кольцевая диаграмма, «Общее состояние» и «Несоответствие / Отсутствие по типу».
        $height = 3 + self::CHART_ROWS + max(\count(MaterialState::SUMMARY), \count($kinds));
        for ($col = 1; $col <= self::PER_ROW * 5; ++$col) {
            $sheet->width($col, match ($col % 5) {
                1 => 17.0, 3 => 28.0, 0 => 3.0, default => 8.0,
            });
        }
        foreach (array_values($sections) as $index => $section) {
            $top = 1 + intdiv($index, self::PER_ROW) * ($height + 2);
            $left = 1 + ($index % self::PER_ROW) * 5;
            $ref = XlsxWriter::sheetRef($sheetNames[(int) $section->getId()]);
            $summary = $this->summary->forSection($section);
            $letter = static fn (int $offset): string => XlsxWriter::column($left + $offset);
            $block = $top + 1 + self::CHART_ROWS;

            $sheet->set($top, $left, $section->getName(), XlsxWriter::STYLE_TITLE)->merge($letter(0).$top.':'.$letter(3).$top);
            $sheet->set($block, $left, 'Общее состояние', XlsxWriter::STYLE_BLOCK)->set($block, $left + 1, '', XlsxWriter::STYLE_BLOCK)->merge($letter(0).$block.':'.$letter(1).$block);
            $sheet->set($block, $left + 2, 'Несоответствие / Отсутствие по типу', XlsxWriter::STYLE_BLOCK)->set($block, $left + 3, '', XlsxWriter::STYLE_BLOCK)->merge($letter(2).$block.':'.$letter(3).$block);
            foreach (['Состояние', 'Кол-во', 'Тип', 'Кол-во'] as $offset => $header) {
                $sheet->set($block + 1, $left + $offset, $header, XlsxWriter::STYLE_HEADER);
            }
            $first = $block + 2;
            $counts = [];
            foreach (MaterialState::SUMMARY as $i => $state) {
                $line = $first + $i;
                $counts[] = (int) ($summary['states'][$state] ?? 0);
                $sheet->set($line, $left, $state);
                $sheet->set($line, $left + 1, (int) ($summary['states'][$state] ?? 0), XlsxWriter::STYLE_DEFAULT, \sprintf('COUNTIF(%s!$C:$C,"%s")', $ref, $state));
            }
            foreach ($kinds as $i => $kind) {
                $line = $first + $i;
                $count = (int) ($summary['problemsByKind'][$this->kindId($kind)] ?? 0);
                $sheet->set($line, $left + 2, $kind);
                $sheet->set($line, $left + 3, $count, XlsxWriter::STYLE_DEFAULT, \sprintf(
                    'COUNTIFS(%1$s!$B:$B,$%2$s%3$d,%1$s!$C:$C,"%4$s")+COUNTIFS(%1$s!$B:$B,$%2$s%3$d,%1$s!$C:$C,"%5$s")',
                    $ref, $letter(2), $line, MaterialState::MISMATCH, MaterialState::ABSENT,
                ));
            }
            $sheet->condition($letter(3).$first.':'.$letter(3).($first - 1 + \count($kinds)), $letter(3).$first.'>0', XlsxWriter::DXF_PROBLEM_COUNT);
            $sheet->condition($letter(1).($first + 2).':'.$letter(1).($first + 3), $letter(1).($first + 2).'>0', XlsxWriter::DXF_PROBLEM_COUNT);

            // Кольцевая диаграмма по «Общему состоянию» — как в исходной книге.
            $summaryRef = XlsxWriter::sheetRef($sheet->getName());
            $last = $first + \count(MaterialState::SUMMARY) - 1;
            // Название категории уже стоит над диаграммой — у самой диаграммы заголовка нет.
            $sheet->doughnut(
                $top + 1, $left, '',
                \sprintf('%s!$%s$%d:$%s$%d', $summaryRef, $letter(0), $first, $letter(0), $last),
                \sprintf('%s!$%s$%d:$%s$%d', $summaryRef, $letter(1), $first, $letter(1), $last),
                MaterialState::SUMMARY, $counts, array_values(self::CHART_COLORS),
            );
        }
        if ([] === $sections) {
            $sheet->set(1, 1, 'Нет разделов с таблицами материалов.', XlsxWriter::STYLE_MUTED);
        }
    }

    /** @return list<string> названия типов в порядке справочника */
    private function kindsList(): array
    {
        return array_map(static fn ($kind): string => $kind->getName(), $this->kinds->all());
    }

    private function kindId(string $name): int
    {
        foreach ($this->kinds->all() as $kind) {
            if ($kind->getName() === $name) {
                return (int) $kind->getId();
            }
        }

        return 0;
    }

    /** Формула состояния — та же, что в исходной книге. */
    private static function stateFormula(string $pm, string $design): string
    {
        return \sprintf(
            'IF(%1$s="","",IF(%1$s="%3$s","%4$s",IF(%1$s="%5$s","%6$s",IF(%1$s="%7$s","%8$s",IF(AND(%1$s="%9$s",%2$s="%9$s"),"%10$s",IF(AND(%1$s="%9$s",%2$s="%3$s"),"%11$s",""))))))',
            $pm, $design,
            MaterialState::CHECK_FAIL, MaterialState::MISMATCH,
            MaterialState::CHECK_NONE, MaterialState::UNKNOWN,
            MaterialState::CHECK_ABSENT, MaterialState::ABSENT,
            MaterialState::CHECK_OK, MaterialState::READY, MaterialState::ACCEPTABLE,
        );
    }
}
