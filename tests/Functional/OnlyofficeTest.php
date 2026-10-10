<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Document;
use App\Service\Http\HttpResponse;
use App\Service\Http\RecordingTransport;
use App\Service\Onlyoffice\OnlyofficeService;
use App\Service\PortalSettings;
use App\Service\Security\Jwt;
use App\Tests\PortalTestCase;

/**
 * Редактирование в ONLYOFFICE / Р7-Офис (1.7.0): кнопки и права, конфигурация редактора с подписью JWT,
 * выдача файла серверу документов по подписанной ссылке, сохранение правки новой версией по уведомлению,
 * защита от поддельных уведомлений и скачивания не с сервера документов, согласование.
 */
final class OnlyofficeTest extends PortalTestCase
{
    private const SECRET = 'jwt-secret-for-tests';

    protected function setUp(): void
    {
        parent::setUp();
        $this->em()->getConnection()->beginTransaction();
        $this->settings()->setOnlyoffice(['enabled' => true, 'url' => 'https://office.test', 'secret' => self::SECRET]);
        $this->transport()->reset();
    }

    protected function tearDown(): void
    {
        $connection = $this->em()->getConnection();
        while ($connection->isTransactionActive()) {
            $connection->rollBack();
        }
        $this->em()->clear();
        static::getContainer()->get('cache.app')->clear();
        $this->transport()->reset();
        parent::tearDown();
    }

    private function settings(): PortalSettings
    {
        return static::getContainer()->get(PortalSettings::class);
    }

    private function transport(): RecordingTransport
    {
        return static::getContainer()->get(RecordingTransport::class);
    }

    /** @return array<string, mixed> конфигурация редактора со страницы */
    private function openEditor(Document $document, bool $edit): array
    {
        $crawler = $this->client->request('GET', '/documents/'.$document->getId().'/office'.($edit ? '?mode=edit' : ''));
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-onlyoffice][data-api^="https://office.test"][data-api$="/web-apps/apps/api/documents/api.js"]');

