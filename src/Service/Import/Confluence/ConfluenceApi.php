<?php

declare(strict_types=1);

namespace App\Service\Import\Confluence;

use App\Service\Http\HttpResponse;
use App\Service\Http\HttpTransportInterface;

/**
 * Обращения к REST API Confluence. Confluence Cloud (*.atlassian.net) — API v2 (страницы пространства с номером
 * родителя, курсорные страницы ответа), Confluence Server и Data Center — API v1 (/rest/api/content). Наружу —
 * одинаковые массивы страниц и вложений.
 *
 * Вход: Cloud — почта и API-токен (Basic), Server/DC — личный токен (Bearer) или логин и пароль (Basic).
 */
final class ConfluenceApi
{
    private const TIMEOUT = 30;
    private const PAGE_LIMIT = 100;

    /** Предел страниц пространства за один импорт (защита от бесконечной выдачи). */
    public const MAX_PAGES = 20000;

    private readonly string $base;
    private readonly string $origin;
    private readonly bool $cloud;

    /** @var array<string, string> */
    private readonly array $headers;

    /** @var array<string, string> номер пространства (v2) по ключу */
    private array $spaceIds = [];

    /**
     * @param array{url: string, auth: string, user: string, token: string} $config
     */
    public function __construct(private readonly HttpTransportInterface $http, array $config)
    {
        [$this->base, $this->origin, $this->cloud] = self::normalizeUrl($config['url']);
        if ('' === $config['token']) {
            throw new ConfluenceException('Не задан токен (пароль) для входа в Confluence.');
        }
        $auth = 'token' === $config['auth']
            ? 'Bearer '.$config['token']
            : 'Basic '.base64_encode($config['user'].':'.$config['token']);
        if ('token' !== $config['auth'] && '' === $config['user']) {
            throw new ConfluenceException('cloud' === $config['auth'] ? 'Укажите почту учётной записи Atlassian.' : 'Укажите логин Confluence.');
        }
        $this->headers = ['Authorization' => $auth, 'Accept' => 'application/json', 'X-Atlassian-Token' => 'no-check'];
    }

    /**
     * Адрес Confluence: https://компания.atlassian.net → …/wiki (Cloud); Server — как указан (с путём, если есть).
     *
     * @return array{0: string, 1: string, 2: bool} база API, origin (схема и хост), Cloud ли это
     */
    public static function normalizeUrl(string $url): array
    {
        $url = rtrim(trim($url), '/');
        $parts = parse_url($url);
        if (!\is_array($parts) || !isset($parts['scheme'], $parts['host']) || !\in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            throw new ConfluenceException('Адрес Confluence — вида https://компания.atlassian.net/wiki или https://confluence.example.ru.');
        }
        $origin = strtolower($parts['scheme']).'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
        $path = rtrim($parts['path'] ?? '', '/');
        $cloud = str_ends_with(strtolower($parts['host']), '.atlassian.net');
        if ($cloud && '' === $path) {
            $path = '/wiki';
        }

        return [$origin.$path, $origin, $cloud];
    }

    public function isCloud(): bool
    {
        return $this->cloud;
    }

    public function base(): string
    {
        return $this->base;
    }

    /**
     * Пространства, доступные учётной записи (для выбора при импорте).
     *
     * @return list<array{key: string, name: string}>
     */
    public function spaces(): array
    {
        $out = [];
        $url = $this->cloud ? $this->base.'/api/v2/spaces?limit=250' : $this->base.'/rest/api/space?limit='.self::PAGE_LIMIT;
        foreach ($this->paginate($url, 50) as $space) {
            if (isset($space['key'])) {
                $out[] = ['key' => (string) $space['key'], 'name' => (string) ($space['name'] ?? $space['key'])];
            }
        }
        usort($out, static fn (array $a, array $b): int => strcmp(mb_strtolower($a['name']), mb_strtolower($b['name'])));

        return $out;
    }

    /** @return array{key: string, name: string} пространство по ключу */
    public function space(string $key): array
    {
        $key = trim($key);
        if ('' === $key || !preg_match('/^[A-Za-z0-9_~.-]{1,255}$/', $key)) {
            throw new ConfluenceException('Ключ пространства — латинские буквы и цифры (например, DOCS).');
        }
        if ($this->cloud) {
            $data = $this->get($this->base.'/api/v2/spaces?keys='.rawurlencode($key).'&limit=1');
            $space = $data['results'][0] ?? null;
            if (!\is_array($space)) {
                throw new ConfluenceException(\sprintf('Пространство %s не найдено или недоступно учётной записи.', $key));
            }
            $this->spaceIds[$key] = (string) $space['id'];

            return ['key' => (string) $space['key'], 'name' => (string) ($space['name'] ?? $key)];
        }
        $space = $this->get($this->base.'/rest/api/space/'.rawurlencode($key));

        return ['key' => (string) ($space['key'] ?? $key), 'name' => (string) ($space['name'] ?? $key)];
    }

