<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Controller\DocumentController;
use App\Entity\Document;
use App\Entity\DocumentDeletion;
use App\Entity\Section;
use App\Repository\DocumentRepository;
use App\Entity\DocumentVersion;
use App\Repository\SectionRepository;
use App\Security\Api\ApiPrincipal;
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

    public function __construct(
        private readonly DocumentRepository $documents,
        private readonly SectionRepository $sections,
        private readonly EntityManagerInterface $em,
        private readonly ApiPresenter $presenter,
        private readonly TextExtractor $extractor,
        private readonly FileStorage $storage,
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
        $versions = $document->getVersions()->toArray();
        usort($versions, static fn ($a, $b) => $a->getNumber() <=> $b->getNumber());
        $data['versions'] = array_map($this->presenter->version(...), $versions);
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
     * (removed), и удалённые (deleted). Значение next_since передаётся в следующий запрос.
     */
    #[Route('/changes', name: 'changes', methods: ['GET'])]
    public function changes(Request $request, #[CurrentUser] ApiPrincipal $principal): ApiResponse
    {
        $since = self::parseDate((string) $request->query->get('since', ''), 'since');
        if (null === $since) {
            throw new BadRequestHttpException('Укажите параметр since (дата и время в формате ISO 8601, например 2026-09-01T00:00:00+03:00).');
        }
        $now = new \DateTimeImmutable();
        $limit = min(1000, max(1, (int) $request->query->get('limit', 500)));
        $filters = $this->filters($request, $principal);
        $filters['updated_since'] = $since;
        $changed = $this->documents->findForApi($filters, 1, $limit);
        $includeArchived = \in_array(Document::STATUS_ARCHIVED, $filters['statuses'], true);
        $hidden = $this->documents->findHiddenSinceForApi($since, !$principal->includesInternal(), $includeArchived, $limit);
        $deletions = $this->em->getRepository(DocumentDeletion::class)->createQueryBuilder('x')
            ->andWhere('x.deletedAt > :since')->setParameter('since', $since)
            ->orderBy('x.deletedAt', 'ASC')->setMaxResults($limit)->getQuery()->getResult();

        return new ApiResponse([
            'since' => $since->format(\DATE_ATOM),
            'until' => $now->format(\DATE_ATOM),
            'next_since' => $now->format(\DATE_ATOM),
            'changed' => array_map($this->presenter->document(...), $changed['items']),
            'changed_total' => $changed['total'],
            'removed' => array_map(static fn (Document $d): array => ['id' => $d->getId(), 'title' => $d->getTitle(), 'status' => $d->getStatus(), 'is_public' => $d->isPublic(), 'updated_at' => $d->getUpdatedAt()->format(\DATE_ATOM)], $hidden),
            'deleted' => array_map($this->presenter->deletion(...), $deletions),
            'truncated' => $changed['total'] > $limit,
        ]);
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
        $sectionId = (int) $request->query->get('section');
        if ($sectionId > 0) {
            $section = $this->sections->find($sectionId) ?? throw new NotFoundHttpException(\sprintf('Раздел %d не найден.', $sectionId));
        }
        $type = trim((string) $request->query->get('type', ''));
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
            return new \DateTimeImmutable($value);
        } catch (\Exception) {
            throw new BadRequestHttpException(\sprintf('Параметр %s должен быть датой в формате ISO 8601.', $param));
        }
    }

    private function assertVisible(Document $document, ApiPrincipal $principal): void
    {
        if ($document->isDraft() || (!$principal->includesInternal() && !$document->isPublic())) {
            throw new NotFoundHttpException(\sprintf('Документ %d не найден.', (int) $document->getId()));
        }
    }

    private function version(Document $document, Request $request): DocumentVersion
    {
        $number = (int) $request->query->get('version');
        if ($number > 0) {
            return $document->findVersion($number) ?? throw new NotFoundHttpException(\sprintf('Версия %d не найдена.', $number));
        }

        return $document->getCurrentVersion() ?? throw new NotFoundHttpException('У документа нет версий.');
    }
}
