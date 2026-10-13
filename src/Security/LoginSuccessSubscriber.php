<?php

declare(strict_types=1);

namespace App\Security;

use App\Controller\KerberosController;
use App\Entity\User;
use App\Repository\PushSubscriptionRepository;
use App\Service\Notification\PushNotifier;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Event\LoginFailureEvent;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;
use Symfony\Component\Security\Http\Event\LogoutEvent;

/**
 * Фиксирует время последнего входа и пишет журнал аудита входов/выходов.
 */
final class LoginSuccessSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $auditLogger,
        private readonly ?PushSubscriptionRepository $pushSubscriptions = null,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            LoginSuccessEvent::class => 'onLoginSuccess',
            LoginFailureEvent::class => 'onLoginFailure',
            // После DefaultLogoutListener (64: ответ с переадресацией — для cookie Kerberos) и раньше очистки сеанса
            // (SessionLogoutListener, 0): нужны подписки, включённые в этом сеансе.
            LogoutEvent::class => ['onLogout', 32],
        ];
    }

    public function onLoginSuccess(LoginSuccessEvent $event): void
    {
        if ('api' === $event->getFirewallName()) {
            return; // обращения по ключу API — не вход пользователя; учёт ведётся в самом ключе (последний запрос, счётчик)
        }
        $user = $event->getUser();
        if ($user instanceof User) {
            $user->setLastLoginAt(new \DateTimeImmutable());
            try {
                $this->em->flush();
            } catch (\Throwable) {
                // Ошибка записи времени входа не должна мешать входу.
            }
        }

        // Второй фактор: каждый новый вход подтверждается заново — до ввода кода пользователь видит
        // только форму подтверждения (TwoFactorSubscriber).
        if ($event->getRequest()->hasSession()) {
            $event->getRequest()->getSession()->remove(TwoFactorSubscriber::SESSION_VERIFIED);
            $event->getRequest()->getSession()->remove(TwoFactorSubscriber::SESSION_ATTEMPTS);
        }

        $this->auditLogger->info('Вход в систему', [
            'user' => $user->getUserIdentifier(),
            'ip' => $event->getRequest()->getClientIp(),
            'second_factor' => $user instanceof User && $user->isTotpEnabled(),
        ]);
    }

    public function onLoginFailure(LoginFailureEvent $event): void
    {
        if ('api' === $event->getFirewallName()) {
            $this->auditLogger->warning('Отклонён запрос к API: неверный или отключённый ключ', ['ip' => $event->getRequest()->getClientIp(), 'path' => $event->getRequest()->getPathInfo()]);

            return;
        }
        $passport = $event->getPassport();
        $badge = null !== $passport && $passport->hasBadge(UserBadge::class) ? $passport->getBadge(UserBadge::class) : null;
        $identifier = $badge instanceof UserBadge ? $badge->getUserIdentifier() : (string) $event->getRequest()->request->get('_username', '');

        $this->auditLogger->warning('Неудачная попытка входа', [
            'user' => mb_substr($identifier, 0, 64),
            'ip' => $event->getRequest()->getClientIp(),
            'reason' => $event->getException()->getMessageKey(),
        ]);
    }

    public function onLogout(LogoutEvent $event): void
    {
        $token = $event->getToken();
        if (null !== $token) {
            $this->auditLogger->info('Выход из системы', ['user' => $token->getUserIdentifier()]);
            // Уведомления в браузер, включённые в этой сессии, больше не приходят на это устройство.
            $user = $token->getUser();
            $request = $event->getRequest();
            if ($user instanceof User && null !== $this->pushSubscriptions && $request->hasSession()) {
                $ids = (array) $request->getSession()->get(PushNotifier::SESSION_KEY, []);
                if ([] !== $ids) {
                    try {
                        $this->pushSubscriptions->deleteForUser($user, $ids);
                    } catch (\Throwable) {
                        // Выход не должен срываться из-за уведомлений.
                    }
                }
            }
        }
        // После выхода страница входа не пробует сразу войти через Windows — иначе выйти было бы нельзя.
        $event->getResponse()?->headers->setCookie(KerberosController::skipCookie($event->getRequest()));
    }
}
