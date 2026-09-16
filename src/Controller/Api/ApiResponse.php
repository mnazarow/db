<?php

declare(strict_types=1);

namespace App\Controller\Api;

use Symfony\Component\HttpFoundation\JsonResponse;

/** JSON-ответ API: без экранирования кириллицы и слэшей — удобно читать и отлаживать. */
final class ApiResponse extends JsonResponse
{
    /**
     * @param array<string, mixed> $data
     * @param array<string, string> $headers
     */
    public function __construct(array $data, int $status = 200, array $headers = [])
    {
        parent::__construct(null, $status, $headers);
        $this->setEncodingOptions(\JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_PRESERVE_ZERO_FRACTION);
        $this->setData($data);
    }

    /** @param array<string, string> $headers */
    public static function error(string $code, string $message, int $status, array $headers = []): self
    {
        return new self(['error' => ['code' => $code, 'message' => $message]], $status, $headers);
    }
}