    /**
     * Страницы пространства (без текста) — родители раньше детей, соседи в порядке Confluence. С $rootId — только
     * эта страница и её потомки.
     *
     * @return list<array{id: string, parentId: ?string, title: string, version: int, position: int, url: ?string}>
     */
    public function pages(string $spaceKey, ?string $rootId = null): array
    {
        $raw = [];
        if ($this->cloud) {
            $this->space($spaceKey);
            $url = $this->base.'/api/v2/spaces/'.rawurlencode($this->spaceIds[$spaceKey]).'/pages?limit=250&status=current';
            foreach ($this->paginate($url, (int) ceil(self::MAX_PAGES / 250)) as $p) {
                $raw[] = [
                    'id' => (string) $p['id'],
                    'parentId' => isset($p['parentId']) && 'page' === ($p['parentType'] ?? 'page') ? (string) $p['parentId'] : null,
                    'title' => (string) ($p['title'] ?? ''),
                    'version' => (int) ($p['version']['number'] ?? 1),
                    'position' => (int) ($p['position'] ?? 0),
                    'url' => isset($p['_links']['webui']) ? $this->absolute((string) $p['_links']['webui']) : null,
                ];
            }
        } else {
            $url = $this->base.'/rest/api/content?spaceKey='.rawurlencode($spaceKey).'&type=page&status=current&expand=ancestors,version,extensions.position&limit='.self::PAGE_LIMIT;
            foreach ($this->paginate($url, (int) ceil(self::MAX_PAGES / self::PAGE_LIMIT)) as $p) {
                $ancestors = \is_array($p['ancestors'] ?? null) ? $p['ancestors'] : [];
                $parent = [] !== $ancestors ? end($ancestors) : null;
                $raw[] = [
                    'id' => (string) $p['id'],
                    'parentId' => \is_array($parent) && isset($parent['id']) ? (string) $parent['id'] : null,
                    'title' => (string) ($p['title'] ?? ''),
                    'version' => (int) ($p['version']['number'] ?? 1),
                    'position' => (int) ($p['extensions']['position'] ?? 0),
                    'url' => isset($p['_links']['webui']) ? $this->absolute((string) $p['_links']['webui']) : null,
                ];
            }
        }

        return self::order($raw, $rootId);
    }

    /**
     * Текст страницы в формате хранения Confluence (XHTML с макросами).
     *
     * @return array{title: string, version: int, body: string}
     */
    public function page(string $id): array
    {
        $data = $this->cloud
            ? $this->get($this->base.'/api/v2/pages/'.rawurlencode($id).'?body-format=storage')
            : $this->get($this->base.'/rest/api/content/'.rawurlencode($id).'?expand=body.storage,version');

        return [
            'title' => (string) ($data['title'] ?? ''),
            'version' => (int) ($data['version']['number'] ?? 1),
            'body' => (string) ($data['body']['storage']['value'] ?? ''),
        ];
    }

    /**
     * Вложения страницы.
     *
     * @return list<array{id: string, title: string, mediaType: string, size: int, version: int, download: string}>
     */
    public function attachments(string $pageId): array
    {
        $out = [];
        $url = $this->cloud
            ? $this->base.'/api/v2/pages/'.rawurlencode($pageId).'/attachments?limit=250'
            : $this->base.'/rest/api/content/'.rawurlencode($pageId).'/child/attachment?limit='.self::PAGE_LIMIT.'&expand=version';
        foreach ($this->paginate($url, 20) as $a) {
            $download = (string) ($this->cloud ? ($a['downloadLink'] ?? '') : ($a['_links']['download'] ?? ''));
            if ('' === $download) {
                continue;
            }
            $out[] = [
                'id' => (string) $a['id'],
                'title' => (string) ($a['title'] ?? 'файл'),
                'mediaType' => strtolower((string) ($a['mediaType'] ?? $a['metadata']['mediaType'] ?? $a['extensions']['mediaType'] ?? 'application/octet-stream')),
                'size' => (int) ($a['fileSize'] ?? $a['extensions']['fileSize'] ?? 0),
                'version' => (int) ($a['version']['number'] ?? 1),
                'download' => $this->absolute($download),
            ];
        }

        return $out;
    }

