<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Document;
use App\Entity\DocumentApproval;
use App\Entity\User;
use App\Repository\DocumentApprovalRepository;
use App\Repository\PresentationRepository;
use App\Repository\UserRepository;
use App\Security\Access;
use App\Security\Voter\PortalVoter;
use App\Service\ApprovalService;
use App\Service\Presentation\PresentationAccess;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Согласование документов перед публикацией: отправка, решение согласующего, свои задачи и сводка.
 */
final class ApprovalController extends AbstractController
{
    public function __construct(
        private readonly ApprovalService $service,
        private readonly DocumentApprovalRepository $approvals,
        private readonly UserRepository $users,
        private readonly Access $access,
    ) {
    }

    /** Отправка документа на согласование (модератор раздела). */
    #[Route('/documents/{id}/approval', name: 'app_document_approval_request', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    #[IsGranted(PortalVoter::DOCUMENT_EDIT, subject: 'document')]
    public function request(Document $document, Request $request, #[CurrentUser] User $user): Response
    {
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('approval_'.$document->getId(), (string) $request->request->get('_token'))) {
                throw $this->createAccessDeniedException('Неверный CSRF-токен.');
            }
            $approver = $this->users->find((int) $request->request->get('approver'));
            if (null === $approver) {
                $this->addFlash('warning', 'Выберите согласующего.');
            } else {
                try {
                    $approval = $this->service->request($document, $approver, (string) $request->request->get('note'), $user);
                    $this->addFlash('success', \sprintf('Документ отправлен на согласование: %s (редакция %d). Письмо отправлено.', $approval->getApproverLabel(), $approval->getVersionNumber()));

                    return $this->redirectToRoute('app_document_show', ['id' => $document->getId()]);
                } catch (\DomainException $e) {
                    $this->addFlash('danger', $e->getMessage());
                }
            }
        }

