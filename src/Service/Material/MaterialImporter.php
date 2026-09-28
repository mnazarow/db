<?php

declare(strict_types=1);

namespace App\Service\Material;

use App\Entity\MaterialParameter;
use App\Entity\MaterialRow;
use App\Entity\Section;
use App\Entity\User;
use App\Repository\MaterialRowRepository;
use App\Repository\SectionRepository;
use App\Service\DocumentKindService;
use App\Service\SectionManager;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Импорт таблиц материалов из книги Excel вида «Продуктовые материалы»: каждый лист с шапкой
 * «Материал | Тип | Состояние | …» — раздел верхнего уровня с тем же названием, строки листа —
 * строки таблицы, остальные столбцы — параметры. Лист без такой шапки (сводная) пропускается.
 *
 * Повторный импорт обновляет таблицы: строки сопоставляются по названию материала (одинаковые
 * названия — по порядку), значения перезаписываются, новые строки добавляются. Загруженные к строкам
 * файлы сохраняются.
 */
final class MaterialImporter
{
    /** Заголовки столбца с названием материала. */
    private const TITLE_HEADERS = ['материал', 'наименование', 'название', 'наименование материала'];
    private const KIND_HEADERS = ['тип', 'тип файла', 'тип материала', 'вид'];
    private const STATE_HEADERS = ['состояние', 'статус'];
    /** Служебные столбцы выгрузки портала — при обратной загрузке не превращаются в параметры. */
    private const IGNORED_HEADERS = ['документ портала', 'файл в портале'];

