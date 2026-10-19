<?php

declare(strict_types=1);

namespace App\Service\Presentation;

use App\Entity\Presentation;
use App\Entity\User;

/**
 * Права в разделах «Подготовка презентаций» и «Подготовка пятничной презентации».
 *
 * - Готовить презентации и темы собраний может любой сотрудник; свою он правит, пока она не на модерации
 *   (отправленную можно отозвать).
 * - Модерируют маркетологи и помощники маркетолога (и администраторы): видят и правят все презентации,
 *   одобряют или возвращают на доработку, ведут собрания. Свою презентацию помощник одобрить не может —
 *   её одобряет маркетолог или другой помощник; маркетолог и администратор могут.
 * - Одобренную обычную презентацию видят все сотрудники (одобренную редакцию); одобренные темы — в составе
 *   опубликованного собрания.
 */
final class PresentationAccess
{
    public function isModerator(?User $user): bool
    {
        return null !== $user && $user->isActive() && ($user->isAdmin() || $user->isMarketer() || \in_array(User::ROLE_ASSISTANT, $user->getRoles(), true));
    }

    /** Видит рабочую редакцию (черновик, замечания, историю): автор и модераторы. */
    public function canSeeWorking(?User $user, Presentation $p): bool
    {
        return null !== $user && ($p->isAuthor($user) || $this->isModerator($user));
    }

    public function canView(?User $user, Presentation $p): bool
    {
        if (null === $user) {
            return false;
        }
        if ($this->canSeeWorking($user, $p)) {
            return true;
        }
        if (!$p->hasApprovedVersion()) {
            return false;
        }

        return !$p->isTopic() || (null !== $p->getMeeting() && $p->getMeeting()->isPublished());
    }

    public function canEdit(?User $user, Presentation $p): bool
    {
        if (null === $user) {
            return false;
        }
        if ($this->isModerator($user)) {
            return true;
        }

        return $p->isAuthor($user) && !$p->isSubmitted();
    }

    public function canSubmit(?User $user, Presentation $p): bool
    {
        return null !== $user && ($p->isAuthor($user) || $this->isModerator($user)) && ($p->isDraft() || $p->isRejected()) && $p->getSlideCount() > 0;
    }

    public function canWithdraw(?User $user, Presentation $p): bool
    {
        return null !== $user && $p->isSubmitted() && ($p->isAuthor($user) || $this->isModerator($user));
    }

    /** Одобрить или вернуть на доработку: модератор, свою — только маркетолог или администратор. */
    public function canDecide(?User $user, Presentation $p): bool
    {
        if (!$p->isSubmitted() || !$this->isModerator($user)) {
            return false;
        }

        return !$p->isAuthor($user) || $user->isMarketer() || $user->isAdmin();
    }

    public function canDelete(?User $user, Presentation $p): bool
    {
        if (null === $user) {
            return false;
        }
        if ($this->isModerator($user)) {
            return true;
        }

        return $p->isAuthor($user) && !($p->isTopic() && null !== $p->getMeeting() && $p->getMeeting()->isPublished() && $p->hasApprovedVersion());
    }

    /** Замечания к слайдам пишут модераторы и автор (ответы). */
    public function canComment(?User $user, Presentation $p): bool
    {
        return $this->canSeeWorking($user, $p);
    }
}
