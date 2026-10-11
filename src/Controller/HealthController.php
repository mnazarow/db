<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\Monitoring\HealthReport;
use App\Service\PortalSettings;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Проверка работоспособности: GET /health → {"status":"ok"} (для скриптов установки и простых проверок),
 * GET /health/details (JSON для Zabbix) и GET /metrics (Prometheus) — подробное состояние, только с токеном
 * мониторинга (панель администратора → Мониторинг).
 */
final class HealthController extends AbstractController
{
    #[Route('/health', name: 'app_health', methods: ['GET'])]
    public function health(Connection $connection): JsonResponse
    {
        try {
            $connection->executeQuery('SELECT 1')->fetchOne();
            $db = 'ok';
        } catch (\Throwable) {
            $db = 'error';
        }
        $status = 'ok' === $db ? 'ok' : 'degraded';

        return new JsonResponse(['status' => $status, 'db' => $db, 'version' => $this->getParameter('app.version'), 'time' => date('c')], 'ok' === $status ? 200 : 503);
    }

    /** Подробное состояние; ?strict=1 — код 503, если есть неисправность (для простых HTTP-проверок). */
    #[Route('/health/details', name: 'app_health_details', methods: ['GET'])]
    public function details(Request $request, HealthReport $health, PortalSettings $settings): JsonResponse
    {
        if (null !== ($denied = $this->checkToken($request, $settings))) {
            return new JsonResponse(['status' => 'denied', 'error' => $denied->getContent()], $denied->getStatusCode(), self::noCache());
        }
        $report = $health->build();
        $code = $request->query->getBoolean('strict') && HealthReport::CRITICAL === $report['status'] ? 503 : 200;

        return new JsonResponse($report, $code, self::noCache());
    }

    #[Route('/metrics', name: 'app_metrics', methods: ['GET'])]
    public function metrics(Request $request, HealthReport $health, PortalSettings $settings): Response
    {
        if (null !== ($denied = $this->checkToken($request, $settings))) {
            return $denied;
        }

        return new Response(HealthReport::prometheus($health->build()), 200, ['Content-Type' => 'text/plain; version=0.0.4; charset=UTF-8'] + self::noCache());
    }

    /** Токен — в заголовке Authorization: Bearer … или параметре ?token= (для систем, где заголовок не задать). */
    private function checkToken(Request $request, PortalSettings $settings): ?Response
    {
        $expected = $settings->monitoringToken();
        if ('' === $expected) {
            return new Response('Мониторинг выключен: создайте токен в панели администратора → Мониторинг.', 404, ['Content-Type' => 'text/plain; charset=UTF-8']);
        }
        $header = (string) $request->headers->get('Authorization', '');
        $given = str_starts_with($header, 'Bearer ') ? trim(substr($header, 7)) : (string) $request->query->get('token', '');
        if ('' === $given || !hash_equals($expected, $given)) {
            return new Response('Неверный токен мониторинга.', 403, ['Content-Type' => 'text/plain; charset=UTF-8']);
        }

        return null;
    }

    /** @return array<string, string> */
    private static function noCache(): array
    {
        return ['Cache-Control' => 'no-store'];
    }
}
