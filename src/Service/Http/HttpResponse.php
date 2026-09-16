<?php

declare(strict_types=1);

namespace App\Service\Http;

/** Результат HTTP-запроса к внешнему сервису. */
final class HttpResponse
{
    public function __construct(
        public readonly int $status,
        public readonly string $body,
        public readonly ?string $error = null,
        public readonly float $seconds = 0.0,
    ) {
    }

    public function ok(): bool
    {
        return null === $this->error && $this->status >= 200 && $this->status < 300;
    }

    /** @return array<string, mixed>|null */
    public function json(): ?array
    {
        $data = json_decode($this->body, true);

        return \is_array($data) ? $data : null;
    }

    /** Короткое описание ошибки для журнала и сообщений администратору. */
    public function describeError(): string
    {
        if (null !== $this->error) {
            return $this->error;
        }
        $json = $this->json();
        $detail = $json['error']['message'] ?? $json['error'] ?? $json['message'] ?? $json['detail'] ?? null;
        if (\is_array($detail)) {
            $detail = json_encode($detail, \JSON_UNESCAPED_UNICODE);
        }
        $detail = \is_string($detail) && '' !== $detail ? $detail : mb_substr(trim(strip_tags($this->body)), 0, 300);

        return \sprintf('HTTP %d%s', $this->status, '' !== $detail ? ': '.$detail : '');
    }
}
