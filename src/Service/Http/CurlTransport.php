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

    public function requestMany(array $requests, int $timeoutSeconds, int $concurrency = 20): array
    {
        if (!\function_exists('curl_multi_init')) {
            return array_map(fn (array $r): HttpResponse => $this->request($r['method'], $r['url'], $r['body'], $r['headers'], $timeoutSeconds), $requests);
        }
        $out = [];
        foreach (array_chunk($requests, max(1, $concurrency), true) as $chunk) {
            $multi = curl_multi_init();
            $handles = [];
            $started = microtime(true);
            foreach ($chunk as $i => $r) {
                $handles[$i] = $this->handle($r['method'], $r['url'], $r['body'], $r['headers'], $timeoutSeconds);
                curl_multi_add_handle($multi, $handles[$i]);
            }
            do {
                $status = curl_multi_exec($multi, $running);
                if ($running > 0) {
                    curl_multi_select($multi, 1.0);
                }
            } while ($running > 0 && \CURLM_OK === $status);
            foreach ($handles as $i => $ch) {
                $body = curl_multi_getcontent($ch);
                $code = (int) curl_getinfo($ch, \CURLINFO_RESPONSE_CODE);
                $error = curl_error($ch);
                $out[$i] = new HttpResponse($code, \is_string($body) ? $body : '', '' !== $error || 0 === $code ? ($error ?: 'Ошибка соединения.') : null, microtime(true) - $started);
                curl_multi_remove_handle($multi, $ch);
                curl_close($ch);
            }
            curl_multi_close($multi);
        }
        ksort($out);

        return array_values($out);
    }

    /** @param array<string, string> $headers */
    private function handle(string $method, string $url, ?string $body, array $headers, int $timeoutSeconds): \CurlHandle
    {
        $ch = curl_init();
        $headerLines = [];
        foreach ($headers as $name => $value) {
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

        return $ch;
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
