<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Если администратор выдал временный пароль, пользователь обязан сменить его
 * до начала работы: все страницы перенаправляются на форму смены пароля.
 */
final class PasswordChangeRequiredSubscriber implements EventSubscriberInterface
{
    private const ALLOWED_ROUTES = ['app_password_change', 'app_logout', 'app_login', 'app_health'];

    public function __construct(
        private readonly Security $security,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => ['onRequest', 6]];
    }

    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $route = (string) $request->attributes->get('_route', '');
        if ('' === $route || \in_array($route, self::ALLOWED_ROUTES, true) || str_starts_with($route, '_')) {
            return;
        }

        $user = $this->security->getUser();
        if ($user instanceof User && $user->isMustChangePassword()) {
            $request->getSession()->getFlashBag()->add('warning', 'Для продолжения работы необходимо сменить временный пароль.');
            $event->setResponse(new RedirectResponse($this->urlGenerator->generate('app_password_change')));
        }
    }
}
