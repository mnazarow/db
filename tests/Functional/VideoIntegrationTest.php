<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Section;
use App\Entity\User;
use App\Repository\DocumentApprovalRepository;
use App\Service\Http\HttpResponse;
use App\Service\Http\RecordingTransport;
use App\Service\Monitoring\HealthReport;
use App\Service\PortalSettings;
use App\Service\Video\VideoApi;
use App\Tests\PortalTestCase;

/**
 * Связка портала с разделом «Видео» сверх общего входа: справочник сотрудников и уведомления для
 * видеопортала (/internal/video/users, /internal/video/notify), число видео на модерации в шапке,
 * проверки видеопортала в «Мониторинге», видео в поиске портала. API видеопортала изображает
 * записывающий HTTP-транспорт.
 */
final class VideoIntegrationTest extends PortalTestCase
{
    private const SECRET = 'test-video-secret-0123456789abcdef';
    private const API = 'http://video-api.test';

    protected function setUp(): void
    {
        $_SERVER['VIDEO_API_URL'] = $_ENV['VIDEO_API_URL'] = self::API;
        parent::setUp();
        $this->http()->reset();
        $this->videoApi()->forgetPending();
    }

    protected function tearDown(): void
    {
        unset($_SERVER['VIDEO_API_URL'], $_ENV['VIDEO_API_URL']);
        parent::tearDown();
    }

    private function http(): RecordingTransport
    {
        return static::getContainer()->get(RecordingTransport::class);
    }

    private function videoApi(): VideoApi
    {
        return static::getContainer()->get(VideoApi::class);
    }

    /** @return list<array{method: string, url: string, body: ?string, headers: array<string, string>, timeout: int}> */
    private function videoRequests(string $path): array
    {
        return array_values(array_filter($this->http()->requests, static fn (array $r): bool => str_starts_with($r['url'], self::API.$path)));
    }

    public function testUsersDirectoryForVideoPortal(): void
    {
        $this->client->request('GET', '/internal/video/users');
        self::assertResponseStatusCodeSame(404, 'без секрета адреса как будто нет');
        $this->client->request('GET', '/internal/video/users', [], [], ['HTTP_X_VIDEO_SECRET' => 'wrong-secret-wrong-secret-wrong']);
        self::assertResponseStatusCodeSame(404);

        $smirnov = $this->user('smirnov');
        $smirnov->setActive(false);
        $this->em()->flush();

        $this->client->request('GET', '/internal/video/users', [], [], ['HTTP_X_VIDEO_SECRET' => self::SECRET]);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $byLogin = [];
        foreach ($data['users'] as $u) {
            $byLogin[$u['username']] = $u;
        }
        self::assertSame(User::POSITION_MARKETER, $byLogin['markova']['position']);
        self::assertSame(User::POSITION_ASSISTANT, $byLogin['belov']['position']);
        self::assertSame(User::POSITION_ADMIN, $byLogin['admin']['position']);
        self::assertSame(User::POSITION_MARKETER, $byLogin['ivanov']['position'], 'модератор раздела — модератор видео');
        self::assertFalse($byLogin['smirnov']['active'], 'заблокированный сотрудник передаётся неактивным — видеопортал его заблокирует');
        self::assertTrue($byLogin['markova']['active']);
        self::assertArrayHasKey('department', $byLogin['ivanov']);
        self::assertArrayNotHasKey('password', $byLogin['ivanov']);

        $smirnov = $this->user('smirnov');
        $smirnov->setActive(true);
        $this->em()->flush();
    }