        return $this->render('document/approval_request.html.twig', [
            'document' => $document,
            'by_department' => $this->service->candidatesByDepartment($user),
            'history' => $this->approvals->findForDocument($document),
            'version' => $this->service->versionToApprove($document)?->getNumber() ?? 0,
            // Опубликованный документ: согласуется новая редакция, сотрудники до решения видят текущую.
            'new_edition' => $document->isPublished() && null !== $document->getPendingVersion(),
            'current_number' => $document->getCurrentVersion()?->getNumber() ?? 0,
            'approval_auto_publish' => $this->service->isAutoPublish(),
        ]);
    }

    /** Решение согласующего: согласовать или отклонить с комментарием. */
    #[Route('/approvals/{id}/decide', name: 'app_approval_decide', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function decide(DocumentApproval $approval, Request $request, #[CurrentUser] User $user): Response
    {
        if (!$this->isCsrfTokenValid('approval_decide_'.$approval->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Неверный CSRF-токен.');
        }
        if (!$approval->isPending()) {
            $this->addFlash('info', 'Решение по этому запросу уже принято'.(null !== $approval->getDecidedBy() ? ': '.$approval->getDeciderName() : '').'.');

            return $this->redirectToRoute('app_document_show', ['id' => $approval->getDocument()->getId()]);
        }
        if (!$this->service->canDecide($approval, $user)) {
            throw $this->createAccessDeniedException($approval->isModeration() ? 'Решение по материалу на модерации принимает маркетолог.' : 'Решение принимает назначенный согласующий.');
        }
        $approved = 'approve' === $request->request->get('decision');
        $note = trim((string) $request->request->get('note'));
        if (!$approved && $approval->isModeration() && '' === $note) {
            $this->addFlash('warning', 'Напишите, что нужно исправить: комментарий получит автор материала.');

            return $this->redirectToRoute($request->request->has('from_list') ? 'app_moderation' : 'app_document_show', $request->request->has('from_list') ? [] : ['id' => $approval->getDocument()->getId()]);
        }
        try {
            $this->service->decide($approval, $approved, $note, $user);
            if ($approval->isModeration()) {
                $this->addFlash('success', $approved
                    ? \sprintf('Материал «%s» одобрен и опубликован.', $approval->getDocument()->getTitle())
                    : \sprintf('Материал «%s» возвращён на доработку, автор получил комментарий.', $approval->getDocument()->getTitle()));
            } else {
                $this->addFlash('success', $approved
                    ? ($this->service->isAutoPublish() ? 'Редакция согласована и вступила в силу.' : 'Редакция согласована — модератор может её публиковать.')
                    : 'Редакция отклонена, автор получил комментарий.');
            }
        } catch (\DomainException $e) {
            $this->addFlash('danger', $e->getMessage());
        }

        if ($request->request->has('from_list')) {
            return $this->redirectToRoute($approval->isModeration() ? 'app_moderation' : 'app_profile_approvals');
        }

        return $this->redirectToRoute('app_document_show', ['id' => $approval->getDocument()->getId()]);
    }

    /** Отзыв запроса автором или администратором. */
    #[Route('/approvals/{id}/cancel', name: 'app_approval_cancel', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function cancel(DocumentApproval $approval, Request $request, #[CurrentUser] User $user): Response
    {
        if (!$this->isCsrfTokenValid('approval_decide_'.$approval->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Неверный CSRF-токен.');
        }
        // Отозвать может модератор раздела, а материал с модерации — и отправивший его помощник.
        if ($approval->getRequestedBy()?->getId() !== $user->getId()) {
            $this->denyAccessUnlessGranted(PortalVoter::DOCUMENT_EDIT, $approval->getDocument());
        }
        $this->service->cancel($approval, $user);
        $this->addFlash('info', $approval->isModeration() ? 'Материал снят с модерации и возвращён в черновики.' : 'Запрос согласования отозван.');

        return $this->redirectToRoute('app_document_show', ['id' => $approval->getDocument()->getId()]);
    }

    /** «Мне на согласование»: задачи сотрудника, отправленное им и история его решений. */
    #[Route('/profile/approvals', name: 'app_profile_approvals', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function mine(#[CurrentUser] User $user): Response
    {
        return $this->render('profile/approvals.html.twig', [
            'pending' => $this->approvals->findPendingForUser($user),
            'decided' => $this->approvals->findDecidedByUser($user),
            'requested' => $this->approvals->findRequestedBy($user, 30),
            'moderation_count' => $this->access->isModerator($user) ? $this->approvals->countModerationQueue($this->access->managedSectionIds($user)) : 0,
        ]);
    }

    /**
     * «На модерации»: материалы помощников маркетолога, ждущие решения, — для маркетологов, модераторов
     * разделов (по своим разделам) и администраторов; помощник видит здесь свои отправленные материалы.
     */
    #[Route('/moderation', name: 'app_moderation', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function moderation(#[CurrentUser] User $user, PresentationRepository $presentations, PresentationAccess $presentationAccess): Response
    {
        $isModerator = $this->access->isModerator($user);
        $presentationModerator = $presentationAccess->isModerator($user);
        if (!$isModerator && !$this->access->isAssistant($user) && !$presentationModerator) {
            throw $this->createAccessDeniedException('Страница модерации доступна маркетологам и их помощникам.');
        }

        return $this->render('moderation/index.html.twig', [
            'is_moderator' => $isModerator,
            // Презентации и темы пятничных собраний: модерируют маркетологи, помощники и администраторы.
            'presentation_moderator' => $presentationModerator,
            'presentation_queue' => $presentationModerator ? $presentations->findModerationQueue() : [],
            'queue' => $isModerator ? $this->approvals->findModerationQueue($this->access->managedSectionIds($user)) : [],
            'requested' => $this->approvals->findRequestedBy($user, 50),
            'decided' => $isModerator ? $this->approvals->findDecidedByUser($user, 30) : [],
        ]);
    }

    /** Сводка по всем незакрытым согласованиям (администратор). */
    #[Route('/admin/approvals', name: 'admin_approvals', methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    public function overview(): Response
    {
        return $this->render('admin/approvals/index.html.twig', [
            'pending' => $this->approvals->findAllPending(),
        ]);
    }
}
