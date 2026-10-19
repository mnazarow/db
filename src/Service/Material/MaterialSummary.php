<?php

declare(strict_types=1);

namespace App\Service\Material;

use App\Entity\MaterialParameter;
use App\Entity\MaterialRow;
use App\Entity\Section;
use App\Repository\MaterialParameterRepository;
use App\Repository\MaterialRowRepository;
use App\Repository\SectionRepository;
use App\Service\DocumentKindService;

/**
 * Сводная по категориям — как лист «Сводная по категориям»: по каждому разделу с таблицей
 * материалов «Общее состояние» (сколько материалов готово, допустимо, не соответствует,
 * отсутствует) и «Несоответствие / Отсутствие по типу».
 */
final class MaterialSummary
{
    public function __construct(
        private readonly SectionRepository $sections,
        private readonly MaterialParameterRepository $parameters,
        private readonly MaterialRowRepository $rows,
        private readonly DocumentKindService $kinds,
        private readonly ?MaterialLinker $linker = null,
    ) {
    }

    /** @return list<Section> разделы верхнего уровня с включённой таблицей материалов */
    public function categories(): array
    {
        return $this->sections->findBy(['parent' => null, 'materialsEnabled' => true], ['position' => 'ASC', 'name' => 'ASC']);
    }

    /** @return list<Section> разделы верхнего уровня, где таблицу ещё можно включить */
    public function candidates(): array
    {
        return $this->sections->findBy(['parent' => null, 'materialsEnabled' => false], ['position' => 'ASC', 'name' => 'ASC']);
    }

    /**
     * Сводная по всем категориям и итог.
     *
     * @return array{categories: list<array{section: Section, summary: array<string, mixed>}>, totals: array<string, mixed>, kinds: list<\App\Entity\DocumentKind>}
     */
    public function overview(): array
    {
        $sections = $this->categories();
        foreach ($sections as $section) {
            $this->linker?->ensureSynced($section);
        }
        $ids = array_map(static fn (Section $s): int => (int) $s->getId(), $sections);
        $parameters = $this->parameters->findForSections($ids);
        $rows = $this->rows->findForSections($ids);

        $categories = [];
        $totals = self::empty();
        foreach ($sections as $section) {
            $summary = $this->summarize($rows[(int) $section->getId()] ?? [], $parameters[(int) $section->getId()] ?? []);
            $categories[] = ['section' => $section, 'summary' => $summary];
            $totals = self::merge($totals, $summary);
        }

        return ['categories' => $categories, 'totals' => $totals, 'kinds' => $this->kinds->all()];
    }

    /** @return array<string, mixed> */
    public function forSection(Section $section): array
    {
        $this->linker?->ensureSynced($section);
        $id = (int) $section->getId();

        return $this->summarize($this->rows->findForSections([$id])[$id] ?? [], $this->parameters->findForSections([$id])[$id] ?? []);
    }

    /**
     * Сводные по разделам верхнего уровня с таблицей материалов (для счётчиков на карточках разделов).
     *
     * @return array<int, array<string, mixed>> id раздела => сводная
     */
    public function bySection(): array
    {
        $out = [];
        foreach ($this->overview()['categories'] as $category) {
            $out[(int) $category['section']->getId()] = $category['summary'];
        }

        return $out;
    }

    /**
     * Сколько материалов таблицы раздела загружено в каждый его подраздел (по документам строк).
     *
     * @param list<Section> $children подразделы раздела
     *
     * @return array<int, int> id подраздела => материалов с файлами
     */
    public function filesBySubsection(Section $root, array $children): array
    {
        $out = array_fill_keys(array_map(static fn (Section $s): int => (int) $s->getId(), $children), 0);
        if ([] === $children) {
            return $out;
        }
        foreach ($this->rows->findForSections([(int) $root->getId()])[(int) $root->getId()] ?? [] as $row) {
            if (!$row->hasFile()) {
                continue;
            }
            $section = $row->getDocument()->getSection();
            foreach ($children as $child) {
                if ($section->isSameOrDescendantOf($child)) {
                    ++$out[(int) $child->getId()];
                    break;
                }
            }
        }

        return $out;
    }