    public function testNotificationsFromVideoPortalByMailAndTelegram(): void
    {
        $settings = static::getContainer()->get(PortalSettings::class);
        $settings->setTelegram(['enabled' => true, 'token' => '123:TEST', 'bot_name' => 'docportal_bot', 'api_url' => 'http://telegram.test', 'admin_chat' => '']);
        // Telegram привязан только у markova (другие тесты могли привязать его другим сотрудникам).
        foreach ($this->em()->getRepository(User::class)->findAll() as $u) {
            $u->unlinkTelegram();
        }
        $markova = $this->user('markova');
        $markova->linkTelegram('3003', 'Мария');
        $this->em()->flush();
        $this->http()->setDefault(new HttpResponse(200, '{"ok":true,"result":{"message_id":1}}'));
        $ids = [(int) $markova->getId(), (int) $this->user('ivanov')->getId()];

        $this->client->request('POST', '/internal/video/notify', [], [], ['CONTENT_TYPE' => 'application/json'], (string) json_encode(['users' => $ids, 'title' => 'x']));
        self::assertResponseStatusCodeSame(404, 'без секрета — 404');

        $headers = ['CONTENT_TYPE' => 'application/json', 'HTTP_X_VIDEO_SECRET' => self::SECRET];
        $this->client->request('POST', '/internal/video/notify', [], [], $headers, (string) json_encode(['users' => [], 'title' => '']));
        self::assertResponseStatusCodeSame(400);

        $this->client->request('POST', '/internal/video/notify', [], [], $headers, (string) json_encode([
            'users' => $ids, 'type' => 'moderation', 'title' => 'Видео ожидает проверки', 'body' => 'Белов: <b>Обзор</b> насосной станции', 'link' => '/admin/moderation',
        ]));
        self::assertResponseIsSuccessful();
        $result = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertTrue($result['ok']);
        self::assertSame(1, $result['telegram'], 'в Telegram — только привязавшей бота');
        $withMail = \count(array_filter([$markova, $this->user('ivanov')], static fn (User $u): bool => null !== $u->getEmail()));
        self::assertSame($withMail, $result['mailed']);
        if ($withMail > 0) {
            self::assertEmailCount($withMail);
            $email = self::getMailerMessage();
            self::assertNotNull($email);
            self::assertEmailHeaderSame($email, 'Subject', 'Портал документации: Видео ожидает проверки');
            self::assertEmailTextBodyContains($email, '/video/admin/moderation');
            self::assertEmailTextBodyContains($email, 'Белов: Обзор насосной станции');
        }
        $tg = array_values(array_filter($this->http()->requests, static fn (array $r): bool => str_contains($r['url'], 'telegram.test')));
        self::assertCount(1, $tg);
        $payload = json_decode((string) $tg[0]['body'], true);
        self::assertSame('3003', (string) $payload['chat_id']);
        self::assertStringContainsString('Видео: Видео ожидает проверки', $payload['text']);
        self::assertStringContainsString('/video/admin/moderation', $payload['text']);

        $settings->setTelegram(['enabled' => false, 'token' => '', 'bot_name' => '', 'api_url' => '', 'admin_chat' => '']);
    }

    public function testVideoModerationCountInHeader(): void
    {
        $this->http()->enqueue(new HttpResponse(200, '{"pending":3}'));
        $this->loginAs('markova');
        $crawler = $this->client->request('GET', '/moderation');
        self::assertResponseIsSuccessful();
        $requests = $this->videoRequests('/api/internal/moderation');
        self::assertCount(1, $requests, 'число запрашивается один раз и кэшируется');
        self::assertSame(self::SECRET, $requests[0]['headers']['X-Video-Secret']);
        // В шапке — материалы и видео на модерации вместе (материалы могли остаться от других тестов).
        $documents = static::getContainer()->get(DocumentApprovalRepository::class)->countModerationQueue(null);
        self::assertSame((string) ($documents + 3), trim($crawler->filter('.ack-chip--approval .ack-chip__count')->first()->text()));
        self::assertStringContainsString('Видео на модерации 3', preg_replace('/\s+/u', ' ', $crawler->filter('.hero__actions')->text()) ?? '');

        // Второй запрос страницы — из кэша, без обращения к видеопорталу.
        $this->client->request('GET', '/');
        self::assertCount(1, $this->videoRequests('/api/internal/moderation'));

        // Сотруднику без прав модератора число не показывается и не запрашивается.
        $this->videoApi()->forgetPending();
        $this->http()->reset();
        $this->loginAs('smirnov');
        $this->client->request('GET', '/');
        self::assertCount(0, $this->videoRequests('/api/internal/moderation'));
    }

