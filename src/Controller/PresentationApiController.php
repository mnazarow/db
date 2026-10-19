<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Presentation;
use App\Entity\PresentationComment;
use App\Entity\PresentationVersion;
use App\Entity\User;
use App\Repository\PresentationAssetRepository;
use App\Repository\PresentationCommentRepository;
use App\Repository\PresentationVersionRepository;
use App\Security\Voter\PresentationVoter;
use App\Service\Presentation\AssetStore;
use App\Service\Presentation\ConflictException;
use App\Service\Presentation\DeckValidator;
use App\Service\Presentation\PptxImporter;
use App\Service\Presentation\PresentationService;
use App\Service\Presentation\QrImage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Запросы редактора слайдов (JSON): загрузка и сохранение слайдов, изображения, импорт из PowerPoint,
 * замечания к слайдам, история версий; выдача изображений и QR-кодов. Изменения — с CSRF-токеном
 * в заголовке X-CSRF-Token.
 */
#[Route('/presentations')]
#[IsGranted('ROLE_USER')]
final class PresentationApiController extends AbstractController
{
    public function __construct(
        private readonly PresentationService $service,
        private readonly AssetStore $assets,
        private readonly PresentationAssetRepository $assetRepository,
        private readonly PresentationCommentRepository $comments,
        private readonly PresentationVersionRepository $versions,
        private readonly PptxImporter $importer,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('/{id}/api/data', name: 'app_presentation_api_data', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function data(Presentation $presentation, #[CurrentUser] User $user): JsonResponse
    {
        $this->denyAccessUnlessGranted(PresentationVoter::VIEW, $presentation);

        return $this->json([
            'deck' => $this->service->deckFor($presentation, $user),
            'revision' => $presentation->getRevision(),
            'status' => $presentation->getStatus(),
            'updatedAt' => $presentation->getUpdatedAt()->format(\DATE_ATOM),
            'updatedBy' => $presentation->getUpdatedBy()?->getDisplayName(),
        ]);
    }

    #[Route('/{id}/api/save', name: 'app_presentation_api_save', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function save(Request $request, Presentation $presentation, #[CurrentUser] User $user): JsonResponse
    {
        $this->checkToken($request, $presentation);
        if (!$this->isGranted(PresentationVoter::EDIT, $presentation)) {
            return $this->json(['error' => $presentation->isSubmitted() ? 'Презентация на модерации — изменения не сохраняются. Отзовите её с модерации, чтобы править.' : 'Нет прав на изменение этой презентации.'], 403);
        }
        if (\strlen($request->getContent()) > DeckValidator::MAX_JSON_BYTES + 65536) {
            return $this->json(['error' => 'Слишком большая презентация (больше 8 МБ данных слайдов).'], 413);
        }
        $body = json_decode($request->getContent(), true);
        if (!\is_array($body) || !\is_array($body['deck'] ?? null)) {
            return $this->json(['error' => 'Слайды не прочитаны.'], 400);
        }
        $base = isset($body['revision']) && is_numeric($body['revision']) ? (int) $body['revision'] : null;
        try {
            $this->service->save($presentation, $body['deck'], $base, $user, true === ($body['force'] ?? false));
        } catch (ConflictException) {
            return $this->json([
                'conflict' => true,
                'error' => 'Презентацию уже изменили в другом окне или другой сотрудник.',
                'revision' => $presentation->getRevision(),
                'updatedBy' => $presentation->getUpdatedBy()?->getDisplayName(),
                'updatedAt' => $presentation->getUpdatedAt()->format(\DATE_ATOM),
            ], 409);
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => $e->getMessage()], 422);
        }

        return $this->json([
            'ok' => true,
            'revision' => $presentation->getRevision(),
            'status' => $presentation->getStatus(),
            'statusLabel' => $presentation->getStatusLabel(),
            'title' => $presentation->getTitle(),
            'slides' => $presentation->getSlideCount(),
            'savedAt' => $presentation->getUpdatedAt()->format(\DATE_ATOM),
        ]);
    }

    #[Route('/{id}/api/upload', name: 'app_presentation_api_upload', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function upload(Request $request, Presentation $presentation, #[CurrentUser] User $user): JsonResponse
    {
        $this->checkToken($request, $presentation);
        $this->denyAccessUnlessGranted(PresentationVoter::EDIT, $presentation);
        $file = $request->files->get('file');
        if (!$file instanceof UploadedFile || !$file->isValid()) {
            return $this->json(['error' => 'Файл не получен'.($file instanceof UploadedFile ? ': '.$file->getErrorMessage() : '.')], 400);
        }
        try {
            $asset = $this->assets->store($file->getPathname(), $file->getClientOriginalName(), $presentation, $user);
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => $e->getMessage()], 422);
        }

