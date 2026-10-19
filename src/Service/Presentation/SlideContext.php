<?php

declare(strict_types=1);

namespace App\Service\Presentation;

/**
 * Связи и номера фигур одного слайда.
 *
 * @internal
 */
final class SlideContext
{
    /** @var list<array{type: string, target: string, external?: bool}> */
    public array $rels = [];
    private int $shapeId = 1;

    /** @param array<string, int> $index */
    public function __construct(private readonly array $index)
    {
    }

    public function nextId(): int
    {
        return ++$this->shapeId;
    }

    public function rel(string $type, string $target, bool $external = false): string
    {
        foreach ($this->rels as $i => $r) {
            if ($r['type'] === $type && $r['target'] === $target) {
                return 'rId'.($i + 1);
            }
        }
        $this->rels[] = ['type' => $type, 'target' => $target] + ($external ? ['external' => true] : []);

        return 'rId'.\count($this->rels);
    }

    public function slideNumber(string $id): ?int
    {
        return $this->index[$id] ?? null;
    }
}
