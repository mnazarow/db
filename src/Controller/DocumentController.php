<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Document;
use App\Entity\DocumentComment;
use App\Entity\DocumentLink;
use App\Entity\MaterialRow;
use App\Entity\DocumentVersion;
use App\Entity\User;
use App\Form\DocumentType;
use App\Repository\DocumentAcknowledgementRepository;
use App\Repository\DocumentEventRepository;
use App\Repository\SectionRepository;
use App\Security\Access;
use App\Security\Voter\PortalVoter;
use App\Service\DiffService;
use App\Repository\DocumentApprovalRepository;
use App\Service\ApprovalService;
use App\Service\CommentService;
use App\Service\DocumentLinkService;
use App\Service\DocumentTemplateService;
use App\Repository\DocumentTemplateRepository;
use App\Service\SubscriptionService;
use App\Service\DocumentManager;
use App\Service\QuizService;
use App\Service\Preview\DocumentPreviewer;
use App\Service\FileStorage;
use App\Service\Llm\DocumentDescriber;
use App\Service\Llm\LlmException;
use App\Service\Material\MaterialService;
use App\Service\Material\MaterialState;
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
        private readonly DocumentAcknowledgementRepository $acknowledgements,
        private readonly Access $access,
        private readonly StatsService $stats,
        private readonly DiffService $diff,
        private readonly DocumentDescriber $describer,
        private readonly DocumentPreviewer $previewer,
        private readonly ApprovalService $approvals,
        private readonly QuizService $quiz,
        private readonly DocumentApprovalRepository $approvalRepository,
        private readonly CommentService $comments,
        private readonly SubscriptionService $subscriptions,
        private readonly DocumentLinkService $links,
        private readonly DocumentTemplateService $templates,
        private readonly DocumentTemplateRepository $templateRepository,
        private readonly MaterialService $materials,
        private readonly int $defaultValidityMonths,
    ) {
    }

    #[Route('/new', name: 'app_document_new', methods: ['GET', 'POST'])]
    #[IsGranted(PortalVoter::CONTRIBUTOR)]
    public function new(Request $request, #[CurrentUser] User $user): Response
    {
        $sectionId = (int) $request->query->get('section');
        $section = $sectionId > 0 ? $this->sections->find($sectionId) : null;
        if (null !== $section && !$this->access->canContribute($user, $section)) {
            throw $this->createAccessDeniedException('Нет прав на добавление документов в этот раздел.');
        }
        $choices = $this->access->contributableSections($user);
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

        // Шаблон заполняет карточку заготовкой: раздел, вид, название, обозначение с автонумерацией,
        // теги, срок и текст страницы. Номер выдаётся сразу, поэтому шаблон применяется только на GET.
        $template = null;
        $templateBody = null;
        $templateId = (int) $request->query->get('template');
        if ($templateId > 0 && $request->isMethod('GET')) {
            $template = $this->templateRepository->find($templateId);
            if (null !== $template && $template->isActive()) {
                $templateBody = $this->templates->prepare($template, $document);
                if (!$this->access->canContribute($user, $document->getSection())) {
                    $document->setSection($section ?? $choices[0]);
                }
                $this->templates->markUsed($template);
                $this->addFlash('info', \sprintf('Карточка заполнена по шаблону «%s». Проверьте поля и сохраните.', $template->getName()));
            }
        }

        // Помощник маркетолога (не модератор выбранного раздела) публикует только через модерацию.
        $moderation = $this->access->isAssistant($user) && !$this->access->isModerator($user);
        $form = $this->createForm(DocumentType::class, $document, [
            'section_choices' => $choices,
            'is_new' => true,
            'accept' => $this->acceptList(),
            'moderation' => $moderation,
        ]);
        if (null !== $templateBody) {
            $form->get('content')->setData($templateBody);
        }
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if (!$this->access->canContribute($user, $document->getSection())) {
                $form->get('section')->addError(new FormError('У вас нет прав на выбранный раздел.'));
            } else {
                /** @var UploadedFile|null $file */
                $file = $form->get('file')->getData();
                $needsModeration = $this->access->needsModeration($user, $document->getSection());
                $publish = (bool) $form->get('publish')->getData();
                try {
                    $this->manager->create(
                        $document,
                        $file,
                        $form->get('content')->getData(),
                        $form->get('changeNote')->getData(),
                        $user,
                        // При обязательном согласовании и у помощника маркетолога новый документ сохраняется черновиком.
                        $publish && !$this->approvals->isRequired() && !$needsModeration,
                        $request->getClientIp(),
                    );
                    if ($needsModeration) {
                        $this->flashModeration($document, $publish, $user);
                    } else {
                        $this->addFlash('success', $document->isPublished()
                            ? 'Документ опубликован.'
                            : ($this->approvals->isRequired() && $publish
                                ? 'Документ сохранён как черновик: по настройкам портала публикация возможна только после согласования.'
                                : 'Документ сохранён как черновик.'));
                    }
                    $this->autoDescribe($document, $user, $request);

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
            'template' => $template,
            'templates' => $this->templateRepository->findForSection($document->getSection()),
            'moderation' => $moderation,
            'card_locked' => false,
        ]);
    }

    /** Отправляет материал помощника маркетолога на модерацию (если он попросил) и сообщает, что дальше. */
    private function flashModeration(Document $document, bool $submit, User $user): void
    {
        if (null !== $this->approvalRepository->findPending($document)) {
            $this->addFlash('success', 'Изменения сохранены. Материал уже на модерации — решение примет маркетолог.');

            return;
        }
        if (!$submit) {
            $this->addFlash('success', 'Материал сохранён как черновик. Когда он будет готов, нажмите в карточке «Отправить на модерацию».');

            return;
        }
        try {
            $this->approvals->submitForModeration($document, null, $user);
            $this->addFlash('success', $document->isPublished()
                ? 'Новая редакция отправлена на модерацию: она вступит в силу после одобрения маркетологом, до этого сотрудники видят прежнюю.'
                : 'Материал отправлен на модерацию: сотрудники увидят его после одобрения маркетологом.');
        } catch (\DomainException $e) {
            $this->addFlash('warning', 'Сохранено, но на модерацию не отправлено: '.$e->getMessage());
        }
    }

    /** Помощник маркетолога отправляет черновик или новую редакцию на модерацию. */
    #[Route('/{id}/moderation', name: 'app_document_moderation_submit', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted(PortalVoter::DOCUMENT_CONTRIBUTE, subject: 'document')]
    public function submitModeration(Document $document, Request $request, #[CurrentUser] User $user): Response
    {
        $this->checkToken($request, $document);
        try {
            $this->approvals->submitForModeration($document, (string) $request->request->get('note'), $user);
            $this->addFlash('success', 'Материал отправлен на модерацию. Маркетологи получили уведомление.');
        } catch (\DomainException $e) {
            $this->addFlash('danger', $e->getMessage());
        }

        return $this->redirectToRoute('app_document_show', ['id' => $document->getId()]);
    }

    #[Route('/{id}', name: 'app_document_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    #[IsGranted(PortalVoter::DOCUMENT_VIEW, subject: 'document')]
    public function show(Document $document, Request $request, #[CurrentUser] ?User $user): Response
    {
        $this->trackView($document, $request, $user);
        $canManage = $this->access->canEditDocument($user, $document);
        $canContribute = $this->access->canContributeToDocument($user, $document);
        $current = $document->getCurrentVersion();
        $approval = $this->approvalRepository->findPending($document);
        $pendingVersion = $canContribute || $this->access->isPendingApprover($user, $document) ? $document->getPendingVersion() : null;
        $comments = $this->comments->isEnabled() ? $this->comments->forDocument($document) : [];
        $editable = $deletable = [];
        foreach ($comments as $comment) {
            $editable[(int) $comment->getId()] = $this->comments->canEdit($comment, $user);
            $deletable[(int) $comment->getId()] = $this->comments->canDelete($comment, $user, $canManage);
        }

        return $this->render('document/show.html.twig', [
            'document' => $document,
            'current' => $current,
            // Новая редакция, ждущая согласования, видна только модераторам раздела и согласующему.
            'versions' => array_values(array_filter($document->getVersions()->toArray(), fn (DocumentVersion $v): bool => $this->access->canViewVersion($user, $document, $v))),
            'pending_version' => $pendingVersion,
            'pending_decision' => null !== $pendingVersion ? $this->approvalRepository->findDecided($document, $pendingVersion->getNumber()) : null,
            'can_promote' => null !== $pendingVersion && $canManage && $this->approvals->canPromote($document, $pendingVersion),
            'can_manage' => $canManage,
            'inline' => null !== $current && $current->isFile() && FileStorage::isInlineViewable($current->getMimeType(), $current->getExtension()),
            'file_exists' => null !== $current && $current->isFile() ? $this->manager->getStorage()->exists($current) : true,
            // Офисные файлы показываем через конвертацию в PDF (LibreOffice) — по кнопке, чтобы не ждать в карточке.
            'previewable' => null !== $current && $this->previewer->isAvailable() && $this->previewer->supports($current),
            'preview_ready' => null !== $current && $this->previewer->isAvailable() && $this->previewer->supports($current) && $this->previewer->isReady($current),
            'preview_failed' => null !== $current && $this->previewer->isAvailable() && $this->previewer->supports($current) && $this->previewer->hasFailed($current),
            'preview_extensions' => $this->previewer->isAvailable() ? DocumentPreviewer::CONVERTIBLE : [],
            // Согласование и модерация: текущий запрос, решение по текущей редакции и правило публикации.
            'approval' => $approval,
            'can_decide' => null !== $approval && $this->approvals->canDecide($approval, $user),
            'can_contribute' => $canContribute,
            'can_edit_card' => $this->access->canEditCard($user, $document),
            'needs_moderation' => $this->access->needsModeration($user, $document->getSection()),
            // Помощник отправляет на модерацию черновик или новую редакцию опубликованного документа.
            'can_submit_moderation' => null === $approval && !$canManage && $canContribute && null !== $current
                && (!$document->isPublished() || null !== $document->getPendingVersion()),
            'approval_decided' => null !== $current ? $this->approvalRepository->findDecided($document, $current->getNumber()) : null,
            'approval_required' => $this->approvals->isRequired(),
            'can_publish' => $canManage && $this->approvals->canPublish($document),
            'question_count' => $canManage ? $this->quiz->count($document) : 0,
            'recent_events' => $canManage ? $this->events->findForDocument($document, 10) : [],
            'llm_enabled' => $canManage && $this->describer->isEnabled(),
            // Ознакомление: что назначено текущему сотруднику и сводка для модератора.
            'acknowledgement' => null !== $user ? $this->acknowledgements->findPending($document, $user) : null,
            'acknowledged' => null !== $user && null !== $current ? $this->acknowledgements->findOneFor($document, $user, $current->getNumber())?->getConfirmedAt() : null,
            'acknowledgement_summary' => $canManage && null !== $current ? $this->acknowledgements->summaryForDocument($document, $current->getNumber()) : null,
            // Обсуждение и подписка на изменения.
            'comments_enabled' => $this->comments->isEnabled(),
            'comments' => $this->comments->isEnabled() ? $comments : [],
            'comment_count' => $this->comments->isEnabled() ? $this->comments->count($document) : 0,
            'comment_max' => DocumentComment::MAX_LENGTH,
            'can_comment' => null !== $user && $this->comments->isEnabled(),
            'comment_edit' => $editable,
            'comment_delete' => $deletable,
            'subscriptions_enabled' => $this->subscriptions->isEnabled(),
            'subscribed' => $this->subscriptions->isSubscribedToDocument($user, $document),
            'subscribed_section' => $this->subscriptions->coveringSection($user, $document),
            // Связи с другими документами.
            // Связанные документы, которые пользователь не видит (черновики, ограниченный доступ, внутренние
            // для гостя), не показываются — даже их названия.
            'links' => array_values(array_filter($this->links->forDocument($document), fn (array $row): bool => $this->isGranted(PortalVoter::DOCUMENT_VIEW, $row['document']))),
            'link_types' => DocumentLink::LABELS,
            // Строки таблиц материалов, из которых загружен документ.
            'material_rows' => $canManage ? $this->materialRows($document, $user) : [],
        ]);
    }

    /** Формирует описание документа через внешнюю LLM (кнопка «Описать через ИИ» в карточке). */
    #[Route('/{id}/describe', name: 'app_document_describe', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted(PortalVoter::DOCUMENT_EDIT, subject: 'document')]
    public function describe(Document $document, Request $request, #[CurrentUser] User $user): Response
    {
        $this->checkToken($request, $document);
        try {
            $this->describer->describe($document, $user, $request->getClientIp());
            $this->addFlash('success', 'Описание сформировано языковой моделью. Проверьте его и при необходимости отредактируйте в карточке.');
        } catch (LlmException $e) {
            $this->addFlash('danger', $e->getMessage());
        }

        return $this->redirectToRoute('app_document_show', ['id' => $document->getId()]);
    }

    /**
     * @return list<array{row: MaterialRow, state: string, css: string}>
     */
    private function materialRows(Document $document, ?User $user): array
    {
        $items = [];
        foreach ($this->materials->rowsForDocument($document) as $row) {
            if (null !== $user && $this->access->canManageSection($user, $row->getSection())) {
                $state = $this->materials->state($row);
                $items[] = ['row' => $row, 'state' => $state, 'css' => MaterialState::css($state)];
            }
        }

        return $items;
    }

    /** При включённом автоописании пытается сразу описать новый документ без описания; ошибка не мешает созданию. */
    private function autoDescribe(Document $document, User $user, Request $request): void
    {
        if ('' !== trim((string) $document->getDescription()) || !$this->describer->isEnabled() || !$this->describer->isAutoEnabled()) {
            return;
        }
        try {
            $this->describer->describe($document, $user, $request->getClientIp());
            $this->addFlash('info', 'Описание документа сформировано языковой моделью — проверьте его в карточке.');
        } catch (LlmException $e) {
            $this->addFlash('warning', 'Описание не сформировано: '.$e->getMessage().' Его можно сформировать позже кнопкой «Описать через ИИ».');
        }
    }

    #[Route('/{id}/edit', name: 'app_document_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    #[IsGranted(PortalVoter::DOCUMENT_CONTRIBUTE, subject: 'document')]
    public function edit(Document $document, Request $request, #[CurrentUser] User $user): Response
    {
        $previousSection = $document->getSection();
        $moderation = $this->access->needsModeration($user, $document->getSection());
        $cardLocked = !$this->access->canEditCard($user, $document);
        $form = $this->createForm(DocumentType::class, $document, [
            'section_choices' => $this->access->contributableSections($user),
            'is_new' => false,
            'accept' => $this->acceptList(),
            'moderation' => $moderation,
            'card_locked' => $cardLocked,
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if (!$this->access->canContribute($user, $document->getSection())) {
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
                    if (!$cardLocked) {
                        $this->manager->updateMetadata($document, $previousSection, $user, $ip);
                        // Название и тип документа — это название и тип строки таблицы материалов.
                        $this->materials->syncFromDocument($document, $user);
                    }
                    if ($moderation) {
                        if (null === $newVersion && $cardLocked) {
                            $this->addFlash('info', 'Новая редакция не загружена — изменений нет.');
                        } else {
                            $this->flashModeration($document, (bool) $form->get('submitModeration')->getData(), $user);
                        }
                    } elseif (null !== $newVersion && !$document->isVersionReleased($newVersion)) {
                        $this->addFlash('warning', \sprintf('Сохранена редакция %d. По настройкам портала она вступит в силу после согласования — отправьте её на согласование; до решения сотрудники видят редакцию %d.', $newVersion->getNumber(), $document->getCurrentVersion()?->getNumber() ?? 0));
                    } else {
                        $this->addFlash('success', null !== $newVersion ? \sprintf('Сохранена версия %d.', $newVersion->getNumber()) : 'Карточка документа сохранена.');
                    }

                    return $this->redirectToRoute('app_document_show', ['id' => $document->getId()]);
                } catch (\InvalidArgumentException|\RuntimeException|\DomainException $e) {
                    $form->get($document->isFile() ? 'file' : 'content')->addError(new FormError($e->getMessage()));
                }
            }
        }

        return $this->render('document/form.html.twig', [
            'form' => $form,
            'document' => $document,
            'is_new' => false,
            'storage' => $this->manager->getStorage(),
            'template' => null,
            'templates' => [],
            'moderation' => $moderation,
            'card_locked' => $cardLocked,
        ]);
    }

    #[Route('/{id}/download', name: 'app_document_download', requirements: ['id' => '\d+'], methods: ['GET'])]
    #[IsGranted(PortalVoter::DOCUMENT_VIEW, subject: 'document')]
    public function download(Document $document, Request $request, #[CurrentUser] ?User $user): Response
    {
        $version = $document->getCurrentVersion();
        if (null === $version) {
            throw $this->createNotFoundException('У документа нет версий.');
        }

        return $this->serveVersion($document, $version, $request, $user);
    }

    #[Route('/{id}/versions/{number}/download', name: 'app_document_version_download', requirements: ['id' => '\d+', 'number' => '\d+'], methods: ['GET'])]
    #[IsGranted(PortalVoter::DOCUMENT_VIEW, subject: 'document')]
    public function downloadVersion(Document $document, int $number, Request $request, #[CurrentUser] ?User $user): Response
    {
        $version = $this->visibleVersion($document, $number, $user);

        return $this->serveVersion($document, $version, $request, $user);
    }

    /** Предпросмотр офисного файла в браузере: LibreOffice переводит его в PDF (результат кэшируется). */
    #[Route('/{id}/preview', name: 'app_document_preview', requirements: ['id' => '\d+'], methods: ['GET'])]
    #[IsGranted(PortalVoter::DOCUMENT_VIEW, subject: 'document')]
    public function preview(Document $document): Response
    {
        $version = $document->getCurrentVersion() ?? throw $this->createNotFoundException('У документа нет версий.');

        return $this->servePreview($document, $version);
    }

    #[Route('/{id}/versions/{number}/preview', name: 'app_document_version_preview', requirements: ['id' => '\d+', 'number' => '\d+'], methods: ['GET'])]
    #[IsGranted(PortalVoter::DOCUMENT_VIEW, subject: 'document')]
    public function previewVersion(Document $document, int $number, #[CurrentUser] ?User $user): Response
    {
        $version = $this->visibleVersion($document, $number, $user);

        return $this->servePreview($document, $version);
    }

    #[Route('/{id}/versions/{number}', name: 'app_document_version', requirements: ['id' => '\d+', 'number' => '\d+'], methods: ['GET'])]
    #[IsGranted(PortalVoter::DOCUMENT_VIEW, subject: 'document')]
    public function version(Document $document, int $number, #[CurrentUser] ?User $user): Response
    {
        $version = $this->visibleVersion($document, $number, $user);
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
    public function compare(Document $document, int $a, int $b, #[CurrentUser] ?User $user): Response
    {
        $va = $this->visibleVersion($document, $a, $user);
        $vb = $this->visibleVersion($document, $b, $user);
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

    /** Новая редакция становится текущей (после согласования или у неопубликованного документа). */
    #[Route('/{id}/versions/{number}/promote', name: 'app_document_version_promote', requirements: ['id' => '\d+', 'number' => '\d+'], methods: ['POST'])]
    #[IsGranted(PortalVoter::DOCUMENT_EDIT, subject: 'document')]
    public function promote(Document $document, int $number, Request $request, #[CurrentUser] User $user): Response
    {
        $this->checkToken($request, $document);
        $version = $document->findVersion($number) ?? throw $this->createNotFoundException('Версия не найдена.');
        if (!$this->approvals->canPromote($document, $version)) {
            $this->addFlash('danger', 'По настройкам портала новая редакция опубликованного документа вступает в силу только после согласования. Отправьте её на согласование.');

            return $this->redirectToRoute('app_document_show', ['id' => $document->getId()]);
        }
        try {
            $this->manager->promoteVersion($document, $version, $user, $request->getClientIp());
            $this->addFlash('success', \sprintf('Редакция %d стала текущей%s.', $number, $document->isPublished() ? ' — сотрудники видят её' : ''));
        } catch (\DomainException $e) {
            $this->addFlash('danger', $e->getMessage());
        }

        return $this->redirectToRoute('app_document_show', ['id' => $document->getId()]);
    }

    #[Route('/{id}/publish', name: 'app_document_publish', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted(PortalVoter::DOCUMENT_EDIT, subject: 'document')]
    public function publish(Document $document, Request $request, #[CurrentUser] User $user): Response
    {
        $this->checkToken($request, $document);
        if (!$this->approvals->canPublish($document)) {
            $this->addFlash('danger', 'По настройкам портала публиковать можно только согласованные документы. Отправьте документ на согласование.');

            return $this->redirectToRoute('app_document_show', ['id' => $document->getId()]);
        }
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

    private function servePreview(Document $document, DocumentVersion $version): Response
    {
        if (!$this->previewer->isAvailable()) {
            throw $this->createNotFoundException('Предпросмотр офисных файлов не настроен: на сервере нет LibreOffice.');
        }
        if (!$this->previewer->supports($version)) {
            // PDF и картинки браузер показывает сам, остальное — только скачиванием.
            return $this->redirectToRoute('app_document_version_download', ['id' => $document->getId(), 'number' => $version->getNumber(), 'inline' => 1]);
        }
        $pdf = $this->previewer->pdf($version);
        if (null === $pdf) {
            $this->addFlash('warning', 'Не удалось подготовить предпросмотр этого файла — скачайте его, чтобы открыть.');

            return $this->redirectToRoute('app_document_show', ['id' => $document->getId()]);
        }
        $response = new BinaryFileResponse($pdf);
        $response->headers->set('Content-Type', 'application/pdf');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $name = pathinfo($version->getOriginalName() ?? 'document', \PATHINFO_FILENAME).'.pdf';
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_INLINE, $name, self::asciiName($name));
        $response->setPrivate();

        return $response;
    }

    private function serveVersion(Document $document, DocumentVersion $version, Request $request, ?User $user): Response
    {
        if ($version->isPage()) {
            // Страницу отдаём как HTML-файл.
            $html = '<!DOCTYPE html><html lang="ru"><head><meta charset="utf-8"><title>'.htmlspecialchars($document->getTitle()).'</title></head><body>'.$version->getContent().'</body></html>';
            $this->manager->recordDownload($document, $version, $user, $request->getClientIp());
            $response = new Response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8']);
            $response->headers->set('Content-Disposition', HeaderUtils::makeDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, self::asciiName($document->getTitle()).'.html', 'document.html'));
            $response->headers->set('X-Content-Type-Options', 'nosniff');
            $response->headers->set('Content-Security-Policy', FileStorage::SANDBOX_CSP);

            return $response;
        }
        $path = $this->manager->getStorage()->absolutePath($version);
        if (null === $path || !is_file($path)) {
            throw $this->createNotFoundException('Файл версии не найден в хранилище. Сообщите администратору.');
        }
        $inline = filter_var($request->query->get('inline'), \FILTER_VALIDATE_BOOL) && FileStorage::isInlineViewable($version->getMimeType(), $version->getExtension());
        if (!$inline) {
            $this->manager->recordDownload($document, $version, $user, $request->getClientIp());
        }
        $response = new BinaryFileResponse($path);
        $inlineType = $inline ? FileStorage::inlineContentType($version->getExtension()) : null;
        // В браузере файл открывается с типом по расширению из белого списка; при скачивании — с исходным типом.
        $response->headers->set('Content-Type', $inlineType ?? ($version->getMimeType() ?: 'application/octet-stream'));
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        if ('application/pdf' !== $inlineType) {
            // Скрипты в SVG, HTML и т. п. не выполняются даже при прямом открытии ссылки.
            $response->headers->set('Content-Security-Policy', FileStorage::SANDBOX_CSP);
        }
        $name = $version->getOriginalName() ?? ('document.'.$version->getExtension());
        $response->setContentDisposition($inline ? ResponseHeaderBag::DISPOSITION_INLINE : ResponseHeaderBag::DISPOSITION_ATTACHMENT, $name, self::asciiName($name));
        $response->setPrivate();

        return $response;
    }

    private function trackView(Document $document, Request $request, ?User $user): void
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

    /** Версия документа, если пользователь может её видеть (новую редакцию до согласования — только модераторы и согласующий). */
    private function visibleVersion(Document $document, int $number, ?User $user): DocumentVersion
    {
        $version = $document->findVersion($number);
        if (null === $version || !$this->access->canViewVersion($user, $document, $version)) {
            throw $this->createNotFoundException('Версия не найдена.');
        }

        return $version;
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
