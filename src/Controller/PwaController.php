<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Манифест веб-приложения: портал можно установить на телефон или компьютер как приложение (значок на
 * рабочем столе, отдельное окно). Работа без сети — public/sw.js и public/offline.html. Адрес открыт и без
 * входа: браузер запрашивает манифест без cookie.
 */
final class PwaController extends AbstractController
{
    public function __construct(
        private readonly string $appName,
        private readonly string $appCompany,
    ) {
    }

    #[Route('/manifest.webmanifest', name: 'app_manifest', methods: ['GET'])]
    public function manifest(): JsonResponse
    {
        $name = trim($this->appName) ?: 'Портал документации';
        $response = new JsonResponse([
            'name' => '' !== trim($this->appCompany) ? $name.' — '.trim($this->appCompany) : $name,
            'short_name' => mb_strlen($name) > 18 ? 'Документы' : $name,
            'description' => 'Документы, ознакомления и материалы компании',
            'lang' => 'ru',
            'start_url' => '/?source=pwa',
            'scope' => '/',
            'display' => 'standalone',
            'background_color' => '#F4F8FB',
            'theme_color' => '#0F4382',
            'icons' => [
                ['src' => '/img/app/icon-192.png', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => '/img/app/icon-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => '/img/app/icon-maskable-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
            ],
            'shortcuts' => [
                ['name' => 'Поиск', 'url' => '/search', 'icons' => [['src' => '/img/app/icon-192.png', 'sizes' => '192x192']]],
                ['name' => 'Ознакомиться', 'url' => '/profile/acknowledgements', 'icons' => [['src' => '/img/app/icon-192.png', 'sizes' => '192x192']]],
            ],
        ], 200, ['Content-Type' => 'application/manifest+json']);
        $response->setEncodingOptions(JsonResponse::DEFAULT_ENCODING_OPTIONS | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
        $response->setPublic();
        $response->setMaxAge(86400);

        return $response;
    }
}
