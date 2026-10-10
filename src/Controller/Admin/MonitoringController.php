<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\User;
use App\Service\Monitoring\HealthReport;
use App\Service\PortalSettings;
use App\Service\PortalUrl;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Панель администратора → Мониторинг: состояние портала, регулярные задания (cron, резервные копии,
 * проверка восстановления) и токен для систем мониторинга (/health/details, /metrics).
 */
#[Route('/admin/monitoring')]
final class MonitoringController extends AbstractController
{
    public function __construct(
        private readonly HealthReport $health,
        private readonly PortalSettings $settings,
        private readonly PortalUrl $portalUrl,
    ) {
    }

    #[Route('', name: 'admin_monitoring', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $token = $this->settings->monitoringToken();

        return $this->render('admin/monitoring/index.html.twig', [
            'report' => $this->health->build(),
            'token_set' => '' !== $token,
            'token_masked' => PortalSettings::mask($token),
            'new_token' => $request->getSession()->getFlashBag()->get('monitoring_token')[0] ?? null,
            'details_url' => $this->portalUrl->absolute('app_health_details'),
            'metrics_url' => $this->portalUrl->absolute('app_metrics'),
        ]);
    }

    #[Route('/token', name: 'admin_monitoring_token', methods: ['POST'])]
    public function token(Request $request, #[CurrentUser] User $user): Response
    {
        $this->checkToken($request);
        $token = $this->settings->regenerateMonitoringToken($user);
        $this->addFlash('monitoring_token', $token);
        $this->addFlash('success', 'Создан новый токен мониторинга — скопируйте его сейчас: потом он будет скрыт. Прежний токен больше не действует.');

        return $this->redirectToRoute('admin_monitoring', ['_fragment' => 'token']);
    }

    #[Route('/token/delete', name: 'admin_monitoring_token_delete', methods: ['POST'])]
    public function deleteToken(Request $request, #[CurrentUser] User $user): Response
    {
        $this->checkToken($request);
        $this->settings->set(PortalSettings::MONITORING_TOKEN, '', $user);
        $this->addFlash('success', 'Токен мониторинга удалён: /health/details и /metrics отключены.');

        return $this->redirectToRoute('admin_monitoring', ['_fragment' => 'token']);
    }

    private function checkToken(Request $request): void
    {
        if (!$this->isCsrfTokenValid('monitoring', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Неверный CSRF-токен.');
        }
    }
}
