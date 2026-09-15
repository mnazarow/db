<?php

declare(strict_types=1);

namespace App\Service\Import;

/**
 * Состояние выполнения плана импорта: позиция, соответствие папок разделам, счётчики и журнал.
 * Сериализуется в JSON, чтобы импорт из панели администратора выполнялся порциями между запросами.
 */
final class ImportState
{
    public const COUNTERS = ['sectionsNew', 'sectionsExisting', 'docsNew', 'versionsNew', 'unchanged', 'skipped', 'errors'];

    public const COUNTER_LABELS = [
        'sectionsNew' => 'Разделов создано',
        'sectionsExisting' => 'Разделов уже было',
        'docsNew' => 'Документов создано',
        'versionsNew' => 'Новых версий загружено',
        'unchanged' => 'Файлов без изменений',
        'skipped' => 'Пропущено',
        'errors' => 'Ошибок',
    ];

    /** Сколько последних записей журнала хранить. */
    public const LOG_LIMIT = 5000;

    public int $position = 0;

    /** @var array<string, int|null> ключ папки → id раздела (null — верхний уровень) */
    public array $sections = [];

    /** @var array<string, int> */
    public array $counters = [];

    /** @var list<array{path: string, type: string, result: string, message: string}> */
    public array $log = [];

    public bool $finished = false;

    public function __construct()
    {
        $this->counters = array_fill_keys(self::COUNTERS, 0);
    }

    /** Начальное состояние: корень плана соответствует целевому разделу, если каталог не становится разделом сам. */
    public static function start(ImportOptions $options): self
    {
        $state = new self();
        if (!$options->rootAsSection) {
            $state->sections[''] = $options->targetSectionId;
        }

        return $state;
    }

    /** @param array<string, mixed> $entry */
    public function record(array $entry, string $result, string $message): void
    {
        $key = match ($result) {
            ImportPlan::RESULT_SECTION_NEW => 'sectionsNew',
            ImportPlan::RESULT_SECTION_EXISTS => 'sectionsExisting',
            ImportPlan::RESULT_DOC_NEW => 'docsNew',
            ImportPlan::RESULT_VERSION_NEW => 'versionsNew',
            ImportPlan::RESULT_UNCHANGED => 'unchanged',
            ImportPlan::RESULT_ERROR => 'errors',
            default => 'skipped',
        };
        ++$this->counters[$key];
        $this->log[] = ['path' => (string) $entry['path'], 'type' => (string) $entry['type'], 'result' => $result, 'message' => $message];
        if (\count($this->log) > self::LOG_LIMIT) {
            array_splice($this->log, 0, \count($this->log) - self::LOG_LIMIT);
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['position' => $this->position, 'sections' => $this->sections, 'counters' => $this->counters, 'log' => $this->log, 'finished' => $this->finished];
    }

    /** @param array<string, mixed> $a */
    public static function fromArray(array $a): self
    {
        $state = new self();
        $state->position = (int) ($a['position'] ?? 0);
        foreach ((array) ($a['sections'] ?? []) as $key => $id) {
            $state->sections[(string) $key] = null === $id ? null : (int) $id;
        }
        foreach (self::COUNTERS as $c) {
            $state->counters[$c] = (int) (($a['counters'] ?? [])[$c] ?? 0);
        }
        $state->log = array_values((array) ($a['log'] ?? []));
        $state->finished = (bool) ($a['finished'] ?? false);

        return $state;
    }
}
