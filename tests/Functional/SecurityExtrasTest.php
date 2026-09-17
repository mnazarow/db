<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Security\TwoFactorSubscriber;
use App\Service\Http\HttpResponse;
use App\Service\Http\RecordingTransport;
use App\Service\PortalSettings;
use App\Service\Security\QrCode;
use App\Service\Security\Totp;
use App\Service\Sso\OidcClient;
use App\Service\Sso\SsoException;
use App\Tests\PortalTestCase;

/**
 * Двухфакторная аутентификация (TOTP, QR-код, резервные коды) и вход через внешнего провайдера (OIDC).
 */
final class SecurityExtrasTest extends PortalTestCase
{
    private function settings(): PortalSettings
    {
        return static::getContainer()->get(PortalSettings::class);
    }

    private function transport(): RecordingTransport
    {
        return static::getContainer()->get(RecordingTransport::class);
    }

    protected function tearDown(): void
    {
        $this->em()->clear();
        $this->settings()->setSso(['enabled' => false, 'issuer' => '', 'client_id' => '', 'client_secret' => '']);
        $this->settings()->setRequireAdminTwoFactor(false);
        parent::tearDown();
    }

    public function testTotpCodesMatchTheStandard(): void
    {
        // Контрольные значения RFC 6238 (секрет «12345678901234567890» в base32).
        $secret = Totp::base32Encode('12345678901234567890');
        self::assertSame('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', $secret);
        self::assertSame('287082', Totp::code($secret, 59));
        self::assertSame('081804', Totp::code($secret, 1111111109));
        self::assertSame('005924', Totp::code($secret, 1234567890));
        self::assertSame('279037', Totp::code($secret, 2000000000));

        // Допуск по времени: ±один шаг принимается, дальше — нет.
        $now = 1700000000;
        self::assertTrue(Totp::verify($secret, Totp::code($secret, $now), $now));
        self::assertTrue(Totp::verify($secret, Totp::code($secret, $now - 30), $now));
        self::assertTrue(Totp::verify($secret, Totp::code($secret, $now + 30), $now));
        self::assertFalse(Totp::verify($secret, Totp::code($secret, $now - 90), $now));
        self::assertFalse(Totp::verify($secret, '000000', $now));
        self::assertFalse(Totp::verify($secret, 'abcdef', $now));
        self::assertFalse(Totp::verify('', '123456', $now));

        // Кодирование и обратное преобразование.
        self::assertSame('12345678901234567890', Totp::base32Decode($secret));
        self::assertSame('12345678901234567890', Totp::base32Decode(Totp::humanSecret($secret)));
        self::assertSame(32, \strlen(Totp::generateSecret()));

        // Ссылка для приложения: название портала переводится в латиницу, иначе адрес не поместится в QR-код.
        $uri = Totp::uri($secret, 'ivanov', 'Портал документации');
        self::assertStringContainsString('otpauth://totp/Portal%20dokumentatsii:ivanov', $uri);
        self::assertStringContainsString('secret='.$secret, $uri);
        self::assertStringContainsString('digits=6', $uri);
        self::assertStringContainsString('period=30', $uri);
        self::assertSame('OOO Vodokomfort', Totp::latin('ООО «Водокомфорт»'));
        self::assertSame('Portal', Totp::latin('«»'));
    }

    public function testQrCodeIsValid(): void
    {
        // Известный код: матрица версии 1 для «HELLO» с уровнем M сравнивается по размеру и служебным узорам.
        $matrix = QrCode::matrix('HELLO');
        self::assertCount(21, $matrix, 'короткие данные — версия 1');
        foreach ($matrix as $row) {
            self::assertCount(21, $row);
        }
        // Поисковые узоры на трёх углах.
        foreach ([[0, 0], [0, 14], [14, 0]] as [$top, $left]) {
            self::assertTrue($matrix[$top][$left], 'угол поискового узора');
            self::assertTrue($matrix[$top + 6][$left + 6]);
            self::assertFalse($matrix[$top + 1][$left + 1], 'белая рамка внутри узора');
            self::assertTrue($matrix[$top + 3][$left + 3], 'центр узора');
        }
        // Синхронизирующие дорожки и обязательный тёмный модуль.
        self::assertTrue($matrix[6][8]);
        self::assertFalse($matrix[6][9]);
        self::assertTrue($matrix[21 - 8][8], 'тёмный модуль');

        // Версия растёт вместе с длиной данных.
        self::assertCount(25, QrCode::matrix(str_repeat('A', 20)));
        self::assertCount(57, QrCode::matrix(str_repeat('A', 210)));
        try {
            QrCode::matrix(str_repeat('A', 300));
            self::fail('слишком длинные данные должны отклоняться');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('Слишком длинные данные', $e->getMessage());
        }

        // Выбор маски детерминирован, и SVG содержит нужный размер.
        self::assertSame(QrCode::matrix('HELLO'), QrCode::matrix('HELLO'));
        $svg = QrCode::svg('HELLO', 4, 4);
        self::assertStringStartsWith('<svg xmlns="http://www.w3.org/2000/svg"', $svg);
        self::assertStringContainsString('width="116"', $svg, '(21 + 8) * 4');
        self::assertStringContainsString('<path d="M', $svg);
    }

