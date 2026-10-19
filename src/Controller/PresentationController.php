<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Presentation;
use App\Entity\PresentationVersion;
use App\Entity\User;
use App\Repository\PresentationCommentRepository;
use App\Repository\PresentationRepository;
use App\Security\Voter\PresentationVoter;
use App\Service\Presentation\BrandAssets;
use App\Service\Presentation\DeckValidator;
use App\Service\Presentation\PptxImporter;
use App\Service\Presentation\PptxWriter;
use App\Service\Presentation\PresentationAccess;
use App\Service\Presentation\PresentationService;
use App\Service\Presentation\SlideLayouts;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Раздел «Подготовка презентаций»: свои презентации, очередь модерации, готовые (одобренные) презентации;
 * просмотр, редактор слайдов, показ, выгрузка в PowerPoint и PDF; отправка на модерацию и решение.
 */
#[Route('/presentations')]
#[IsGranted('ROLE_USER')]
final class PresentationController extends AbstractController
{
    public function __construct(
        private readonly PresentationRepository $presentations,
        private readonly PresentationService $service,
        private readonly PresentationAccess $access,
        private readonly PptxWriter $pptx,
        private readonly PresentationCommentRepository $comments,
        private readonly EntityManagerInterface $em,
        private readonly CsrfTokenManagerInterface $csrf,
        private readonly string $appCompany,
        private readonly ?PptxImporter $importer = null,
    ) {
    }

