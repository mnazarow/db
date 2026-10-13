<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Controller\PushController;
use App\Entity\Document;
use App\Entity\DocumentSubscription;
use App\Entity\PushSubscription;
use App\Service\AcknowledgementService;
use App\Service\DocumentManager;
use App\Service\Http\HttpResponse;
use App\Service\Http\RecordingTransport;
use App\Service\Notification\PushNotifier;
use App\Service\Notification\TelegramNotifier;
use App\Service\Notification\WebPush;
use App\Service\PortalSettings;
use App\Tests\PortalTestCase;

/**
 * Уведомления в браузере (Web Push): шифрование по RFC 8291 (проверочный пример из RFC), подпись VAPID,
 * включение администратором, подписка устройства сотрудника, доставка уведомлений о назначенном ознакомлении
 * и о событиях, которые уходят в Telegram, удаление устаревших подписок.
 */
final class WebPushTest extends PortalTestCase
{
    private const ENDPOINT = 'https://fcm.googleapis.com/fcm/send/test-device-1';

    protected function setUp(): void
    {
        parent::setUp();
        $this->http()->reset();
        $this->http()->setDefault(new HttpResponse(201, ''));
    }

    protected function tearDown(): void
    {
        $this->http()->reset();
        $this->settings()->set(PortalSettings::PUSH_ENABLED, false);
        $this->em()->getConnection()->executeStatement('DELETE FROM push_subscription');
        parent::tearDown();
    }

    public function testEncryptionMatchesRfc8291AndVapidSignatureVerifies(): void
    {
        // RFC 8291, раздел 5: ключи сервера и браузера, секрет, соль и итоговое сообщение.
        $asPublic = WebPush::base64UrlDecode('BP4z9KsN6nGRTbVYI_c7VJSPQTBtkgcy27mlmlMoZIIgDll6e3vCYLocInmYWAmS6TlzAC8wEqKK6PBru3jl7A8');
        $as = WebPush::privateKeyFromRaw(WebPush::base64UrlDecode('yfWPiYE-n46HLnH0KqZOF1fJJU3MYrct3AELtAQ-oRw'), $asPublic);
        $uaPublic = 'BCVxsr7N_eNgVRqvHtD0zTZsEc6-VV-JvLexhqUzORcxaOzi6-AYWXvTBHm4bjyPjs7Vd8pZGH6SRpkNtoIAiw4';
        $auth = 'BTBZMqHH6r4Tts7J_aSIgg';
        $body = WebPush::encrypt('When I grow up, I want to be a watermelon', $uaPublic, $auth, $as, WebPush::base64UrlDecode('DGv6ra1nlYgDCS1FRnbzlw'));
        self::assertSame('DGv6ra1nlYgDCS1FRnbzlwAAEABBBP4z9KsN6nGRTbVYI_c7VJSPQTBtkgcy27mlmlMoZIIgDll6e3vCYLocInmYWAmS6TlzAC8wEqKK6PBru3jl7A_yl95bQpu6cVPTpK4Mqgkf1CXztLVBSt2Ks3oZwbuwXPXLWyouBWLVWGNWQexSgSxsj_Qulcy4a-fN', WebPush::base64UrlEncode($body));
        $ua = WebPush::privateKeyFromRaw(WebPush::base64UrlDecode('q1dXpw3UpT5VOmu_cf_v6ih07Aems3njxI-JWgLcM94'), WebPush::base64UrlDecode($uaPublic));
        self::assertSame('When I grow up, I want to be a watermelon', WebPush::decrypt($body, $ua, $auth));

        // Случайные ключи и соль — браузер расшифрует.
        [$device, $p256dh, $deviceAuth] = $this->device();
        self::assertSame('{"title":"Привет"}', WebPush::decrypt(WebPush::encrypt('{"title":"Привет"}', $p256dh, $deviceAuth), $device, $deviceAuth));

        // VAPID: подпись ES256 проверяется открытым ключом, адресат — служба уведомлений.
        $keys = WebPush::generateKeys();
        $header = WebPush::vapidHeader(self::ENDPOINT, 'mailto:admin@example.ru', $keys['public'], $keys['private']);
        self::assertMatchesRegularExpression('/^vapid t=([\w-]+)\.([\w-]+)\.([\w-]+), k=[\w-]+$/', $header);
        preg_match('/t=([\w-]+)\.([\w-]+)\.([\w-]+)/', $header, $m);
        self::assertSame(1, openssl_verify($m[1].'.'.$m[2], WebPush::rawToDer(WebPush::base64UrlDecode($m[3])), WebPush::publicKeyFromPoint(WebPush::base64UrlDecode($keys['public'])), \OPENSSL_ALGO_SHA256));
        $claims = json_decode(WebPush::base64UrlDecode($m[2]), true);
        self::assertSame('https://fcm.googleapis.com', $claims['aud']);
        self::assertSame('mailto:admin@example.ru', $claims['sub']);
        self::assertGreaterThan(time(), $claims['exp']);

        self::assertTrue(PushNotifier::isAllowedEndpoint(self::ENDPOINT));
        self::assertTrue(PushNotifier::isAllowedEndpoint('https://wns2-par02p.notify.windows.com/w/?token=x'));
        self::assertFalse(PushNotifier::isAllowedEndpoint('http://fcm.googleapis.com/fcm/send/x'), 'только https');
        self::assertFalse(PushNotifier::isAllowedEndpoint('https://intranet.local/hook'), 'произвольный адрес — нет');
        self::assertFalse(PushNotifier::isAllowedEndpoint('https://fcm.googleapis.com.evil.ru/x'));
        self::assertSame('Яндекс Браузер, Windows', PushController::device('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 YaBrowser/24.4.0.0 Safari/537.36'));
        self::assertSame('Firefox, Linux', PushController::device('Mozilla/5.0 (X11; Linux x86_64; rv:125.0) Gecko/20100101 Firefox/125.0'));
    }