    public function testTwoFactorSetupAndLogin(): void
    {
        $this->loginAs('petrova');
        $crawler = $this->client->request('GET', '/profile/2fa');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.qr svg');
        $secret = str_replace(' ', '', trim($crawler->filter('#totp-secret')->text()));
        self::assertNotSame('', $secret);
        $token = (string) $crawler->filter('form input[name="_token"]')->first()->attr('value');

        // Неверный код не включает второй фактор.
        $this->client->request('POST', '/profile/2fa', ['_token' => $token, 'code' => '000000']);
        self::assertResponseRedirects('/profile/2fa');
        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('Код не подошёл', $crawler->text());
        self::assertFalse($this->user('petrova')->isTotpEnabled());

        // Верный код включает и показывает резервные коды.
        $this->client->request('POST', '/profile/2fa', ['_token' => $token, 'code' => Totp::code($secret)]);
        self::assertResponseRedirects('/profile/2fa');
        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('Двухфакторная аутентификация включена', $crawler->text());
        self::assertSame(8, $crawler->filter('#recovery-codes')->count() > 0 ? preg_match_all('/[0-9a-f]{4}-[0-9a-f]{4}/', $crawler->filter('#recovery-codes')->text()) : 0);
        $user = $this->user('petrova');
        self::assertTrue($user->isTotpEnabled());
        self::assertSame(8, $user->countRecoveryCodes());
        self::assertNotNull($user->getTotpConfirmedAt());
        // Коды хранятся только хэшами.
        foreach ($user->getRecoveryCodes() as $stored) {
            self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $stored);
        }

