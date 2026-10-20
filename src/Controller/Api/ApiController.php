<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Controller\DocumentController;
use App\Entity\Document;
use App\Entity\DocumentDeletion;
use App\Entity\DocumentVersion;
use App\Entity\Section;
use App\Repository\DocumentRepository;
use App\Repository\SectionRepository;
use App\Security\Api\ApiPrincipal;
use App\Service\DocumentLinkService;
use App\Service\FileStorage;
use App\Service\Integration\ApiPresenter;
use App\Service\Text\TextExtractor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * REST API портала (v1) для внешних систем — прежде всего для выгрузки документов в RAG-индекс.
 *
 * Доступ по ключу API (Authorization: Bearer dp_… или X-Api-Key). Внешней системе видны только
 * опубликованные (и, по запросу, архивные) документы; внутренние документы — только ключам с соответствующим правом.
 * Черновики через API недоступны. Обращения через API не учитываются в статистике просмотров и скачиваний.
 */
#[Route('/api/v1', name: 'api_')]
final class ApiController extends AbstractController
{
    public const VERSION = '1';
    public const MAX_PER_PAGE = 200;
    public const DEFAULT_PER_PAGE = 50;

    /** Насколько next_since сдвигается назад (с): страховка от потери изменений в ту же секунду. */
    public const OVERLAP_SECONDS = 2;

    public function __construct(
        private readonly DocumentRepository $documents,
        private readonly SectionRepository $sections,
        private readonly EntityManagerInterface $em,
        private readonly ApiPresenter $presenter,
        private readonly TextExtractor $extractor,
        private readonly FileStorage $storage,
        private readonly DocumentLinkService $links,
        private readonly string $appName,
        private readonly string $appVersion,
    ) {
    }

