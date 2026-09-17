<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;

/**
 * Кто смотрит списки документов: нужен, чтобы документы с ограниченным доступом
 * не попадали в перечни и счётчики тем, кому они не открыты.
 *
 * $unrestricted — администратор (видит всё); $managedSectionIds — разделы, которые
 * пользователь модерирует (в них он видит и документы с ограниченным доступом).
 */
final readonly class Viewer
{
    /** @param list<int> $managedSectionIds */
    public function __construct(
        public ?User $user,
        public bool $unrestricted = false,
        public array $managedSectionIds = [],
    ) {
    }

    public static function guest(): self
    {
        return new self(null);
    }
}
