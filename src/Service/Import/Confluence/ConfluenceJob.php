<?php

declare(strict_types=1);

namespace App\Service\Import\Confluence;

/**
 * Задание импорта пространства Confluence: список страниц (без текста), параметры и ход выполнения. Хранится в
 * JSON-файле (var/import-jobs/confluence-*.json) и выполняется порциями — из панели администратора по одному
 * запросу на порцию или целиком из консоли.
 */
final class ConfluenceJob
{
    public const COUNTER_LABELS = [
        'sectionsNew' => 'Разделов создано',
        'sectionsExisting' => 'Разделов уже было',
        'docsNew' => 'Страниц импортировано',
        'versionsNew' => 'Страниц обновлено',
        'unchanged' => 'Без изменений',
        'attachments' => 'Вложений загружено',
        'skipped' => 'Пропущено',
        'errors' => 'Ошибок',
    ];

    public const LOG_LIMIT = 5000;

    public int $position = 0;
    public bool $finished = false;
    public ?string $error = null;
    public ?\DateTimeImmutable $finishedAt = null;
    public \DateTimeImmutable $updatedAt;

    /** @var array<string, int> */
    public array $counters;

    /** @var list<array{type: string, path: string, result: string, message: string}> */
    public array $log = [];

    /** @var array<string, int> страница с дочерними → её раздел */
    public array $sections = [];

    /** @var array<string, string> страница → путь в журнале («Родитель / Страница») */
    public array $paths = [];

    /**
     * @param list<array{id: string, parentId: ?string, title: string, version: int, position: int, url: ?string}> $pages
     * @param array<string, int>                                                                               $childCount
     */
    public function __construct(
        public readonly string $id,
        public readonly string $base,
        public readonly string $spaceKey,
        public readonly string $spaceName,
        public readonly ?string $rootPageId,
        public readonly int $rootSectionId,
        public readonly bool $attachments,
        public readonly bool $publish,
        public readonly bool $flat,
        public readonly array $pages,
        public readonly array $childCount,
        public readonly int $actorId,
        public readonly string $actorName,
        public readonly \DateTimeImmutable $createdAt,
    ) {
        $this->updatedAt = $createdAt;
        $this->counters = array_fill_keys(array_keys(self::COUNTER_LABELS), 0);
    }

    public function total(): int
    {
        return \count($this->pages);
    }

    public function isFinished(): bool
    {
        return $this->finished || null !== $this->error;
    }

    public function progressPercent(): int
    {
        $total = $this->total();

        return 0 === $total ? 100 : (int) floor(min($this->position, $total) * 100 / $total);
    }

    public function label(): string
    {
        return $this->spaceName.' ('.$this->spaceKey.')';
    }

    public function count(string $counter): void
    {
        $this->counters[$counter] = ($this->counters[$counter] ?? 0) + 1;
    }

    public function addLog(string $type, string $path, string $result, string $message = ''): void
    {
        $this->log[] = ['type' => $type, 'path' => $path, 'result' => $result, 'message' => $message];
        if (\count($this->log) > self::LOG_LIMIT) {
            $this->log = \array_slice($this->log, -self::LOG_LIMIT);
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id, 'base' => $this->base, 'spaceKey' => $this->spaceKey, 'spaceName' => $this->spaceName,
            'rootPageId' => $this->rootPageId, 'rootSectionId' => $this->rootSectionId,
            'attachments' => $this->attachments, 'publish' => $this->publish, 'flat' => $this->flat,
            'pages' => $this->pages, 'childCount' => $this->childCount,
            'actorId' => $this->actorId, 'actorName' => $this->actorName,
            'createdAt' => $this->createdAt->format(\DATE_ATOM), 'updatedAt' => $this->updatedAt->format(\DATE_ATOM),
            'finishedAt' => $this->finishedAt?->format(\DATE_ATOM),
            'position' => $this->position, 'finished' => $this->finished, 'error' => $this->error,
            'counters' => $this->counters, 'log' => $this->log, 'sections' => $this->sections, 'paths' => $this->paths,
        ];
    }

    /** @param array<string, mixed> $d */
    public static function fromArray(array $d): self
    {
        $job = new self((string) $d['id'], (string) $d['base'], (string) $d['spaceKey'], (string) $d['spaceName'],
            isset($d['rootPageId']) ? (string) $d['rootPageId'] : null, (int) $d['rootSectionId'],
            (bool) $d['attachments'], (bool) $d['publish'], (bool) $d['flat'],
            array_values((array) $d['pages']), (array) $d['childCount'], (int) $d['actorId'], (string) $d['actorName'],
            new \DateTimeImmutable((string) $d['createdAt']));
        $job->updatedAt = new \DateTimeImmutable((string) ($d['updatedAt'] ?? $d['createdAt']));
        $job->finishedAt = isset($d['finishedAt']) ? new \DateTimeImmutable((string) $d['finishedAt']) : null;
        $job->position = (int) ($d['position'] ?? 0);
        $job->finished = (bool) ($d['finished'] ?? false);
        $job->error = isset($d['error']) ? (string) $d['error'] : null;
        $job->counters = array_map('intval', (array) ($d['counters'] ?? [])) + $job->counters;
        $job->log = array_values((array) ($d['log'] ?? []));
        $job->sections = array_map('intval', (array) ($d['sections'] ?? []));
        $job->paths = array_map('strval', (array) ($d['paths'] ?? []));

        return $job;
    }
}
