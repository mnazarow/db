<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\User;
use App\Security\KerberosLogin;
use App\Service\PortalSettings;
use App\Tests\PortalTestCase;

/**
 * Вход через Windows (Kerberos): учётная запись из REMOTE_USER (nginx + SPNEGO) или из заголовка доверенного
 * прокси, допустимые домены, защита локальных учётных записей, создание при первом входе, автоматический вход
 * со страницы входа без зацикливания.
 */
final class KerberosTest extends PortalTestCase
{
    protected function tearDown(): void
    {
        $this->settings()->setKerberos(['enabled' => false]);
        parent::tearDown();
    }

    private function settings(): PortalSettings
    {
        return static::getContainer()->get(PortalSettings::class);
    }

    private function enable(array $values = []): void
    {
        $this->settings()->setKerberos($values + ['enabled' => true, 'source' => 'remote_user', 'create_users' => true]);
    }

    /** Текущий вошедший пользователь (по главной странице). */
    private function currentUser(): ?string
    {
        $token = static::getContainer()->get('security.token_storage')->getToken();

        return $token?->getUserIdentifier();
    }

    public function testPrincipalParsing(): void
    {
        self::assertSame(['ivanov', 'CORP.LOCAL'], KerberosLogin::split('Ivanov@CORP.LOCAL'));
        self::assertSame(['ivanov', 'CORP'], KerberosLogin::split('CORP\\Ivanov'));
        self::assertSame(['ivanov', null], KerberosLogin::split(' ivanov '));
        self::assertSame(['ivanov', null], KerberosLogin::split('ivanov@'));
    }

    public function testDisabledByDefault(): void
    {
        $this->client->request('GET', '/login/kerberos', [], [], ['REMOTE_USER' => 'sidorov@CORP.LOCAL']);
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', '/login');
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('a[href="/login/kerberos"]');
    }

    public function testRemoteUserLoginCreatesDomainAccount(): void
    {
        $this->enable(['realms' => 'CORP.LOCAL']);
        $this->client->request('GET', '/login');
        self::assertSelectorExists('a[href="/login/kerberos"]', 'кнопка «Войти через Windows»');

        $this->client->request('GET', '/login/kerberos', [], [], ['REMOTE_USER' => 'Kerb.User@CORP.LOCAL']);
        self::assertResponseRedirects('/');
        self::assertSame('kerb.user', $this->currentUser());
        $user = static::getContainer()->get('App\Repository\UserRepository')->findOneByUsername('kerb.user');
        self::assertInstanceOf(User::class, $user);
        self::assertSame(User::SOURCE_LDAP, $user->getAuthSource(), 'новая учётная запись — доменная, без пароля портала');
        self::assertSame('', $user->getPassword());
        self::assertNotNull($user->getLastLoginAt());
    }

    public function testRefusals(): void
    {
        $this->enable(['realms' => 'CORP.LOCAL', 'create_users' => false]);

        // Нет учётной записи от веб-сервера.
        $this->client->request('GET', '/login/kerberos');
        self::assertResponseRedirects('/login?kerberos=failed');
        self::assertNotNull($this->client->getResponse()->headers->getCookies()[0] ?? null, 'cookie «не пробовать автоматически»');
        self::assertSame(KerberosLogin::SKIP_COOKIE, $this->client->getResponse()->headers->getCookies()[0]->getName());
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert', 'Не удалось войти через Windows');
        self::assertNull($this->currentUser());

        // Чужой домен.
        $this->client->request('GET', '/login/kerberos', [], [], ['REMOTE_USER' => 'sidorov@EVIL.ORG']);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert', 'EVIL.ORG');
        self::assertNull($this->currentUser());

        // Локальная учётная запись портала с тем же логином — только если это разрешено.
        $this->client->request('GET', '/login/kerberos', [], [], ['REMOTE_USER' => 'admin@CORP.LOCAL']);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert', 'локальная');
        self::assertNull($this->currentUser(), 'доменный admin не входит в локального администратора');

        // Нет в портале, создание выключено.
        $this->client->request('GET', '/login/kerberos', [], [], ['REMOTE_USER' => 'stranger@CORP.LOCAL']);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert', 'нет');
        self::assertNull(static::getContainer()->get('App\Repository\UserRepository')->findOneByUsername('stranger'));

        // Заблокированная.
        $this->user('kuznetsova')->setAuthSource(User::SOURCE_LDAP)->setActive(false);
        $this->em()->flush();
        $this->client->request('GET', '/login/kerberos', [], [], ['REMOTE_USER' => 'kuznetsova@CORP.LOCAL']);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert', 'заблокирована');
        // После запроса менеджер сущностей очищен — возвращаем учётную запись через свежую копию.
        $this->user('kuznetsova')->setActive(true)->setAuthSource(User::SOURCE_LOCAL);
        $this->em()->flush();

        // Заголовок от браузера при режиме REMOTE_USER ничего не значит.
        $this->client->request('GET', '/login/kerberos', [], [], ['HTTP_X_REMOTE_USER' => 'sidorov', 'HTTP_REMOTE_USER' => 'sidorov']);
        self::assertResponseRedirects('/login?kerberos=failed');
        self::assertNull($this->currentUser());
    }

