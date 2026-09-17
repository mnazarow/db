<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Document;
use App\Entity\DocumentLink;
use App\Entity\User;
use App\Security\Voter\PortalVoter;
use App\Service\DocumentLinkService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Связи между документами: «заменяет», «отменяет», «приложение к», «см. также».
 */
#[Route('/documents/{id}/links', requirements: ['id' => '\d+'])]
final class DocumentLinkController extends AbstractController
{
    public function __construct(private readonly DocumentLinkService $links)
    {
    }

    #[Route('', name: 'app_document_link_add', methods: ['POST'])]
    #[IsGranted(PortalVoter::DOCUMENT_EDIT, subject: 'document')]
    public function add(Document $document, Request $request, #[CurrentUser] User $user): Response
    {
        $this->checkToken($request, $document);
        $target = $this->links->resolve((string) $request->request->get('target'));
        if (null === $target) {
            $this->addFlash('error', 'Документ не найден. Укажите обозначение, идентификатор или точное название.');

            return $this->back($document);
        }
        try {
            $link = $this->links->link($document, $target, (string) $request->request->get('type', DocumentLink::RELATED), $user, (string) $request->request->get('note'));
            $this->addFlash('success', \sprintf('Связь добавлена: %s «%s».', $link->labelFor($document), $target->getTitle()));
            if ($request->request->getBoolean('archive_target') && !$target->isArchived() && $this->isGranted(PortalVoter::DOCUMENT_EDIT, $target)) {
                $archived = $this->links->archiveSuperseded($document, $user, $request->getClientIp());
                if ([] !== $archived) {
                    $this->addFlash('success', 'Перенесены в архив: '.implode(', ', $archived).'.');
                }
            }
        } catch (\DomainException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->back($document);
    }

    #[Route('/{link}/delete', name: 'app_document_link_delete', requirements: ['link' => '\d+'], methods: ['POST'])]
    #[IsGranted(PortalVoter::DOCUMENT_EDIT, subject: 'document')]
    public function delete(Document $document, DocumentLink $link, Request $request, #[CurrentUser] User $user): Response
    {
        $this->checkToken($request, $document);
        if ($link->getSource()->getId() !== $document->getId() && $link->getTarget()->getId() !== $document->getId()) {
            throw $this->createNotFoundException('Связь относится к другому документу.');
        }
        $this->links->unlink($link, $user);
        $this->addFlash('success', 'Связь удалена.');

        return $this->back($document);
    }

    private function back(Document $document): Response
    {
        return $this->redirectToRoute('app_document_show', ['id' => $document->getId(), '_fragment' => 'links']);
    }

    private function checkToken(Request $request, Document $document): void
    {
        if (!$this->isCsrfTokenValid('document_'.$document->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Неверный CSRF-токен.');
        }
    }
}
