<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Service\PortalSettings;
use App\Tests\PortalTestCase;

/**
 * Портал как приложение (PWA): манифест доступен и без входа, страницы подключают его и service worker.
 */
final class PwaTest extends PortalTestCase
{
    public function testManifestIsPublicAndDescribesApp(): void
    {
        $settings = static::getContainer()->get(PortalSettings::class);
        $settings->set(PortalSettings::GUEST_MODE, PortalSettings::GUEST_OFF);
        try {
            $this->client->request('GET', '/manifest.webmanifest');
            self::assertResponseIsSuccessful('браузер запрашивает манифест без cookie — вход не нужен');
            self::assertStringStartsWith('application/manifest+json', (string) $this->client->getResponse()->headers->get('Content-Type'));
            $manifest = json_decode((string) $this->client->getResponse()->getContent(), true);
            self::assertSame('standalone', $manifest['display']);
            self::assertSame('/', $manifest['scope']);
            self::assertStringContainsString('Портал документации', $manifest['name']);
            $sizes = array_column($manifest['icons'], 'sizes');
            self::assertContains('192x192', $sizes);
            self::assertContains('512x512', $sizes);
            foreach ($manifest['icons'] as $icon) {
                self::assertFileExists(\dirname(__DIR__, 2).'/public'.$icon['src']);
            }
        } finally {
            $settings->set(PortalSettings::GUEST_MODE, PortalSettings::GUEST_ALL);
        }
    }

    public function testPagesLinkManifestAndServiceWorker(): void
    {
        $crawler = $this->client->request('GET', '/login');
        self::assertResponseIsSuccessful();
        self::assertSame('/manifest.webmanifest', $crawler->filter('link[rel="manifest"]')->attr('href'));
        $root = \dirname(__DIR__, 2).'/public';
        self::assertStringContainsString("register('/sw.js'", (string) file_get_contents($root.'/js/app.js'));
        $sw = (string) file_get_contents($root.'/sw.js');
        self::assertStringContainsString("/offline.html", $sw);
        self::assertStringContainsString("indexOf('/video/') === 0", $sw, 'раздел «Видео» service worker портала не трогает');
        foreach (['/offline.html', '/css/app.css', '/js/app.js', '/img/logo.svg', '/img/logo-mark.svg', '/img/favicon.svg', '/img/app/icon-192.png'] as $file) {
            self::assertFileExists($root.$file, 'файл из списка предзагрузки service worker');
        }
    }
}
