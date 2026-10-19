<?php

declare(strict_types=1);

namespace App\Service\Presentation;

use App\Service\Security\QrCode;

/**
 * QR-код для слайда: SVG — для редактора и показа, PNG — для PowerPoint. Без внешних библиотек и без GD:
 * PNG собирается вручную (палитра из двух цветов, сжатие zlib).
 */
final class QrImage
{
    public const QUIET = 2;

    public static function svg(string $text, string $color = '#0F4382', string $bg = '#FFFFFF'): string
    {
        $matrix = QrCode::matrix('' === $text ? ' ' : $text);
        $size = \count($matrix) + 2 * self::QUIET;
        $path = '';
        foreach ($matrix as $y => $row) {
            foreach ($row as $x => $dark) {
                if ($dark) {
                    $path .= \sprintf('M%d %dh1v1h-1z', $x + self::QUIET, $y + self::QUIET);
                }
            }
        }
        [$fg, $fgA] = self::rgb($color);
        [$bgc, $bgA] = self::rgb($bg);

        return \sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 %d %d" shape-rendering="crispEdges"><rect width="%d" height="%d" fill="%s" fill-opacity="%s"/><path d="%s" fill="%s" fill-opacity="%s"/></svg>',
            $size, $size, $size, $size, $bgc, $bgA, $path, $fg, $fgA
        );
    }

    /** PNG: модуль — $scale пикселей. */
    public static function png(string $text, string $color = '#0F4382', string $bg = '#FFFFFF', int $scale = 12): string
    {
        $matrix = QrCode::matrix('' === $text ? ' ' : $text);
        $n = \count($matrix);
        $side = ($n + 2 * self::QUIET) * $scale;
        $raw = '';
        for ($py = 0; $py < $side; ++$py) {
            $my = intdiv($py, $scale) - self::QUIET;
            $raw .= "\0";
            $bits = 0;
            $byte = 0;
            for ($px = 0; $px < $side; ++$px) {
                $mx = intdiv($px, $scale) - self::QUIET;
                $dark = $my >= 0 && $my < $n && $mx >= 0 && $mx < $n && $matrix[$my][$mx];
                $byte = ($byte << 1) | ($dark ? 1 : 0);
                if (8 === ++$bits) {
                    $raw .= \chr($byte);
                    $bits = 0;
                    $byte = 0;
                }
            }
            if ($bits > 0) {
                $raw .= \chr($byte << (8 - $bits));
            }
        }
        [$fg] = self::rgb($color);
        [$bgc, $bgA] = self::rgb($bg);
        $plte = self::hexBytes($bgc).self::hexBytes($fg);
        $png = "\x89PNG\r\n\x1a\n";
        $png .= self::chunk('IHDR', pack('NNCCCCC', $side, $side, 1, 3, 0, 0, 0));
        $png .= self::chunk('PLTE', $plte);
        if ((float) $bgA < 1.0) {
            $png .= self::chunk('tRNS', \chr((int) round((float) $bgA * 255))."\xFF");
        }
        $png .= self::chunk('IDAT', (string) gzcompress($raw, 9));
        $png .= self::chunk('IEND', '');

        return $png;
    }

    private static function chunk(string $type, string $data): string
    {
        return pack('N', \strlen($data)).$type.$data.pack('N', crc32($type.$data));
    }

    /** @return array{0: string, 1: string} #RRGGBB и непрозрачность 0–1 */
    private static function rgb(string $color): array
    {
        $c = strtoupper(ltrim($color, '#'));
        if (1 !== preg_match('/^[0-9A-F]{6}([0-9A-F]{2})?$/', $c)) {
            return ['#000000', '1'];
        }
        $alpha = 8 === \strlen($c) ? round(hexdec(substr($c, 6, 2)) / 255, 3) : 1;

        return ['#'.substr($c, 0, 6), (string) $alpha];
    }

    private static function hexBytes(string $hex): string
    {
        return (string) hex2bin(ltrim($hex, '#'));
    }
}
