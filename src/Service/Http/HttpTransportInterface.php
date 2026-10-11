<?php

declare(strict_types=1);

namespace App\Service\Http;

/**
 * Минимальный HTTP-клиент для обращений к внешним сервисам (LLM, webhook).
 * В тестах подменяется записывающей реализацией.
 */
interface HttpTransportInterface
{
    /**
     * @param array<string, string> $headers
     */
    public function request(string $method, string $url, ?string $body, array $headers, int $timeoutSeconds): HttpResponse;

    /**
     * Скачивает ответ на GET-запрос в файл (не держа его в памяти). Больше $maxBytes не скачивается:
     * загрузка прерывается с ошибкой. У ответа тело пустое; при ошибке файл может остаться неполным.
     *
     * @param array<string, string> $headers
     */
    public function download(string $url, string $path, array $headers, int $timeoutSeconds, int $maxBytes): HttpResponse;
}
