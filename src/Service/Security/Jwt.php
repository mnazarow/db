<?php

declare(strict_types=1);

namespace App\Service\Security;

/**
 * JSON Web Token с подписью HS256 (HMAC-SHA256) — для обмена с сервером документов ONLYOFFICE / Р7-Офис
 * и для подписанных ссылок портала. Другие алгоритмы не принимаются (в том числе «none»).
 */
final class Jwt
{
    /** @param array<string, mixed> $payload */
    public static function encode(array $payload, string $secret): string
    {
        if ('' === $secret) {
            throw new \InvalidArgumentException('Пустой секрет подписи.');
        }
        $header = self::base64url((string) json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
        $body = self::base64url((string) json_encode($payload, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR));

        return $header.'.'.$body.'.'.self::base64url(hash_hmac('sha256', $header.'.'.$body, $secret, true));
    }

    /**
     * Проверяет подпись и сроки (exp, nbf с запасом $leeway секунд) и возвращает содержимое.
     * $requireExpiry — токен без срока действия (exp) не принимается.
     *
     * @return array<string, mixed>
     *
     * @throws \InvalidArgumentException токен повреждён, подпись не сходится или срок истёк
     */
    public static function decode(string $token, string $secret, int $leeway = 60, bool $requireExpiry = false): array
    {
        if ('' === $secret) {
            throw new \InvalidArgumentException('Пустой секрет подписи.');
        }
        $parts = explode('.', trim($token));
        if (3 !== \count($parts)) {
            throw new \InvalidArgumentException('Токен повреждён.');
        }
        [$headerPart, $bodyPart, $signaturePart] = $parts;
        $header = json_decode((string) self::unbase64url($headerPart), true);
        if (!\is_array($header) || 'HS256' !== ($header['alg'] ?? null)) {
            throw new \InvalidArgumentException('Неподдерживаемый алгоритм подписи токена.');
        }
        $expected = hash_hmac('sha256', $headerPart.'.'.$bodyPart, $secret, true);
        $signature = self::unbase64url($signaturePart);
        if (null === $signature || !hash_equals($expected, $signature)) {
            throw new \InvalidArgumentException('Подпись токена не сходится.');
        }
        $payload = json_decode((string) self::unbase64url($bodyPart), true);
        if (!\is_array($payload)) {
            throw new \InvalidArgumentException('Токен повреждён.');
        }
        $now = time();
        if (isset($payload['exp']) && !is_numeric($payload['exp'])) {
            throw new \InvalidArgumentException('Срок действия токена указан неверно.');
        }
        if ($requireExpiry && !isset($payload['exp'])) {
            throw new \InvalidArgumentException('У токена нет срока действия.');
        }
        if (isset($payload['exp']) && $now > (int) $payload['exp'] + $leeway) {
            throw new \InvalidArgumentException('Срок действия токена истёк.');
        }
        if (isset($payload['nbf']) && is_numeric($payload['nbf']) && $now + $leeway < (int) $payload['nbf']) {
            throw new \InvalidArgumentException('Токен ещё не действует.');
        }

        return $payload;
    }

    private static function base64url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function unbase64url(string $data): ?string
    {
        if (1 !== preg_match('/^[A-Za-z0-9_-]*$/', $data)) {
            return null;
        }
        $decoded = base64_decode(strtr($data, '-_', '+/').str_repeat('=', (4 - \strlen($data) % 4) % 4), true);

        return false === $decoded ? null : $decoded;
    }
}