        return json_decode($crawler->filter('#onlyoffice-config')->text(), true, 512, \JSON_THROW_ON_ERROR);
    }

    private static function path(string $url): string
    {
        return (string) parse_url($url, \PHP_URL_PATH);
    }

    /** @param array<string, mixed> $body */
    private function notify(string $callbackUrl, array $body, array $server = []): array
    {
        $this->client->request('POST', self::path($callbackUrl), [], [], ['CONTENT_TYPE' => 'application/json'] + $server, json_encode($body, \JSON_THROW_ON_ERROR));
        self::assertResponseIsSuccessful();

        return json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
    }

    public function testEditorButtonsAndConfig(): void
    {
        $document = $this->document('ПОЛ-003'); // DOCX в «Регламенты и политики», модератор — ivanov
        $this->loginAs('ivanov');
        $this->client->request('GET', '/documents/'.$document->getId());
        self::assertSelectorTextContains('.hero__actions', 'Редактировать онлайн');

        $config = $this->openEditor($document, true);
        self::assertSame('word', $config['documentType']);
        self::assertSame('docx', $config['document']['fileType']);
        self::assertMatchesRegularExpression('/^dp\d+v\d+-[0-9a-f]{20}$/', $config['document']['key']);
        self::assertSame('edit', $config['editorConfig']['mode']);
        self::assertSame((string) $this->user('ivanov')->getId(), $config['editorConfig']['user']['id']);
        self::assertStringContainsString('/onlyoffice/callback/', $config['editorConfig']['callbackUrl']);
        self::assertTrue($config['document']['permissions']['edit']);
        // Подпись конфигурации секретом сервера документов: содержимое токена — та же конфигурация.
        $signed = Jwt::decode($config['token'], self::SECRET);
        unset($config['token']);
        self::assertEquals($config, $signed);

        // Сотрудник без прав модератора: кнопки правки нет, просмотр в редакторе — только если включён.
        $this->loginAs('smirnov');
        $this->client->request('GET', '/documents/'.$document->getId());
        self::assertSelectorTextNotContains('.hero__actions', 'Редактировать онлайн');
        self::assertSelectorTextNotContains('.hero__actions', 'Открыть в редакторе');
        $this->client->request('GET', '/documents/'.$document->getId().'/office?mode=edit');
        self::assertResponseStatusCodeSame(403);
        $this->client->request('GET', '/documents/'.$document->getId().'/office');
        self::assertResponseStatusCodeSame(404);

        $this->settings()->setOnlyoffice(['enabled' => true, 'url' => 'https://office.test', 'view' => true]);
        $this->client->request('GET', '/documents/'.$document->getId());
        self::assertSelectorTextContains('.hero__actions', 'Открыть в редакторе');
        $view = $this->openEditor($document, false);
        self::assertSame('view', $view['editorConfig']['mode']);
        self::assertArrayNotHasKey('callbackUrl', $view['editorConfig']);
        self::assertFalse($view['document']['permissions']['edit']);

        // Выключенная интеграция — ни кнопок, ни страницы.
        $this->settings()->setOnlyoffice(['enabled' => false, 'url' => 'https://office.test']);
        $this->client->request('GET', '/documents/'.$document->getId().'/office');
        self::assertResponseStatusCodeSame(404);
    }

    public function testFileIsServedBySignedLinkOnly(): void
    {
        $document = $this->document('ПОЛ-003');
        $this->loginAs('ivanov');
        $config = $this->openEditor($document, true);
        $this->client->getCookieJar()->clear(); // сервер документов приходит без сессии
        $this->settings()->set(PortalSettings::GUEST_MODE, PortalSettings::GUEST_OFF);

        $this->client->request('GET', self::path($config['document']['url']));
        self::assertResponseIsSuccessful();
        $path = $this->client->getResponse()->getFile()->getPathname();
        self::assertSame($document->getCurrentVersion()?->getChecksum(), hash_file('sha256', $path));

        // Подделанная подпись и ссылка уведомления вместо ссылки на файл — не отдаём.
        $this->client->request('GET', self::path($config['document']['url']).'x');
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', str_replace('/onlyoffice/callback/', '/onlyoffice/file/', self::path($config['editorConfig']['callbackUrl'])));
        self::assertResponseStatusCodeSame(404);
        // Ссылка ключом портала без срока действия не принимается.
        $forged = Jwt::encode(['p' => 'file', 'd' => (int) $document->getId(), 'v' => (int) $document->getCurrentVersion()?->getNumber(), 'u' => (int) $this->user('ivanov')->getId()], $this->settings()->onlyofficeLinkKey());
        $this->client->request('GET', '/onlyoffice/file/'.$forged);
        self::assertResponseStatusCodeSame(404);
        // Токен, подписанный секретом приложения (как до 1.7.0), не годится.
        $appSigned = Jwt::encode(['p' => 'file', 'd' => (int) $document->getId(), 'v' => 1, 'u' => (int) $this->user('ivanov')->getId(), 'exp' => time() + 60], hash_hmac('sha256', 'onlyoffice-links', (string) static::getContainer()->getParameter('kernel.secret')));
        $this->client->request('GET', '/onlyoffice/file/'.$appSigned);
        self::assertResponseStatusCodeSame(404);

        // Выключение интеграции отзывает выданные ссылки — и после повторного включения они не действуют.
        $this->settings()->setOnlyoffice(['enabled' => false, 'url' => 'https://office.test']);
        $this->client->request('GET', self::path($config['document']['url']));
        self::assertResponseStatusCodeSame(404);
        $this->settings()->setOnlyoffice(['enabled' => true, 'url' => 'https://office.test']);
        $this->client->request('GET', self::path($config['document']['url']));
        self::assertResponseStatusCodeSame(404);
    }

    public function testSecretIsRequiredAndGuestsGetNoEditor(): void
    {
        $this->settings()->setOnlyoffice(['enabled' => false, 'url' => 'https://office.test']);
        $this->settings()->set(PortalSettings::ONLYOFFICE_SECRET, '');
        try {
            $this->settings()->setOnlyoffice(['enabled' => true, 'url' => 'https://office.test']);
            self::fail('Без секрета JWT редактирование не включается.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('секрет JWT', $e->getMessage());
        }
        // Включено в базе, но без секрета (например, секрет удалили) — интеграция считается выключенной.
        $this->settings()->set(PortalSettings::ONLYOFFICE_ENABLED, true);
        self::assertFalse($this->settings()->isOnlyofficeEnabled());

        $this->settings()->setOnlyoffice(['enabled' => true, 'url' => 'https://office.test', 'secret' => self::SECRET, 'view' => true]);
        $document = $this->document('ПОЛ-003');
        $this->client->request('GET', '/documents/'.$document->getId().'/office');
        self::assertResponseRedirects('/login');
    }

    public function testCallbackSavesNewVersion(): void
    {
        $document = $this->document('ПОЛ-003');
        $before = (int) $document->getLatestVersion()?->getNumber();
        $ivanov = $this->user('ivanov');
        $this->loginAs('ivanov');
        $config = $this->openEditor($document, true);
        $this->client->getCookieJar()->clear();
        $key = $config['document']['key'];
        $body = ['key' => $key, 'status' => 2, 'url' => 'https://office.test/cache/files/data/'.$key.'/output.docx?md5=abc&expires=1', 'filetype' => 'docx',
            'users' => [(string) $ivanov->getId()], 'actions' => [['type' => 0, 'userid' => (string) $ivanov->getId()]]];

        // Без токена, с чужой подписью, с адресом не на сервере документов, с чужим ключом — отказ.
        self::assertSame(['error' => 1], $this->notify($config['editorConfig']['callbackUrl'], $body));
        self::assertSame(['error' => 1], $this->notify($config['editorConfig']['callbackUrl'], $body + ['token' => Jwt::encode($body, 'wrong-secret')]));
        $evil = ['url' => 'http://169.254.169.254/latest/meta-data'] + $body;
        self::assertSame(['error' => 1], $this->notify($config['editorConfig']['callbackUrl'], ['token' => Jwt::encode($evil, self::SECRET)]));
        $otherKey = ['key' => 'dp1v1-0000'] + $body;
        self::assertSame(['error' => 1], $this->notify($config['editorConfig']['callbackUrl'], ['token' => Jwt::encode($otherKey, self::SECRET)]));
        self::assertSame([], $this->transport()->requests, 'ничего не скачивалось');
        // Статус 1 (редактируют) — просто принимается.
        self::assertSame(['error' => 0], $this->notify($config['editorConfig']['callbackUrl'], ['token' => Jwt::encode(['status' => 1] + $body, self::SECRET)]));

        $this->transport()->enqueue(new HttpResponse(200, 'PK edited document bytes'));
        self::assertSame(['error' => 0], $this->notify($config['editorConfig']['callbackUrl'], ['token' => Jwt::encode($body, self::SECRET)]));
        self::assertSame($body['url'], $this->transport()->last()['url']);
        $this->em()->clear();
        $saved = $this->document('ПОЛ-003');
        $latest = $saved->getLatestVersion();
        self::assertSame($before + 1, $latest?->getNumber());
        self::assertSame($latest, $saved->getCurrentVersion(), 'без обязательного согласования новая версия сразу действует');
        self::assertSame('ivanov', $latest->getCreatedBy()?->getUsername());
        self::assertStringContainsString('Изменено в редакторе онлайн', (string) $latest->getChangeNote());
        self::assertSame(hash('sha256', 'PK edited document bytes'), $latest->getChecksum());
        self::assertSame('docx', $latest->getExtension());

        // Повтор того же уведомления не создаёт ещё одну версию.
        self::assertSame(['error' => 0], $this->notify($config['editorConfig']['callbackUrl'], ['token' => Jwt::encode($body, self::SECRET)]));
        $this->em()->clear();
        self::assertSame($before + 1, $this->document('ПОЛ-003')->getLatestVersion()?->getNumber());
    }

    public function testHeaderTokenInternalAddressAndApproval(): void
    {
        $this->settings()->setOnlyoffice(['enabled' => true, 'url' => 'https://office.test', 'internal_url' => 'http://onlyoffice:8080', 'portal_url' => 'http://portal.internal', 'secret' => null]);
        $this->settings()->setApproval(true, false);
        $document = $this->document('ПОЛ-003');
        $current = (int) $document->getCurrentVersion()?->getNumber();
        $this->loginAs('ivanov');
        $config = $this->openEditor($document, true);
        // Ссылки для сервера документов — на «адрес портала для сервера документов».
        self::assertStringStartsWith('http://portal.internal/onlyoffice/file/', $config['document']['url']);
        self::assertStringStartsWith('http://portal.internal/onlyoffice/callback/', $config['editorConfig']['callbackUrl']);
        $this->client->getCookieJar()->clear();

        $key = $config['document']['key'];
        $body = ['key' => $key, 'status' => 2, 'url' => 'https://office.test/cache/files/'.$key.'/output.docx', 'users' => [(string) $this->user('ivanov')->getId()]];
        $this->transport()->enqueue(new HttpResponse(200, 'PK pending edit'));
        $result = $this->notify($config['editorConfig']['callbackUrl'], $body, ['HTTP_AUTHORIZATION' => 'Bearer '.Jwt::encode(['payload' => $body], self::SECRET)]);
        self::assertSame(['error' => 0], $result);
        self::assertSame('http://onlyoffice:8080/cache/files/'.$key.'/output.docx', $this->transport()->last()['url'], 'скачивание — по внутреннему адресу');

        $this->em()->clear();
        $saved = $this->document('ПОЛ-003');
        self::assertSame($current, $saved->getCurrentVersion()?->getNumber(), 'при обязательном согласовании новая редакция ждёт решения');
        self::assertSame($current + 1, $saved->getLatestVersion()?->getNumber());

        // Следующее открытие редактора — уже ожидающая редакция.
        $this->loginAs('ivanov');
        $next = $this->openEditor($saved, true);
        self::assertNotSame($key, $next['document']['key']);
        self::assertStringContainsString('v'.($current + 1).'-', $next['document']['key']);
        self::assertSelectorTextContains('.office-bar__hint', 'ещё ждёт согласования');
    }

    public function testDownloadAddressMustStayOnDocumentServer(): void
    {
        $this->settings()->setOnlyoffice(['enabled' => true, 'url' => 'https://office.test/ds', 'internal_url' => 'http://onlyoffice:8080', 'secret' => null]);
        $document = $this->document('ПОЛ-003');
        $this->loginAs('ivanov');
        $config = $this->openEditor($document, true);
        $this->client->getCookieJar()->clear();
        $key = $config['document']['key'];
        $base = ['key' => $key, 'status' => 2, 'users' => [(string) $this->user('ivanov')->getId()]];
        // Путь, лишь начинающийся с пути сервера документов, другой порт, имя пользователя в адресе — отказ.
        foreach (['https://office.test/dsevil.example/x.docx', 'https://office.test:8443/ds/cache/x.docx', 'https://user@office.test/ds/cache/x.docx', 'https://office.test/ds/../admin/x.docx', 'https://office.test.evil/ds/x.docx'] as $url) {
            self::assertSame(['error' => 1], $this->notify($config['editorConfig']['callbackUrl'], ['token' => Jwt::encode(['url' => $url] + $base, self::SECRET)]), $url);
        }
        self::assertSame([], $this->transport()->requests);
        // Явный порт 443 у внешнего адреса — тот же сервер; скачивание идёт по внутреннему адресу.
        $this->transport()->enqueue(new HttpResponse(200, 'PK path based'));
        self::assertSame(['error' => 0], $this->notify($config['editorConfig']['callbackUrl'], ['token' => Jwt::encode(['url' => 'https://office.test:443/ds/cache/files/'.$key.'/output.docx?md5=1'] + $base, self::SECRET)]));
        self::assertSame('http://onlyoffice:8080/cache/files/'.$key.'/output.docx?md5=1', $this->transport()->last()['url']);
    }

    public function testAdminSettingsForm(): void
    {
        $this->loginAs('admin');
        $crawler = $this->client->request('GET', '/admin/integrations');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('#onlyoffice', 'Редактор документов');
        $token = $crawler->filter('#onlyoffice form input[name="_token"]')->attr('value');

        $this->client->request('POST', '/admin/integrations/onlyoffice', ['_token' => $token, 'enabled' => '1', 'url' => 'ftp://office']);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert--danger', 'http://');

        $this->client->request('POST', '/admin/integrations/onlyoffice', ['_token' => $token, 'enabled' => '1', 'url' => 'https://docs.example.local/', 'secret' => '', 'view' => '1']);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert--success', 'сохранены');
        $settings = $this->settings()->onlyoffice();
        self::assertSame('https://docs.example.local', $settings['url']);
        self::assertSame(self::SECRET, $settings['secret'], 'пустое поле секрета не стирает прежний');
        self::assertTrue($settings['view']);

        $this->transport()->enqueue(new HttpResponse(200, 'true'));
        $this->client->request('POST', '/admin/integrations/onlyoffice/test', ['_token' => $token]);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert--success', 'Сервер документов отвечает');
        self::assertSame('https://docs.example.local/healthcheck', $this->transport()->last()['url']);
    }

    public function testFormatsAndKeys(): void
    {
        self::assertTrue(OnlyofficeService::isEditableFormat('DOCX'));
        self::assertFalse(OnlyofficeService::isEditableFormat('doc'));
        self::assertTrue(OnlyofficeService::isViewableFormat('doc'));
        self::assertFalse(OnlyofficeService::isViewableFormat('pdf'));
        self::assertSame('cell', OnlyofficeService::documentType('xlsx'));
        self::assertSame('slide', OnlyofficeService::documentType('odp'));
        self::assertSame('word', OnlyofficeService::documentType('rtf'));
    }
}
