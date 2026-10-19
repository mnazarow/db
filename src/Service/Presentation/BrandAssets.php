<?php

declare(strict_types=1);

namespace App\Service\Presentation;

/**
 * Фирменные изображения для слайдов (из образца «Презентация к собранию»): знак «капля» для шапки светлых
 * слайдов, белый знак и вертикальная надпись «ВОДО КОМФОРТ» для титульного слайда, логотип с надписью для
 * финального, фото для «Содержания» и шары для «Юбилея». На слайдах — src: "brand:ИМЯ".
 */
final class BrandAssets
{
    /** @var array<string, array{label: string, png: string, svg: ?string, width: int, height: int, mime: string}> */
    public const ASSETS = [
        'logo-mark' => ['label' => 'Знак (синий)', 'png' => 'logo-mark.png', 'svg' => 'logo-mark.svg', 'width' => 260, 'height' => 212, 'mime' => 'image/png'],
        'logo-white' => ['label' => 'Знак (белый)', 'png' => 'logo-white.png', 'svg' => 'logo-white.svg', 'width' => 400, 'height' => 320, 'mime' => 'image/png'],
        'wordmark-vertical' => ['label' => 'Надпись «ВОДО КОМФОРТ»', 'png' => 'wordmark-vertical.png', 'svg' => 'wordmark-vertical.svg', 'width' => 1166, 'height' => 2500, 'mime' => 'image/png'],
        'logo-full-white' => ['label' => 'Логотип с надписью (белый)', 'png' => 'logo-full-white.png', 'svg' => null, 'width' => 941, 'height' => 650, 'mime' => 'image/png'],
        'contents-photo' => ['label' => 'Фото для содержания', 'png' => 'contents-photo.jpg', 'svg' => null, 'width' => 630, 'height' => 900, 'mime' => 'image/jpeg'],
        'balloons' => ['label' => 'Шары (юбилей)', 'png' => 'balloons.png', 'svg' => null, 'width' => 1312, 'height' => 1184, 'mime' => 'image/png'],
    ];

    public const PUBLIC_DIR = '/img/presentation/';

    private readonly string $publicDir;

    public function __construct(string $projectDir)
    {
        $this->publicDir = rtrim($projectDir, '/').'/public';
    }

    /** Файл растрового варианта (для PowerPoint). */
    public function rasterPath(string $key): ?string
    {
        $a = self::ASSETS[$key] ?? null;

        return null === $a ? null : $this->publicDir.self::PUBLIC_DIR.$a['png'];
    }

    /** Файл векторного варианта (PowerPoint 2016+ показывает его, старые версии — растровый). */
    public function svgPath(string $key): ?string
    {
        $a = self::ASSETS[$key] ?? null;

        return null === $a || null === $a['svg'] ? null : $this->publicDir.self::PUBLIC_DIR.$a['svg'];
    }

    /**
     * Для редактора: адреса и размеры.
     *
     * @return array<string, array{label: string, url: string, width: int, height: int}>
     */
    public static function forEditor(string $basePath = ''): array
    {
        $out = [];
        foreach (self::ASSETS as $key => $a) {
            $out[$key] = ['label' => $a['label'], 'url' => $basePath.self::PUBLIC_DIR.($a['svg'] ?? $a['png']), 'width' => $a['width'], 'height' => $a['height']];
        }

        return $out;
    }
}
