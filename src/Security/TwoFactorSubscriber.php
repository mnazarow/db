<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use App\Service\PortalSettings;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Второй фактор при входе: пока код из приложения-аутентификатора не введён, все страницы
 * перенаправляются на форму подтверждения. Если политика портала требует двухфакторной
 * аутентификации для администраторов, они отправляются на страницу её настройки.
 */
final class TwoFactorSubscriber implements EventSubscriberInterface
{
    /** Ключ сессии: вход выполнен, но код второго фактора ещё не введён. */
    public const SESSION_PENDING = '2fa_pending';

    /** Ключ сессии: число неудачных попыток ввода кода. */
    public const SESSION_ATTEMPTS = '2fa_attempts';

    public const MAX_ATTEMPTS = 5;

    private const ALLOWED_ROUTES = ['app_2fa_verify', 'app_logout', 'app_login', 'app_health'];
    private const SETUP_ROUTES = ['app_2fa_setup', 'app_2fa_verify', 'app_logout', 'app_login', 'app_health', 'app_password_change'];

    public function __construct(
        private readonly Security $security,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly PortalSettings $settings,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        // Приоритет 7 — до проверки временного пароля (6): второй фактор важнее.
        return [KernelEvents::REQUEST => ['onRequest', 7]];
    }

    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $request = $event->getRequest();
        $route = (string) $request->attributes->get('_route', '');
        if ('' === $route || str_starts_with($route, '_') || str_starts_with($route, 'api_')) {
            return;
        }
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            return;
        }
        if ($request->hasSession() && true === $request->getSession()->get(self::SESSION_PENDING)) {
            if (\in_array($route, self::ALLOWED_ROUTES, true)) {
                return;
            }
            $event->setResponse(new RedirectResponse($this->urlGenerator->generate('app_2fa_verify')));

            return;
        }
        // Политика: администраторы обязаны включить второй фактор.
        if ($user->isAdmin() && !$user->isTotpEnabled() && $this->settings->requireAdminTwoFactor() && !\in_array($route, self::SETUP_ROUTES, true)) {
            $request->getSession()->getFlashBag()->add('warning', 'Для администраторов включена обязательная двухфакторная аутентификация — настройте её, чтобы продолжить работу.');
            $event->setResponse(new RedirectResponse($this->urlGenerator->generate('app_2fa_setup')));
        }
    }
}
