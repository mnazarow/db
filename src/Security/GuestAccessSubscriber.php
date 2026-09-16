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
use Symfony\Component\Security\Http\Util\TargetPathTrait;

/**
 * Гостевой доступ: если просмотр без входа выключен или не разрешён с адреса посетителя,
 * все страницы, кроме входа и служебных, перенаправляют на форму входа (с возвратом после входа).
 */
final class GuestAccessSubscriber implements EventSubscriberInterface
{
    use TargetPathTrait;

    /** Маршруты, доступные всегда. */
    private const PUBLIC_ROUTES = ['app_login', 'app_logout', 'app_health'];

    public function __construct(
        private readonly Security $security,
        private readonly PortalSettings $settings,
        private readonly UrlGeneratorInterface $urls,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        // После слушателя брандмауэра (приоритет 8), чтобы был известен пользователь.
        return [KernelEvents::REQUEST => ['onRequest', 4]];
    }

    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $request = $event->getRequest();
        $route = (string) $request->attributes->get('_route', '');
        if ('' === $route || str_starts_with($route, '_') || \in_array($route, self::PUBLIC_ROUTES, true)) {
            return;
        }
        if ($this->security->getUser() instanceof User) {
            return;
        }
        if ($this->settings->isGuestAllowed($request->getClientIp())) {
            return;
        }
        if ($request->isMethod('GET') && !$request->isXmlHttpRequest() && $request->hasSession()) {
            $this->saveTargetPath($request->getSession(), 'main', $request->getUri());
            $request->getSession()->getFlashBag()->add('info', self::message($this->settings->guestMode()));
        }
        $event->setResponse(new RedirectResponse($this->urls->generate('app_login')));
    }

    public static function message(string $mode): string
    {
        return PortalSettings::GUEST_IP === $mode
            ? 'Просмотр без входа с вашего адреса не разрешён. Войдите в портал.'
            : 'Для просмотра документов войдите в портал.';
    }
}
