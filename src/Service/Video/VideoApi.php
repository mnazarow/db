<?php

declare(strict_types=1);

namespace App\Service\Video;

use App\Service\Http\HttpTransportInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Обращения портала к API видеопортала по внутреннему адресу (VIDEO_API_URL: http://127.0.0.1:3100 на
 * сервере, http://video-api:3000 в Docker): состояние для «Мониторинга», число видео на модерации для
 * шапки и страницы «На модерации», поиск видео для страницы поиска портала.
 *
 * Запросы подписаны общим секретом (VIDEO_SHARED_SECRET = PORTAL_SHARED_SECRET видеопортала) и идут
 * на адреса /api/internal/…, которые nginx снаружи не пропускает. Видеопортал не отвечает или раздел
 * выключен — методы возвращают «нет данных», а не ошибку: страницы портала от видео не зависят.
 */
final class VideoApi
{
    /** Сколько секунд кэшируется число видео на модерации (шапка показывается на каждой странице). */
    public const PENDING_CACHE_SECONDS = 60;

    private const PENDING_CACHE_KEY = 'video_moderation_pending';

    public function __construct(
        private readonly VideoIntegration $video,
        private readonly HttpTransportInterface $http,
        private readonly CacheInterface $cache,
        private readonly LoggerInterface $logger,
        #[Autowire(env: 'default::VIDEO_API_URL')]
        private readonly ?string $apiUrl = null,
    ) {
    }

    /** Раздел включён, секрет задан и известен внутренний адрес API. */
    public function isAvailable(): bool
    {
        return $this->video->isConfigured() && '' !== $this->baseUrl();
    }

    /**
     * Состояние видеопортала: база, очередь обработки, обработчик (worker), место под видео, синхронизация
     * сотрудников. null — раздел выключен; ['error' => …] — видеопортал не ответил.
     *
     * @return array<string, mixed>|null
     */
    public function status(): ?array
    {
        if (!$this->isAvailable()) {
            return null;
        }
        $response = $this->http->request('GET', $this->baseUrl().'/api/internal/status', null, $this->headers(), 5);
        $data = $response->json();
        if (!$response->ok() || null === $data) {
            return ['error' => $response->describeError()];
        }

        return $data;
    }

    /** Сколько видео ждут модерации (0 — нет данных); кэшируется на минуту для всех модераторов сразу. */
    public function pendingModeration(): int
    {
        if (!$this->isAvailable()) {
            return 0;
        }
        try {
            return (int) $this->cache->get(self::PENDING_CACHE_KEY, function (ItemInterface $item): int {
                $item->expiresAfter(self::PENDING_CACHE_SECONDS);
                $response = $this->http->request('GET', $this->baseUrl().'/api/internal/moderation', null, $this->headers(), 2);
                $data = $response->json();
                if (!$response->ok() || null === $data) {
                    // Не отвечает — до следующей попытки (через минуту) считаем, что видео на модерации нет.
                    $this->logger->info('Видеопортал не сообщил число видео на модерации', ['error' => $response->describeError()]);

                    return 0;
                }

                return max(0, (int) ($data['pending'] ?? 0));
            });
        } catch (\Throwable) {
            return 0;
        }
    }

    /** Сбросить кэш числа видео на модерации (тесты, решение модератора). */
    public function forgetPending(): void
    {
        try {
            $this->cache->delete(self::PENDING_CACHE_KEY);
        } catch (\Throwable) {
        }
    }

    /**
     * Поиск видео для страницы поиска портала. $employee — сотрудник вошёл (видны и внутренние видео),
     * иначе только открытые всем. Адреса в результатах — от корня сайта (/video/watch/…).
     *
     * @return array{total: int, items: list<array{title: string, url: string, thumbnail: ?string, duration: ?int, channel: string, published_at: ?string, snippet: string}>}|null
     */
    public function search(string $query, bool $employee, int $limit = 6): ?array
    {
        $query = trim($query);
        if (!$this->isAvailable() || '' === $query) {
            return null;
        }
        $url = $this->baseUrl().'/api/internal/search?'.http_build_query(['q' => mb_substr($query, 0, 200), 'audience' => $employee ? 'employee' : 'guest', 'limit' => max(1, min(20, $limit))]);
        $response = $this->http->request('GET', $url, null, $this->headers(), 3);
        $data = $response->json();
        if (!$response->ok() || null === $data || !\is_array($data['items'] ?? null)) {
            $this->logger->info('Поиск по видео недоступен', ['error' => $response->describeError()]);

            return null;
        }
        $items = [];
        foreach ($data['items'] as $row) {
            if (!\is_array($row) || '' === (string) ($row['shortId'] ?? '')) {
                continue;
            }
            $thumb = (string) ($row['thumbnail'] ?? '');
            $items[] = [
                'title' => (string) ($row['title'] ?? ''),
                'url' => $this->video->url('/watch/'.rawurlencode((string) $row['shortId'])),
                'thumbnail' => '' !== $thumb ? $this->video->url('/media/'.ltrim($thumb, '/')) : null,
                'duration' => isset($row['duration']) ? (int) $row['duration'] : null,
                'channel' => (string) ($row['channel'] ?? ''),
                'published_at' => isset($row['publishedAt']) ? (string) $row['publishedAt'] : null,
                'snippet' => trim(strip_tags((string) ($row['snippet'] ?? ''))),
            ];
        }

        return ['total' => max(\count($items), (int) ($data['total'] ?? 0)), 'items' => $items];
    }

    private function baseUrl(): string
    {
        return rtrim(trim((string) $this->apiUrl), '/');
    }

    /** @return array<string, string> */
    private function headers(): array
    {
        return ['Accept' => 'application/json', 'X-Video-Secret' => $this->video->sharedSecret()];
    }
}
