<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\PriceList;
use App\Entity\PriceRevision;
use App\Entity\User;
use App\Repository\PriceItemRepository;
use App\Repository\PriceListRepository;
use App\Repository\PriceRevisionRepository;
use App\Service\Price\PriceAccess;
use App\Service\Price\PriceListService;
use App\Service\Price\PriceMapper;
use App\Service\Price\PriceSheetReader;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Раздел «Прайс-листы»: список и поиск по всем прайс-листам, таблица позиций с поиском, группами и сортировкой,
 * выгрузка в Excel и исходный файл; загрузка новой редакции из Excel с сопоставлением столбцов, история
 * редакций и отличия между ними, модерация редакций помощников маркетолога.
 */
#[Route('/prices')]
final class PriceListController extends AbstractController
{
    private const CSRF = 'prices';
    private const PER_PAGE = 100;

    public function __construct(
        private readonly PriceListService $service,
        private readonly PriceAccess $access,
        private readonly PriceListRepository $lists,
        private readonly PriceRevisionRepository $revisions,
        private readonly PriceItemRepository $items,
    ) {
    }

    #[Route('', name: 'app_prices', methods: ['GET'])]
    public function index(Request $request, #[CurrentUser] ?User $user): Response
    {
        $q = trim((string) $request->query->get('q', ''));
        $manage = $this->access->canUpload($user);
        $all = $this->lists->findForListing(null === $user, $manage);
        $lists = array_values(array_filter($all, fn (PriceList $l): bool => $this->access->canView($user, $l)));
        $hits = mb_strlen($q) >= 2 ? $this->items->searchCurrent($q, null === $user, 200) : [];

        return $this->render('prices/index.html.twig', [
            'q' => $q,
            'active' => array_values(array_filter($lists, static fn (PriceList $l): bool => !$l->isArchived())),
            'archived' => array_values(array_filter($lists, static fn (PriceList $l): bool => $l->isArchived())),
            'hits' => $hits,
            'can_upload' => $manage,
            'can_manage' => $this->access->canManage($user),
            'pending' => $manage ? array_values(array_filter($this->revisions->findPending(), fn (PriceRevision $r): bool => $this->access->canManage($user) || $r->getUploadedBy()?->getId() === $user?->getId())) : [],
        ]);
    }

    #[Route('/new', name: 'app_price_new', methods: ['GET', 'POST'])]
    public function new(Request $request, #[CurrentUser] ?User $user): Response
    {
        if (!$this->access->canUpload($user)) {
            throw $this->createAccessDeniedException('Прайс-листы ведут маркетологи и их помощники.');
        }
        $form = ['title' => '', 'description' => '', 'currency' => 'RUB', 'public' => true, 'original' => false];
        $errors = [];
        if ($request->isMethod('POST')) {
            $this->csrf($request);
            $form = [
                'title' => (string) $request->request->get('title', ''),
                'description' => (string) $request->request->get('description', ''),
                'currency' => (string) $request->request->get('currency', 'RUB'),
                'public' => $request->request->getBoolean('public'),
                'original' => $request->request->getBoolean('original_download'),
            ];
            $file = $request->files->get('file');
            if ('' === trim($form['title']) && $file instanceof UploadedFile) {
                $form['title'] = trim((string) preg_replace('/[_\s]+/u', ' ', pathinfo($file->getClientOriginalName(), \PATHINFO_FILENAME)));
            }
            if (!$file instanceof UploadedFile) {
                $errors[] = 'Выберите файл прайс-листа.';
            } else {
                try {
                    $list = $this->service->create($user, $form['title'], $form['description'], $form['currency'], $form['public'], $form['original']);
                    try {
                        $revision = $this->service->upload($list, $file, $user);
                    } catch (\InvalidArgumentException|\RuntimeException $e) {
                        $this->service->discardEmpty($list, $user);

                        throw $e;
                    }

                    return $this->redirectToRoute('app_price_mapping', ['id' => $list->getId(), 'number' => $revision->getNumber()]);
                } catch (\InvalidArgumentException|\RuntimeException $e) {
                    $errors[] = $e->getMessage();
                }
            }
        }

        return $this->render('prices/new.html.twig', [
            'form' => $form,
            'errors' => $errors,
            'currencies' => PriceList::CURRENCIES,
            'extensions' => PriceSheetReader::EXTENSIONS,
            'max_mb' => PriceListService::MAX_FILE_MB,
        ]);
    }

    #[Route('/{id}', name: 'app_price_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(PriceList $list, Request $request, #[CurrentUser] ?User $user): Response
    {
        $revision = $this->revisionFromQuery($list, $request, $user);
        if (!$this->access->canView($user, $list) || (null !== $revision && !$this->access->canViewRevision($user, $revision))) {
            throw $this->createNotFoundException();
        }
        $q = trim((string) $request->query->get('q', ''));
        $group = trim((string) $request->query->get('group', ''));
        $sort = (string) $request->query->get('sort', 'position');
        $sort = \in_array($sort, PriceItemRepository::SORTS, true) ? $sort : 'position';
        $page = max(1, $request->query->getInt('page', 1));
        $data = ['items' => [], 'total' => 0];
        $groups = [];
        if (null !== $revision) {
            $data = $this->items->page($revision, $q, '' !== $group ? $group : null, $sort, ($page - 1) * self::PER_PAGE, self::PER_PAGE);
            $groups = $this->items->groups($revision);
        }
        $pending = $this->access->canUpload($user)
            ? array_values(array_filter($this->revisions->findForList($list), fn (PriceRevision $r): bool => !$r->isPublished() && ($this->access->canManage($user) || $r->getUploadedBy()?->getId() === $user?->getId())))
            : [];

        return $this->render('prices/show.html.twig', [
            'list' => $list,
            'revision' => $revision,
            'items' => $data['items'],
            'total' => $data['total'],
            'groups' => $groups,
            'q' => $q,
            'group' => $group,
            'sort' => $sort,
            'page' => $page,
            'pages' => max(1, (int) ceil($data['total'] / self::PER_PAGE)),
            'filters' => array_filter(['q' => $q, 'group' => $group, 'sort' => 'position' !== $sort ? $sort : null, 'revision' => null !== $revision && !$revision->isCurrent() ? $revision->getNumber() : null]),
            'can_upload' => $this->access->canUpload($user),
            'can_manage' => $this->access->canManage($user),
            'can_original' => null !== $revision && $this->access->canDownloadOriginal($user, $revision),
            'unfinished' => $pending,
        ]);
    }

    #[Route('/{id}/upload', name: 'app_price_upload', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function upload(PriceList $list, Request $request, #[CurrentUser] ?User $user): Response
    {
        $this->csrf($request);
        if (!$this->access->canUpload($user)) {
            throw $this->createAccessDeniedException();
        }
        $file = $request->files->get('file');
        if (!$file instanceof UploadedFile) {
            $this->addFlash('error', 'Выберите файл прайс-листа.');

            return $this->redirectToRoute('app_price_show', ['id' => $list->getId()]);
        }
        try {
            $revision = $this->service->upload($list, $file, $user);
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            $this->addFlash('error', $e->getMessage());

            return $this->redirectToRoute('app_price_show', ['id' => $list->getId()]);
        }

        return $this->redirectToRoute('app_price_mapping', ['id' => $list->getId(), 'number' => $revision->getNumber()]);
    }

    #[Route('/{id}/revisions/{number}/mapping', name: 'app_price_mapping', requirements: ['id' => '\d+', 'number' => '\d+'], methods: ['GET', 'POST'])]
    public function mapping(PriceList $list, int $number, Request $request, #[CurrentUser] ?User $user): Response
    {
        $revision = $this->revision($list, $number);
        if (null === $user || !$this->service->canEdit($user, $revision)) {
            throw $this->createAccessDeniedException('Эту редакцию сопоставлять нельзя.');
        }
        $source = $request->isMethod('POST') ? $request->request : $request->query;
        $sheet = $source->has('sheet') ? (string) $source->get('sheet') : null;
        $header = $source->has('header') && '' !== (string) $source->get('header') ? max(0, (int) $source->get('header')) : null;
        $roles = $source->has('roles') && !$source->getBoolean('reset') ? (array) $source->all('roles') : null;
        $effective = (string) $source->get('effective_from', $revision->getEffectiveFrom()?->format('Y-m-d') ?? '');
        $note = (string) $source->get('note', $revision->getNote() ?? '');
        $error = null;
        try {
            $preview = $this->service->preview($revision, $sheet, $header, $roles);
        } catch (\RuntimeException $e) {
            $this->addFlash('error', $e->getMessage());

            return $this->redirectToRoute('app_price_show', ['id' => $list->getId()]);
        }
        if ($request->isMethod('POST')) {
            $this->csrf($request);
            try {
                $date = '' !== $effective ? \DateTimeImmutable::createFromFormat('!Y-m-d', $effective) : null;
                if (false === $date) {
                    throw new \InvalidArgumentException('Дата — в формате ГГГГ-ММ-ДД.');
                }
                $this->service->import($revision, $preview['sheet'], $preview['mapping'], $date, $note, $user);
                $this->addFlash('success', $revision->isPending()
                    ? \sprintf('Редакция %d отправлена на модерацию: %d позиций. Сотрудники увидят её после одобрения маркетолога.', $revision->getNumber(), $revision->getItemCount())
                    : \sprintf('Прайс-лист обновлён: редакция %d, %d позиций.', $revision->getNumber(), $revision->getItemCount()));

                return $revision->isPending()
                    ? $this->redirectToRoute('app_price_revision', ['id' => $list->getId(), 'number' => $revision->getNumber()])
                    : $this->redirectToRoute('app_price_show', ['id' => $list->getId()]);
            } catch (\InvalidArgumentException|\DomainException $e) {
                $error = $e->getMessage();
            }
        }

        return $this->render('prices/mapping.html.twig', [
            'list' => $list,
            'revision' => $revision,
            'preview' => $preview,
            'roles' => PriceMapper::ROLES,
            'effective_from' => $effective,
            'note' => $note,
            'error' => $error,
            'needs_moderation' => $this->access->needsModeration($user),
            'letters' => array_combine(array_keys($preview['mapping']['columns']), array_map(static fn (int $c): string => PriceMapper::letter($c), array_keys($preview['mapping']['columns']))),
        ]);
    }

    #[Route('/{id}/revisions', name: 'app_price_history', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function history(PriceList $list, #[CurrentUser] ?User $user): Response
    {
        if (!$this->access->canView($user, $list)) {
            throw $this->createNotFoundException();
        }
        $revisions = array_values(array_filter($this->revisions->findForList($list), fn (PriceRevision $r): bool => $this->access->canViewRevision($user, $r)));

        return $this->render('prices/history.html.twig', [
            'list' => $list,
            'revisions' => $revisions,
            'can_manage' => $this->access->canManage($user),
            'can_original' => $this->access->canUpload($user) || $list->isOriginalDownload(),
        ]);
    }

    #[Route('/{id}/revisions/{number}', name: 'app_price_revision', requirements: ['id' => '\d+', 'number' => '\d+'], methods: ['GET'])]
    public function revisionPage(PriceList $list, int $number, #[CurrentUser] ?User $user): Response
    {
        $revision = $this->revision($list, $number);
        if (!$this->access->canViewRevision($user, $revision)) {
            throw $this->createNotFoundException();
        }
        $diff = $revision->isDraft() ? null : $this->service->diff($revision);

        return $this->render('prices/revision.html.twig', [
            'list' => $list,
            'revision' => $revision,
            'diff' => $diff,
            'can_manage' => $this->access->canManage($user),
            'can_original' => $this->access->canDownloadOriginal($user, $revision),
            'can_edit' => null !== $user && $this->service->canEdit($user, $revision),
            'can_delete' => null !== $user && !$revision->isCurrent() && ($this->access->canManage($user) || (!$revision->isPublished() && $revision->getUploadedBy()?->getId() === $user->getId())),
        ]);
    }

    #[Route('/{id}/revisions/{number}/decide', name: 'app_price_decide', requirements: ['id' => '\d+', 'number' => '\d+'], methods: ['POST'])]
    public function decide(PriceList $list, int $number, Request $request, #[CurrentUser] ?User $user): Response
    {
        $this->csrf($request);
        $revision = $this->revision($list, $number);
        if (null === $user) {
            throw $this->createAccessDeniedException();
        }
        $note = (string) $request->request->get('note', '');
        try {
            if ('approve' === $request->request->get('decision')) {
                $this->service->approve($revision, $user, $note);
                $this->addFlash('success', \sprintf('Редакция %d прайс-листа «%s» опубликована.', $revision->getNumber(), $list->getTitle()));
            } else {
                $this->service->reject($revision, $user, $note);
                $this->addFlash('success', \sprintf('Редакция %d возвращена на доработку.', $revision->getNumber()));
            }
        } catch (\DomainException|\InvalidArgumentException $e) {
            $this->addFlash('error', $e->getMessage());
        }
        $back = (string) $request->request->get('back', '');

        return str_starts_with($back, '/') && !str_starts_with($back, '//') ? $this->redirect($back) : $this->redirectToRoute('app_price_revision', ['id' => $list->getId(), 'number' => $number]);
    }

    #[Route('/{id}/revisions/{number}/current', name: 'app_price_make_current', requirements: ['id' => '\d+', 'number' => '\d+'], methods: ['POST'])]
    public function makeCurrent(PriceList $list, int $number, Request $request, #[CurrentUser] ?User $user): Response
    {
        $this->csrf($request);
        $revision = $this->revision($list, $number);
        try {
            $this->service->makeCurrent($revision, $this->user($user));
            $this->addFlash('success', \sprintf('Действующая редакция — %d.', $revision->getNumber()));
        } catch (\DomainException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('app_price_history', ['id' => $list->getId()]);
    }

    #[Route('/{id}/revisions/{number}/delete', name: 'app_price_revision_delete', requirements: ['id' => '\d+', 'number' => '\d+'], methods: ['POST'])]
    public function deleteRevision(PriceList $list, int $number, Request $request, #[CurrentUser] ?User $user): Response
    {
        $this->csrf($request);
        $revision = $this->revision($list, $number);
        $noRevisions = null === $list->getCurrentRevision();
        try {
            $this->service->deleteRevision($revision, $this->user($user));
            $this->addFlash('success', \sprintf('Редакция %d удалена.', $number));
        } catch (\DomainException $e) {
            $this->addFlash('error', $e->getMessage());

            return $this->redirectToRoute('app_price_revision', ['id' => $list->getId(), 'number' => $number]);
        }
        // Отменили загрузку первого файла нового прайс-листа — пустой прайс-лист больше не нужен.
        if ($noRevisions && $this->service->discardEmpty($list, $this->user($user))) {
            return $this->redirectToRoute('app_prices');
        }

        return $this->redirectToRoute('app_price_history', ['id' => $list->getId()]);
    }

    #[Route('/{id}/revisions/{number}/file', name: 'app_price_file', requirements: ['id' => '\d+', 'number' => '\d+'], methods: ['GET'])]
    public function original(PriceList $list, int $number, #[CurrentUser] ?User $user): Response
    {
        $revision = $this->revision($list, $number);
        if (!$this->access->canDownloadOriginal($user, $revision)) {
            throw $this->createNotFoundException();
        }
        $path = $this->service->path($revision);
        if (!is_file($path)) {
            throw $this->createNotFoundException('Файл редакции не найден в хранилище.');
        }
        $response = new BinaryFileResponse($path);
        $response->headers->set('Content-Disposition', HeaderUtils::makeDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $revision->getOriginalName(), 'price-'.$list->getId().'-'.$number.'.'.$revision->getExtension()));

        return $response;
    }

    #[Route('/{id}/export', name: 'app_price_export', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function export(PriceList $list, Request $request, #[CurrentUser] ?User $user): Response
    {
        $revision = $this->revisionFromQuery($list, $request, $user);
        if (null === $revision || !$this->access->canView($user, $list) || !$this->access->canViewRevision($user, $revision)) {
            throw $this->createNotFoundException();
        }
        $path = $this->service->export($revision);
        $response = new BinaryFileResponse($path);
        $response->headers->set('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $response->headers->set('Content-Disposition', HeaderUtils::makeDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $this->service->fileName($revision, 'xlsx'), 'price-'.$list->getId().'.xlsx'));
        $response->deleteFileAfterSend(true);

        return $response;
    }

    #[Route('/{id}/settings', name: 'app_price_settings', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function settings(PriceList $list, Request $request, #[CurrentUser] ?User $user): Response
    {
        if (!$this->access->canManage($user)) {
            throw $this->createAccessDeniedException('Настройки прайс-листа меняют маркетологи и администраторы.');
        }
        $error = null;
        if ($request->isMethod('POST')) {
            $this->csrf($request);
            try {
                $this->service->update($list, $user, (string) $request->request->get('title', ''), (string) $request->request->get('description', ''),
                    (string) $request->request->get('currency', 'RUB'), $request->request->getBoolean('public'), $request->request->getInt('position', $list->getPosition()),
                    $request->request->getBoolean('original_download'));
                $this->addFlash('success', 'Настройки прайс-листа сохранены.');

                return $this->redirectToRoute('app_price_show', ['id' => $list->getId()]);
            } catch (\InvalidArgumentException $e) {
                $error = $e->getMessage();
            }
        }

        return $this->render('prices/settings.html.twig', ['list' => $list, 'currencies' => PriceList::CURRENCIES, 'error' => $error]);
    }

    #[Route('/{id}/archive', name: 'app_price_archive', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function archive(PriceList $list, Request $request, #[CurrentUser] ?User $user): Response
    {
        $this->csrf($request);
        $archived = $request->request->getBoolean('archived');
        try {
            $this->service->setArchived($list, $this->user($user), $archived);
            $this->addFlash('success', $archived ? \sprintf('Прайс-лист «%s» перенесён в архив — сотрудники его больше не видят.', $list->getTitle()) : \sprintf('Прайс-лист «%s» снова действует.', $list->getTitle()));
        } catch (\DomainException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('app_price_show', ['id' => $list->getId()]);
    }

    #[Route('/{id}/delete', name: 'app_price_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(PriceList $list, Request $request, #[CurrentUser] ?User $user): Response
    {
        $this->csrf($request);
        $title = $list->getTitle();
        try {
            $this->service->delete($list, $this->user($user));
            $this->addFlash('success', \sprintf('Прайс-лист «%s» удалён вместе с редакциями и файлами.', $title));
        } catch (\DomainException $e) {
            $this->addFlash('error', $e->getMessage());

            return $this->redirectToRoute('app_price_show', ['id' => $list->getId()]);
        }

        return $this->redirectToRoute('app_prices');
    }

    /** Редакция из ?revision=N (видимая пользователю) или действующая. */
    private function revisionFromQuery(PriceList $list, Request $request, ?User $user): ?PriceRevision
    {
        $number = $request->query->getInt('revision');
        if ($number > 0) {
            $revision = $this->revision($list, $number);

            return $this->access->canViewRevision($user, $revision) ? $revision : throw $this->createNotFoundException();
        }

        return $list->getCurrentRevision();
    }

    private function revision(PriceList $list, int $number): PriceRevision
    {
        return $this->revisions->findOneBy(['priceList' => $list, 'number' => $number]) ?? throw $this->createNotFoundException('Редакция не найдена.');
    }

    private function user(?User $user): User
    {
        return $user ?? throw $this->createAccessDeniedException();
    }

    private function csrf(Request $request): void
    {
        if (!$this->isCsrfTokenValid(self::CSRF, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Страница устарела — обновите её и повторите.');
        }
    }
}
