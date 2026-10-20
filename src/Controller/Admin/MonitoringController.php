<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\User;
use App\Service\FailedJobs;
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
        private readonly ?FailedJobs $failedJobs = null,
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
            'failed_jobs' => $this->failedJobs?->all() ?? [],
            'failed_total' => $this->failedJobs?->count(),
        ]);
    }

    /** Невыполненная фоновая задача: повторить или удалить (по одной или все сразу). */
    #[Route('/failed', name: 'admin_monitoring_failed', methods: ['POST'])]
    public function failed(Request $request, #[CurrentUser] User $user): Response
    {
        if (!$this->isCsrfTokenValid('monitoring_failed', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Неверный CSRF-токен.');
        }
        if (null === $this->failedJobs) {
            throw $this->createNotFoundException();
        }
        $id = (string) $request->request->get('id', '');
        $by = $user->getUsername();
        switch ((string) $request->request->get('action')) {
            case 'retry':
                $error = $this->failedJobs->retry($id, $by);
                null === $error
                    ? $this->addFlash('success', 'Задача повторена.')
                    : $this->addFlash('error', 'Повтор не удался: '.$error);
                break;
            case 'delete':
                $this->failedJobs->delete($id, $by)
                    ? $this->addFlash('success', 'Задача удалена из невыполненных.')
                    : $this->addFlash('error', 'Задача уже повторена или удалена.');
                break;
            case 'retry_all':
                $r = $this->failedJobs->retryAll($by);
                $this->addFlash($r['failed'] > 0 ? 'error' : 'success', \sprintf('Повторено задач: %d%s.', $r['retried'],
                    $r['failed'] > 0 ? \sprintf('; не удалось: %d (%s)', $r['failed'], implode('; ', \array_slice($r['errors'], 0, 3))) : ''));
                break;
            case 'delete_all':
                $this->addFlash('success', \sprintf('Удалено невыполненных задач: %d.', $this->failedJobs->deleteAll($by)));
                break;
            default:
                throw $this->createNotFoundException();
        }

        return $this->redirectToRoute('admin_monitoring', ['_fragment' => 'failed']);
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
