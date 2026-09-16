<?php

declare(strict_types=1);

namespace App\Service\Http;

/**
 * HTTP-клиент на расширении curl (без дополнительных зависимостей).
 */
final class CurlTransport implements HttpTransportInterface
{
    public function request(string $method, string $url, ?string $body, array $headers, int $timeoutSeconds): HttpResponse
    {
        if (!\function_exists('curl_init')) {
            return new HttpResponse(0, '', 'Расширение PHP curl не установлено.');
        }
        $started = microtime(true);
        $ch = curl_init();
        $headerLines = [];
        foreach ($headers as $name => $value) {
            $headerLines[] = $name.': '.$value;
        }
        curl_setopt_array($ch, [
            \CURLOPT_URL => $url,
            \CURLOPT_CUSTOMREQUEST => strtoupper($method),
            \CURLOPT_RETURNTRANSFER => true,
            \CURLOPT_FOLLOWLOCATION => false,
            \CURLOPT_CONNECTTIMEOUT => min(15, max(1, $timeoutSeconds)),
            \CURLOPT_TIMEOUT => max(1, $timeoutSeconds),
            \CURLOPT_HTTPHEADER => $headerLines,
            \CURLOPT_USERAGENT => 'docportal/1.0',
            \CURLOPT_PROTOCOLS => \CURLPROTO_HTTP | \CURLPROTO_HTTPS,
        ]);
        if (null !== $body) {
            curl_setopt($ch, \CURLOPT_POSTFIELDS, $body);
        }
        $result = curl_exec($ch);
        $status = (int) curl_getinfo($ch, \CURLINFO_RESPONSE_CODE);
        $error = false === $result ? (curl_error($ch) ?: 'Ошибка соединения.') : null;
        curl_close($ch);

        return new HttpResponse($status, \is_string($result) ? $result : '', $error, microtime(true) - $started);
    }
}