    /**
     * @param list<MaterialRow>       $rows
     * @param list<MaterialParameter> $parameters
     *
     * @return array{total: int, files: int, states: array<string, int>, problems: int, problemsByKind: array<int, int>, noKindProblems: int, unrated: int}
     */
    public function summarize(array $rows, array $parameters): array
    {
        $pm = $design = null;
        foreach ($parameters as $parameter) {
            if (MaterialParameter::ROLE_PM === $parameter->getRole()) {
                $pm = $parameter;
            } elseif (MaterialParameter::ROLE_DESIGN === $parameter->getRole()) {
                $design = $parameter;
            }
        }
        $summary = self::empty();
        foreach ($rows as $row) {
            ++$summary['total'];
            if ($row->hasFile()) {
                ++$summary['files'];
            }
            $state = MaterialState::effective($row->getValue($pm), $row->getValue($design), $row->hasFile());
            if ('' === $state) {
                ++$summary['unrated'];
            } else {
                $summary['states'][$state] = ($summary['states'][$state] ?? 0) + 1;
            }
            if (MaterialState::isProblem($state)) {
                ++$summary['problems'];
                $kindId = $row->getKind()?->getId();
                if (null === $kindId) {
                    ++$summary['noKindProblems'];
                } else {
                    $summary['problemsByKind'][$kindId] = ($summary['problemsByKind'][$kindId] ?? 0) + 1;
                }
            }
        }

        return $summary;
    }

    /** Данные кольцевой диаграммы: сегменты с долями и углами. */
    public static function donut(array $summary, float $gapDegrees = 1.6): array
    {
        $segments = [];
        $states = [...MaterialState::SUMMARY, MaterialState::UNKNOWN];
        $total = 0;
        foreach ($states as $state) {
            $total += (int) ($summary['states'][$state] ?? 0);
        }
        if (0 === $total) {
            return ['total' => 0, 'segments' => []];
        }
        $angle = 0.0;
        $nonZero = \count(array_filter($states, static fn (string $s): bool => ($summary['states'][$s] ?? 0) > 0));
        foreach ($states as $state) {
            $count = (int) ($summary['states'][$state] ?? 0);
            if (0 === $count) {
                continue;
            }
            $sweep = 360.0 * $count / $total;
            $gap = $nonZero > 1 ? min($gapDegrees, $sweep / 3) : 0.0;
            $segments[] = [
                'state' => $state,
                'count' => $count,
                'share' => round(100 * $count / $total),
                'class' => MaterialState::css($state),
                'color' => MaterialState::COLORS[$state],
                'path' => self::arc($angle + $gap / 2, $angle + $sweep - $gap / 2),
            ];
            $angle += $sweep;
        }

        return ['total' => $total, 'segments' => $segments];
    }

    /** @return array{total: int, files: int, states: array<string, int>, problems: int, problemsByKind: array<int, int>, noKindProblems: int, unrated: int} */
    private static function empty(): array
    {
        return ['total' => 0, 'files' => 0, 'states' => array_fill_keys([...MaterialState::SUMMARY, MaterialState::UNKNOWN], 0), 'problems' => 0, 'problemsByKind' => [], 'noKindProblems' => 0, 'unrated' => 0];
    }

    private static function merge(array $a, array $b): array
    {
        foreach (['total', 'files', 'problems', 'noKindProblems', 'unrated'] as $key) {
            $a[$key] += $b[$key];
        }
        foreach ($b['states'] as $state => $count) {
            $a['states'][$state] = ($a['states'][$state] ?? 0) + $count;
        }
        foreach ($b['problemsByKind'] as $kind => $count) {
            $a['problemsByKind'][$kind] = ($a['problemsByKind'][$kind] ?? 0) + $count;
        }

        return $a;
    }

    /**
     * Путь SVG для сегмента кольца (центр 60,60, внешний радиус 56, внутренний 36) от угла $from до $to
     * (градусы, 0 — сверху, по часовой стрелке).
     */
    private static function arc(float $from, float $to, float $outer = 56.0, float $inner = 36.0): string
    {
        if ($to - $from >= 359.99) {
            // Полное кольцо: две половинки, иначе дуга вырождается.
            return self::arc(0, 180, $outer, $inner).' '.self::arc(180, 360, $outer, $inner);
        }
        $point = static function (float $angle, float $radius): string {
            $rad = deg2rad($angle - 90);

            return \sprintf('%.3F %.3F', 60 + $radius * cos($rad), 60 + $radius * sin($rad));
        };
        $large = ($to - $from) > 180 ? 1 : 0;

        return \sprintf('M %s A %.1F %.1F 0 %d 1 %s L %s A %.1F %.1F 0 %d 0 %s Z',
            $point($from, $outer), $outer, $outer, $large, $point($to, $outer),
            $point($to, $inner), $inner, $inner, $large, $point($from, $inner));
    }
}
