<?php

declare(strict_types=1);

namespace App\Service\Price;

/**
 * Вывод цены: «1234.5» → «1 234,50» (с $sign — «1 234,50 ₽»), целые — без копеек; текст («по запросу») —
 * как есть; пусто — «—». Пробелы — неразрывные, чтобы число не переносилось.
 */
final class PriceFormat
{
    public static function money(?string $value, ?string $sign = null): string
    {
        if (null === $value || '' === $value) {
            return '—';
        }
        if (!is_numeric($value)) {
            return $value;
        }
        $number = (float) $value;
        $decimals = 0.0 === fmod($number, 1.0) ? 0 : 2;

        return number_format($number, $decimals, ',', "\u{00A0}").(null !== $sign && '' !== $sign ? "\u{00A0}".$sign : '');
    }
}