    /** Значения, которые в столбцах-датах и числах означают «пусто». */
    private const EMPTY_MARKS = ['—', '–', '-', '―', 'нет', 'n/a'];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SectionRepository $sections,
        private readonly SectionManager $sectionManager,
        private readonly MaterialService $materials,
        private readonly MaterialRowRepository $rows,
        private readonly DocumentKindService $kinds,
        private readonly LoggerInterface $auditLogger,
    ) {
    }

    /**
     * Разбирает книгу и считает, что изменится, ничего не сохраняя.
     *
     * @param list<array{name: string, hidden: bool, rows: array<int, array<int, string>>, lists: array<int, list<string>>, dates: array<int, bool>}> $sheets
     *
     * @return array<string, mixed>
     */
    public function plan(array $sheets, bool $deleteMissing = false): array
    {
        return $this->process($sheets, $deleteMissing, null, true);
    }

    /**
     * Выполняет импорт.
     *
     * @param list<array{name: string, hidden: bool, rows: array<int, array<int, string>>, lists: array<int, list<string>>, dates: array<int, bool>}> $sheets
     *
     * @return array<string, mixed> отчёт (тот же вид, что у plan())
     */
    public function apply(array $sheets, bool $deleteMissing, ?User $by): array
    {
        $report = $this->em->wrapInTransaction(fn (): array => $this->process($sheets, $deleteMissing, $by, false));
        $this->auditLogger->info('Импорт таблиц материалов из Excel', [
            'sections' => \count($report['sheets']),
            'created_sections' => $report['totals']['sectionsCreated'],
            'rows_created' => $report['totals']['created'],
            'rows_updated' => $report['totals']['updated'],
            'rows_deleted' => $report['totals']['deleted'],
            'user' => $by?->getUsername(),
        ]);

        return $report;
    }

    /**
     * @param list<array{name: string, hidden: bool, rows: array<int, array<int, string>>, lists: array<int, list<string>>, dates: array<int, bool>}> $sheets
     *
     * @return array<string, mixed>
     */
    private function process(array $sheets, bool $deleteMissing, ?User $by, bool $dryRun): array
    {
        $report = [
            'sheets' => [],
            'skipped' => [],
            'kindsCreated' => [],
            'warnings' => [],
            'totals' => ['sectionsCreated' => 0, 'parametersCreated' => 0, 'created' => 0, 'updated' => 0, 'unchanged' => 0, 'deleted' => 0, 'rows' => 0],
        ];
        $plannedKinds = [];

        foreach ($sheets as $sheet) {
            if ($sheet['hidden']) {
                $report['skipped'][] = ['name' => $sheet['name'], 'reason' => 'скрытый лист'];
                continue;
            }
            $layout = self::layout($sheet['rows']);
            if (null === $layout) {
                $report['skipped'][] = ['name' => $sheet['name'], 'reason' => 'нет шапки «Материал | Тип | …» — например, сводная'];
                continue;
            }
            $name = trim(preg_replace('/\s+/u', ' ', $sheet['name']) ?? '');
            $section = $this->topLevelSection($name);
            $entry = ['name' => $name, 'section' => $section, 'sectionCreated' => null === $section, 'parametersCreated' => [], 'created' => 0, 'updated' => 0, 'unchanged' => 0, 'deleted' => 0, 'rows' => 0];

            if (!$dryRun) {
                if (null === $section) {
                    $section = $this->sectionManager->create($name, null, null, $by);
                }
                if (!$section->isMaterialsEnabled()) {
                    $section->setMaterialsEnabled(true);
                }
                $this->materials->ensureDefaults($section);
                $entry['section'] = $section;
            }
            if ($entry['sectionCreated']) {
                ++$report['totals']['sectionsCreated'];
            }

            // Столбцы листа → параметры таблицы (существующие, по умолчанию или новые).
            $existing = null !== $section ? $this->materials->parameters($section) : [];
            $virtual = [] === $existing ? self::defaultSpecs() : array_map(static fn (MaterialParameter $p): array => ['name' => $p->getName(), 'role' => $p->getRole(), 'kind' => $p->getKind(), 'entity' => $p], $existing);
            $columns = [];
            foreach ($layout['params'] as $col => $header) {
                $spec = self::matchSpec($virtual, $header);
                if (null === $spec) {
                    $kind = self::guessKind($col, $header, $sheet);
                    $spec = ['name' => $header, 'role' => null, 'kind' => $kind, 'entity' => null, 'options' => $sheet['lists'][$col] ?? []];
                    $entry['parametersCreated'][] = $header;
                    ++$report['totals']['parametersCreated'];
                    if (!$dryRun && null !== $section) {
                        $options = MaterialParameter::KIND_LIST === $kind ? ($spec['options'] ?: self::distinct($sheet['rows'], $col, $layout['header'])) : [];
                        $spec['entity'] = $this->materials->addParameter($section, $header, $kind, $options, $by);
                    }
                    $virtual[] = $spec;
                }
                $columns[$col] = $spec;
            }

            // Строки листа и сопоставление с имеющимися по названию (одинаковые — по порядку).
            $existingRows = null !== $section && null !== $section->getId() ? $this->rows->findForSection($section) : [];
            $pool = [];
            foreach ($existingRows as $row) {
                $pool[mb_strtolower($row->getTitle())][] = $row;
            }
            $matched = [];
            $position = 0;
            foreach ($sheet['rows'] as $index => $cells) {
                if ($index <= $layout['header']) {
                    continue;
                }
                $title = trim(preg_replace('/\s+/u', ' ', (string) ($cells[$layout['title']] ?? '')) ?? '');
                if ('' === $title) {
                    continue;
                }
                ++$position;
                ++$entry['rows'];
                $kindName = null !== $layout['kind'] ? (string) ($cells[$layout['kind']] ?? '') : '';
                $kindLabel = '' !== $kindName ? $this->kindLabel($kindName, $plannedKinds, $report) : '';

                $row = null;
                $key = mb_strtolower($title);
                if (isset($pool[$key]) && [] !== $pool[$key]) {
                    $row = array_shift($pool[$key]);
                    $matched[spl_object_id($row)] = true;
                }

                if ($dryRun) {
                    if (null === $row) {
                        ++$entry['created'];
                    } elseif ($this->differs($row, $kindLabel, $columns, $cells)) {
                        ++$entry['updated'];
                    } else {
                        ++$entry['unchanged'];
                    }
                    continue;
                }

                $isNew = null === $row;
                if ($isNew) {
                    $row = new MaterialRow($section, $title, $position);
                    $this->em->persist($row);
                }
                $before = $isNew ? null : json_encode([$row->getKind()?->getName(), $row->getValues()], \JSON_UNESCAPED_UNICODE);
                $row->setPosition($position);
                if (null !== $layout['kind']) {
                    $row->setKind('' !== $kindName ? $this->kinds->resolve($kindName, true, $by) : null);
                }
                foreach ($columns as $col => $spec) {
                    $this->setValue($row, $spec['entity'], (string) ($cells[$col] ?? ''), $report, $name, $title);
                }
                $after = json_encode([$row->getKind()?->getName(), $row->getValues()], \JSON_UNESCAPED_UNICODE);
                if ($isNew) {
                    $row->touch($by);
                    ++$entry['created'];
                } elseif ($before !== $after) {
                    $row->touch($by);
                    ++$entry['updated'];
                } else {
                    ++$entry['unchanged'];
                }
            }

            // Строки, которых нет в файле: удалить или поставить в конец таблицы.
            foreach ($existingRows as $row) {
                if (isset($matched[spl_object_id($row)])) {
                    continue;
                }
                if ($deleteMissing) {
                    ++$entry['deleted'];
                    if (!$dryRun) {
                        $this->em->remove($row);
                    }
                } elseif (!$dryRun) {
                    $row->setPosition(++$position);
                }
            }
            if (!$dryRun) {
                $this->em->flush();
            }

            foreach (['created', 'updated', 'unchanged', 'deleted', 'rows'] as $counter) {
                $report['totals'][$counter] += $entry[$counter];
            }
            $report['sheets'][] = $entry;
        }
        if (!$dryRun) {
            $this->materials->forgetParameters();
        }

        return $report;
    }

    /**
     * Шапка листа: номер строки и столбцы «Материал», «Тип», «Состояние» и параметров.
     *
     * @param array<int, array<int, string>> $rows
     *
     * @return array{header: int, title: int, kind: ?int, state: ?int, params: array<int, string>}|null
     */
    public static function layout(array $rows): ?array
    {
        foreach (\array_slice($rows, 0, 5, true) as $index => $cells) {
            $title = null;
            foreach ($cells as $col => $value) {
                if (\in_array(mb_strtolower(trim($value)), self::TITLE_HEADERS, true)) {
                    $title = $col;
                    break;
                }
            }
            if (null === $title) {
                continue;
            }
            $layout = ['header' => $index, 'title' => $title, 'kind' => null, 'state' => null, 'params' => []];
            foreach ($cells as $col => $value) {
                $header = trim(preg_replace('/\s+/u', ' ', $value) ?? '');
                $lower = mb_strtolower($header);
                if ($col === $title || '' === $header || \in_array($lower, self::IGNORED_HEADERS, true)) {
                    continue;
                }
                if (null === $layout['kind'] && \in_array($lower, self::KIND_HEADERS, true)) {
                    $layout['kind'] = $col;
                } elseif (null === $layout['state'] && \in_array($lower, self::STATE_HEADERS, true)) {
                    $layout['state'] = $col; // состояние считается формулой, из файла не берём
                } else {
                    $layout['params'][$col] = mb_substr($header, 0, MaterialParameter::MAX_NAME);
                }
            }

            return $layout;
        }

        return null;
    }

    private function topLevelSection(string $name): ?Section
    {
        foreach ($this->sections->findBy(['parent' => null]) as $section) {
            if (mb_strtolower($section->getName()) === mb_strtolower($name)) {
                return $section;
            }
        }

        return null;
    }

    /** @return list<array{name: string, role: string, kind: string, entity: null}> */
    private static function defaultSpecs(): array
    {
        return array_map(static fn (array $d): array => ['name' => $d[0], 'role' => $d[3], 'kind' => $d[1], 'entity' => null], MaterialParameter::DEFAULTS);
    }

    /**
     * Существующий параметр для заголовка: по названию, затем по роли («Дата обновления» → «Дата обнов.»).
     *
     * @param list<array<string, mixed>> $specs
     *
     * @return array<string, mixed>|null
     */
    private static function matchSpec(array $specs, string $header): ?array
    {
        foreach ($specs as $spec) {
            if (mb_strtolower($spec['name']) === mb_strtolower($header)) {
                return $spec;
            }
        }
        $role = MaterialParameter::roleForName($header);
        if (null !== $role) {
            foreach ($specs as $spec) {
                if ($spec['role'] === $role) {
                    return $spec;
                }
            }
        }

        return null;
    }

    /** Вид нового параметра по данным листа: список, дата, ссылка или текст. */
    private static function guessKind(int $col, string $header, array $sheet): string
    {
        if ([] !== ($sheet['lists'][$col] ?? [])) {
            return MaterialParameter::KIND_LIST;
        }
        if (isset($sheet['dates'][$col]) || str_starts_with(mb_strtolower($header), 'дата')) {
            return MaterialParameter::KIND_DATE;
        }
        $values = array_filter(array_map(static fn (array $cells): string => (string) ($cells[$col] ?? ''), \array_slice($sheet['rows'], 1)));
        if ([] !== $values && [] === array_filter($values, static fn (string $v): bool => 1 !== preg_match('~^https?://~i', $v))) {
            return MaterialParameter::KIND_LINK;
        }

        return MaterialParameter::KIND_TEXT;
    }

    /** @return list<string> различные значения столбца (варианты для нового списка) */
    private static function distinct(array $rows, int $col, int $header): array
    {
        $values = [];
        foreach ($rows as $index => $cells) {
            $value = trim((string) ($cells[$col] ?? ''));
            if ($index > $header && '' !== $value && !\in_array($value, $values, true)) {
                $values[] = $value;
            }
        }

        return $values;
    }

    /** Название типа, которое получит строка (для плана: с учётом ещё не созданных типов). */
    private function kindLabel(string $name, array &$planned, array &$report): string
    {
        $kind = $this->kinds->match($name);
        if (null !== $kind) {
            return $kind->getName();
        }
        $normalized = \App\Entity\DocumentKind::normalizeName($name);
        if (!\in_array($normalized, $planned, true)) {
            $planned[] = $normalized;
            $report['kindsCreated'][] = $normalized;
        }

        return $normalized;
    }

    private function differs(MaterialRow $row, string $kindLabel, array $columns, array $cells): bool
    {
        if (($row->getKind()?->getName() ?? '') !== $kindLabel) {
            return true;
        }
        foreach ($columns as $col => $spec) {
            $parameter = $spec['entity'];
            if (!$parameter instanceof MaterialParameter) {
                // Новый столбец меняет строку, только если в нём что-то заполнено.
                if ('' !== trim((string) ($cells[$col] ?? ''))) {
                    return true;
                }
                continue;
            }
            try {
                $value = $this->prepare($parameter, (string) ($cells[$col] ?? ''));
            } catch (\DomainException) {
                $value = (string) ($cells[$col] ?? '');
            }
            if ($row->getValue($parameter) !== $value) {
                return true;
            }
        }

        return false;
    }

    private function setValue(MaterialRow $row, MaterialParameter $parameter, string $raw, array &$report, string $sheet, string $title): void
    {
        try {
            $row->setValue($parameter, $this->prepare($parameter, $raw));
        } catch (\DomainException) {
            // Значение не из списка: добавляем его в список, чтобы ничего не потерять.
            if ($parameter->isList()) {
                $parameter->addOption(trim($raw));
                $row->setValue($parameter, $parameter->normalize($raw));
                $report['warnings'][] = \sprintf('«%s», «%s»: значение «%s» добавлено в список столбца «%s».', $sheet, $title, trim($raw), $parameter->getName());

                return;
            }
            $row->setValue($parameter, mb_substr(trim($raw), 0, MaterialParameter::MAX_TEXT));
            $report['warnings'][] = \sprintf('«%s», «%s»: значение «%s» в столбце «%s» перенесено как есть — проверьте его.', $sheet, $title, trim($raw), $parameter->getName());
        }
    }

    /** @throws \DomainException */
    private function prepare(MaterialParameter $parameter, string $raw): string
    {
        $raw = trim($raw);
        if (MaterialParameter::KIND_TEXT !== $parameter->getKind() && \in_array(mb_strtolower($raw), self::EMPTY_MARKS, true)) {
            return '';
        }

        return $parameter->normalize($raw);
    }
}
