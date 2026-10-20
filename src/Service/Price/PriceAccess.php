<?php

declare(strict_types=1);

namespace App\Service\Price;

use App\Entity\PriceList;
use App\Entity\PriceRevision;
use App\Entity\User;

/**
 * Права в разделе «Прайс-листы».
 *
 * - Открытый прайс-лист видят все, в том числе гости (по правилам просмотра без входа), закрытый — сотрудники.
 * - Маркетологи и администраторы ведут прайс-листы: создают, загружают редакции (публикуются сразу),
 *   меняют настройки, архивируют, удаляют, одобряют редакции помощников.
 * - Помощник маркетолога загружает новые редакции и создаёт прайс-листы — публикуются после модерации.
 */
final class PriceAccess
{
    public function canManage(?User $user): bool
    {
        return null !== $user && $user->isActive() && ($user->isAdmin() || $user->isMarketer());
    }

    public function canUpload(?User $user): bool
    {
        return $this->canManage($user) || (null !== $user && $user->isActive() && $user->isAssistant());
    }

    /** Загружает ли пользователь через модерацию (помощник маркетолога). */
    public function needsModeration(?User $user): bool
    {
        return $this->canUpload($user) && !$this->canManage($user);
    }

    public function canView(?User $user, PriceList $list): bool
    {
        if ($this->canUpload($user)) {
            return true;
        }
        if ($list->isArchived() || null === $list->getCurrentRevision()) {
            return false;
        }

        return $list->isPublic() || (null !== $user && $user->isActive());
    }

    /** Исходный файл редакции: тем, кто ведёт прайс-листы, остальным — если это разрешено в настройках прайс-листа. */
    public function canDownloadOriginal(?User $user, PriceRevision $revision): bool
    {
        if (!$this->canViewRevision($user, $revision)) {
            return false;
        }

        return $this->canUpload($user) || $revision->getPriceList()->isOriginalDownload();
    }

    /** Черновики, редакции на модерации и отклонённые видят загрузивший и те, кто ведёт прайс-листы. */
    public function canViewRevision(?User $user, PriceRevision $revision): bool
    {
        if ($revision->isPublished()) {
            return $this->canView($user, $revision->getPriceList());
        }

        return $this->canManage($user) || (null !== $user && $revision->getUploadedBy()?->getId() === $user->getId());
    }
}
