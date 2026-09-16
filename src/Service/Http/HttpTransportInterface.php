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
}