    public function testLocalAccountsWhenAllowed(): void
    {
        $this->enable(['allow_local' => true]);
        $this->client->request('GET', '/login/kerberos', [], [], ['REMOTE_USER' => 'CORP\\Sidorov']);
        self::assertResponseRedirects('/');
        self::assertSame('sidorov', $this->currentUser());
    }

    public function testHeaderFromTrustedProxyOnly(): void
    {
        try {
            $this->settings()->setKerberos(['enabled' => true, 'source' => 'header']);
            self::fail('без доверенных прокси режим заголовка не включается');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('доверенных прокси', $e->getMessage());
        }
        $this->enable(['source' => 'header', 'header' => 'X-Remote-User', 'trusted_proxies' => '10.0.0.0/24', 'allow_local' => true]);

        $this->client->request('GET', '/login/kerberos', [], [], ['REMOTE_ADDR' => '192.168.5.5', 'HTTP_X_REMOTE_USER' => 'sidorov@CORP.LOCAL']);
        self::assertResponseRedirects('/login?kerberos=failed');
        self::assertNull($this->currentUser(), 'заголовок не от доверенного прокси');

        $this->client->request('GET', '/login/kerberos', [], [], ['REMOTE_ADDR' => '10.0.0.7', 'HTTP_X_REMOTE_USER' => 'sidorov@CORP.LOCAL']);
        self::assertResponseRedirects('/');
        self::assertSame('sidorov', $this->currentUser());
    }

    public function testAutoLoginWithoutLoops(): void
    {
        $this->enable(['auto' => true, 'allow_local' => true]);
        $this->client->request('GET', '/login');
        self::assertResponseRedirects('/login/kerberos');

        // Ссылка «войти по паролю», неудача на веб-сервере (kerberos-401.html) и cookie — без перехода.
        $this->client->request('GET', '/login?kerberos=off');
        self::assertResponseIsSuccessful();
        $this->client->request('GET', '/login?kerberos=failed');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.alert', 'браузер не передал учётную запись');
        $this->client->getCookieJar()->set(new \Symfony\Component\BrowserKit\Cookie(KerberosLogin::SKIP_COOKIE, '1'));
        $this->client->request('GET', '/login');
        self::assertResponseIsSuccessful();
        $this->client->getCookieJar()->expire(KerberosLogin::SKIP_COOKIE);

        // Вход, затем выход: после выхода автоматического входа нет (cookie ставится при выходе).
        $this->client->request('GET', '/login/kerberos', [], [], ['REMOTE_USER' => 'sidorov@CORP.LOCAL']);
        self::assertSame('sidorov', $this->currentUser());
        $crawler = $this->client->request('GET', '/');
        $token = (string) $crawler->filter('form[action="/logout"] input[name=_csrf_token]')->attr('value');
        $this->client->request('POST', '/logout', ['_csrf_token' => $token]);
        self::assertResponseRedirects();
        $names = array_map(static fn ($c) => $c->getName(), $this->client->getResponse()->headers->getCookies());
        self::assertContains(KerberosLogin::SKIP_COOKIE, $names);
        $this->client->request('GET', '/login');
        self::assertResponseIsSuccessful();
    }

    public function testAdminSettings(): void
    {
        $this->loginAs('admin');
        $crawler = $this->client->request('GET', '/admin/security');
        self::assertSelectorTextContains('#kerberos', 'Вход через Windows');
        $token = (string) $crawler->filter('#kerberos input[name=_token]')->attr('value');
        $this->client->request('POST', '/admin/security/kerberos', ['_token' => $token, 'enabled' => '1', 'source' => 'header', 'header' => 'X-Remote-User', 'trusted_proxies' => 'bad', 'realms' => 'corp.local']);
        self::assertResponseRedirects();
        self::assertFalse($this->settings()->kerberos()['enabled'], 'неверный адрес прокси — не сохранено');
        $this->client->request('POST', '/admin/security/kerberos', ['_token' => $token, 'enabled' => '1', 'source' => 'header', 'header' => 'X-Remote-User', 'trusted_proxies' => '10.0.0.5 10.0.1.0/24', 'realms' => 'corp.local, corp', 'auto' => '1', 'create_users' => '1']);
        $settings = $this->settings()->kerberos();
        self::assertTrue($settings['enabled']);
        self::assertSame('header', $settings['source']);
        self::assertSame('10.0.0.5, 10.0.1.0/24', $settings['trusted_proxies']);
        self::assertSame('CORP.LOCAL, CORP', $settings['realms']);
        self::assertTrue($settings['auto']);
        self::assertFalse($settings['allow_local']);

        // Консольная команда (её вызывает «docportal kerberos on|off»).
        $application = new \Symfony\Bundle\FrameworkBundle\Console\Application(static::$kernel);
        $tester = new \Symfony\Component\Console\Tester\CommandTester($application->find('app:kerberos'));
        self::assertSame(0, $tester->execute(['--enable' => true, '--realm' => 'corp.local', '--auto' => '0']));
        $settings = $this->settings()->kerberos();
        self::assertSame('remote_user', $settings['source']);
        self::assertSame('CORP.LOCAL', $settings['realms']);
        self::assertFalse($settings['auto']);
        self::assertSame(0, $tester->execute(['--disable' => true]));
        self::assertFalse($this->settings()->kerberos()['enabled']);
        self::assertStringContainsString('выключен', $tester->getDisplay());
    }
}
