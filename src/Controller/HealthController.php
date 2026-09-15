<?php

declare(strict_types=1);

namespace App\Controller;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Проверка работоспособности для скриптов установки и мониторинга: GET /health → {"status":"ok"}.
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
}
