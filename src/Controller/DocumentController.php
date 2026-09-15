<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Document;
use App\Entity\DocumentVersion;
use App\Entity\Section;
use App\Entity\User;
use App\Form\DocumentType;
use App\Repository\DocumentEventRepository;
use App\Repository\SectionRepository;
use App\Security\Access;
use App\Security\Voter\PortalVoter;
use App\Service\DiffService;
use App\Service\DocumentManager;
use App\Service\FileStorage;
use App\Service\StatsService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Документы: карточка, версии, скачивание, создание/изменение (модераторы), публикация, статистика.
 */
#[Route('/documents')]
final class DocumentController extends AbstractController
{
    private const VIEW_THROTTLE_SECONDS = 600;

    public function __construct(
        private readonly DocumentManager $manager,
        private readonly SectionRepository $sections,
        private readonly DocumentEventRepository $events,
        private readonly Access $access,
        private readonly StatsService $stats,
        private readonly DiffService $diff,
        private readonly int $defaultValidityMonths,
    ) {
    }

    #[Route('/new', name: 'app_document_new', methods: ['GET', 'POST'])]
    #[IsGranted(PortalVoter::MODERATOR)]
    public function new(Request $request, #[CurrentUser] User $user): Response
    {
        $sectionId = $request->query->getInt('section');
        $section = $sectionId > 0 ? $this->sections->find($sectionId) : null;
        if (null !== $section && !$this->access->canManageSection($user, $section)) {
            throw $this->createAccessDeniedException('Нет прав на добавление документов в этот раздел.');
        }
        $choices = $this->access->managedSections($user);
        if ([] === $choices) {
            $this->addFlash('warning', 'У вас нет разделов, в которые можно добавлять документы.');

            return $this->redirectToRoute('app_home');
        }
        $document = new Document($section ?? $choices[0]);
        if ($this->defaultValidityMonths > 0) {
            $document->setValidUntil(new \DateTimeImmutable('today +'.$this->defaultValidityMonths.' months'));
        }
        $type = (string) $request->query->get('type', Document::TYPE_FILE);
        $document->setType(\in_array($type, Document::TYPES, true) ? $type : Document::TYPE_FILE);

        $form = $this->createForm(DocumentType::class, $document, [
            'section_choices' => $choices,
            'is_new' => true,
            'accept' => $this->acceptList(),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if (!$this->access->canManageSection($user, $document->getSection())) {
                $form->get('section')->addError(new FormError('У вас нет прав на выбранный раздел.'));
            } else {
                /** @var UploadedFile|null $file */
                $file = $form->get('file')->getData();
                try {
                    $this->manager->create(
                        $document,
                        $file,
                        $form->get('content')->getData(),
                        $form->get('changeNote')->getData(),
                        $user,
                        (bool) $form->get('publish')->getData(),
                        $request->getClientIp(),
                    );
                    $this->addFlash('success', $document->isPublished() ? 'Документ опубликован.' : 'Документ сохранён как черновик.');

                    return $this->redirectToRoute('app_document_show', ['id' => $document->getId()]);
                } catch (\InvalidArgumentException|\RuntimeException $e) {
                    $form->get($document->isFile() ? 'file' : 'content')->addError(new FormError($e->getMessage()));
                }
            }
        }

        return $this->render('document/form.html.twig', [
            'form' => $form,
            'document' => $document,
            'is_new' => true,
            'storage' => $this->manager->getStorage(),
        ]);
    }

    #[Route('/{id}', name: 'app_document_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    #[IsGranted(PortalVoter::DOCUMENT_VIEW, subject: 'document')]
    public function show(Document $document, Request $request, #[CurrentUser] User $user): Response
    {
        $this->trackView($document, $request, $user);
        $canManage = $this->access->canEditDocument($user, $document);
        $current = $document->getCurrentVersion();

        return $this->render('document/show.html.twig', [
            'document' => $document,
            'current' => $current,
            'versions' => $document->getVersions(),
            'can_manage' => $canManage,
            'inline' => null !== $current && $current->isFile() && FileStorage::isInlineViewable($current->getMimeType(), $current->getExtension()),
            'file_exists' => null !== $current && $current->isFile() ? $this->manager->getStorage()->exists($current) : true,
            'recent_events' => $canManage ? $this->events->findForDocument($document, 10) : [],
        ]);
    }

    #[Route('/{id}/edit', name: 'app_document_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    #[IsGranted(PortalVoter::DOCUMENT_EDIT, subject: 'document')]
    public function edit(Document $document, Request $request, #[CurrentUser] User $user): Response
    {
        $previousSection = $document->getSection();
        $form = $this->createForm(DocumentType::class, $document, [
            'section_choices' => $this->access->managedSections($user),
            'is_new' => false,
            'accept' => $this->acceptList(),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if (!$this->access->canManageSection($user, $document->getSection())) {
                $form->get('section')->addError(new FormError('У вас нет прав на выбранный раздел.'));
            } else {
                try {
                    $ip = $request->getClientIp();
                    $note = $form->get('changeNote')->getData();
                    $newVersion = null;
                    if ($document->isFile()) {
                        /** @var UploadedFile|null $file */
                        $file = $form->get('file')->getData();
                        if (null !== $file) {
                            $newVersion = $this->manager->addFileVersion($document, $file, $note, $user, $ip);
                        }
                    } else {
                        $newVersion = $this->manager->addPageVersion($document, $form->get('content')->getData(), $note, $user, $ip);
                    }
                    $this->manager->updateMetadata($document, $previousSection, $user, $ip);
                    $this->addFlash('success', null !== $newVersion ? \sprintf('Сохранена версия %d.', $newVersion->getNumber()) : 'Карточка документа сохранена.');

                    return $this->redirectToRoute('app_document_show', ['id' => $document->getId()]);
                } catch (\InvalidArgumentException|\RuntimeException $e) {
                    $form->get($document->isFile() ? 'file' : 'content')->addError(new FormError($e->getMessage()));
                }
            }
        }

        return $this->render('document/form.html.twig', [
            'form' => $form,
            'document' => $document,
            'is_new' => false,
            'storage' => $this->manager->getStorage(),
        ]);
    }

    #[Route('/{id}/download', name: 'app_document_download', requirements: ['id' => '\d+'], methods: ['GET'])]
    #[IsGranted(PortalVoter::DOCUMENT_VIEW, subject: 'document')]
    public function download(Document $document, Request $request, #[CurrentUser] User $user): Response
    {
        $version = $document->getCurrentVersion();
        if (null === $version) {
            throw $this->createNotFoundException('У документа нет версий.');
        }

        return $this->serveVersion($document, $version, $request, $user);
    }

    #[Route('/{id}/versions/{number}/download', name: 'app_document_version_download', requirements: ['id' => '\d+', 'number' => '\d+'], methods: ['GET'])]
    #[IsGranted(PortalVoter::DOCUMENT_VIEW, subject: 'document')]
    public function downloadVersion(Document $document, int $number, Request $request, #[CurrentUser] User $user): Response
    {
        $version = $document->findVersion($number) ?? throw $this->createNotFoundException('Версия не найдена.');

        return $this->serveVersion($document, $version, $request, $user);
    }

    #[Route('/{id}/versions/{number}', name: 'app_document_version', requirements: ['id' => '\d+', 'number' => '\d+'], methods: ['GET'])]
    #[IsGranted(PortalVoter::DOCUMENT_VIEW, subject: 'document')]
    public function version(Document $document, int $number, #[CurrentUser] User $user): Response
    {
        $version = $document->findVersion($number) ?? throw $this->createNotFoundException('Версия не найдена.');
        if ($version->isFile()) {
            return $this->redirectToRoute('app_document_version_download', ['id' => $document->getId(), 'number' => $number]);
        }

        return $this->render('document/version.html.twig', [
            'document' => $document,
            'version' => $version,
            'can_manage' => $this->access->canEditDocument($user, $document),
        ]);
    }

    #[Route('/{id}/versions/{a}/compare/{b}', name: 'app_document_compare', requirements: ['id' => '\d+', 'a' => '\d+', 'b' => '\d+'], methods: ['GET'])]
    #[IsGranted(PortalVoter::DOCUMENT_VIEW, subject: 'document')]
    public function compare(Document $document, int $a, int $b): Response
    {
        $va = $document->findVersion($a) ?? throw $this->createNotFoundException('Версия не найдена.');
        $vb = $document->findVersion($b) ?? throw $this->createNotFoundException('Версия не найдена.');
        $lines = ($va->isPage() && $vb->isPage()) ? $this->diff->compare($va, $vb) : [];

        return $this->render('document/compare.html.twig', [
            'document' => $document,
            'a' => $va,
            'b' => $vb,
            'lines' => $lines,
            'summary' => DiffService::summary($lines),
            'same_file' => $va->isFile() && $vb->isFile() && null !== $va->getChecksum() && $va->getChecksum() === $vb->getChecksum(),
        ]);
    }

    #[Route('/{id}/versions/{number}/restore', name: 'app_document_restore', requirements: ['id' => '\d+', 'number' => '\d+'], methods: ['POST'])]
    #[IsGranted(PortalVoter::DOCUMENT_EDIT, subject: 'document')]
    public function restore(Document $document, int $number, Request $request, #[CurrentUser] User $user): Response
    {
        $this->checkToken($request, $document);
        $version = $document->findVersion($number) ?? throw $this->createNotFoundException('Версия не найдена.');
        try {
            $new = $this->manager->restoreVersion($document, $version, $user, $request->getClientIp());
            $this->addFlash('success', \sprintf('Версия %d восстановлена как версия %d.', $number, $new->getNumber()));
        } catch (\DomainException|\RuntimeException|\InvalidArgumentException $e) {
            $this->addFlash('danger', $e->getMessage());
        }

        return $this->redirectToRoute('app_document_show', ['id' => $document->getId()]);
    }

    #[Route('/{id}/publish', name: 'app_document_publish', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted(PortalVoter::DOCUMENT_EDIT, subject: 'document')]
    public function publish(Document $document, Request $request, #[CurrentUser] User $user): Response
    {
        $this->checkToken($request, $document);
        try {
            $this->manager->publish($document, $user, $request->getClientIp());
            $this->addFlash('success', 'Документ опубликован — теперь он виден всем сотрудникам.');
        } catch (\DomainException $e) {
            $this->addFlash('danger', $e->getMessage());
        }

        return $this->redirectToRoute('app_document_show', ['id' => $document->getId()]);
    }

    #[Route('/{id}/unpublish', name: 'app_document_unpublish', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted(PortalVoter::DOCUMENT_EDIT, subject: 'document')]
    public function unpublish(Document $document, Request $request, #[CurrentUser] User $user): Response
    {
        $this->checkToken($request, $document);
        $this->manager->unpublish($document, $user, $request->getClientIp());
        $this->addFlash('success', 'Документ снят с публикации и переведён в черновики.');

        return $this->redirectToRoute('app_document_show', ['id' => $document->getId()]);
    }

    #[Route('/{id}/archive', name: 'app_document_archive', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted(PortalVoter::DOCUMENT_EDIT, subject: 'document')]
    public function archive(Document $document, Request $request, #[CurrentUser] User $user): Response
    {
        $this->checkToken($request, $document);
        $this->manager->archive($document, $user, $request->getClientIp());
        $this->addFlash('success', 'Документ перенесён в архив.');

        return $this->redirectToRoute('app_document_show', ['id' => $document->getId()]);
    }

    #[Route('/{id}/delete', name: 'app_document_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted(PortalVoter::DOCUMENT_DELETE, subject: 'document')]
    public function delete(Document $document, Request $request, #[CurrentUser] User $user): Response
    {
        $this->checkToken($request, $document);
        $section = $document->getSection();
        $title = $document->getTitle();
        $this->manager->delete($document, $user);
        $this->addFlash('success', \sprintf('Документ «%s» удалён вместе со всеми версиями.', $title));

        return $this->redirectToRoute('app_section_show', ['id' => $section->getId()]);
    }

    #[Route('/{id}/stats', name: 'app_document_stats', requirements: ['id' => '\d+'], methods: ['GET'])]
    #[IsGranted(PortalVoter::DOCUMENT_STATS, subject: 'document')]
    public function stats(Document $document): Response
    {
        $data = $this->stats->forDocument($document);

        return $this->render('document/stats.html.twig', [
            'document' => $document,
            'stats' => $data,
            'chart' => StatsService::chartSeries($data['by_day']),
        ]);
    }

    private function serveVersion(Document $document, DocumentVersion $version, Request $request, User $user): Response
    {
        if ($version->isPage()) {
            // Страницу отдаём как HTML-файл.
            $html = '<!DOCTYPE html><html lang="ru"><head><meta charset="utf-8"><title>'.htmlspecialchars($document->getTitle()).'</title></head><body>'.$version->getContent().'</body></html>';
            $this->manager->recordDownload($document, $version, $user, $request->getClientIp());
            $response = new Response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8']);
            $response->headers->set('Content-Disposition', HeaderUtils::makeDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, self::asciiName($document->getTitle()).'.html', 'document.html'));

            return $response;
        }
        $path = $this->manager->getStorage()->absolutePath($version);
        if (null === $path || !is_file($path)) {
            throw $this->createNotFoundException('Файл версии не найден в хранилище. Сообщите администратору.');
        }
        $inline = $request->query->getBoolean('inline') && FileStorage::isInlineViewable($version->getMimeType(), $version->getExtension());
        if (!$inline) {
            $this->manager->recordDownload($document, $version, $user, $request->getClientIp());
        }
        $response = new BinaryFileResponse($path);
        $response->headers->set('Content-Type', $version->getMimeType() ?: 'application/octet-stream');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $name = $version->getOriginalName() ?? ('document.'.$version->getExtension());
        $response->setContentDisposition($inline ? ResponseHeaderBag::DISPOSITION_INLINE : ResponseHeaderBag::DISPOSITION_ATTACHMENT, $name, self::asciiName($name));
        $response->setPrivate();

        return $response;
    }

    private function trackView(Document $document, Request $request, User $user): void
    {
        $session = $request->getSession();
        $key = 'viewed_doc_'.$document->getId();
        $last = (int) $session->get($key, 0);
        if (time() - $last < self::VIEW_THROTTLE_SECONDS) {
            return;
        }
        $session->set($key, time());
        $this->manager->recordView($document, $user, $request->getClientIp());
    }

    private function checkToken(Request $request, Document $document): void
    {
        if (!$this->isCsrfTokenValid('document_'.$document->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Неверный CSRF-токен.');
        }
    }

    private function acceptList(): string
    {
        return implode(',', array_map(static fn (string $e) => '.'.$e, $this->manager->getStorage()->getAllowedExtensions()));
    }

    /** ASCII-вариант имени файла для старых браузеров (Content-Disposition filename=). */
    public static function asciiName(string $name): string
    {
        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name);
        if (false === $ascii || '' === trim($ascii)) {
            $ascii = preg_replace('/[^A-Za-z0-9._-]+/', '_', $name) ?? 'document';
        }
        $ascii = preg_replace('/[^A-Za-z0-9._ -]+/', '_', $ascii) ?? $ascii;
        $ascii = trim($ascii, ' _');

        return '' !== $ascii ? $ascii : 'document';
    }
}
