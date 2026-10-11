<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Security\Access;
use App\Security\TwoFactorSubscriber;
use App\Service\PortalSettings;
use App\Service\Video\VideoIntegration;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/**
 * Общий вход с разделом «Видео».
 *
 * Видеопортал (CorpVideo по адресу /video) своих паролей не хранит: на каждый запрос браузера он
 * спрашивает портал, кто вошёл, передавая cookie браузера (сессия, «Запомнить меня») на этот адрес по
 * внутренней сети и подписывая запрос общим секретом VIDEO_SHARED_SECRET. Портал отвечает так же, как
 * ответил бы самому браузеру: вошёл ли сотрудник, прошёл ли второй фактор, не заблокирован ли, какая у
 * него роль. Если сессия восстановлена по «Запомнить меня», новые cookie возвращаются в ответе —
 * видеопортал передаёт их браузеру.
 *
 * Снаружи адрес закрыт (nginx отдаёт /internal/ только с локальных адресов), а без секрета он
 * отвечает 404, как будто его нет.
 */
final class VideoSessionController extends AbstractController
{
    public function __construct(
        private readonly VideoIntegration $video,
        private readonly Access $access,
        private readonly PortalSettings $settings,
        private readonly CsrfTokenManagerInterface $csrf,
        private readonly string $appName,
        private readonly string $appCompany,
    ) {
    }

    #[Route('/internal/video/session', name: 'video_session', methods: ['GET'])]
    public function session(Request $request): JsonResponse
    {
        if (!$this->video->verifySecret($request->headers->get('X-Video-Secret'))) {
            return new JsonResponse(['error' => 'not found'], 404);
        }

        $user = $this->getUser();
        $state = 'guest';
        if ($user instanceof User) {
            $session = $request->hasSession() ? $request->getSession() : null;
            $state = match (true) {
                !$user->isActive() => 'blocked',
                $user->isTotpEnabled() && (null === $session || !TwoFactorSubscriber::isVerified($session, $user)) => 'two_factor',
                $user->isMustChangePassword() => 'password_change',
                $user->isAdmin() && !$user->isTotpEnabled() && $this->settings->requireAdminTwoFactor() => 'two_factor_setup',
                default => 'ok',
            };
        }

        $data = [
            'authenticated' => 'ok' === $state,
            'state' => $state,
            'user' => null,
            'portal' => [
                'name' => $this->appName,
                'company' => $this->appCompany,
                'home' => $this->generateUrl('app_home'),
                'login' => $this->generateUrl('app_login'),
                'moderation' => $this->generateUrl('app_moderation'),
            ],
        ];
        if ($user instanceof User) {
            // Роль для видео: маркетолог и модератор раздела (хоть одного) — модераторы видео.
            $position = $user->getPosition();
            if (User::POSITION_USER === $position && $this->access->isModerator($user)) {
                $position = User::POSITION_MARKETER;
            }
            $data['user'] = [
                'id' => $user->getId(),
                'username' => $user->getUsername(),
                'displayName' => $user->getDisplayName(),
                'email' => $user->getEmail(),
                'department' => $user->getDepartment(),
                'position' => $position,
                'active' => $user->isActive(),
                'source' => $user->getAuthSource(),
            ];
            // Кнопка «Выйти» видеопортала отправляет форму выхода портала с этим токеном (выход общий).
            $data['logout'] = [
                'url' => $this->generateUrl('app_logout'),
                'field' => '_csrf_token',
                'token' => $this->csrf->getToken('logout')->getValue(),
            ];
        }

        $response = new JsonResponse($data);
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('X-Robots-Tag', 'noindex');

        return $response;
    }
}
