<?php

declare(strict_types=1);

namespace App\Service\Notification;

/**
 * Протокол Web Push без сторонних библиотек (расширение openssl): ключи VAPID (RFC 8292), шифрование
 * содержимого уведомления для браузера (RFC 8291, aes128gcm по RFC 8188) и подпись запроса к службе
 * уведомлений браузера (JWT ES256).
 */
final class WebPush
{
    /** Начало DER-структуры открытого ключа P-256 (SubjectPublicKeyInfo) перед 65 байтами точки. */
    private const SPKI_PREFIX = '3059301306072a8648ce3d020106082a8648ce3d030107034200';

    /** Размер записи aes128gcm: одно уведомление всегда помещается в одну запись. */
    private const RECORD_SIZE = 4096;

    /** Наибольший размер содержимого (службы принимают до 4 КБ зашифрованного тела). */
    public const MAX_PAYLOAD = 3000;

    public static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    public static function base64UrlDecode(string $data): string
    {
        $decoded = base64_decode(strtr($data, '-_', '+/').str_repeat('=', (4 - \strlen($data) % 4) % 4), true);

        return false === $decoded ? '' : $decoded;
    }

    /**
     * Новая пара ключей VAPID: открытый — точка P-256 (65 байт, base64url) для браузера, закрытый — PEM.
     *
     * @return array{public: string, private: string}
     */
    public static function generateKeys(): array
    {
        $key = openssl_pkey_new(['private_key_type' => \OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        if (false === $key || !openssl_pkey_export($key, $pem)) {
            throw new \RuntimeException('Не удалось создать ключи VAPID: '.(openssl_error_string() ?: 'ошибка openssl'));
        }

        return ['public' => self::base64UrlEncode(self::publicPoint($key)), 'private' => $pem];
    }

    /** Открытый ключ (65 байт) по закрытому ключу. */
    public static function publicPoint(\OpenSSLAsymmetricKey $key): string
    {
        $details = openssl_pkey_get_details($key);
        if (!\is_array($details) || !isset($details['ec']['x'], $details['ec']['y'])) {
            throw new \RuntimeException('Ключ не является ключом P-256.');
        }

        return "\x04".str_pad($details['ec']['x'], 32, "\0", \STR_PAD_LEFT).str_pad($details['ec']['y'], 32, "\0", \STR_PAD_LEFT);
    }

    /** Открытый ключ OpenSSL из точки P-256 (65 байт, несжатая форма). */
    public static function publicKeyFromPoint(string $point): \OpenSSLAsymmetricKey
    {
        if (65 !== \strlen($point) || "\x04" !== $point[0]) {
            throw new \InvalidArgumentException('Открытый ключ браузера должен быть точкой P-256 (65 байт).');
        }
        $pem = "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode(hex2bin(self::SPKI_PREFIX).$point), 64, "\n")."-----END PUBLIC KEY-----\n";
        $key = openssl_pkey_get_public($pem);
        if (false === $key) {
            throw new \InvalidArgumentException('Открытый ключ браузера не является точкой кривой P-256.');
        }

        return $key;
    }

    /** Закрытый ключ OpenSSL из 32 байт d и точки открытого ключа (для проверочных векторов). */
    public static function privateKeyFromRaw(string $d, string $point): \OpenSSLAsymmetricKey
    {
        $der = hex2bin('30770201010420').$d.hex2bin('a00a06082a8648ce3d030107a144034200').$point;
        $key = openssl_pkey_get_private("-----BEGIN EC PRIVATE KEY-----\n".chunk_split(base64_encode($der), 64, "\n")."-----END EC PRIVATE KEY-----\n");
        if (false === $key) {
            throw new \InvalidArgumentException('Неверный закрытый ключ P-256.');
        }

        return $key;
    }

    /**
     * Зашифрованное тело уведомления (RFC 8291). $ephemeral и $salt задаются только в проверках —
     * обычно они новые для каждого сообщения.
     *
     * @param string $p256dh открытый ключ браузера (base64url)
     * @param string $auth   секрет аутентификации браузера (base64url, 16 байт)
     */
    public static function encrypt(string $payload, string $p256dh, string $auth, ?\OpenSSLAsymmetricKey $ephemeral = null, ?string $salt = null): string
    {
        $uaPublic = self::base64UrlDecode($p256dh);
        $authSecret = self::base64UrlDecode($auth);
        if (16 !== \strlen($authSecret)) {
            throw new \InvalidArgumentException('Секрет аутентификации браузера должен быть 16 байт.');
        }
        $ephemeral ??= openssl_pkey_new(['private_key_type' => \OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']) ?: throw new \RuntimeException('Не удалось создать временный ключ.');
        $asPublic = self::publicPoint($ephemeral);
        $shared = openssl_pkey_derive(self::publicKeyFromPoint($uaPublic), $ephemeral, 32);
        if (false === $shared) {
            throw new \RuntimeException('Не удалось вычислить общий секрет (ECDH).');
        }
        $salt ??= random_bytes(16);
        // IKM = HKDF(соль = секрет браузера, общий секрет, "WebPush: info" || 0 || ключ браузера || ключ сервера)
        $ikm = hash_hkdf('sha256', $shared, 32, "WebPush: info\0".$uaPublic.$asPublic, $authSecret);
        $cek = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\0", $salt);
        $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\0", $salt);
        // Одна (последняя) запись: содержимое и разделитель 0x02, без дополнения.
        $cipher = openssl_encrypt($payload."\x02", 'aes-128-gcm', $cek, \OPENSSL_RAW_DATA, $nonce, $tag, '', 16);
        if (false === $cipher) {
            throw new \RuntimeException('Не удалось зашифровать уведомление.');
        }

        return $salt.pack('N', self::RECORD_SIZE).\chr(\strlen($asPublic)).$asPublic.$cipher.$tag;
    }

    /**
     * Расшифровка (для проверок): то же, что делает браузер.
     */
    public static function decrypt(string $body, \OpenSSLAsymmetricKey $uaPrivate, string $auth): string
    {
        $salt = substr($body, 0, 16);
        $idLength = \ord($body[20]);
        $asPublic = substr($body, 21, $idLength);
        $data = substr($body, 21 + $idLength);
        $uaPublic = self::publicPoint($uaPrivate);
        $shared = openssl_pkey_derive(self::publicKeyFromPoint($asPublic), $uaPrivate, 32);
        $ikm = hash_hkdf('sha256', (string) $shared, 32, "WebPush: info\0".$uaPublic.$asPublic, self::base64UrlDecode($auth));
        $cek = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\0", $salt);
        $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\0", $salt);
        $plain = openssl_decrypt(substr($data, 0, -16), 'aes-128-gcm', $cek, \OPENSSL_RAW_DATA, $nonce, substr($data, -16));
        if (false === $plain) {
            throw new \RuntimeException('Не удалось расшифровать.');
        }

        return rtrim($plain, "\0\x02");
    }

    /**
     * Заголовок Authorization по VAPID для службы уведомлений, к которой относится $endpoint.
     */
    public static function vapidHeader(string $endpoint, string $subject, string $publicKey, string $privatePem, ?int $expires = null): string
    {
        $parts = parse_url($endpoint);
        $audience = ($parts['scheme'] ?? 'https').'://'.($parts['host'] ?? '').(isset($parts['port']) ? ':'.$parts['port'] : '');
        $header = self::base64UrlEncode((string) json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
        $claims = self::base64UrlEncode((string) json_encode(['aud' => $audience, 'exp' => $expires ?? time() + 12 * 3600, 'sub' => $subject], \JSON_UNESCAPED_SLASHES));
        $key = openssl_pkey_get_private($privatePem);
        if (false === $key || !openssl_sign($header.'.'.$claims, $der, $key, \OPENSSL_ALGO_SHA256)) {
            throw new \RuntimeException('Не удалось подписать запрос ключом VAPID.');
        }

        return \sprintf('vapid t=%s.%s.%s, k=%s', $header, $claims, self::base64UrlEncode(self::derToRaw($der)), $publicKey);
    }

    /** Подпись ECDSA из DER (SEQUENCE двух INTEGER) в 64 байта r||s, как требует JWT. */
    public static function derToRaw(string $der): string
    {
        $offset = 2;
        if ((\ord($der[1]) & 0x80) !== 0) {
            $offset += \ord($der[1]) & 0x7F;
        }
        $out = '';
        for ($i = 0; $i < 2; ++$i) {
            $length = \ord($der[$offset + 1]);
            $value = ltrim(substr($der, $offset + 2, $length), "\0");
            $out .= str_pad($value, 32, "\0", \STR_PAD_LEFT);
            $offset += 2 + $length;
        }

        return $out;
    }

    /** Обратное преобразование (для проверки подписи в тестах). */
    public static function rawToDer(string $raw): string
    {
        $int = static function (string $v): string {
            $v = ltrim($v, "\0");
            if ('' === $v || \ord($v[0]) > 0x7F) {
                $v = "\0".$v;
            }

            return "\x02".\chr(\strlen($v)).$v;
        };
        $seq = $int(substr($raw, 0, 32)).$int(substr($raw, 32, 32));

        return "\x30".\chr(\strlen($seq)).$seq;
    }
}