    public function testVideoPortalUnavailableDoesNotBreakPages(): void
    {
        $this->http()->setDefault(new HttpResponse(0, '', 'Connection refused'));
        $this->loginAs('markova');
        $this->client->request('GET', '/moderation');
        self::assertResponseIsSuccessful();
        $this->client->request('GET', '/search?q=насосная');
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('.video-hits');
    }

    public function testVideoResultsInPortalSearch(): void
    {
        $this->http()->setDefault(new HttpResponse(200, (string) json_encode(['total' => 7, 'items' => [
            ['shortId' => 'abc123', 'title' => 'Монтаж насосной станции', 'thumbnail' => 'v/abc/thumb.jpg', 'duration' => 3725, 'channel' => 'Маркетинг', 'publishedAt' => '2026-10-01T10:00:00Z', 'snippet' => '…подключение <b>насосной</b> станции…'],
        ]])));
        $this->loginAs('smirnov');
        $crawler = $this->client->request('GET', '/search?q=насосная');
        self::assertResponseIsSuccessful();
        $requests = $this->videoRequests('/api/internal/search');
        self::assertCount(1, $requests);
        self::assertStringContainsString('audience=employee', $requests[0]['url']);
        self::assertSelectorTextContains('.video-hits', 'Монтаж насосной станции');
        self::assertSame('/video/watch/abc123', $crawler->filter('.video-hit .snippet__title')->attr('href'));
        self::assertSame('/video/media/v/abc/thumb.jpg', $crawler->filter('.video-hit__thumb img')->attr('src'));
        self::assertSelectorTextContains('.video-hit__duration', '1:02:05');
        self::assertStringNotContainsString('<b>насосной</b>', (string) $this->client->getResponse()->getContent(), 'разметка из видеопортала не выводится');
        self::assertStringContainsString('Видео: 7', $crawler->filter('.video-hits')->ancestors()->first()->text());

        // С фильтром по разделу видео не ищутся (у видео нет разделов портала).
        $this->http()->reset();
        $section = $this->em()->getRepository(Section::class)->findOneBy([]);
        self::assertNotNull($section);
        $this->client->request('GET', '/search?q=насосная&section='.$section->getId());
        self::assertCount(0, $this->videoRequests('/api/internal/search'));
    }

    public function testMonitoringShowsVideoPortalState(): void
    {
        $report = static::getContainer()->get(HealthReport::class);
        $this->http()->enqueue(new HttpResponse(200, (string) json_encode([
            'status' => 'ok', 'db' => 'ok', 'version' => '1.13.0',
            'worker' => ['ageSeconds' => 3600, 'alive' => false],
            'queue' => ['queued' => 4, 'running' => 0, 'failed24h' => 1, 'oldestQueuedSeconds' => 120],
            'storage' => ['freeBytes' => 2 * 1024 * 1024 * 1024],
            'moderation' => ['pending' => 2],
            'portalSync' => ['enabled' => true, 'ageSeconds' => 300, 'lastError' => null, 'users' => 42],
        ])));
        $built = $report->build();
        $checks = [];
        foreach ($built['checks'] as $c) {
            $checks[$c['key']] = $c;
        }
        self::assertSame(HealthReport::OK, $checks['video']['status']);
        self::assertStringContainsString('1.13.0', $checks['video']['message']);
        self::assertSame(HealthReport::WARNING, $checks['video_worker']['status'], 'обработчик видео давно не брал заданий');
        self::assertSame(HealthReport::WARNING, $checks['video_storage']['status'], 'мало места под видео');
        self::assertSame(HealthReport::OK, $checks['video_sync']['status']);
        self::assertSame(1, $built['metrics']['video_up']);
        self::assertSame(2, $built['metrics']['video_moderation_pending']);
        self::assertStringContainsString('docportal_video_queue_queued 4', HealthReport::prometheus($built));

        // Видеопортал не отвечает — предупреждение, а не неисправность портала.
        $this->http()->enqueue(new HttpResponse(0, '', 'Connection refused'));
        $built = $report->build();
        $video = array_values(array_filter($built['checks'], static fn (array $c): bool => 'video' === $c['key']))[0];
        self::assertSame(HealthReport::WARNING, $video['status']);
        self::assertStringContainsString('Connection refused', $video['message']);
        self::assertSame(0, $built['metrics']['video_up']);
    }
}