    /** Сведения об API и о предъявленном ключе. */
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(#[CurrentUser] ApiPrincipal $principal): ApiResponse
    {
        $key = $principal->getKey();

        return new ApiResponse([
            'name' => $this->appName,
            'api_version' => self::VERSION,
            'portal_version' => $this->appVersion,
            'server_time' => (new \DateTimeImmutable())->format(\DATE_ATOM),
            'key' => ['name' => $key->getName(), 'include_internal' => $key->isIncludeInternal(), 'prefix' => $key->getTokenPrefix()],
            'text_extraction' => ['pdf' => $this->extractor->hasPdftotext(), 'extensions' => $this->extractor->supportedExtensions()],
            'endpoints' => [
                'sections' => $this->generateUrl('api_sections', [], UrlGeneratorInterface::ABSOLUTE_URL),
                'documents' => $this->generateUrl('api_documents', [], UrlGeneratorInterface::ABSOLUTE_URL),
                'document' => $this->generateUrl('api_document', ['id' => 1], UrlGeneratorInterface::ABSOLUTE_URL),
                'content' => $this->generateUrl('api_document_content', ['id' => 1], UrlGeneratorInterface::ABSOLUTE_URL),
                'text' => $this->generateUrl('api_document_text', ['id' => 1], UrlGeneratorInterface::ABSOLUTE_URL),
                'changes' => $this->generateUrl('api_changes', [], UrlGeneratorInterface::ABSOLUTE_URL),
                'search' => $this->generateUrl('api_search', [], UrlGeneratorInterface::ABSOLUTE_URL),
                // Вопросы помощнику от имени сотрудника (ключ с доступом к внутренним документам).
                'assistant' => $this->generateUrl('api_assistant', [], UrlGeneratorInterface::ABSOLUTE_URL),
            ],
        ]);
    }

    /** Дерево разделов (плоский список с parent_id, в порядке обхода дерева). */
    #[Route('/sections', name: 'sections', methods: ['GET'])]
    public function sections(#[CurrentUser] ApiPrincipal $principal): ApiResponse
    {
        $counts = $this->documents->countPerSection(!$principal->includesInternal());
        $items = [];
        foreach ($this->sections->findAllTree() as $section) {
            $items[] = $this->presenter->section($section, (int) ($counts[$section->getId()][Document::STATUS_PUBLISHED] ?? 0));
        }

        return new ApiResponse(['items' => $items, 'total' => \count($items)]);
    }

    #[Route('/sections/{id}', name: 'section', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function section(Section $section, #[CurrentUser] ApiPrincipal $principal): ApiResponse
    {
        $counts = $this->documents->countPerSection(!$principal->includesInternal());
        $data = $this->presenter->section($section, (int) ($counts[$section->getId()][Document::STATUS_PUBLISHED] ?? 0));
        $data['children'] = array_map(fn (Section $child): array => $this->presenter->section($child, (int) ($counts[$child->getId()][Document::STATUS_PUBLISHED] ?? 0)), $section->getChildren()->toArray());

        return new ApiResponse($data);
    }

    /**
     * Список документов с фильтрами и постраничной выдачей (сортировка по времени изменения — удобно для синхронизации).
     *
     * Параметры: updated_since (ISO 8601), section (id; subtree=0 — без подразделов), type (file|page),
     * status (published|archived|all), tag, q, page, per_page (≤200).
     */
    #[Route('/documents', name: 'documents', methods: ['GET'])]
    public function documents(Request $request, #[CurrentUser] ApiPrincipal $principal): ApiResponse
    {
        $page = max(1, (int) $request->query->get('page', 1));
        $perPage = min(self::MAX_PER_PAGE, max(1, (int) $request->query->get('per_page', self::DEFAULT_PER_PAGE)));
        $this->preloadSections();
        $filters = $this->filters($request, $principal);
        $result = $this->documents->findForApi($filters, $page, $perPage);

        return new ApiResponse([
            'items' => array_map($this->presenter->document(...), $result['items']),
            'page' => $page,
            'per_page' => $perPage,
            'total' => $result['total'],
            'pages' => (int) max(1, ceil($result['total'] / $perPage)),
            'filters' => [
                'status' => $filters['status_param'],
                'include_internal' => $principal->includesInternal(),
                'updated_since' => $filters['updated_since']?->format(\DATE_ATOM),
                'section' => $filters['section']?->getId(),
                'subtree' => $filters['subtree'],
                'type' => $filters['type'],
                'tag' => $filters['tag'],
                'q' => $filters['q'],
            ],
        ]);
    }

    /** Поиск (как на портале): q — слова запроса, section — ограничить разделом, limit ≤ 200. */
    #[Route('/search', name: 'search', methods: ['GET'])]
    public function search(Request $request, #[CurrentUser] ApiPrincipal $principal): ApiResponse
    {
        $q = trim((string) $request->query->get('q', ''));
        if (mb_strlen($q) < 2) {
            throw new BadRequestHttpException('Параметр q должен содержать не менее 2 символов.');
        }
        $filters = $this->filters($request, $principal);
        $filters['q'] = $q;
        $this->preloadSections();
        $limit = min(self::MAX_PER_PAGE, max(1, (int) $request->query->get('limit', self::DEFAULT_PER_PAGE)));
        $result = $this->documents->findForApi($filters, 1, $limit);

        return new ApiResponse(['q' => $q, 'items' => array_map($this->presenter->document(...), $result['items']), 'total' => $result['total'], 'limit' => $limit]);
    }

    /** Карточка документа: реквизиты, все версии и текст текущей версии (text=0 — без текста). */
    #[Route('/documents/{id}', name: 'document', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function document(Document $document, Request $request, #[CurrentUser] ApiPrincipal $principal): ApiResponse
    {
        $this->assertVisible($document, $principal);
        $data = $this->presenter->document($document);
        // Новая редакция, ждущая согласования, ещё не действует — внешним системам её не отдаём.
        $versions = array_values(array_filter($document->getVersions()->toArray(), static fn (DocumentVersion $v): bool => $document->isVersionReleased($v)));
        usort($versions, static fn ($a, $b) => $a->getNumber() <=> $b->getNumber());
        $data['versions'] = array_map($this->presenter->version(...), $versions);
        // Связи с другими документами: индексатору RAG важно знать, какой документ заменён и чем.
        $data['related'] = [];
        foreach ($this->links->forDocument($document) as $row) {
            $other = $row['document'];
            if ($this->isVisibleTo($other, $principal)) {
                $data['related'][] = [
                    'relation' => $row['label'],
                    'type' => $row['link']->getType(),
                    'id' => $other->getId(),
                    'code' => $other->getCode(),
                    'title' => $other->getTitle(),
                    'status' => $other->getStatus(),
                ];
            }
        }
        $withText = filter_var($request->query->get('text', '1'), \FILTER_VALIDATE_BOOL);
        $current = $document->getCurrentVersion();
        if ($withText && null !== $current) {
            $extracted = $this->extractor->extract($current);
            $data['text'] = $extracted['text'];
            $data['text_status'] = $extracted['status'];
            $data['text_chars'] = $extracted['chars'];
        }

        return new ApiResponse($data);
    }

    /** Извлечённый текст версии (по умолчанию — текущей) в JSON. */
    #[Route('/documents/{id}/text', name: 'document_text', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function text(Document $document, Request $request, #[CurrentUser] ApiPrincipal $principal): ApiResponse
    {
        $this->assertVisible($document, $principal);
        $version = $this->version($document, $request);
        $extracted = $this->extractor->extract($version);

        return new ApiResponse([
            'id' => $document->getId(),
            'title' => $document->getTitle(),
            'version' => $version->getNumber(),
            'file_name' => $version->getOriginalName(),
            'status' => $extracted['status'],
            'chars' => $extracted['chars'],
            'text' => $extracted['text'],
        ]);
    }

    /** Содержимое версии: файл как есть (attachment) или страница как HTML. */
    #[Route('/documents/{id}/content', name: 'document_content', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function content(Document $document, Request $request, #[CurrentUser] ApiPrincipal $principal): Response
    {
        $this->assertVisible($document, $principal);
        $version = $this->version($document, $request);
        if ($version->isPage()) {
            $html = '<!DOCTYPE html><html lang="ru"><head><meta charset="utf-8"><title>'.htmlspecialchars($document->getTitle()).'</title></head><body>'.$version->getContent().'</body></html>';

            return new Response($html, 200, [
                'Content-Type' => 'text/html; charset=UTF-8',
                'Content-Disposition' => HeaderUtils::makeDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, DocumentController::asciiName($document->getTitle()).'.html', 'document.html'),
                'X-Content-Type-Options' => 'nosniff',
                'Content-Security-Policy' => FileStorage::SANDBOX_CSP,
                'X-Document-Version' => (string) $version->getNumber(),
            ]);
        }
        $absolute = $this->storage->absolutePath($version);
        if (null === $absolute || !is_file($absolute)) {
            throw new NotFoundHttpException('Файл версии не найден в хранилище.');
        }
        $response = new BinaryFileResponse($absolute);
        $response->headers->set('Content-Type', $version->getMimeType() ?: 'application/octet-stream');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Content-Security-Policy', FileStorage::SANDBOX_CSP);
        $response->headers->set('X-Document-Version', (string) $version->getNumber());
        if (null !== $version->getChecksum()) {
            $response->headers->set('ETag', '"'.$version->getChecksum().'"');
        }
        $name = $version->getOriginalName() ?? ('document.'.$version->getExtension());
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $name, DocumentController::asciiName($name));
        $response->setPrivate();

        return $response;
    }

    /**
     * Изменения с момента since (ISO 8601): изменившиеся видимые документы, документы, ставшие невидимыми
     * (removed), и удалённые (deleted).
     *
     * Все три списка — одна лента, упорядоченная по (время, список, id). Если изменений больше limit, ответ
     * содержит next_cursor: следующий запрос с cursor=… продолжает ровно с того места (даже если сотни
     * документов изменились в одну секунду). next_since остаётся для простых клиентов.
     */
    #[Route('/changes', name: 'changes', methods: ['GET'])]
    public function changes(Request $request, #[CurrentUser] ApiPrincipal $principal): ApiResponse
    {
        $cursor = self::decodeCursor((string) $request->query->get('cursor', ''));
        $since = $cursor['at'] ?? self::parseDate((string) $request->query->get('since', ''), 'since');
        if (null === $since) {
            throw new BadRequestHttpException('Укажите параметр since (дата и время в формате ISO 8601, например 2026-09-01T00:00:00+03:00) или cursor из предыдущего ответа.');
        }
        $now = new \DateTimeImmutable();
        $limit = min(1000, max(1, (int) $request->query->get('limit', 500)));
        $filters = $this->filters($request, $principal);
        $includeArchived = \in_array(Document::STATUS_ARCHIVED, $filters['statuses'], true);
        $publicOnly = !$principal->includesInternal();
        $this->preloadSections();

        // Порог для каждого списка: записи строго после (время, список, id) курсора; без курсора — после since.
        $afterIn = static function (int $list) use ($cursor): int {
            if (null === $cursor) {
                return \PHP_INT_MAX;
            }

            return $list > $cursor['list'] ? 0 : ($list === $cursor['list'] ? $cursor['id'] : \PHP_INT_MAX);
        };
        $stream = [];
        foreach ($this->documents->findChangedForApiAfter($filters, $since, $afterIn(0), $limit) as $d) {
            $stream[] = [$d->getUpdatedAt()->format('Y-m-d H:i:s'), 0, (int) $d->getId(), $d];
        }
        foreach ($this->documents->findHiddenForApiAfter($since, $afterIn(1), $publicOnly, $includeArchived, $limit) as $d) {
            $stream[] = [$d->getUpdatedAt()->format('Y-m-d H:i:s'), 1, (int) $d->getId(), $d];
        }
        $deletions = $this->em->getRepository(DocumentDeletion::class)->createQueryBuilder('x')
            ->andWhere('x.deletedAt > :at OR (x.deletedAt = :at AND x.documentId > :after)')
            ->setParameter('at', $since)->setParameter('after', $afterIn(2))
            ->orderBy('x.deletedAt', \SortDirection::Ascending)->addOrderBy('x.documentId', \SortDirection::Ascending)->setMaxResults($limit + 1)
            ->getQuery()->getResult();
        foreach ($deletions as $x) {
            $stream[] = [$x->getDeletedAt()->format('Y-m-d H:i:s'), 2, $x->getDocumentId(), $x];
        }
        usort($stream, static fn (array $a, array $b): int => [$a[0], $a[1], $a[2]] <=> [$b[0], $b[1], $b[2]]);
        $truncated = \count($stream) > $limit;
        $page = \array_slice($stream, 0, $limit);

        $changed = $hidden = $deleted = [];
        foreach ($page as [, $list, , $item]) {
            match ($list) {
                0 => $changed[] = $item,
                1 => $hidden[] = $item,
                default => $deleted[] = $item,
            };
        }
        $last = [] !== $page ? $page[array_key_last($page)] : null;
        if ($truncated && null !== $last) {
            $nextCursor = self::encodeCursor($last[0], $last[1], $last[2]);
            // Для клиентов без курсора: с перекрытием, чтобы не потерять записи той же секунды (повтор безвреден),
            // но обязательно дальше since — иначе при сотнях изменений за пару секунд клиент получал бы одну и
            // ту же страницу бесконечно. Точный постраничный проход — только по курсору.
            $nextSince = (new \DateTimeImmutable($last[0]))->modify('-'.self::OVERLAP_SECONDS.' seconds');
            $minimum = $since->modify('+1 second');
            if ($nextSince < $minimum) {
                $nextSince = $minimum;
            }
        } else {
            $nextCursor = null;
            // Следующий запрос делаем с небольшим перекрытием: у времени изменения точность до секунды,
            // и документ, изменённый в ту же секунду, иначе потерялся бы.
            $nextSince = $now->modify('-'.self::OVERLAP_SECONDS.' seconds');
        }

        return new ApiResponse([
            'since' => $since->format(\DATE_ATOM),
            'until' => $now->format(\DATE_ATOM),
            'next_since' => $nextSince->format(\DATE_ATOM),
            'next_cursor' => $nextCursor,
            // Скрытые документы отдаём без названий: ключ «только открытые» не должен видеть
            // содержимое внутренних документов и черновиков — индексатору достаточно id и причины.
            'changed' => array_map($this->presenter->document(...), $changed),
            // Сколько видимых документов изменилось после since (всего, без учёта страниц курсора).
            'changed_total' => $this->documents->findForApi(['updated_since' => $since] + $filters, 1, 1)['total'],
            'removed' => array_map(static fn (Document $d): array => [
                'id' => $d->getId(),
                'reason' => match (true) {
                    $d->isUnpublished() => 'draft',
                    $d->isArchived() && !$includeArchived => 'archived',
                    default => 'internal',
                },
                'updated_at' => $d->getUpdatedAt()->format(\DATE_ATOM),
            ], $hidden),
            'deleted' => array_map(fn (DocumentDeletion $d): array => $this->presenter->deletion($d, !$publicOnly), $deleted),
            'truncated' => $truncated,
        ]);
    }

    /** Курсор ленты изменений: время, номер списка (0 — changed, 1 — removed, 2 — deleted) и id. */
    private static function encodeCursor(string $at, int $list, int $id): string
    {
        return rtrim(strtr(base64_encode(\sprintf('%s|%d|%d', $at, $list, $id)), '+/', '-_'), '=');
    }

    /** @return array{at: \DateTimeImmutable, list: int, id: int}|null */
    private static function decodeCursor(string $cursor): ?array
    {
        $cursor = trim($cursor);
        if ('' === $cursor) {
            return null;
        }
        $raw = base64_decode(strtr($cursor, '-_', '+/'), true);
        $at = false;
        if (false !== $raw && 1 === preg_match('/^(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\|([0-2])\|(\d{1,10})$/', $raw, $m)) {
            // Строгий разбор: несуществующая дата («2026-13-40») — ошибка запроса, а не ответ 500.
            $at = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $m[1]);
            $errors = \DateTimeImmutable::getLastErrors();
            if (false !== $at && false !== $errors && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) {
                $at = false;
            }
        }
        if (false === $at) {
            throw new BadRequestHttpException('Некорректный параметр cursor: передайте значение next_cursor из предыдущего ответа.');
        }

        return ['at' => $at, 'list' => (int) $m[2], 'id' => (int) $m[3]];
    }

    /**
     * Загружает всё дерево разделов одним запросом: в списках у каждого документа отдаётся путь по дереву,
     * и без этого предзагрузки родительские разделы подтягивались бы по одному.
     */
    private function preloadSections(): void
    {
        $this->sections->findAllTree();
    }

    /**
     * Общие фильтры списков.
     *
     * @return array{statuses: list<string>, status_param: string, public_only: bool, section: ?Section, subtree: bool, type: ?string, updated_since: ?\DateTimeImmutable, tag: ?string, q: ?string}
     */
    private function filters(Request $request, ApiPrincipal $principal): array
    {
        $status = strtolower(trim((string) $request->query->get('status', Document::STATUS_PUBLISHED)));
        if (filter_var($request->query->get('include_archived'), \FILTER_VALIDATE_BOOL)) {
            $status = 'all';
        }
        $statuses = match ($status) {
            '', Document::STATUS_PUBLISHED => [Document::STATUS_PUBLISHED],
            Document::STATUS_ARCHIVED => [Document::STATUS_ARCHIVED],
            'all' => [Document::STATUS_PUBLISHED, Document::STATUS_ARCHIVED],
            default => throw new BadRequestHttpException('Параметр status может быть published, archived или all (черновики через API недоступны).'),
        };
        $section = null;
        $sectionParam = trim((string) $request->query->get('section', ''));
        if ('' !== $sectionParam) {
            if (!ctype_digit($sectionParam) || 0 === (int) $sectionParam) {
                throw new BadRequestHttpException('Параметр section должен быть числовым идентификатором раздела.');
            }
            $section = $this->sections->find((int) $sectionParam) ?? throw new NotFoundHttpException(\sprintf('Раздел %d не найден.', (int) $sectionParam));
        }
        $type = strtolower(trim((string) $request->query->get('type', '')));
        if ('' !== $type && !\in_array($type, Document::TYPES, true)) {
            throw new BadRequestHttpException('Параметр type может быть file или page.');
        }
        $tag = trim((string) $request->query->get('tag', ''));
        $q = trim((string) $request->query->get('q', ''));

        return [
            'statuses' => $statuses,
            'status_param' => '' === $status ? Document::STATUS_PUBLISHED : $status,
            'public_only' => !$principal->includesInternal(),
            'section' => $section,
            'subtree' => !$request->query->has('subtree') || filter_var($request->query->get('subtree'), \FILTER_VALIDATE_BOOL),
            'type' => '' !== $type ? $type : null,
            'updated_since' => self::parseDate((string) $request->query->get('updated_since', ''), 'updated_since'),
            'tag' => '' !== $tag ? $tag : null,
            'q' => '' !== $q ? $q : null,
        ];
    }

    private static function parseDate(string $value, string $param): ?\DateTimeImmutable
    {
        $value = trim($value);
        if ('' === $value) {
            return null;
        }
        if (ctype_digit($value)) { // unix time
            return (new \DateTimeImmutable())->setTimestamp((int) $value);
        }
        try {
            // Время в базе хранится в часовом поясе портала: «2026-09-01T00:00:00Z» переводится в него,
            // иначе сравнение шло бы по «голым» часам и минутам и часть изменений терялась бы.
            return (new \DateTimeImmutable($value))->setTimezone(new \DateTimeZone(date_default_timezone_get()));
        } catch (\Exception) {
            throw new BadRequestHttpException(\sprintf('Параметр %s должен быть датой в формате ISO 8601.', $param));
        }
    }

    /**
     * Видимость документа для ключа API — те же правила, что у списков (findForApi): только опубликованные
     * и архивные, без документов с ограниченным доступом (они видны лишь указанным сотрудникам), внутренние —
     * только ключам с доступом к ним. Черновики и документы на согласовании через API не отдаются.
     */
    private function isVisibleTo(Document $document, ApiPrincipal $principal): bool
    {
        return ($document->isPublished() || $document->isArchived())
            && !$document->isRestricted()
            && ($principal->includesInternal() || $document->isPublic());
    }

    private function assertVisible(Document $document, ApiPrincipal $principal): void
    {
        if (!$this->isVisibleTo($document, $principal)) {
            throw new NotFoundHttpException(\sprintf('Документ %d не найден.', (int) $document->getId()));
        }
    }

    private function version(Document $document, Request $request): DocumentVersion
    {
        $number = (int) $request->query->get('version');
        if ($number > 0) {
            $version = $document->findVersion($number);
            if (null === $version || !$document->isVersionReleased($version)) {
                throw new NotFoundHttpException(\sprintf('Версия %d не найдена.', $number));
            }

            return $version;
        }

        return $document->getCurrentVersion() ?? throw new NotFoundHttpException('У документа нет версий.');
    }
}
