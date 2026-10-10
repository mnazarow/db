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
            // Значения берутся из настроек портала — переводы строк убираем, чтобы нельзя было подставить свой заголовок.
            $headerLines[] = $name.': '.str_replace(["\r", "\n"], '', $value);
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

    public function download(string $url, string $path, array $headers, int $timeoutSeconds, int $maxBytes): HttpResponse
    {
        if (!\function_exists('curl_init')) {
            return new HttpResponse(0, '', 'Расширение PHP curl не установлено.');
        }
        $file = @fopen($path, 'wb');
        if (false === $file) {
            return new HttpResponse(0, '', 'Не удалось создать временный файл.');
        }
        $started = microtime(true);
        $tooLarge = false;
        $ch = curl_init();
        $headerLines = [];
        foreach ($headers as $name => $value) {
            $headerLines[] = $name.': '.str_replace(["\r", "\n"], '', $value);
        }
        curl_setopt_array($ch, [
            \CURLOPT_URL => $url,
            \CURLOPT_HTTPGET => true,
            \CURLOPT_FILE => $file,
            \CURLOPT_FOLLOWLOCATION => false,
            \CURLOPT_CONNECTTIMEOUT => min(15, max(1, $timeoutSeconds)),
            \CURLOPT_TIMEOUT => max(1, $timeoutSeconds),
            \CURLOPT_HTTPHEADER => $headerLines,
            \CURLOPT_USERAGENT => 'docportal/1.0',
            \CURLOPT_PROTOCOLS => \CURLPROTO_HTTP | \CURLPROTO_HTTPS,
            \CURLOPT_FAILONERROR => true,
            \CURLOPT_NOPROGRESS => false,
            \CURLOPT_PROGRESSFUNCTION => static function ($handle, float|int $total, float|int $received) use ($maxBytes, &$tooLarge): int {
                if ($total > $maxBytes || $received > $maxBytes) {
                    $tooLarge = true;

                    return 1; // ненулевой ответ прерывает загрузку
                }

                return 0;
            },
        ]);
        $ok = curl_exec($ch);
        $status = (int) curl_getinfo($ch, \CURLINFO_RESPONSE_CODE);
        $error = false === $ok ? ($tooLarge ? 'Файл больше допустимого размера.' : (curl_error($ch) ?: 'Ошибка соединения.')) : null;
        curl_close($ch);
        fclose($file);

        return new HttpResponse($status, '', $error, microtime(true) - $started);
    }
}
