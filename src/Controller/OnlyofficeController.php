<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Document;
use App\Entity\User;
use App\Security\Voter\PortalVoter;
use App\Service\DocumentManager;
use App\Service\Onlyoffice\OnlyofficeService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Редактор ONLYOFFICE / Р7-Офис в портале: страница с редактором, выдача файла серверу документов
 * по подписанной ссылке и приём уведомлений о сохранении (обе — без входа в портал, с проверкой подписи).
 */
final class OnlyofficeController extends AbstractController
{
    private const TOKEN = '[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+';

    public function __construct(
        private readonly OnlyofficeService $onlyoffice,
        private readonly DocumentManager $manager,
    ) {
    }

    /** Страница редактора: ?mode=edit — правка последней редакции, иначе просмотр действующей. */
    #[Route('/documents/{id}/office', name: 'app_document_office', requirements: ['id' => '\d+'], methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    #[IsGranted(PortalVoter::DOCUMENT_VIEW, subject: 'document')]
    public function editor(Document $document, Request $request, #[CurrentUser] User $user): Response
    {
        if (!$this->onlyoffice->isEnabled()) {
            throw $this->createNotFoundException('Редактор документов не подключён.');
        }
        $edit = 'edit' === $request->query->get('mode');
        if ($edit) {
            $reason = $this->onlyoffice->editBlockedReason($user, $document);
            if (null !== $reason) {
                if ('недоступно' === $reason) {
                    throw $this->createAccessDeniedException('Редактировать документ онлайн нельзя.');
                }
                $this->addFlash('warning', $reason);

                return $this->redirectToRoute('app_document_show', ['id' => $document->getId()]);
            }
            $version = $this->onlyoffice->editableVersion($document);
        } else {
            $version = $this->onlyoffice->viewableVersion($user, $document);
        }
        if (null === $version) {
            throw $this->createNotFoundException('Этот файл нельзя открыть в редакторе.');
        }
        $this->manager->recordView($document, $user, $request->getClientIp());
        $response = $this->render('document/office.html.twig', [
            'document' => $document,
            'version' => $version,
            'edit' => $edit,
            'api_url' => $this->onlyoffice->apiUrl(),
            'config' => $this->onlyoffice->editorConfig($document, $version, $user, $edit),
            'pending' => $edit && !$document->isVersionReleased($version),
        ]);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }

    /** Файл для сервера документов (ссылка подписана ключом портала и действует сутки). */
    #[Route('/onlyoffice/file/{token}', name: 'onlyoffice_file', requirements: ['token' => self::TOKEN], methods: ['GET'])]
    public function serveFile(string $token): Response
    {
        $file = $this->onlyoffice->resolveFile($token);
        if (null === $file) {
            throw $this->createNotFoundException();
        }
        $response = new BinaryFileResponse($file['path'], Response::HTTP_OK, [
            'Content-Type' => $file['version']->getMimeType() ?: 'application/octet-stream',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
        $name = (string) $file['version']->getOriginalName();
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $name, 'document.'.($file['version']->getExtension() ?: 'bin'));

        return $response;
    }

    /** Уведомления сервера документов; ответ всегда JSON {"error": 0|1}. */
    #[Route('/onlyoffice/callback/{token}', name: 'onlyoffice_callback', requirements: ['token' => self::TOKEN], methods: ['POST'])]
    public function callback(string $token, Request $request): JsonResponse
    {
        @set_time_limit(170);

        return new JsonResponse($this->onlyoffice->handleCallback($token, $request->getContent(), $request->headers->get($this->onlyoffice->tokenHeader())));
    }
}