    /** Скачать вложение в файл (не больше $maxBytes). */
    public function download(string $url, string $path, int $maxBytes): void
    {
        if (!str_starts_with($url, $this->origin.'/')) {
            throw new ConfluenceException('Вложение — не с адреса Confluence: '.$url);
        }
        $response = $this->http->download($url, $path, $this->headers, 300, $maxBytes);
        if (!$response->ok()) {
            throw new ConfluenceException('Вложение не скачано: '.self::describe($response));
        }
    }

    /**
     * Родители раньше детей, соседи — по позиции и названию; с $rootId — только поддерево.
     *
     * @param list<array{id: string, parentId: ?string, title: string, version: int, position: int, url: ?string}> $pages
     *
     * @return list<array{id: string, parentId: ?string, title: string, version: int, position: int, url: ?string}>
     */
    public static function order(array $pages, ?string $rootId = null): array
    {
        $byId = [];
        foreach ($pages as $p) {
            $byId[$p['id']] = $p;
        }
        // Номера страниц — строки из цифр: ключи массива PHP превращает в числа, поэтому дети собираются по списку.
        $children = [];
        foreach ($byId as $p) {
            $parent = null !== $p['parentId'] && isset($byId[$p['parentId']]) ? (string) $p['parentId'] : '';
            $children[$parent][] = (string) $p['id'];
        }
        foreach ($children as &$list) {
            usort($list, static fn (string $a, string $b): int => [$byId[$a]['position'], mb_strtolower($byId[$a]['title'])] <=> [$byId[$b]['position'], mb_strtolower($byId[$b]['title'])]);
        }
        unset($list);
        $out = [];
        $walk = static function (string $parent) use (&$walk, &$out, $children, $byId): void {
            foreach ($children[$parent] ?? [] as $id) {
                $out[] = $byId[$id];
                $walk((string) $id);
            }
        };
        if (null !== $rootId && '' !== $rootId) {
            if (!isset($byId[$rootId])) {
                throw new ConfluenceException(\sprintf('Страница %s не найдена в пространстве.', $rootId));
            }
            $root = $byId[$rootId];
            $root['parentId'] = null;
            $out[] = $root;
            $walk($rootId);

            return $out;
        }
        $walk('');

        return $out;
    }

    /** @return array<string, mixed> */
    private function get(string $url): array
    {
        $response = $this->http->request('GET', $url, null, $this->headers, self::TIMEOUT);
        if (!$response->ok()) {
            throw new ConfluenceException(self::describe($response));
        }
        $data = $response->json();
        if (null === $data) {
            throw new ConfluenceException('Confluence ответил не JSON — проверьте адрес (для Cloud: https://компания.atlassian.net/wiki).');
        }

        return $data;
    }

    /**
     * Все элементы постраничной выдачи (v1: start/limit и _links.next, v2: курсор в _links.next).
     *
     * @return iterable<array<string, mixed>>
     */
    private function paginate(string $url, int $maxPages): iterable
    {
        for ($i = 0; $i < $maxPages && null !== $url; ++$i) {
            $data = $this->get($url);
            foreach ($data['results'] ?? [] as $item) {
                if (\is_array($item)) {
                    yield $item;
                }
            }
            $next = $data['_links']['next'] ?? null;
            $url = \is_string($next) && '' !== $next ? $this->absolute($next) : null;
        }
    }

    /** Адрес из ответа Confluence: v2 даёт пути от корня сайта (/wiki/…), v1 — от базы Confluence. */
    private function absolute(string $link): string
    {
        if (preg_match('~^https?://~i', $link)) {
            return $link;
        }
        $path = (string) parse_url($this->base, \PHP_URL_PATH);
        if ('' !== $path && str_starts_with($link, $path.'/')) {
            return $this->origin.$link;
        }

        return $this->base.('/' === ($link[0] ?? '') ? '' : '/').$link;
    }

    private static function describe(HttpResponse $response): string
    {
        return match (true) {
            null !== $response->error => 'нет связи с Confluence: '.$response->error,
            401 === $response->status => 'Confluence не принял логин или токен (401).',
            403 === $response->status => 'у учётной записи нет доступа (403).',
            404 === $response->status => 'не найдено (404) — проверьте адрес Confluence и ключ пространства.',
            429 === $response->status => 'Confluence ограничил частоту запросов (429) — повторите позже.',
            default => 'Confluence ответил ошибкой '.$response->status.': '.$response->describeError(),
        };
    }
}
