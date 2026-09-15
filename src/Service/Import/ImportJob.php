<?php

declare(strict_types=1);

namespace App\Service\Import;

/**
 * Задание импорта из панели администратора: план, параметры и состояние выполнения.
 * Хранится в JSON-файле (см. ImportJobStore) и выполняется порциями — по одному HTTP-запросу на порцию.
 */
final class ImportJob
{
    public ?string $error = null;
    public \DateTimeImmutable $updatedAt;
    public ?\DateTimeImmutable $finishedAt = null;

    public function __construct(
        public readonly string $id,
        public readonly ImportPlan $plan,
        public readonly ImportOptions $options,
        public ImportState $state,
        public readonly int $actorId,
        public readonly string $actorName,
        public readonly \DateTimeImmutable $createdAt,
    ) {
        $this->updatedAt = $createdAt;
    }

    public function total(): int
    {
        return $this->plan->count();
    }

    public function isFinished(): bool
    {
        return $this->state->finished || null !== $this->error;
    }

    public function progressPercent(): int
    {
        $total = $this->total();
        if (0 === $total) {
            return 100;
        }

        return (int) floor(min($this->state->position, $total) * 100 / $total);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'plan' => $this->plan->toArray(),
            'options' => $this->options->toArray(),
            'state' => $this->state->toArray(),
            'actorId' => $this->actorId,
            'actorName' => $this->actorName,
            'createdAt' => $this->createdAt->format(\DATE_ATOM),
            'updatedAt' => $this->updatedAt->format(\DATE_ATOM),
            'finishedAt' => $this->finishedAt?->format(\DATE_ATOM),
            'error' => $this->error,
        ];
    }

    /** @param array<string, mixed> $a */
    public static function fromArray(array $a): self
    {
        $job = new self(
            (string) $a['id'],
            ImportPlan::fromArray((array) $a['plan']),
            ImportOptions::fromArray((array) ($a['options'] ?? [])),
            ImportState::fromArray((array) ($a['state'] ?? [])),
            (int) ($a['actorId'] ?? 0),
            (string) ($a['actorName'] ?? ''),
            new \DateTimeImmutable((string) ($a['createdAt'] ?? 'now')),
        );
        $job->updatedAt = new \DateTimeImmutable((string) ($a['updatedAt'] ?? $a['createdAt'] ?? 'now'));
        $job->finishedAt = isset($a['finishedAt']) && '' !== $a['finishedAt'] ? new \DateTimeImmutable((string) $a['finishedAt']) : null;
        $job->error = isset($a['error']) && '' !== $a['error'] ? (string) $a['error'] : null;

        return $job;
    }
}
