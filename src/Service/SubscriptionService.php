<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Document;
use App\Entity\DocumentSubscription;
use App\Entity\Section;
use App\Entity\User;
use App\Repository\DocumentSubscriptionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Подписка на изменения документа или раздела (вместе с подразделами).
 *
 * Уведомления уходят по почте и в Telegram; каналы сотрудник выбирает в профиле.
 */
final class SubscriptionService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly DocumentSubscriptionRepository $subscriptions,
        private readonly PortalSettings $settings,
        private readonly LoggerInterface $auditLogger,
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->settings->subscriptionsEnabled();
    }

    public function isSubscribedToDocument(?User $user, Document $document): bool
    {
        return null !== $user && null !== $this->subscriptions->findForDocument($user, $document);
    }

    public function isSubscribedToSection(?User $user, Section $section): bool
    {
        return null !== $user && null !== $this->subscriptions->findForSection($user, $section);
    }

    /** Подписан ли сотрудник на документ напрямую или через раздел (для подсказки в карточке). */
    public function coveringSection(?User $user, Document $document): ?Section
    {
        if (null === $user) {
            return null;
        }
        foreach (array_reverse($document->getSection()->getBreadcrumbs()) as $section) {
            if ($this->isSubscribedToSection($user, $section)) {
                return $section;
            }
        }

        return null;
    }

    /** Включает или выключает подписку на документ; возвращает новое состояние. */
    public function toggleDocument(User $user, Document $document): bool
    {
        $existing = $this->subscriptions->findForDocument($user, $document);
        if (null !== $existing) {
            $this->em->remove($existing);
            $this->em->flush();
            $this->auditLogger->info('Отписка от документа', ['document' => $document->getId(), 'user' => $user->getUsername()]);

            return false;
        }
        $this->em->persist(new DocumentSubscription($user, $document));
        $this->em->flush();
        $this->auditLogger->info('Подписка на документ', ['document' => $document->getId(), 'title' => $document->getTitle(), 'user' => $user->getUsername()]);

        return true;
    }

    /** Включает или выключает подписку на раздел; возвращает новое состояние. */
    public function toggleSection(User $user, Section $section): bool
    {
        $existing = $this->subscriptions->findForSection($user, $section);
        if (null !== $existing) {
            $this->em->remove($existing);
            $this->em->flush();
            $this->auditLogger->info('Отписка от раздела', ['section' => $section->getId(), 'user' => $user->getUsername()]);

            return false;
        }
        $this->em->persist(new DocumentSubscription($user, null, $section));
        $this->em->flush();
        $this->auditLogger->info('Подписка на раздел', ['section' => $section->getId(), 'name' => $section->getFullName(), 'user' => $user->getUsername()]);

        return true;
    }

    /** @return list<DocumentSubscription> */
    public function forUser(User $user): array
    {
        return $this->subscriptions->findForUser($user);
    }

    public function remove(DocumentSubscription $subscription, User $by): void
    {
        $this->em->remove($subscription);
        $this->em->flush();
        $this->auditLogger->info('Отписка от изменений', ['subscription' => $subscription->getTitle(), 'user' => $by->getUsername()]);
    }
}
