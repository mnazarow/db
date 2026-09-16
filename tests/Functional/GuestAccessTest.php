<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Service\PortalSettings;
use App\Tests\PortalTestCase;

/**
 * Настройка гостевого доступа: режимы «отовсюду», «только с указанных IP/подсетей», «выключен»;
 * форма в панели администратора, проверка адресов, возврат на запрошенную страницу после входа.
 */
final class GuestAccessTest extends PortalTestCase
{
    private function settings(): PortalSettings
    {
        return static::getContainer()->get(PortalSettings::class);
    }

    protected function tearDown(): void
    {
        // Возвращаем режим по умолчанию, чтобы не влиять на другие тесты.
        $this->settings()->setGuestAccess(PortalSettings::GUEST_ALL, []);
        parent::tearDown();
    }

    public function testNetworkValidation(): void
    {
        self::assertSame(['192.168.1.10', '10.0.0.0/8', '2001:db8::/32'], PortalSettings::normalizeNetworks(['192.168.1.10', ' 10.0.0.0/8 ', '', '# комментарий', '2001:db8::/32', '10.0.0.0/8']));
        self::assertSame(['1.2.3.4', '5.6.7.0/24'], PortalSettings::parseNetworkList("1.2.3.4, 5.6.7.0/24\n"));
        foreach (['abc', '10.0.0.0/33', '300.1.1.1', '2001:db8::/129', '10.0.0.0/x'] as $bad) {
            self::assertFalse(PortalSettings::isValidNetwork($bad), $bad);
        }
        $this->expectException(\InvalidArgumentException::class);
        PortalSettings::normalizeNetworks(['10.0.0.0/8', 'не адрес']);
    }

    public function testIpModeRestrictsGuestsAndRedirectsBackAfterLogin(): void
    {
        $this->settings()->setGuestAccess(PortalSettings::GUEST_IP, ['10.10.0.0/16', '192.168.5.7']);
        self::assertTrue($this->settings()->isGuestAllowed('10.10.3.4'));
        self::assertTrue($this->settings()->isGuestAllowed('192.168.5.7'));
        self::assertFalse($this->settings()->isGuestAllowed('192.168.5.8'));
        self::assertFalse($this->settings()->isGuestAllowed(null));

        $doc = $this->document('ИТ-РМ-002');
        $this->client->request('GET', '/documents/'.$doc->getId(), [], [], ['REMOTE_ADDR' => '10.10.3.4']);
        self::assertResponseIsSuccessful();
        $this->client->request('GET', '/', [], [], ['REMOTE_ADDR' => '172.16.0.9']);
        self::assertResponseRedirects('/login');
        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('с вашего адреса не разрешён', $crawler->text());
        $this->client->request('GET', '/health', [], [], ['REMOTE_ADDR' => '172.16.0.9']);
        self::assertResponseIsSuccessful();

        // После входа пользователь попадает на запрошенную страницу.
        $this->client->request('GET', '/documents/'.$doc->getId(), [], [], ['REMOTE_ADDR' => '172.16.0.9']);
        self::assertResponseRedirects('/login');
        $crawler = $this->client->followRedirect();
        $form = $crawler->filter('form[action="/login"], form.login__form, form')->first()->form(['_username' => 'smirnov', '_password' => 'Demo12345']);
        $this->client->submit($form, [], ['REMOTE_ADDR' => '172.16.0.9']);
        self::assertResponseRedirects('http://localhost/documents/'.$doc->getId());
        $this->client->request('GET', '/documents/'.$doc->getId(), [], [], ['REMOTE_ADDR' => '172.16.0.9']);
        self::assertResponseIsSuccessful();
    }

    public function testOffModeRequiresLoginForEveryone(): void
    {
        $this->settings()->setGuestAccess(PortalSettings::GUEST_OFF, []);
        $this->client->request('GET', '/');
        self::assertResponseRedirects('/login');
        $this->client->request('GET', '/login');
        self::assertResponseIsSuccessful();
        $this->loginAs('smirnov');
        $this->client->request('GET', '/');
        self::assertResponseIsSuccessful();
    }

    public function testAdminFormSavesAndValidates(): void
    {
        $this->loginAs('smirnov');
        $this->client->request('GET', '/admin/settings');
        self::assertResponseStatusCodeSame(403);

        $this->loginAs('admin');
        $crawler = $this->client->request('GET', '/admin/settings');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('#guest-networks');
        $token = $crawler->filter('form[action$="/guest-access"] input[name=_token]')->attr('value');

        $this->client->request('POST', '/admin/settings/guest-access', ['_token' => $token, 'mode' => 'ip', 'networks' => "10.20.0.0/16\n192.168.100.5"]);
        self::assertResponseRedirects('/admin/settings');
        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('сохранены', $crawler->filter('.alert--success')->text());
        self::assertSame('ip', $this->settings()->guestMode());
        self::assertSame(['10.20.0.0/16', '192.168.100.5'], $this->settings()->guestNetworks());
        self::assertStringContainsString('10.20.0.0/16', $crawler->filter('#guest-networks')->text());

        $this->client->request('POST', '/admin/settings/guest-access', ['_token' => $token, 'mode' => 'ip', 'networks' => '10.0.0.0/8, ошибка']);
        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('Некорректный адрес', $crawler->filter('.alert--danger')->text());
        self::assertSame(['10.20.0.0/16', '192.168.100.5'], $this->settings()->guestNetworks(), 'при ошибке прежние значения сохраняются');

        $this->client->request('POST', '/admin/settings/guest-access', ['_token' => $token, 'mode' => 'ip', 'networks' => '']);
        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('хотя бы один', $crawler->filter('.alert--danger')->text());

        $this->client->request('POST', '/admin/settings/guest-access', ['_token' => 'bad', 'mode' => 'off']);
        self::assertResponseStatusCodeSame(403);

        $this->client->request('POST', '/admin/settings/guest-access', ['_token' => $token, 'mode' => 'off', 'networks' => '']);
        self::assertResponseRedirects('/admin/settings');
        self::assertSame('off', $this->settings()->guestMode());
    }
}
