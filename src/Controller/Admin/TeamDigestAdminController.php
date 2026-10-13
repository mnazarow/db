<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\User;
use App\Service\Org\TeamDigest;
use App\Service\PortalSettings;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** Еженедельная сводка руководителям: включение, пробный запуск и отправка сейчас (панель администратора → Ознакомление). */
#[Route('/admin/acknowledgements/team-digest')]
#[IsGranted('ROLE_ADMIN')]
final class TeamDigestAdminController extends AbstractController
{
    public function __construct(
        private readonly PortalSettings $settings,
        private readonly TeamDigest $digest,
    ) {
    }

    #[Route('', name: 'admin_team_digest', methods: ['POST'])]
    public function save(Request $request, #[CurrentUser] User $user): Response
    {
        $this->checkToken($request);
        $enabled = $request->request->getBoolean('enabled');
        $this->settings->setTeamDigest($enabled, $request->request->getBoolean('only_pending'), $user);
        $this->addFlash('success', $enabled
            ? 'Сводка включена: по понедельникам руководители получат список сотрудников, которые не ознакомились с документами'.' и не посмотрели обязательные видео.'
            : 'Еженедельная сводка руководителям выключена.');

        return $this->redirectToRoute('admin_acknowledgements', ['_fragment' => 'team-digest']);
    }

    #[Route('/send', name: 'admin_team_digest_send', methods: ['POST'])]
    public function send(Request $request): Response
    {
        $this->checkToken($request);
        $dryRun = 'preview' === $request->request->get('mode');
        if (!$dryRun) {
            // Рассылка не обрывается на полпути, даже если браузер или nginx перестали ждать ответа.
            ignore_user_abort(true);
            @set_time_limit(600);
        }
        $result = $this->digest->run($dryRun, true);
        if ($dryRun && [] !== $result['plan']) {
            $lines = array_map(static fn (array $p): string => \sprintf('%s — не выполнено %d, просрочено %d', $p['user']->getDisplayName(), $p['pending'], $p['overdue']), $result['plan']);
            $this->addFlash('info', $result['message'].' Получатели: '.implode('; ', \array_slice($lines, 0, 20)).(\count($lines) > 20 ? '; …' : '').'.');
        } else {
            $this->addFlash(match (true) {
                'error' === $result['status'] => 'error',
                'skipped' === $result['status'] || 0 === $result['managers'] => 'info',
                default => 'success',
            }, $result['message']);
        }

        return $this->redirectToRoute('admin_acknowledgements', ['_fragment' => 'team-digest']);
    }

    private function checkToken(Request $request): void
    {
        if (!$this->isCsrfTokenValid('team_digest', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Неверный CSRF-токен.');
        }
    }
}
