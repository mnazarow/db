<?php

declare(strict_types=1);

namespace App\Service\Security;

/**
 * Одноразовые коды по времени (TOTP, RFC 6238) для двухфакторной аутентификации.
 *
 * Совместимо с обычными приложениями-аутентификаторами: HMAC-SHA1, 6 цифр, шаг 30 секунд.
 */
final class Totp
{
    public const DIGITS = 6;
    public const PERIOD = 30;

    /** На сколько шагов назад и вперёд принимаем код (расхождение часов телефона и сервера). */
    public const WINDOW = 1;

    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /** Новый секрет в виде base32 (20 байт — как рекомендует RFC 4226). */
    public static function generateSecret(int $bytes = 20): string
    {
        return self::base32Encode(random_bytes(max(10, $bytes)));
    }

    /** Код для указанного момента времени (по умолчанию — сейчас). */
    public static function code(string $secret, ?int $timestamp = null, int $offsetSteps = 0): string
    {
        $key = self::base32Decode($secret);
        if ('' === $key) {
            return '';
        }
        $counter = intdiv($timestamp ?? time(), self::PERIOD) + $offsetSteps;
        $binary = pack('J', $counter); // 64-битный счётчик, старший байт первым
        $hash = hash_hmac('sha1', $binary, $key, true);
        $offset = \ord($hash[19]) & 0x0F;
        $value = ((\ord($hash[$offset]) & 0x7F) << 24)
            | ((\ord($hash[$offset + 1]) & 0xFF) << 16)
            | ((\ord($hash[$offset + 2]) & 0xFF) << 8)
            | (\ord($hash[$offset + 3]) & 0xFF);

        return str_pad((string) ($value % 10 ** self::DIGITS), self::DIGITS, '0', \STR_PAD_LEFT);
    }

    /** Проверка кода с допуском по времени. Сравнение — постоянного времени. */
    public static function verify(string $secret, string $code, ?int $timestamp = null, int $window = self::WINDOW): bool
    {
        $code = preg_replace('/\D+/', '', $code) ?? '';
        if (self::DIGITS !== \strlen($code) || '' === trim($secret)) {
            return false;
        }
        for ($step = -$window; $step <= $window; ++$step) {
            if (hash_equals(self::code($secret, $timestamp, $step), $code)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Ссылка otpauth:// для QR-кода в приложении-аутентификаторе.
     *
     * Кириллица в адресе кодируется по шесть символов на букву, и длинное название портала
     * не помещается в QR-код, поэтому в ссылке используется латинская запись названия
     * (в приложении она и отображается); в интерфейсе портала название остаётся как есть.
     */
    public static function uri(string $secret, string $account, string $issuer): string
    {
        $issuer = self::latin($issuer);
        $account = self::latin($account);
        $label = rawurlencode($issuer).':'.rawurlencode($account);

        return \sprintf(
            'otpauth://totp/%s?secret=%s&issuer=%s&algorithm=SHA1&digits=%d&period=%d',
            $label, $secret, rawurlencode($issuer), self::DIGITS, self::PERIOD
        );
    }

    /** Латинская запись строки: кириллица переводится по таблице, прочие символы отбрасываются. */
    public static function latin(string $value): string
    {
        $map = [
            'а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'g', 'д' => 'd', 'е' => 'e', 'ё' => 'e', 'ж' => 'zh',
            'з' => 'z', 'и' => 'i', 'й' => 'y', 'к' => 'k', 'л' => 'l', 'м' => 'm', 'н' => 'n', 'о' => 'o',
            'п' => 'p', 'р' => 'r', 'с' => 's', 'т' => 't', 'у' => 'u', 'ф' => 'f', 'х' => 'kh', 'ц' => 'ts',
            'ч' => 'ch', 'ш' => 'sh', 'щ' => 'shch', 'ъ' => '', 'ы' => 'y', 'ь' => '', 'э' => 'e', 'ю' => 'yu', 'я' => 'ya',
        ];
        $out = '';
        foreach (preg_split('//u', $value, -1, \PREG_SPLIT_NO_EMPTY) ?: [] as $char) {
            $lower = mb_strtolower($char);
            if (isset($map[$lower])) {
                $letter = $map[$lower];
                $out .= $char === $lower ? $letter : mb_strtoupper(mb_substr($letter, 0, 1)).mb_substr($letter, 1);
            } elseif (preg_match('/[A-Za-z0-9 .\-_]/', $char)) {
                $out .= $char;
            }
        }
        $out = trim(preg_replace('/\s+/', ' ', $out) ?? '');

        return '' !== $out ? mb_substr($out, 0, 40) : 'Portal';
    }

    /** Секрет группами по четыре символа — чтобы ввести вручную, если камера недоступна. */
    public static function humanSecret(string $secret): string
    {
        return trim(chunk_split($secret, 4, ' '));
    }

    /**
     * Резервные коды на случай утери телефона: показываются один раз, хранятся хэшами.
     *
     * @return list<string>
     */
    public static function recoveryCodes(int $count = 8): array
    {
        $codes = [];
        for ($i = 0; $i < $count; ++$i) {
            $codes[] = strtolower(bin2hex(random_bytes(2)).'-'.bin2hex(random_bytes(2)));
        }

        return $codes;
    }

    public static function base32Encode(string $binary): string
    {
        $bits = '';
        foreach (str_split($binary) as $char) {
            $bits .= str_pad(decbin(\ord($char)), 8, '0', \STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::ALPHABET[bindec(str_pad($chunk, 5, '0', \STR_PAD_RIGHT))];
        }

        return $out;
    }

    public static function base32Decode(string $value): string
    {
        $value = strtoupper(preg_replace('/[^A-Za-z2-7]/', '', $value) ?? '');
        if ('' === $value) {
            return '';
        }
        $bits = '';
        foreach (str_split($value) as $char) {
            $index = strpos(self::ALPHABET, $char);
            if (false === $index) {
                return '';
            }
            $bits .= str_pad(decbin($index), 5, '0', \STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 8) as $chunk) {
            if (8 === \strlen($chunk)) {
                $out .= \chr(bindec($chunk));
            }
        }

        return $out;
    }
}
