<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Document;
use App\Entity\DocumentApproval;
use App\Entity\User;
use App\Repository\DocumentApprovalRepository;
use App\Repository\UserRepository;
use App\Security\Voter\PortalVoter;
use App\Service\ApprovalService;
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
                    $this->addFlash('success', \sprintf('Документ отправлен на согласование: %s (редакция %d). Письмо отправлено.', $approval->getApprover()->getDisplayName(), $approval->getVersionNumber()));

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
            'version' => $document->getCurrentVersion()?->getNumber() ?? 0,
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
        if ($approval->getApprover()->getId() !== $user->getId() && !$user->isAdmin()) {
            throw $this->createAccessDeniedException('Решение принимает назначенный согласующий.');
        }
        $approved = 'approve' === $request->request->get('decision');
        try {
            $this->service->decide($approval, $approved, (string) $request->request->get('note'), $user);
            $this->addFlash('success', $approved
                ? ($this->service->isAutoPublish() ? 'Документ согласован и опубликован.' : 'Документ согласован — модератор может публиковать.')
                : 'Документ отклонён, автор получил комментарий.');
        } catch (\DomainException $e) {
            $this->addFlash('danger', $e->getMessage());
        }

        return $this->redirectToRoute($request->request->has('from_list') ? 'app_profile_approvals' : 'app_document_show', $request->request->has('from_list') ? [] : ['id' => $approval->getDocument()->getId()]);
    }

    /** Отзыв запроса автором или администратором. */
    #[Route('/approvals/{id}/cancel', name: 'app_approval_cancel', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function cancel(DocumentApproval $approval, Request $request, #[CurrentUser] User $user): Response
    {
        if (!$this->isCsrfTokenValid('approval_decide_'.$approval->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Неверный CSRF-токен.');
        }
        $this->denyAccessUnlessGranted(PortalVoter::DOCUMENT_EDIT, $approval->getDocument());
        $this->service->cancel($approval, $user);
        $this->addFlash('info', 'Запрос согласования отозван.');

        return $this->redirectToRoute('app_document_show', ['id' => $approval->getDocument()->getId()]);
    }

    /** «Мне на согласование»: задачи сотрудника и история его решений. */
    #[Route('/profile/approvals', name: 'app_profile_approvals', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function mine(#[CurrentUser] User $user): Response
    {
        return $this->render('profile/approvals.html.twig', [
            'pending' => $this->approvals->findPendingForUser($user),
            'decided' => $this->approvals->findDecidedByUser($user),
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
