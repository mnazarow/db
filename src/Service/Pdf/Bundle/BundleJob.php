<?php

declare(strict_types=1);

namespace App\Service\Pdf\Bundle;

/**
 * Задание на PDF-сборник раздела: список документов (действующие редакции на момент создания задания),
 * параметры и ход сборки. Хранится в JSON рядом с рабочими файлами (см. BundleJobStore).
 */
final class BundleJob
{
    public const PHASE_PARTS = 'parts';
    public const PHASE_MERGE = 'merge';
    public const PHASE_DONE = 'done';

    /** Как документ попадает в сборник. */
    public const KIND_PDF = 'pdf';        // PDF — как есть
    public const KIND_OFFICE = 'office';  // офисный файл — через LibreOffice
    public const KIND_IMAGE = 'image';    // картинка — на странице A4
    public const KIND_TEXT = 'text';      // текстовый файл
    public const KIND_PAGE = 'page';      // страница портала (HTML)
    public const KIND_OTHER = 'other';    // формат не переводится в PDF — только реквизиты

    /** Итог по документу. */
    public const ITEM_PENDING = 'pending';
    public const ITEM_OK = 'ok';
    public const ITEM_NOTE = 'note'; // содержимое не включено: вместо него страница с пояснением

    /**
     * @param list<array{document: int, version: int, title: string, code: ?string, section: string, kind: string, extension: string, status: string, pages: int, note: ?string, files: list<string>}> $items
     */
    public function __construct(
        public readonly string $id,
        public readonly int $sectionId,
        public readonly string $sectionName,
        public readonly int $actorId,
        public readonly string $actorName,
        public readonly bool $titlePages,
        public array $items,
        public int $position = 0,
        public string $phase = self::PHASE_PARTS,
        public ?string $error = null,
        public ?int $pages = null,
        public ?int $bytes = null,
        public ?\DateTimeImmutable $finishedAt = null,
        public \DateTimeImmutable $createdAt = new \DateTimeImmutable(),
        public \DateTimeImmutable $updatedAt = new \DateTimeImmutable(),
        /** Попыток обработать текущий документ (или объединить файлы): запрос мог прерваться на нём. */
        public int $attempts = 0,
        /** Сборка передана обработчику фоновых задач: страницу можно закрыть. */
        public bool $background = false,
    ) {
    }

    public function total(): int
    {
        return \count($this->items);
    }

    public function isFinished(): bool
    {
        return null !== $this->finishedAt;
    }

    public function isReady(): bool
    {
        return self::PHASE_DONE === $this->phase && null === $this->error;
    }

    /** Ход в процентах: документы — до 95 %, объединение файлов — остальное. */
    public function progressPercent(): int
    {
        if ($this->isFinished()) {
            return 100;
        }
        if (self::PHASE_MERGE === $this->phase) {
            return 95;
        }

        return 0 === $this->total() ? 0 : (int) floor($this->position * 95 / $this->total());
    }

    /** @return array{ok: int, note: int, pending: int} */
    public function counters(): array
    {
        $counters = ['ok' => 0, 'note' => 0, 'pending' => 0];
        foreach ($this->items as $item) {
            ++$counters[match ($item['status']) {
                self::ITEM_OK => 'ok',
                self::ITEM_NOTE => 'note',
                default => 'pending',
            }];
        }

        return $counters;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'section_id' => $this->sectionId,
            'section_name' => $this->sectionName,
            'actor_id' => $this->actorId,
            'actor_name' => $this->actorName,
            'title_pages' => $this->titlePages,
            'items' => $this->items,
            'position' => $this->position,
            'phase' => $this->phase,
            'error' => $this->error,
            'pages' => $this->pages,
            'bytes' => $this->bytes,
            'finished_at' => $this->finishedAt?->format(\DATE_ATOM),
            'created_at' => $this->createdAt->format(\DATE_ATOM),
            'updated_at' => $this->updatedAt->format(\DATE_ATOM),
            'attempts' => $this->attempts,
            'background' => $this->background,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            (string) $data['id'],
            (int) $data['section_id'],
            (string) $data['section_name'],
            (int) $data['actor_id'],
            (string) $data['actor_name'],
            (bool) $data['title_pages'],
            array_values((array) $data['items']),
            (int) $data['position'],
            (string) $data['phase'],
            isset($data['error']) ? (string) $data['error'] : null,
            isset($data['pages']) ? (int) $data['pages'] : null,
            isset($data['bytes']) ? (int) $data['bytes'] : null,
            isset($data['finished_at']) ? new \DateTimeImmutable((string) $data['finished_at']) : null,
            new \DateTimeImmutable((string) $data['created_at']),
            new \DateTimeImmutable((string) $data['updated_at']),
            (int) ($data['attempts'] ?? 0),
            (bool) ($data['background'] ?? false),
        );
    }
}