    public function testEmployeeSubscribesAndReceivesNotifications(): void
    {
        // Выключено — страница профиля об этом говорит, подписаться нельзя.
        $this->loginAs('smirnov');
        $crawler = $this->client->request('GET', '/profile/subscriptions');
        self::assertSelectorTextContains('#push', 'не включены администратором');

        $this->loginAs('admin');
        $crawler = $this->client->request('GET', '/admin/integrations');
        $this->client->submit($crawler->filter('form[action$="/admin/integrations/push"]')->form(['enabled' => '1', 'subject' => 'admin@example.ru']));
        self::assertResponseRedirects();
        $push = $this->settings()->push();
        self::assertTrue($push['ready']);
        self::assertSame(65, \strlen(WebPush::base64UrlDecode($push['public_key'])));

        $this->loginAs('smirnov');
        $crawler = $this->client->request('GET', '/profile/subscriptions');
        $box = $crawler->filter('[data-push]');
        self::assertSame($push['public_key'], $box->attr('data-key'));
        $token = (string) $box->attr('data-token');
        [$device, $p256dh, $auth] = $this->device();
        // Чужая служба и испорченные ключи не принимаются.
        self::assertSame(400, $this->subscribe($token, 'https://evil.example.ru/push', $p256dh, $auth));
        self::assertSame(400, $this->subscribe($token, self::ENDPOINT, 'AAAA', $auth));
        self::assertSame(400, $this->subscribe('wrong', self::ENDPOINT, $p256dh, $auth));
        self::assertSame(200, $this->subscribe($token, self::ENDPOINT, $p256dh, $auth));
        self::assertSame(200, $this->subscribe($token, self::ENDPOINT, $p256dh, $auth), 'повторно — та же подписка');
        self::assertSame(1, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM push_subscription'));
        $crawler = $this->client->request('GET', '/profile/subscriptions');
        self::assertSelectorTextContains('.push-devices', 'Firefox');

        // Назначено ознакомление — уведомление уходит после ответа (здесь — при сбросе очереди).
        $this->http()->reset();
        $this->http()->setDefault(new HttpResponse(201, ''));
        $document = $this->publishedPage('ПУШ-1', 'Инструкция по охране труда для офиса');
        static::getContainer()->get(AcknowledgementService::class)->assign($document, [$this->user('smirnov')], new \DateTimeImmutable('+7 days'), $this->user('admin'));
        $stats = $this->push()->flush();
        self::assertSame(1, $stats['sent']);
        $request = $this->http()->last();
        self::assertSame(self::ENDPOINT, $request['url']);
        self::assertSame('aes128gcm', $request['headers']['Content-Encoding']);
        self::assertStringStartsWith('vapid t=', $request['headers']['Authorization']);
        self::assertSame('86400', $request['headers']['TTL']);
        $payload = json_decode(WebPush::decrypt((string) $request['body'], $device, $auth), true);
        self::assertSame('Ознакомьтесь с документом', $payload['title']);
        self::assertStringContainsString('Инструкция по охране труда для офиса', $payload['body']);
        self::assertStringContainsString('/documents/'.$document->getId(), $payload['url']);

        // Сообщение, которое уходит в Telegram (даже если Telegram не настроен), — тоже в браузер.
        static::getContainer()->get(TelegramNotifier::class)->notify($this->user('smirnov'), "<b>Документ на модерации</b>\nПоложение &laquo;о премиях&raquo;\n<a href=\"https://docs.example.ru/moderation\">https://docs.example.ru/moderation</a>");
        $this->push()->flush();
        $payload = json_decode(WebPush::decrypt((string) $this->http()->last()['body'], $device, $auth), true);
        self::assertSame('Документ на модерации', $payload['title']);
        self::assertSame('Положение «о премиях»', $payload['body']);
        self::assertSame('https://docs.example.ru/moderation', $payload['url']);

        // Пробное уведомление со страницы профиля.
        $crawler = $this->client->request('GET', '/profile/subscriptions');
        $this->client->submit($crawler->filter('form[action$="/profile/push/test"]')->form());
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert--success', 'Пробное уведомление отправлено');

        // Служба ответила 410 — подписка устарела и удаляется.
        $this->http()->reset();
        $this->http()->setDefault(new HttpResponse(410, ''));
        $this->push()->notify($this->user('smirnov'), 'Проверка', 'текст');
        self::assertSame(1, $this->push()->flush()['removed']);
        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM push_subscription'));

        // Отключение устройства — только своего.
        $this->http()->setDefault(new HttpResponse(201, ''));
        self::assertSame(200, $this->subscribe($token, self::ENDPOINT, $p256dh, $auth));
        $id = (int) $this->em()->getConnection()->fetchOne('SELECT id FROM push_subscription');
        $this->loginAs('sidorov');
        $crawler = $this->client->request('GET', '/profile/subscriptions');
        $this->client->request('POST', '/profile/push/unsubscribe', ['_token' => (string) $crawler->filter('[data-push]')->attr('data-token'), 'id' => $id]);
        self::assertSame(1, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM push_subscription'), 'чужое устройство не отключается');
        $this->loginAs('smirnov');
        $crawler = $this->client->request('GET', '/profile/subscriptions');
        $this->client->submit($crawler->filter('.push-devices form')->form());
        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM push_subscription'));

        // Замена ключей удаляет подписки.
        self::assertSame(200, $this->subscribe($token, self::ENDPOINT, $p256dh, $auth));
        $this->loginAs('admin');
        $crawler = $this->client->request('GET', '/admin/integrations');
        self::assertSelectorTextContains('#push', 'Устройств с уведомлениями');
        $this->client->submit($crawler->filter('form[action$="/admin/integrations/push/reset"]')->form());
        self::assertNotSame($push['public_key'], $this->settings()->push()['public_key']);
        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM push_subscription'));
    }

    public function testSentAfterResponseLimitedAndRemovedOnLogout(): void
    {
        $keys = WebPush::generateKeys();
        $this->settings()->set(PortalSettings::PUSH_PRIVATE_KEY, $keys['private']);
        $this->settings()->set(PortalSettings::PUSH_PUBLIC_KEY, $keys['public']);
        $this->settings()->set(PortalSettings::PUSH_ENABLED, true);
        $em = $this->em();
        $smirnov = $this->user('smirnov');
        $document = $this->publishedPage('ПУШ-2', 'Регламент работы склада');
        if (null === $em->getRepository(DocumentSubscription::class)->findOneBy(['user' => $smirnov, 'document' => $document])) {
            $em->persist(new DocumentSubscription($smirnov, $document));
        }
        // Устройств у сотрудника не больше десяти — лишние (самые старые) удаляются.
        for ($i = 0; $i < PushNotifier::MAX_PER_USER + 2; ++$i) {
            $em->persist(new PushSubscription($smirnov, 'https://fcm.googleapis.com/fcm/send/old-'.$i, WebPush::generateKeys()['public'], WebPush::base64UrlEncode(random_bytes(16)), 'old'));
        }
        $em->flush();

        $this->loginAs('smirnov');
        $crawler = $this->client->request('GET', '/profile/subscriptions');
        $token = (string) $crawler->filter('[data-push]')->attr('data-token');
        [$device, $p256dh, $auth] = $this->device();
        // Проверка при открытии страницы: незнакомое устройство молча не подписывается.
        self::assertFalse($this->subscribeRefresh($token, self::ENDPOINT, $p256dh, $auth)['known']);
        self::assertSame(200, $this->subscribe($token, self::ENDPOINT, $p256dh, $auth));
        self::assertTrue($this->subscribeRefresh($token, self::ENDPOINT, $p256dh, $auth)['known']);
        self::assertSame(PushNotifier::MAX_PER_USER, (int) $em->getConnection()->fetchOne('SELECT COUNT(*) FROM push_subscription WHERE user_id = ?', [$smirnov->getId()]));
        self::assertNotFalse($em->getConnection()->fetchOne('SELECT 1 FROM push_subscription WHERE endpoint_hash = ?', [PushSubscription::hash(self::ENDPOINT)]));

        // Комментарий к документу, на который подписан сотрудник: уведомление уходит после ответа на запрос
        // (после всех обработчиков, которые ставят сообщения в очередь).
        $this->http()->reset();
        $this->http()->setDefault(new HttpResponse(201, ''));
        $this->loginAs('ivanov');
        $crawler = $this->client->request('GET', '/documents/'.$document->getId());
        $this->client->submit($crawler->filter('#comment-form')->form(['body' => 'Обновили порядок приёмки']));
        $sent = array_values(array_filter($this->http()->all(), static fn (array $r): bool => self::ENDPOINT === $r['url']));
        self::assertCount(1, $sent, 'уведомление о комментарии отправлено');
        $payload = json_decode(WebPush::decrypt((string) $sent[0]['body'], $device, $auth), true);
        self::assertStringContainsString('Регламент работы склада', $payload['title'].' '.$payload['body']);

        // «Не показывать текст уведомлений» (общие компьютеры) — на экране только общая фраза.
        $this->settings()->set(PortalSettings::PUSH_HIDE_TEXT, true);
        $this->push()->notify($smirnov, 'Документ «Регламент работы склада» изменён', 'Подробности');
        $this->push()->flush();
        $payload = json_decode(WebPush::decrypt((string) $this->http()->last()['body'], $device, $auth), true);
        self::assertSame('Новое уведомление портала', $payload['title']);
        self::assertStringNotContainsString('Регламент', $payload['title'].$payload['body']);
        $this->settings()->set(PortalSettings::PUSH_HIDE_TEXT, false);

        // Выход из портала — устройство, включённое в этом сеансе, больше не получает уведомлений.
        $this->loginAs('smirnov');
        self::assertSame(200, $this->subscribe($this->client->request('GET', '/profile/subscriptions')->filter('[data-push]')->attr('data-token'), self::ENDPOINT, $p256dh, $auth));
        $this->client->submit($this->client->request('GET', '/')->filter('form[action="/logout"]')->form());
        self::assertFalse($em->getConnection()->fetchOne('SELECT 1 FROM push_subscription WHERE endpoint_hash = ?', [PushSubscription::hash(self::ENDPOINT)]));
        self::assertSame(PushNotifier::MAX_PER_USER - 1, (int) $em->getConnection()->fetchOne('SELECT COUNT(*) FROM push_subscription WHERE user_id = ?', [$smirnov->getId()]), 'другие устройства остаются');
    }

    /** @return array<string, mixed> */
    private function subscribeRefresh(string $token, string $endpoint, string $p256dh, string $auth): array
    {
        $this->client->request('POST', '/profile/push/subscribe', [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $token],
            (string) json_encode(['endpoint' => $endpoint, 'keys' => ['p256dh' => $p256dh, 'auth' => $auth], 'refresh' => true]));

        return (array) json_decode((string) $this->client->getResponse()->getContent(), true);
    }

    /** @return array{0: \OpenSSLAsymmetricKey, 1: string, 2: string} ключ устройства, p256dh, auth */
    private function device(): array
    {
        $keys = WebPush::generateKeys();

        return [openssl_pkey_get_private($keys['private']), $keys['public'], WebPush::base64UrlEncode(random_bytes(16))];
    }

    private function subscribe(string $token, string $endpoint, string $p256dh, string $auth): int
    {
        $this->client->request('POST', '/profile/push/subscribe', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_CSRF_TOKEN' => $token,
            'HTTP_USER_AGENT' => 'Mozilla/5.0 (X11; Linux x86_64; rv:125.0) Gecko/20100101 Firefox/125.0',
        ], (string) json_encode(['endpoint' => $endpoint, 'keys' => ['p256dh' => $p256dh, 'auth' => $auth]]));

        return $this->client->getResponse()->getStatusCode();
    }

    private function publishedPage(string $code, string $title): Document
    {
        $existing = static::getContainer()->get('App\Repository\DocumentRepository')->findOneBy(['code' => $code]);
        if (null !== $existing) {
            return $existing;
        }
        $document = (new Document($this->section('Общие документы')))->setTitle($title)->setCode($code)->setType(Document::TYPE_PAGE);
        static::getContainer()->get(DocumentManager::class)->create($document, null, '<p>Текст</p>', null, $this->user('ivanov'), true);

        return $document;
    }

    private function push(): PushNotifier
    {
        return static::getContainer()->get(PushNotifier::class);
    }

    private function http(): RecordingTransport
    {
        return static::getContainer()->get(RecordingTransport::class);
    }

    private function settings(): PortalSettings
    {
        return static::getContainer()->get(PortalSettings::class);
    }
}
