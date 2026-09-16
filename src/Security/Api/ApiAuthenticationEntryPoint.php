<?php

declare(strict_types=1);

namespace App\Security\Api;

use App\Controller\Api\ApiResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authentication\AuthenticationFailureHandlerInterface;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;

/**
 * Ответы 401 для REST API в формате JSON (без ключа и при неверном ключе).
 */
final class ApiAuthenticationEntryPoint implements AuthenticationEntryPointInterface, AuthenticationFailureHandlerInterface
{
    public function start(Request $request, ?AuthenticationException $authException = null): Response
    {
        return self::unauthorized('missing_token', 'Требуется ключ API: заголовок Authorization: Bearer <ключ> или X-Api-Key: <ключ>.');
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        return self::unauthorized('invalid_token', $exception->getMessage() ?: 'Неверный ключ API.');
    }

    private static function unauthorized(string $code, string $message): ApiResponse
    {
        return ApiResponse::error($code, $message, Response::HTTP_UNAUTHORIZED, ['WWW-Authenticate' => \sprintf('Bearer realm="docportal-api", error="%s"', $code)]);
    }
}