        // Повторный показ резервных кодов невозможен.
        $crawler = $this->client->request('GET', '/profile/2fa');
        self::assertSame(0, $crawler->filter('#recovery-codes')->count());
    }

    public function testTwoFactorGateBlocksPagesUntilCodeIsEntered(): void
    {
        $user = $this->user('kuznetsova');
        $secret = Totp::generateSecret();
        $codes = ['aaaa-bbbb', 'cccc-dddd'];
        $user->setTotpSecret($secret)->confirmTotp()->setRecoveryCodes($codes);
        $this->em()->flush();

        // Входим настоящей формой: признак второго фактора ставит обработчик события входа.
        $this->formLogin('kuznetsova');
        $this->client->request('GET', '/');
        self::assertResponseRedirects('/login/2fa');
        $crawler = $this->client->request('GET', '/login/2fa');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.login__title', 'Подтверждение входа');
        $token = (string) $crawler->filter('form input[name="_token"]')->first()->attr('value');

        // Неверный код: страница остаётся закрытой.
        $this->client->request('POST', '/login/2fa', ['_token' => $token, 'code' => '000000']);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Неверный код', $this->client->getResponse()->getContent() ?: '');
        $this->client->request('GET', '/documents/1');
        self::assertResponseRedirects('/login/2fa');

        // Верный код открывает портал.
        $this->client->request('POST', '/login/2fa', ['_token' => $token, 'code' => Totp::code($secret)]);
        self::assertResponseRedirects('/');
        $this->client->request('GET', '/');
        self::assertResponseIsSuccessful();

        // Резервный код одноразовый.
        $this->formLogin('kuznetsova');
        $crawler = $this->client->request('GET', '/login/2fa');
        $token = (string) $crawler->filter('form input[name="_token"]')->first()->attr('value');
        $this->client->request('POST', '/login/2fa', ['_token' => $token, 'code' => 'aaaa-bbbb']);
        self::assertResponseRedirects('/');
        self::assertSame(1, $this->user('kuznetsova')->countRecoveryCodes(), 'использованный код погашен');
        $this->formLogin('kuznetsova');
        $crawler = $this->client->request('GET', '/login/2fa');
        $token = (string) $crawler->filter('form input[name="_token"]')->first()->attr('value');
        $this->client->request('POST', '/login/2fa', ['_token' => $token, 'code' => 'aaaa-bbbb']);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Неверный код', $this->client->getResponse()->getContent() ?: '');

        // Выключение требует действующего кода (сначала проходим второй фактор).
        $crawler = $this->client->request('GET', '/login/2fa');
        $token = (string) $crawler->filter('form input[name="_token"]')->first()->attr('value');
        $this->client->request('POST', '/login/2fa', ['_token' => $token, 'code' => Totp::code($secret)]);
        $crawler = $this->client->request('GET', '/profile/2fa');
        $token = (string) $crawler->filter('form input[name="_token"]')->first()->attr('value');
        $this->client->request('POST', '/profile/2fa', ['_token' => $token, 'action' => 'disable', 'code' => '000000']);
        self::assertTrue($this->user('kuznetsova')->isTotpEnabled());
        $this->client->request('POST', '/profile/2fa', ['_token' => $token, 'action' => 'disable', 'code' => Totp::code($secret)]);
        self::assertFalse($this->user('kuznetsova')->isTotpEnabled());
        self::assertSame(0, $this->user('kuznetsova')->countRecoveryCodes());
    }

    public function testAdminPolicyRequiresTwoFactor(): void
    {
        $this->settings()->setRequireAdminTwoFactor(true);
        $this->loginAs('admin');
        $this->client->request('GET', '/admin');
        self::assertResponseRedirects('/profile/2fa');
        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('обязательная двухфакторная аутентификация', $crawler->text());

        // Обычного сотрудника политика не касается.
        $this->loginAs('smirnov');
        $this->client->request('GET', '/');
        self::assertResponseIsSuccessful();

        $this->settings()->setRequireAdminTwoFactor(false);
        $this->loginAs('admin');
        $this->client->request('GET', '/admin');
        self::assertResponseIsSuccessful();
    }

    public function testSsoLogin(): void
    {
        $this->settings()->setSso([
            'enabled' => true,
            'issuer' => 'https://sso.example.local/realms/company',
            'client_id' => 'docportal',
            'client_secret' => 'secret',
            'create_users' => true,
            'username_claim' => 'preferred_username',
            'name_claim' => 'name',
            'email_claim' => 'email',
            'department_claim' => 'department',
            'admin_claim' => 'groups',
            'admin_value' => 'docportal-admins',
        ]);
        $oidc = static::getContainer()->get(OidcClient::class);
        self::assertTrue($oidc->isEnabled());

        // Кнопка появляется на странице входа.
        $crawler = $this->client->request('GET', '/login');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('/login/sso', $crawler->html());

        // Переход к провайдеру: адрес собирается из discovery, с PKCE и state.
        $this->warmDiscovery();
        $this->transport()->setDefault($this->discovery());
        $this->client->request('GET', '/login/sso');
        self::assertResponseStatusCodeSame(302);
        $location = (string) $this->client->getResponse()->headers->get('Location');
        self::assertStringStartsWith('https://sso.example.local/realms/company/auth', $location);
        parse_str((string) parse_url($location, \PHP_URL_QUERY), $query);
        self::assertSame('code', $query['response_type']);
        self::assertSame('docportal', $query['client_id']);
        self::assertSame('S256', $query['code_challenge_method']);
        self::assertNotEmpty($query['state']);
        self::assertNotEmpty($query['nonce']);
        self::assertStringEndsWith('/login/sso/callback', $query['redirect_uri']);

        // Чужой state отклоняется.
        $this->client->request('GET', '/login/sso/callback?code=abc&state='.bin2hex(random_bytes(8)));
        self::assertResponseRedirects('/login');
        self::assertStringContainsString('не удался', $this->client->followRedirect()->text());

        // Полный обмен: токены и userinfo. Пользователь создаётся из утверждений.
        $this->transport()->setDefault($this->discovery());
        $this->client->request('GET', '/login/sso');
        parse_str((string) parse_url((string) $this->client->getResponse()->headers->get('Location'), \PHP_URL_QUERY), $query);
        $this->transport()->reset();
        $this->transport()->enqueue(
            new HttpResponse(200, json_encode([
                'access_token' => 'at',
                'id_token' => $this->idToken(['nonce' => $query['nonce']]),
                'token_type' => 'Bearer',
            ], \JSON_THROW_ON_ERROR)),
            new HttpResponse(200, json_encode([
                'sub' => 'sso-1',
                'preferred_username' => 'novikov@example.local',
                'name' => 'Новиков Пётр Ильич',
                'email' => 'novikov@example.ru',
                'department' => 'Служба главного инженера',
                'groups' => ['docportal-admins'],
            ], \JSON_THROW_ON_ERROR, 512))
        );
        $this->transport()->setDefault($this->discovery());
        $this->client->request('GET', '/login/sso/callback?code=code-1&state='.$query['state']);
        self::assertResponseRedirects('/');
        $created = static::getContainer()->get(UserRepository::class)->findOneByUsername('novikov');
        self::assertNotNull($created, 'учётная запись создаётся при первом входе');
        self::assertSame('Новиков Пётр Ильич', $created->getDisplayName());
        self::assertSame('novikov@example.ru', $created->getEmail());
        self::assertSame('Служба главного инженера', $created->getDepartment());
        self::assertSame(User::SOURCE_SSO, $created->getAuthSource());
        self::assertTrue($created->isAdmin(), 'группа из утверждения даёт права администратора');
        self::assertSame('', $created->getPassword(), 'пароль для входа через SSO не используется');
    }

    public function testSsoRejectsBadTokens(): void
    {
        $this->settings()->setSso([
            'enabled' => true,
            'issuer' => 'https://sso.example.local/realms/company',
            'client_id' => 'docportal',
            'client_secret' => 'secret',
            'create_users' => false,
            'username_claim' => 'preferred_username',
        ]);
        $oidc = static::getContainer()->get(OidcClient::class);

        // Токен другого приложения.
        $this->warmDiscovery();
        $this->transport()->setDefault($this->discovery());
        $this->transport()->enqueue(
            new HttpResponse(200, json_encode(['access_token' => 'at', 'id_token' => $this->idToken(['aud' => 'other-app'])], \JSON_THROW_ON_ERROR))
        );
        try {
            $oidc->claims('code', 'https://portal/callback', 'verifier', '');
            self::fail('токен другого приложения должен отклоняться');
        } catch (SsoException $e) {
            self::assertStringContainsString('другому приложению', $e->getMessage());
        }

        // Просроченный токен.
        $this->transport()->reset();
        $this->transport()->setDefault($this->discovery());
        $this->transport()->enqueue(
            new HttpResponse(200, json_encode(['access_token' => 'at', 'id_token' => $this->idToken(['exp' => time() - 3600])], \JSON_THROW_ON_ERROR))
        );
        try {
            $oidc->claims('code', 'https://portal/callback', 'verifier', '');
            self::fail('просроченный токен должен отклоняться');
        } catch (SsoException $e) {
            self::assertStringContainsString('истёк', $e->getMessage());
        }

        // Неизвестный пользователь при выключенном автосоздании.
        $this->transport()->reset();
        $this->transport()->setDefault($this->discovery());
        $this->client->request('GET', '/login/sso');
        parse_str((string) parse_url((string) $this->client->getResponse()->headers->get('Location'), \PHP_URL_QUERY), $query);
        $this->transport()->reset();
        $this->transport()->enqueue(
            new HttpResponse(200, json_encode(['access_token' => 'at', 'id_token' => $this->idToken(['nonce' => $query['nonce']])], \JSON_THROW_ON_ERROR)),
            new HttpResponse(200, json_encode(['preferred_username' => 'unknown-person'], \JSON_THROW_ON_ERROR))
        );
        $this->transport()->setDefault($this->discovery());
        $this->client->request('GET', '/login/sso/callback?code=code-2&state='.$query['state']);
        self::assertResponseRedirects('/login');
        self::assertStringContainsString('не заведён в портале', $this->client->followRedirect()->text());
        self::assertNull(static::getContainer()->get(UserRepository::class)->findOneByUsername('unknown-person'));
    }

    /** Вход через настоящую форму: срабатывают события входа (второй фактор, журнал). */
    private function formLogin(string $username, string $password = 'Demo12345'): void
    {
        // Начинаем с чистой сессии, иначе страница входа перенаправит уже вошедшего пользователя.
        $this->client->getCookieJar()->clear();
        $crawler = $this->client->request('GET', '/login');
        $this->client->submit($crawler->selectButton('Войти')->form([
            '_username' => $username,
            '_password' => $password,
        ]));
    }

    /** Сведения о провайдере кэшируются: прогреваем кэш, чтобы дальше очередь ответов была предсказуемой. */
    private function warmDiscovery(): void
    {
        $this->transport()->reset();
        $this->transport()->setDefault($this->discovery());
        static::getContainer()->get(OidcClient::class)->discover(true);
        $this->transport()->reset();
    }

    private function discovery(): HttpResponse
    {
        return new HttpResponse(200, json_encode([
            'issuer' => 'https://sso.example.local/realms/company',
            'authorization_endpoint' => 'https://sso.example.local/realms/company/auth',
            'token_endpoint' => 'https://sso.example.local/realms/company/token',
            'userinfo_endpoint' => 'https://sso.example.local/realms/company/userinfo',
        ], \JSON_THROW_ON_ERROR));
    }

    /** @param array<string, mixed> $overrides */
    private function idToken(array $overrides = []): string
    {
        $payload = array_merge([
            'iss' => 'https://sso.example.local/realms/company',
            'aud' => 'docportal',
            'sub' => 'sso-1',
            'exp' => time() + 300,
            'preferred_username' => 'novikov',
        ], $overrides);
        $encode = static fn (array $data): string => rtrim(strtr(base64_encode(json_encode($data, \JSON_THROW_ON_ERROR)), '+/', '-_'), '=');

        return $encode(['alg' => 'RS256', 'typ' => 'JWT']).'.'.$encode($payload).'.signature';
    }
}
