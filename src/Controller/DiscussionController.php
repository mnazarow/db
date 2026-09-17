<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Document;
use App\Entity\DocumentComment;
use App\Entity\DocumentSubscription;
use App\Entity\Section;
use App\Entity\User;
use App\Repository\DocumentCommentRepository;
use App\Security\Access;
use App\Security\Voter\PortalVoter;
use App\Service\CommentService;
use App\Service\Notification\TelegramNotifier;
use App\Service\SubscriptionService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Обсуждение документов, подписка на изменения и каналы уведомлений сотрудника.
 */
final class DiscussionController extends AbstractController
{
    public function __construct(
        private readonly CommentService $comments,
        private readonly SubscriptionService $subscriptions,
        private readonly DocumentCommentRepository $repository,
        private readonly Access $access,
        private readonly TelegramNotifier $telegram,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /** Отправка реплики в обсуждение документа. */
    #[Route('/documents/{id}/comments', name: 'app_document_comment', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted(PortalVoter::DOCUMENT_VIEW, subject: 'document')]
    public function comment(Document $document, Request $request, #[CurrentUser] User $user): Response
    {
        $this->checkToken($request, $document);
        $parent = null;
        $parentId = (int) $request->request->get('parent', 0);
        if ($parentId > 0) {
            $parent = $this->repository->find($parentId);
            if (null === $parent || $parent->getDocument()->getId() !== $document->getId()) {
                throw $this->createNotFoundException('Реплика не найдена.');
            }
        }
        try {
            $comment = $this->comments->add($document, $user, (string) $request->request->get('body'), $parent);
            $this->addFlash('success', 'Сообщение добавлено в обсуждение.');
        } catch (\DomainException $e) {
            $this->addFlash('error', $e->getMessage());

            return $this->redirectToRoute('app_document_show', ['id' => $document->getId()]);
        }

        return $this->redirectToRoute('app_document_show', ['id' => $document->getId(), '_fragment' => 'comment-'.$comment->getId()]);
    }

    /** Правка своей реплики (в течение часа после отправки). */
    #[Route('/documents/{id}/comments/{comment}/edit', name: 'app_document_comment_edit', requirements: ['id' => '\d+', 'comment' => '\d+'], methods: ['POST'])]
    #[IsGranted(PortalVoter::DOCUMENT_VIEW, subject: 'document')]
    public function edit(Document $document, DocumentComment $comment, Request $request, #[CurrentUser] User $user): Response
    {
        $this->checkToken($request, $document);
        $this->checkComment($document, $comment);
        if (!$this->comments->canEdit($comment, $user)) {
            throw $this->createAccessDeniedException('Править можно только свою реплику и только в течение часа.');
        }
        try {
            $this->comments->edit($comment, (string) $request->request->get('body'), $user);
            $this->addFlash('success', 'Сообщение изменено.');
        } catch (\DomainException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('app_document_show', ['id' => $document->getId(), '_fragment' => 'comment-'.$comment->getId()]);
    }

    /** Удаление реплики: автором или модератором раздела. */
    #[Route('/documents/{id}/comments/{comment}/delete', name: 'app_document_comment_delete', requirements: ['id' => '\d+', 'comment' => '\d+'], methods: ['POST'])]
    #[IsGranted(PortalVoter::DOCUMENT_VIEW, subject: 'document')]
    public function delete(Document $document, DocumentComment $comment, Request $request, #[CurrentUser] User $user): Response
    {
        $this->checkToken($request, $document);
        $this->checkComment($document, $comment);
        if (!$this->comments->canDelete($comment, $user, $this->access->canEditDocument($user, $document))) {
            throw $this->createAccessDeniedException('Удалить реплику может её автор или модератор раздела.');
        }
        $this->comments->delete($comment, $user);
        $this->addFlash('success', 'Сообщение удалено.');

        return $this->redirectToRoute('app_document_show', ['id' => $document->getId()]);
    }

    /** Подписка (и отписка) на изменения документа. */
    #[Route('/documents/{id}/subscribe', name: 'app_document_subscribe', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted(PortalVoter::DOCUMENT_VIEW, subject: 'document')]
    public function subscribeDocument(Document $document, Request $request, #[CurrentUser] User $user): Response
    {
        $this->checkToken($request, $document);
        if (!$this->subscriptions->isEnabled()) {
            throw $this->createNotFoundException('Подписка на изменения выключена администратором.');
        }
        $on = $this->subscriptions->toggleDocument($user, $document);
        $this->addFlash('success', $on ? 'Вы подписаны на изменения документа.' : 'Подписка на документ отключена.');

        return $this->redirectToRoute('app_document_show', ['id' => $document->getId()]);
    }

    /** Подписка (и отписка) на изменения раздела вместе с подразделами. */
    #[Route('/sections/{id}/subscribe', name: 'app_section_subscribe', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted(PortalVoter::SECTION_VIEW, subject: 'section')]
    public function subscribeSection(Section $section, Request $request, #[CurrentUser] User $user): Response
    {
        if (!$this->isCsrfTokenValid('section_'.$section->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Неверный CSRF-токен.');
        }
        if (!$this->subscriptions->isEnabled()) {
            throw $this->createNotFoundException('Подписка на изменения выключена администратором.');
        }
        $on = $this->subscriptions->toggleSection($user, $section);
        $this->addFlash('success', $on ? 'Вы подписаны на изменения раздела и его подразделов.' : 'Подписка на раздел отключена.');

        return $this->redirectToRoute('app_section_show', ['id' => $section->getId()]);
    }

    /** Профиль: подписки, каналы уведомлений и привязка Telegram. */
    #[Route('/profile/subscriptions', name: 'app_profile_subscriptions', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function profile(#[CurrentUser] User $user): Response
    {
        return $this->render('profile/subscriptions.html.twig', [
            'subscriptions' => $this->subscriptions->forUser($user),
            'subscriptions_enabled' => $this->subscriptions->isEnabled(),
            'comments' => $this->repository->findRecentForUser($user, 10),
            'telegram_enabled' => $this->telegram->isEnabled(),
            'telegram_code' => TelegramNotifier::codeIsFresh($user) ? $user->getTelegramCode() : null,
            'telegram_link' => TelegramNotifier::codeIsFresh($user) ? $this->telegram->linkUrl((string) $user->getTelegramCode()) : null,
            'code_ttl' => TelegramNotifier::CODE_TTL_MINUTES,
        ]);
    }

    /** Отписка из списка в профиле. */
    #[Route('/profile/subscriptions/{id}/remove', name: 'app_profile_subscription_remove', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function removeSubscription(DocumentSubscription $subscription, Request $request, #[CurrentUser] User $user): Response
    {
        if (!$this->isCsrfTokenValid('subscriptions', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Неверный CSRF-токен.');
        }
        if ($subscription->getUser()->getId() !== $user->getId()) {
            throw $this->createAccessDeniedException('Это чужая подписка.');
        }
        $this->subscriptions->remove($subscription, $user);
        $this->addFlash('success', 'Подписка удалена.');

        return $this->redirectToRoute('app_profile_subscriptions');
    }

    /** Каналы уведомлений: почта и Telegram. */
    #[Route('/profile/notifications', name: 'app_profile_notifications', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function channels(Request $request, #[CurrentUser] User $user): Response
    {
        if (!$this->isCsrfTokenValid('subscriptions', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Неверный CSRF-токен.');
        }
        $user->setNotifyEmail($request->request->getBoolean('notify_email'))
            ->setNotifyTelegram($request->request->getBoolean('notify_telegram'));
        $this->em->flush();
        $this->addFlash('success', 'Настройки уведомлений сохранены.');

        return $this->redirectToRoute('app_profile_subscriptions');
    }

    /** Привязка и отвязка Telegram. */
    #[Route('/profile/telegram', name: 'app_profile_telegram', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function telegram(Request $request, #[CurrentUser] User $user): Response
    {
        if (!$this->isCsrfTokenValid('subscriptions', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Неверный CSRF-токен.');
        }
        if ($request->request->has('unlink')) {
            $user->unlinkTelegram();
            $this->em->flush();
            $this->addFlash('success', 'Telegram отвязан.');

            return $this->redirectToRoute('app_profile_subscriptions');
        }
        if (!$this->telegram->isEnabled()) {
            $this->addFlash('error', 'Уведомления в Telegram выключены администратором.');

            return $this->redirectToRoute('app_profile_subscriptions');
        }
        $code = $this->telegram->issueCode($user);
        $this->addFlash('success', \sprintf('Код привязки: %s. Отправьте его боту портала в течение %d минут.', $code, TelegramNotifier::CODE_TTL_MINUTES));

        return $this->redirectToRoute('app_profile_subscriptions');
    }

    private function checkToken(Request $request, Document $document): void
    {
        if (!$this->isCsrfTokenValid('document_'.$document->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Неверный CSRF-токен.');
        }
    }

    private function checkComment(Document $document, DocumentComment $comment): void
    {
        if ($comment->getDocument()->getId() !== $document->getId()) {
            throw $this->createNotFoundException('Реплика не найдена.');
        }
    }
}
