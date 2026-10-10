<?php

declare(strict_types=1);

namespace App\Service\Http;

/**
 * Тестовая реализация: запоминает запросы и отдаёт заранее заданные ответы (в порядке очереди).
 */
final class RecordingTransport implements HttpTransportInterface
{
    /** @var list<array{method: string, url: string, body: ?string, headers: array<string, string>, timeout: int}> */
    public array $requests = [];

    /** @var list<HttpResponse> */
    private array $queue = [];

    private ?HttpResponse $default = null;

    public function enqueue(HttpResponse ...$responses): void
    {
        foreach ($responses as $response) {
            $this->queue[] = $response;
        }
    }

    public function setDefault(?HttpResponse $response): void
    {
        $this->default = $response;
    }

    public function reset(): void
    {
        $this->requests = [];
        $this->queue = [];
        $this->default = null;
    }

    public function request(string $method, string $url, ?string $body, array $headers, int $timeoutSeconds): HttpResponse
    {
        $this->requests[] = ['method' => $method, 'url' => $url, 'body' => $body, 'headers' => $headers, 'timeout' => $timeoutSeconds];
        if ([] !== $this->queue) {
            return array_shift($this->queue);
        }

        return $this->default ?? new HttpResponse(200, '{}');
    }

    /** Тело очередного ответа записывается в файл (если ответ успешный и не больше $maxBytes). */
    public function download(string $url, string $path, array $headers, int $timeoutSeconds, int $maxBytes): HttpResponse
    {
        $response = $this->request('GET', $url, null, $headers, $timeoutSeconds);
        if (\strlen($response->body) > $maxBytes) {
            return new HttpResponse($response->status, '', 'Файл больше допустимого размера.');
        }
        if ($response->ok()) {
            file_put_contents($path, $response->body);
        }

        return new HttpResponse($response->status, '', $response->error);
    }

    /** @return array{method: string, url: string, body: ?string, headers: array<string, string>, timeout: int}|null */
    public function last(): ?array
    {
        return [] === $this->requests ? null : $this->requests[\count($this->requests) - 1];
    }
}
