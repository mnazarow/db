<?php

declare(strict_types=1);

namespace App\Service\Video;

/**
 * Раздел «Видео» — встроенный видеопортал (CorpVideo) по адресу /video на том же сайте.
 *
 * Видеопортал не хранит своих паролей: каждый запрос он проверяет у портала по cookie сессии
 * (см. VideoSessionController — /internal/video/session), поэтому вход, выход, второй фактор,
 * блокировка и роли общие. Обмен идёт по внутреннему адресу и подписан общим секретом
 * VIDEO_SHARED_SECRET — он одинаков в настройках портала и видеопортала.
 */
final class VideoIntegration
{
    private readonly string $basePath;

    public function __construct(
        private readonly bool $enabled,
        string $basePath,
        private readonly string $sharedSecret,
    ) {
        $basePath = '/'.trim($basePath, " /\t\n\r");
        $this->basePath = '/' === $basePath ? '/video' : $basePath;
    }

    /** Включён ли раздел «Видео» (ссылки в меню, проверка сессии для видеопортала). */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /** Секрет задан и достаточно длинный — иначе проверку сессии видеопорталу не отдаём. */
    public function isConfigured(): bool
    {
        return $this->enabled && \strlen($this->sharedSecret) >= 24;
    }

    /** Префикс видеопортала на сайте, например «/video». */
    public function getBasePath(): string
    {
        return $this->basePath;
    }

    /** Адрес страницы видеопортала от корня сайта: url('/watch/abc') → '/video/watch/abc'. */
    public function url(string $path = '/'): string
    {
        $path = '' === $path ? '/' : $path;

        return $this->basePath.('/' === $path[0] ? $path : '/'.$path);
    }

    /** Общий секрет для подписи запросов портала к видеопорталу (заголовок X-Video-Secret). */
    public function sharedSecret(): string
    {
        return $this->sharedSecret;
    }

    /** Проверка подписи запроса видеопортала: секрет сравнивается за постоянное время. */
    public function verifySecret(?string $provided): bool
    {
        return $this->isConfigured() && null !== $provided && '' !== $provided && hash_equals($this->sharedSecret, $provided);
    }
}
