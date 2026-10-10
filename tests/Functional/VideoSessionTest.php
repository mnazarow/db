<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\User;
use App\Tests\PortalTestCase;

/**
 * Общий вход с разделом «Видео»: видеопортал спрашивает у портала, кто вошёл (/internal/video/session).
 */
final class VideoSessionTest extends PortalTestCase
{
    private const SECRET = 'test-video-secret-0123456789abcdef';

    /** @return array<string, mixed> */
    private function session(?string $secret = self::SECRET): array
    {
        $this->client->request('GET', '/internal/video/session', [], [], null !== $secret ? ['HTTP_X_VIDEO_SECRET' => $secret] : []);

        return json_decode((string) $this->client->getResponse()->getContent(), true) ?? [];
    }

    public function testSecretIsRequired(): void
    {
        $this->session(null);
        self::assertResponseStatusCodeSame(404);
        $this->session('wrong-secret-wrong-secret-wrong');
        self::assertResponseStatusCodeSame(404);
    }

    public function testGuestAndUsers(): void
    {
        $guest = $this->session();
        self::assertResponseIsSuccessful();
        self::assertFalse($guest['authenticated']);
        self::assertSame('guest', $guest['state']);
        self::assertNull($guest['user']);
        self::assertSame('/login', $guest['portal']['login']);
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));

        foreach (['markova' => User::POSITION_MARKETER, 'belov' => User::POSITION_ASSISTANT, 'smirnov' => User::POSITION_USER, 'admin' => User::POSITION_ADMIN, 'ivanov' => User::POSITION_MARKETER] as $login => $position) {
            $this->loginAs($login);
            $data = $this->session();
            self::assertTrue($data['authenticated'], $login);
            self::assertSame($login, $data['user']['username']);
            self::assertSame($position, $data['user']['position'], $login.': модератор раздела в видео — модератор');
            self::assertSame('/logout', $data['logout']['url']);
            self::assertNotEmpty($data['logout']['token']);
        }
        self::assertSame('Иванов Игорь Петрович', $data['user']['displayName']);
    }

    public function testSecondFactorAndBlockedAccount(): void
    {
        $user = $this->user('smirnov');
        $user->setTotpSecret('JBSWY3DPEHPK3PXP')->confirmTotp();
        $this->em()->flush();
        $this->loginAs('smirnov');
        $data = $this->session();
        self::assertFalse($data['authenticated'], 'без второго фактора видео не открывается');
        self::assertSame('two_factor', $data['state']);

        $user = $this->user('smirnov');
        $user->setTotpSecret(null)->setMustChangePassword(true);
        $this->em()->flush();
        $this->loginAs('smirnov');
        self::assertSame('password_change', $this->session()['state']);

        $user = $this->user('smirnov');
        $user->setMustChangePassword(false);
        $this->em()->flush();
    }

    public function testLoginReturnsToVideoPage(): void
    {
        $crawler = $this->client->request('GET', '/login?next=/video/watch/abc123');
        self::assertResponseIsSuccessful();
        $this->client->submit($crawler->selectButton('Войти')->form(['_username' => 'smirnov', '_password' => 'Demo12345']));
        self::assertResponseRedirects('/video/watch/abc123');

        // Чужой сайт в next не принимается.
        $this->client->getCookieJar()->clear();
        $crawler = $this->client->request('GET', '/login?next=//evil.example/x');
        $this->client->submit($crawler->selectButton('Войти')->form(['_username' => 'smirnov', '_password' => 'Demo12345']));
        self::assertResponseRedirects('/');
    }

    public function testVideoLinkInHeader(): void
    {
        $this->loginAs('smirnov');
        $crawler = $this->client->request('GET', '/');
        self::assertSame(1, $crawler->filter('.site-nav a[href="/video/"]')->count());
        self::assertStringContainsString('пользователь', $crawler->filter('.user-chip__role')->text());
        $this->loginAs('belov');
        $crawler = $this->client->request('GET', '/');
        self::assertStringContainsString('помощник маркетолога', $crawler->filter('.user-chip__role')->text());
        self::assertSame(1, $crawler->filter('.site-nav a[href="/moderation"]')->count());
    }
}