        return $this->json([
            'src' => 'asset:'.$asset->getToken(),
            'url' => $this->generateUrl('app_presentation_asset', ['token' => $asset->getToken(), 'ext' => $asset->getExtension()]),
            'width' => $asset->getWidth(),
            'height' => $asset->getHeight(),
            'name' => $asset->getOriginalName(),
        ]);
    }

    /** Импорт слайдов из PowerPoint: добавить в конец или заменить все слайды. */
    #[Route('/{id}/api/import', name: 'app_presentation_api_import', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function import(Request $request, Presentation $presentation, #[CurrentUser] User $user): JsonResponse
    {
        $this->checkToken($request, $presentation);
        $this->denyAccessUnlessGranted(PresentationVoter::EDIT, $presentation);
        $file = $request->files->get('file');
        if (!$file instanceof UploadedFile || !$file->isValid()) {
            return $this->json(['error' => 'Файл не получен'.($file instanceof UploadedFile ? ': '.$file->getErrorMessage() : ' — возможно, он больше допустимого размера загрузки.')], 400);
        }
        try {
            $slides = $this->importer->import($file->getPathname(), $presentation, $user);
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            return $this->json(['error' => $e->getMessage()], 422);
        }
        $deck = $this->service->deck($presentation);
        $this->service->snapshotBeforeImport($presentation, $user);
        $replace = 'replace' === $request->request->get('mode');
        $deck['slides'] = $replace ? $slides['slides'] : array_merge($deck['slides'] ?? [], $slides['slides']);
        try {
            $this->service->save($presentation, $deck, null, $user, true);
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => $e->getMessage()], 422);
        }

        return $this->json([
            'deck' => $this->service->deck($presentation),
            'revision' => $presentation->getRevision(),
            'imported' => \count($slides['slides']),
            'warnings' => $slides['warnings'],
        ]);
    }

    #[Route('/assets/{token}.{ext}', name: 'app_presentation_asset', requirements: ['token' => '[a-f0-9]{24}|_T_', 'ext' => 'png|jpg|gif|img'], methods: ['GET'])]
    public function asset(string $token): Response
    {
        $asset = $this->assetRepository->findOneByToken($token);
        if (null === $asset) {
            throw $this->createNotFoundException();
        }
        $path = $this->assets->path($asset);
        if (!is_file($path)) {
            throw $this->createNotFoundException();
        }
        $response = new BinaryFileResponse($path);
        $response->headers->set('Content-Type', $asset->getMime());
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Content-Security-Policy', "default-src 'none'");
        $response->setPrivate();
        $response->setMaxAge(31536000);
        $response->headers->addCacheControlDirective('immutable');

        return $response;
    }

    #[Route('/qr.svg', name: 'app_presentation_qr', methods: ['GET'])]
    public function qr(Request $request): Response
    {
        $text = mb_substr((string) $request->query->get('text', ''), 0, 200);
        $color = DeckValidator::cssColor((string) $request->query->get('color', '#0F4382')) ?? '#0F4382';
        $bg = DeckValidator::cssColor((string) $request->query->get('bg', '#FFFFFF')) ?? '#FFFFFF';
        try {
            $svg = QrImage::svg($text, $color, $bg);
        } catch (\InvalidArgumentException) {
            $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><rect width="10" height="10" fill="#FCE9EC"/><path d="M3 3l4 4M7 3l-4 4" stroke="#D00024"/></svg>';
        }
        $response = new Response($svg, 200, ['Content-Type' => 'image/svg+xml', 'X-Content-Type-Options' => 'nosniff', 'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'"]);
        $response->setPrivate();
        $response->setMaxAge(86400);

        return $response;
    }

    // ---- Замечания -----------------------------------------------------------------------------

    #[Route('/{id}/api/comments', name: 'app_presentation_api_comments', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function comments(Request $request, Presentation $presentation, #[CurrentUser] User $user): JsonResponse
    {
        $this->denyAccessUnlessGranted(PresentationVoter::COMMENT, $presentation);
        if ($request->isMethod('POST')) {
            $this->checkToken($request, $presentation);
            $body = json_decode($request->getContent(), true);
            $text = trim((string) (\is_array($body) ? ($body['text'] ?? '') : ''));
            if ('' === $text) {
                return $this->json(['error' => 'Напишите замечание.'], 422);
            }
            $parent = null;
            if (\is_array($body) && isset($body['parentId'])) {
                $parent = $this->comments->find((int) $body['parentId']);
                if (null === $parent || $parent->getPresentation()->getId() !== $presentation->getId()) {
                    return $this->json(['error' => 'Замечание не найдено.'], 404);
                }
                $parent = $parent->getParent() ?? $parent;
            }
            $comment = new PresentationComment($presentation, $user, $text, \is_array($body) ? ($body['slideId'] ?? null) : null, \is_array($body) ? ($body['elementId'] ?? null) : null, $parent);
            if (null !== $parent && $parent->isResolved()) {
                $parent->reopen();
            }
            $this->em->persist($comment);
            $this->em->flush();
        }

        return $this->json(['comments' => array_map(fn (PresentationComment $c): array => $this->commentJson($c, $user), $this->comments->findForPresentation($presentation))]);
    }

    #[Route('/api/comments/{comment}', name: 'app_presentation_api_comment_update', requirements: ['comment' => '\d+'], methods: ['POST'])]
    public function commentUpdate(Request $request, PresentationComment $comment, #[CurrentUser] User $user): JsonResponse
    {
        $presentation = $comment->getPresentation();
        $this->checkToken($request, $presentation);
        $this->denyAccessUnlessGranted(PresentationVoter::COMMENT, $presentation);
        $body = json_decode($request->getContent(), true);
        $action = \is_array($body) ? (string) ($body['action'] ?? '') : '';
        if ('resolve' === $action) {
            $comment->resolve($user);
        } elseif ('reopen' === $action) {
            $comment->reopen();
        } elseif ('delete' === $action) {
            if ($comment->getAuthor()?->getId() !== $user->getId() && !$this->isGranted(PresentationVoter::MODERATE)) {
                return $this->json(['error' => 'Удалить можно только своё замечание.'], 403);
            }
            $this->em->remove($comment);
        } else {
            return $this->json(['error' => 'Неизвестное действие.'], 400);
        }
        $this->em->flush();

        return $this->json(['comments' => array_map(fn (PresentationComment $c): array => $this->commentJson($c, $user), $this->comments->findForPresentation($presentation))]);
    }

    /** @return array<string, mixed> */
    private function commentJson(PresentationComment $c, User $user): array
    {
        return [
            'id' => $c->getId(),
            'parentId' => $c->getParent()?->getId(),
            'slideId' => $c->getSlideId(),
            'elementId' => $c->getElementId(),
            'author' => $c->getAuthorName(),
            'mine' => $c->getAuthor()?->getId() === $user->getId(),
            'text' => $c->getText(),
            'at' => $c->getCreatedAt()->format(\DATE_ATOM),
            'resolved' => $c->isResolved(),
            'resolvedBy' => $c->getResolvedBy()?->getDisplayName(),
        ];
    }

    // ---- История версий ------------------------------------------------------------------------

    #[Route('/{id}/api/versions', name: 'app_presentation_api_versions', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function versions(Presentation $presentation): JsonResponse
    {
        $this->denyAccessUnlessGranted(PresentationVoter::WORKING, $presentation);

        return $this->json(['versions' => array_map(static fn (PresentationVersion $v): array => [
            'id' => $v->getId(),
            'revision' => $v->getRevision(),
            'kind' => $v->getKind(),
            'label' => $v->getLabel(),
            'at' => $v->getCreatedAt()->format(\DATE_ATOM),
            'by' => $v->getCreatedBy()?->getDisplayName(),
            'slides' => $v->getSlideCount(),
        ], $this->versions->findForPresentation($presentation))]);
    }

    #[Route('/{id}/api/versions/{version}', name: 'app_presentation_api_version', requirements: ['id' => '\d+', 'version' => '\d+'], methods: ['GET'])]
    public function version(Presentation $presentation, PresentationVersion $version): JsonResponse
    {
        $this->denyAccessUnlessGranted(PresentationVoter::WORKING, $presentation);
        if ($version->getPresentation()->getId() !== $presentation->getId()) {
            throw $this->createNotFoundException();
        }

        return $this->json(['deck' => DeckValidator::decode($version->getContent())]);
    }

    private function checkToken(Request $request, Presentation $presentation): void
    {
        $token = (string) ($request->headers->get('X-CSRF-Token') ?? $request->request->get('_token'));
        if (!$this->isCsrfTokenValid('presentation_api_'.$presentation->getId(), $token)) {
            throw $this->createAccessDeniedException('Неверный CSRF-токен — обновите страницу.');
        }
    }
}
