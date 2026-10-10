<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\Document;
use App\Entity\DocumentVersion;
use App\Entity\Section;
use App\Entity\User;
use App\Repository\DocumentApprovalRepository;
use App\Repository\SectionModeratorRepository;
use App\Repository\SectionRepository;

/**
 * Правила доступа портала.
 *
 * - Администратор может всё.
 * - Маркетолог — модератор всех разделов: управляет любым разделом и документами в нём, решает по
 *   материалам, присланным на модерацию.
 * - Модератор раздела управляет разделом, его подразделами и документами в них
 *   (создание/изменение/публикация/удаление документов, создание подразделов).
 * - Помощник маркетолога добавляет документы в любой раздел, правит черновики и загружает новые
 *   редакции, но публикует только через модерацию (см. canContribute(), canEditCard()).
 * - Обычный пользователь читает опубликованные документы во всех разделах; документ, отправленный
 *   ему на согласование, он читает и до публикации.
 * - Гость (без входа) читает опубликованные открытые документы, если гостевой доступ разрешён
 *   настройками портала (режим и список сетей проверяет GuestAccessSubscriber).
 */
final class Access
{
    /** @var array<int, list<int>> кэш: id пользователя => id разделов, где он модератор напрямую */
    private array $moderatedCache = [];

    public function __construct(
        private readonly SectionModeratorRepository $moderators,
        private readonly SectionRepository $sections,
        private readonly ?DocumentApprovalRepository $approvals = null,
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

    /** Модератор всех разделов: администратор или маркетолог. */
    public function isGlobalModerator(?User $user): bool
    {
        return null !== $user && ($user->isAdmin() || $user->isMarketer());
    }

    /** Помощник маркетолога (без прав модератора во всём портале). */
    public function isAssistant(?User $user): bool
    {
        return null !== $user && $user->isAssistant();
    }

    /** Может ли пользователь добавлять документы в раздел: модератор раздела или помощник маркетолога. */
    public function canContribute(?User $user, Section $section): bool
    {
        return $this->canManageSection($user, $section) || $this->isAssistant($user);
    }

    /** Добавляет ли пользователь документы хоть куда-нибудь (кнопка «Документ», страница создания). */
    public function isContributor(?User $user): bool
    {
        return $this->isModerator($user) || $this->isAssistant($user);
    }

    /**
     * Нужна ли модерация материалам этого пользователя в разделе: помощник маркетолога, не являющийся
     * модератором раздела, публикует только через маркетолога.
     */
    public function needsModeration(?User $user, Section $section): bool
    {
        return null !== $user && !$this->canManageSection($user, $section) && $this->isAssistant($user);
    }

    /**
     * Может ли пользователь менять документ как автор материала: модератор раздела — всегда; помощник —
     * новые редакции любого неархивного документа (они ждут модерации) и карточку неопубликованного.
     */
    public function canContributeToDocument(?User $user, Document $document): bool
    {
        if ($this->canEditDocument($user, $document)) {
            return true;
        }

        return $this->isAssistant($user) && !$document->isArchived() && $this->canViewDocument($user, $document);
    }

    /**
     * Может ли пользователь менять карточку документа (название, описание, теги, срок, раздел…): модератор
     * раздела — всегда; помощник — только пока документ не опубликован (сотрудники его не видят).
     */
    public function canEditCard(?User $user, Document $document): bool
    {
        if ($this->canEditDocument($user, $document)) {
            return true;
        }

        return $this->canContributeToDocument($user, $document) && !$document->isPublished();
    }

    /** Может ли пользователь управлять разделом (сам раздел или любой из его предков закреплён за ним). */
    public function canManageSection(?User $user, Section $section): bool
    {
        if (null === $user) {
            return false;
        }
        if ($this->isGlobalModerator($user)) {
            return true;
        }
        $moderated = $this->moderatedSectionIds($user);
        if ([] === $moderated) {
            return false;
        }

        return [] !== array_intersect($moderated, $section->getPathIds());
    }

    /** Модерирует ли пользователь хотя бы один раздел (маркетолог и администратор — все). */
    public function isModerator(?User $user): bool
    {
        return null !== $user && ($this->isGlobalModerator($user) || [] !== $this->moderatedSectionIds($user));
    }

    /**
     * Гость (без входа) видит только опубликованные открытые документы; сотрудник — все опубликованные;
     * модератор раздела и администратор — также черновики и архив своих разделов.
     */
    public function canViewDocument(?User $user, Document $document): bool
    {
        if (null === $user) {
            // Гостю ограниченный доступ закрыт всегда: список сотрудников он предъявить не может.
            return $document->isPublished() && $document->isPublic() && !$document->isRestricted();
        }
        if ($document->isRestricted() && !$document->isAllowedFor($user) && !$this->canManageSection($user, $document->getSection())) {
            // Согласующий прочитает документ, который ждёт его решения, даже если в списке допуска его нет.
            return $this->isPendingApprover($user, $document);
        }
        if ($document->isPublished()) {
            return true;
        }
        if ($this->canManageSection($user, $document->getSection())) {
            return true;
        }
        // Помощник маркетолога видит черновики и материалы на модерации: он их готовит.
        if ($this->isAssistant($user)) {
            return true;
        }

        // Согласующий должен прочитать документ, который ждёт его решения, даже вне своих разделов.
        return $this->isPendingApprover($user, $document);
    }

    /** Ждёт ли документ (или его новая редакция) решения этого сотрудника. */
    public function isPendingApprover(?User $user, Document $document): bool
    {
        return null !== $user && null !== $this->approvals && !$document->isArchived() && $this->approvals->isPendingApprover($user, $document);
    }

    /**
     * Видна ли пользователю версия документа: текущая и прежние — всем, кто видит документ; новая редакция,
     * ждущая согласования, — только модераторам раздела и согласующему.
     */
    public function canViewVersion(?User $user, Document $document, DocumentVersion $version): bool
    {
        if ($document->isVersionReleased($version)) {
            return true;
        }

        return $this->canContributeToDocument($user, $document) || $this->isPendingApprover($user, $document);
    }

    /** Контекст просмотра списков: по нему из выборок убираются документы с ограниченным доступом. */
    public function viewer(?User $user): Viewer
    {
        if (null === $user) {
            return Viewer::guest();
        }
        if ($this->isGlobalModerator($user)) {
            return new Viewer($user, true);
        }

        return new Viewer($user, false, $this->managedSectionIds($user) ?? []);
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
        if ($this->isGlobalModerator($user)) {
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
        if ($this->isGlobalModerator($user)) {
            return $all;
        }
        $ids = $this->managedSectionIds($user) ?? [];

        return array_values(array_filter($all, static fn (Section $s) => \in_array($s->getId(), $ids, true)));
    }

    /**
     * Разделы, куда пользователь может добавлять документы: свои (модератор) или все (помощник маркетолога).
     *
     * @return list<Section>
     */
    public function contributableSections(User $user): array
    {
        if ($this->isAssistant($user)) {
            return $this->sections->findAllTree();
        }

        return $this->managedSections($user);
    }
}