    #[Route('', name: 'app_presentations', methods: ['GET'])]
    public function index(Request $request, #[CurrentUser] User $user): Response
    {
        $q = trim((string) $request->query->get('q', ''));
        $moderator = $this->access->isModerator($user);

        return $this->render('presentation/index.html.twig', [
            'mine' => $this->presentations->findByAuthor($user, Presentation::KIND_GENERAL),
            'queue' => $moderator ? $this->presentations->findModerationQueue(Presentation::KIND_GENERAL) : [],
            'approved' => $this->presentations->findApprovedGeneral($q),
            'q' => $q,
            'is_moderator' => $moderator,
        ]);
    }

    #[Route('/new', name: 'app_presentation_new', methods: ['POST'])]
    public function create(Request $request, #[CurrentUser] User $user): Response
    {
        if (!$this->isCsrfTokenValid('presentation_new', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Неверный CSRF-токен.');
        }
        $title = trim((string) $request->request->get('title', ''));
        if ('' === $title) {
            $this->addFlash('danger', 'Укажите название презентации.');

            return $this->redirectToRoute('app_presentations');
        }
        $p = $this->service->create(Presentation::KIND_GENERAL, $title, trim((string) $request->request->get('responsible', '')) ?: null, $user);
        $p->setDescription((string) $request->request->get('description', ''));
        $this->em->flush();
        $file = $request->files->get('pptx');
        if ($file instanceof UploadedFile && null !== $this->importer) {
            $this->importInto($p, $file, $user);
        }

        return $this->redirectToRoute('app_presentation_edit', ['id' => $p->getId()]);
    }

    /** Слайды из PowerPoint вместо слайдов по умолчанию (при создании презентации или темы из файла). */
    public function importInto(Presentation $p, UploadedFile $file, User $user): void
    {
        if (!$file->isValid()) {
            $this->addFlash('danger', 'Файл PowerPoint не загружен: '.$file->getErrorMessage());

            return;
        }
        try {
            $result = $this->importer?->import($file->getPathname(), $p, $user) ?? ['slides' => [], 'warnings' => []];
            $deck = $this->service->deck($p);
            $deck['slides'] = $result['slides'];
            $this->service->save($p, $deck, $p->getRevision(), $user, true);
            $this->addFlash('success', \sprintf('Перенесено слайдов из PowerPoint: %d.', \count($result['slides'])).([] !== $result['warnings'] ? ' '.implode(' ', $result['warnings']) : ''));
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            $this->addFlash('danger', 'Слайды из файла не перенесены: '.$e->getMessage());
        }
    }

    #[Route('/{id}', name: 'app_presentation_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(Presentation $presentation, #[CurrentUser] User $user): Response
    {
        $this->denyAccessUnlessGranted(PresentationVoter::VIEW, $presentation);
        $working = $this->access->canSeeWorking($user, $presentation);

        return $this->render('presentation/show.html.twig', [
            'p' => $presentation,
            'deck' => $this->service->deckFor($presentation, $user),
            'working' => $working,
            'open_comments' => $working ? $this->comments->countOpen($presentation) : 0,
            'deck_config' => $this->viewerConfig($presentation),
        ]);
    }

    #[Route('/{id}/edit', name: 'app_presentation_edit', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function edit(Presentation $presentation, #[CurrentUser] User $user): Response
    {
        if (!$this->isGranted(PresentationVoter::EDIT, $presentation)) {
            if ($this->isGranted(PresentationVoter::VIEW, $presentation)) {
                if ($presentation->isSubmitted() && $presentation->isAuthor($user)) {
                    $this->addFlash('info', 'Презентация на модерации — чтобы изменить её, отзовите её с модерации.');
                }

                return $this->redirectToRoute('app_presentation_show', ['id' => $presentation->getId()]);
            }
            throw $this->createAccessDeniedException();
        }

        return $this->render('presentation/editor.html.twig', [
            'p' => $presentation,
            'deck' => $this->service->deck($presentation),
            'config' => $this->editorConfig($presentation, $user),
        ]);
    }

    #[Route('/{id}/present', name: 'app_presentation_present', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function present(Request $request, Presentation $presentation, #[CurrentUser] User $user): Response
    {
        $this->denyAccessUnlessGranted(PresentationVoter::VIEW, $presentation);

        return $this->render('presentation/present.html.twig', [
            'title' => $presentation->getTitle(),
            'deck' => $this->service->deckFor($presentation, $user),
            'start' => max(0, (int) $request->query->get('slide', 1) - 1),
            'back' => $this->generateUrl('app_presentation_show', ['id' => $presentation->getId()]),
            'speaker' => $request->query->getBoolean('speaker'),
        ]);
    }

    #[Route('/{id}/print', name: 'app_presentation_print', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function print(Presentation $presentation, #[CurrentUser] User $user): Response
    {
        $this->denyAccessUnlessGranted(PresentationVoter::VIEW, $presentation);

        return $this->render('presentation/print.html.twig', [
            'title' => $presentation->getTitle(),
            'deck' => $this->service->deckFor($presentation, $user),
        ]);
    }

    #[Route('/{id}/export.pptx', name: 'app_presentation_export', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function export(Request $request, Presentation $presentation, #[CurrentUser] User $user): Response
    {
        $this->denyAccessUnlessGranted(PresentationVoter::VIEW, $presentation);
        $approved = $request->query->getBoolean('approved') && $presentation->hasApprovedVersion();
        $deck = $approved ? $this->service->deck($presentation, true) : $this->service->deckFor($presentation, $user);

        return $this->pptxResponse($deck, $presentation->getTitle(), $presentation->getAuthor()?->getDisplayName() ?? '');
    }

    /** @param array<string, mixed> $deck */
    public function pptxResponse(array $deck, string $title, string $author): Response
    {
        $path = $this->pptx->write($deck, $title, $author, $this->appCompany);
        $name = self::fileName($title).'.pptx';
        $response = new BinaryFileResponse($path);
        $response->headers->set('Content-Type', 'application/vnd.openxmlformats-officedocument.presentationml.presentation');
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $name, DocumentController::asciiName($name));
        $response->deleteFileAfterSend(true);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }

    // ---- Модерация и действия ------------------------------------------------------------------

    #[Route('/{id}/submit', name: 'app_presentation_submit', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function submit(Request $request, Presentation $presentation, #[CurrentUser] User $user): Response
    {
        $this->checkCsrf($request, $presentation);
        try {
            $this->service->submit($presentation, (string) $request->request->get('note', ''), $user);
            $this->addFlash('success', $presentation->isTopic() ? 'Тема отправлена на модерацию: маркетологи получили уведомление.' : 'Презентация отправлена на модерацию: маркетологи получили уведомление.');
        } catch (\DomainException $e) {
            $this->addFlash('danger', $e->getMessage());
        }

        return $this->back($request, $presentation);
    }

    #[Route('/{id}/withdraw', name: 'app_presentation_withdraw', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function withdraw(Request $request, Presentation $presentation, #[CurrentUser] User $user): Response
    {
        $this->checkCsrf($request, $presentation);
        try {
            $this->service->withdraw($presentation, $user);
            $this->addFlash('info', 'Снято с модерации — снова черновик.');
        } catch (\DomainException $e) {
            $this->addFlash('danger', $e->getMessage());
        }

        return $this->back($request, $presentation);
    }

    #[Route('/{id}/decide', name: 'app_presentation_decide', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function decide(Request $request, Presentation $presentation, #[CurrentUser] User $user): Response
    {
        $this->checkCsrf($request, $presentation);
        $note = trim((string) $request->request->get('note', ''));
        try {
            if ('approve' === $request->request->get('decision')) {
                $this->service->approve($presentation, $user, $note);
                $this->addFlash('success', $presentation->isTopic() ? 'Тема одобрена и попадёт в презентацию собрания.' : 'Презентация одобрена — сотрудники видят её в «Готовых презентациях».');
            } else {
                $this->service->reject($presentation, $user, $note);
                $this->addFlash('info', 'Возвращено автору на доработку с вашим комментарием.');
            }
        } catch (\DomainException|\InvalidArgumentException $e) {
            $this->addFlash('danger', $e->getMessage());
        }

        return $this->back($request, $presentation);
    }

    #[Route('/{id}/revoke', name: 'app_presentation_revoke', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function revoke(Request $request, Presentation $presentation, #[CurrentUser] User $user): Response
    {
        $this->checkCsrf($request, $presentation);
        try {
            $this->service->revoke($presentation, $user);
            $this->addFlash('info', 'Одобрение снято: сотрудники и собрание больше не видят эту редакцию.');
        } catch (\DomainException $e) {
            $this->addFlash('danger', $e->getMessage());
        }

        return $this->back($request, $presentation);
    }

    #[Route('/{id}/duplicate', name: 'app_presentation_duplicate', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function duplicate(Request $request, Presentation $presentation, #[CurrentUser] User $user): Response
    {
        $this->checkCsrf($request, $presentation);
        $this->denyAccessUnlessGranted(PresentationVoter::VIEW, $presentation);
        $copy = $this->service->duplicate($presentation, $user);
        $this->addFlash('success', 'Создана копия — теперь это ваш черновик.');

        return $this->redirectToRoute('app_presentation_edit', ['id' => $copy->getId()]);
    }

    #[Route('/{id}/delete', name: 'app_presentation_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(Request $request, Presentation $presentation, #[CurrentUser] User $user): Response
    {
        $this->checkCsrf($request, $presentation);
        $meeting = $presentation->getMeeting();
        try {
            $this->service->delete($presentation, $user);
            $this->addFlash('info', 'Удалено.');
        } catch (\DomainException $e) {
            $this->addFlash('danger', $e->getMessage());

            return $this->back($request, $presentation);
        }

        return null !== $meeting ? $this->redirectToRoute('app_meeting_show', ['id' => $meeting->getId()]) : $this->redirectToRoute('app_presentations');
    }

    #[Route('/{id}/versions/{version}/restore', name: 'app_presentation_restore', requirements: ['id' => '\d+', 'version' => '\d+'], methods: ['POST'])]
    public function restore(Request $request, Presentation $presentation, PresentationVersion $version, #[CurrentUser] User $user): Response
    {
        $this->checkCsrf($request, $presentation);
        $this->denyAccessUnlessGranted(PresentationVoter::EDIT, $presentation);
        try {
            $this->service->restore($presentation, $version, $user);
            $this->addFlash('success', 'Восстановлена версия от '.$version->getCreatedAt()->format('d.m.Y H:i').' (текущая сохранена в истории).');
        } catch (\InvalidArgumentException $e) {
            $this->addFlash('danger', $e->getMessage());
        }

        return $this->redirectToRoute('app_presentation_edit', ['id' => $presentation->getId()]);
    }

    // ---- Помощники ------------------------------------------------------------------------------

    private function checkCsrf(Request $request, Presentation $presentation): void
    {
        if (!$this->isCsrfTokenValid('presentation_'.$presentation->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Неверный CSRF-токен.');
        }
    }

    private function back(Request $request, Presentation $presentation): Response
    {
        $to = (string) $request->request->get('back', '');
        if (str_starts_with($to, '/') && !str_starts_with($to, '//')) {
            return $this->redirect($to);
        }

        return $this->redirectToRoute('app_presentation_show', ['id' => $presentation->getId()]);
    }

    public static function fileName(string $title): string
    {
        $name = trim(preg_replace('/[\\\\\/:*?"<>|\x00-\x1F]+/u', ' ', $title) ?? '');

        return '' !== $name ? mb_substr($name, 0, 120) : 'Презентация';
    }

    /** @return array<string, mixed> */
    private function viewerConfig(Presentation $p): array
    {
        return [
            'brand' => BrandAssets::forEditor(),
            'assetBase' => $this->generateUrl('app_presentation_asset', ['token' => '_T_', 'ext' => 'img']),
            'qr' => $this->generateUrl('app_presentation_qr'),
            'present' => $this->generateUrl('app_presentation_present', ['id' => $p->getId()]),
        ];
    }

    /** @return array<string, mixed> */
    private function editorConfig(Presentation $p, User $user): array
    {
        $id = $p->getId();
        $meeting = $p->getMeeting();

        return [
            'id' => $id,
            'kind' => $p->getKind(),
            'title' => $p->getTitle(),
            'responsible' => $p->getResponsibleLabel(),
            'authorName' => Presentation::shortName($p->getAuthor()?->getDisplayName() ?? ''),
            'status' => $p->getStatus(),
            'statusLabel' => $p->getStatusLabel(),
            'revision' => $p->getRevision(),
            'decisionNote' => $p->isRejected() ? $p->getDecisionNote() : null,
            'decidedBy' => $p->getDecidedBy()?->getDisplayName(),
            'isModerator' => $this->access->isModerator($user),
            'canSubmit' => ($p->isAuthor($user) || $this->access->isModerator($user)) && ($p->isDraft() || $p->isRejected()),
            'canDecide' => $this->access->canDecide($user, $p),
            'canComment' => $this->access->canComment($user, $p),
            'user' => ['id' => $user->getId(), 'name' => $user->getDisplayName()],
            'meeting' => null !== $meeting ? ['id' => $meeting->getId(), 'title' => $meeting->getDisplayTitle(), 'date' => $meeting->getDateLabel()] : null,
            'token' => $this->csrf->getToken('presentation_api_'.$id)->getValue(),
            'formToken' => $this->csrf->getToken('presentation_'.$id)->getValue(),
            'urls' => [
                'save' => $this->generateUrl('app_presentation_api_save', ['id' => $id]),
                'data' => $this->generateUrl('app_presentation_api_data', ['id' => $id]),
                'upload' => $this->generateUrl('app_presentation_api_upload', ['id' => $id]),
                'import' => $this->generateUrl('app_presentation_api_import', ['id' => $id]),
                'comments' => $this->generateUrl('app_presentation_api_comments', ['id' => $id]),
                'commentBase' => $this->generateUrl('app_presentation_api_comment_update', ['comment' => 0]),
                'versions' => $this->generateUrl('app_presentation_api_versions', ['id' => $id]),
                'version' => $this->generateUrl('app_presentation_api_version', ['id' => $id, 'version' => 0]),
                'restore' => $this->generateUrl('app_presentation_restore', ['id' => $id, 'version' => 0]),
                'assetBase' => $this->generateUrl('app_presentation_asset', ['token' => '_T_', 'ext' => 'img']),
                'qr' => $this->generateUrl('app_presentation_qr'),
                'present' => $this->generateUrl('app_presentation_present', ['id' => $id]),
                'print' => $this->generateUrl('app_presentation_print', ['id' => $id]),
                'export' => $this->generateUrl('app_presentation_export', ['id' => $id]),
                'show' => $this->generateUrl('app_presentation_show', ['id' => $id]),
                'submit' => $this->generateUrl('app_presentation_submit', ['id' => $id]),
                'withdraw' => $this->generateUrl('app_presentation_withdraw', ['id' => $id]),
                'decide' => $this->generateUrl('app_presentation_decide', ['id' => $id]),
                'back' => null !== $meeting ? $this->generateUrl('app_meeting_show', ['id' => $meeting->getId()]) : $this->generateUrl('app_presentations'),
                'absolute' => $this->generateUrl('app_presentation_show', ['id' => $id], UrlGeneratorInterface::ABSOLUTE_URL),
            ],
            'layouts' => SlideLayouts::forEditor(),
            'brand' => BrandAssets::forEditor(),
            'palette' => SlideLayouts::PALETTE,
            'chartColors' => SlideLayouts::CHART_COLORS,
            'fonts' => DeckValidator::FONTS,
            'shapes' => DeckValidator::SHAPES,
            'limits' => ['slides' => DeckValidator::MAX_SLIDES, 'elements' => DeckValidator::MAX_ELEMENTS, 'imageBytes' => 15 * 1024 * 1024],
        ];
    }
}
