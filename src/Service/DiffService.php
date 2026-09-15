<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\DocumentVersion;
use SebastianBergmann\Diff\Differ;
use SebastianBergmann\Diff\Output\DiffOnlyOutputBuilder;

/**
 * Сравнение версий страниц: построчное сравнение текста без разметки.
 */
final class DiffService
{
    /**
     * @return list<array{type: 'same'|'add'|'del', text: string}>
     */
    public function compare(DocumentVersion $a, DocumentVersion $b): array
    {
        $differ = new Differ(new DiffOnlyOutputBuilder());
        $lines = [];
        foreach ($differ->diffToArray($a->getPlainText(), $b->getPlainText()) as [$text, $op]) {
            $lines[] = ['type' => match ($op) { Differ::ADDED => 'add', Differ::REMOVED => 'del', default => 'same' }, 'text' => rtrim((string) $text)];
        }

        return $lines;
    }

    /**
     * @param list<array{type: string, text: string}> $lines
     *
     * @return array{added: int, removed: int}
     */
    public static function summary(array $lines): array
    {
        $added = $removed = 0;
        foreach ($lines as $l) {
            if ('add' === $l['type']) {
                ++$added;
            } elseif ('del' === $l['type']) {
                ++$removed;
            }
        }

        return ['added' => $added, 'removed' => $removed];
    }
}
