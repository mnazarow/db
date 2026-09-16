<?php

declare(strict_types=1);

namespace App\Security\Api;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use App\Controller\Api\ApiResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * Ошибки в /api/… отдаются в формате JSON, а не HTML-страницами.
 */
final class ApiExceptionSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly bool $debug = false)
    {
    }

    public static function getSubscribedEvents(): array
    {
        // Раньше стандартного ErrorListener (-128), позже слушателя security (1).
        return [KernelEvents::EXCEPTION => ['onException', -10]];
    }

    public function onException(ExceptionEvent $event): void
    {
        $request = $event->getRequest();
        if (!str_starts_with($request->getPathInfo(), '/api/')) {
            return;
        }
        $e = $event->getThrowable();
        if ($e instanceof HttpExceptionInterface) {
            $status = $e->getStatusCode();
            $headers = $e->getHeaders();
        } elseif ($e instanceof AccessDeniedException) {
            $status = Response::HTTP_FORBIDDEN;
            $headers = [];
        } else {
            $status = Response::HTTP_INTERNAL_SERVER_ERROR;
            $headers = [];
        }
        $message = match (true) {
            Response::HTTP_NOT_FOUND === $status => \in_array($e->getMessage(), ['', 'Not Found'], true) || preg_match('/object not found by|No route found/', $e->getMessage()) ? 'Не найдено.' : $e->getMessage(),
            Response::HTTP_UNAUTHORIZED === $status => 'Требуется ключ API.',
            Response::HTTP_FORBIDDEN === $status => 'Доступ запрещён.',
            $status >= 500 => $this->debug ? $e->getMessage() : 'Внутренняя ошибка сервера.',
            default => $e->getMessage(),
        };
        $code = match ($status) {
            400 => 'bad_request',
            401 => 'unauthorized',
            403 => 'forbidden',
            404 => 'not_found',
            405 => 'method_not_allowed',
            429 => 'too_many_requests',
            default => $status >= 500 ? 'server_error' : 'error',
        };
        $event->setResponse(ApiResponse::error($code, $message, $status, $headers));
    }
}
