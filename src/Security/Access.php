<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\Document;
use App\Entity\Section;
use App\Entity\User;
use App\Repository\SectionModeratorRepository;
use App\Repository\SectionRepository;

/**
 * Правила доступа портала.
 *
 * - Администратор может всё.
 * - Модератор раздела управляет разделом, его подразделами и документами в них
 *   (создание/изменение/публикация/удаление документов, создание подразделов).
 * - Обычный пользователь читает опубликованные документы во всех разделах.
 */
final class Access
{
    /** @var array<int, list<int>> кэш: id пользователя => id разделов, где он модератор напрямую */
    private array $moderatedCache = [];

    public function __construct(
        private readonly SectionModeratorRepository $moderators,
        private readonly SectionRepository $sections,
    ) {
    }

    /** @return list<int> разделы, где пользователь назначен модератором (без учёта наследования) */
    public function moderatedSectionIds(User $user): array
    {
        $id = (int) $user->getId();
        if (!isset($this->moderatedCache[$id])) {
            $this->moderatedCache[$id] = $this->moderators->findSectionIdsForUser($user);
        }

        return $this->moderatedCache[$id];
    }

    public function resetCache(): void
    {
        $this->moderatedCache = [];
    }

    public function isAdmin(?User $user): bool
    {
        return null !== $user && $user->isAdmin();
    }

    /** Может ли пользователь управлять разделом (сам раздел или любой из его предков закреплён за ним). */
    public function canManageSection(?User $user, Section $section): bool
    {
        if (null === $user) {
            return false;
        }
        if ($user->isAdmin()) {
            return true;
        }
        $moderated = $this->moderatedSectionIds($user);
        if ([] === $moderated) {
            return false;
        }

        return [] !== array_intersect($moderated, $section->getPathIds());
    }

    /** Модерирует ли пользователь хотя бы один раздел. */
    public function isModerator(?User $user): bool
    {
        return null !== $user && ($user->isAdmin() || [] !== $this->moderatedSectionIds($user));
    }

    public function canViewDocument(?User $user, Document $document): bool
    {
        if (null === $user) {
            return false;
        }
        if ($document->isPublished()) {
            return true;
        }

        return $this->canManageSection($user, $document->getSection());
    }

    public function canEditDocument(?User $user, Document $document): bool
    {
        return null !== $user && $this->canManageSection($user, $document->getSection());
    }

    /**
     * Идентификаторы всех разделов (с потомками), которыми управляет пользователь; null — все разделы (администратор).
     *
     * @return list<int>|null
     */
    public function managedSectionIds(User $user): ?array
    {
        if ($user->isAdmin()) {
            return null;
        }
        $ids = [];
        foreach ($this->moderatedSectionIds($user) as $sid) {
            $section = $this->sections->find($sid);
            if (null !== $section) {
                foreach ($this->sections->findSubtreeIds($section) as $id) {
                    $ids[$id] = $id;
                }
            }
        }

        return array_values($ids);
    }

    /**
     * Разделы, куда пользователь может добавлять документы/подразделы.
     *
     * @return list<Section>
     */
    public function managedSections(User $user): array
    {
        $all = $this->sections->findAllTree();
        if ($user->isAdmin()) {
            return $all;
        }
        $ids = $this->managedSectionIds($user) ?? [];

        return array_values(array_filter($all, static fn (Section $s) => \in_array($s->getId(), $ids, true)));
    }
}
