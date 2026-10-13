<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\PushSubscription;
use App\Entity\User;
use App\Repository\PushSubscriptionRepository;
use App\Service\Notification\PushNotifier;
use App\Service\Notification\WebPush;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Уведомления в браузере: подписка устройства сотрудника (её создаёт браузер — Push API), отписка и пробное
 * уведомление. Страница — «Подписки и уведомления» в профиле.
 */
#[Route('/profile/push')]
#[IsGranted('ROLE_USER')]
final class PushController extends AbstractController
{
    public function __construct(
        private readonly PushNotifier $push,
        private readonly PushSubscriptionRepository $subscriptions,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $auditLogger,
    ) {
    }

    #[Route('/subscribe', name: 'app_push_subscribe', methods: ['POST'])]
    public function subscribe(Request $request, #[CurrentUser] User $user): JsonResponse
    {
        if (!$this->isCsrfTokenValid('push', (string) $request->headers->get('X-CSRF-Token'))) {
            return $this->json(['error' => 'Страница устарела — обновите её и повторите.'], 400);
        }
        if (!$this->push->isEnabled()) {
            return $this->json(['error' => 'Уведомления в браузере выключены администратором.'], 404);
        }
        $data = json_decode($request->getContent(), true);
        $endpoint = \is_array($data) ? (string) ($data['endpoint'] ?? '') : '';
        $p256dh = \is_array($data) ? (string) ($data['keys']['p256dh'] ?? '') : '';
        $auth = \is_array($data) ? (string) ($data['keys']['auth'] ?? '') : '';
        if (!PushNotifier::isAllowedEndpoint($endpoint)) {
            return $this->json(['error' => 'Этот браузер использует неизвестную службу уведомлений — подписка не сохранена.'], 400);
        }
        try {
            WebPush::publicKeyFromPoint(WebPush::base64UrlDecode($p256dh));
            if (16 !== \strlen(WebPush::base64UrlDecode($auth))) {
                throw new \InvalidArgumentException('auth');
            }
        } catch (\InvalidArgumentException) {
            return $this->json(['error' => 'Браузер передал неверные ключи подписки.'], 400);
        }
        $device = self::device((string) $request->headers->get('User-Agent'));
        $subscription = $this->subscriptions->findByEndpoint($endpoint);
        // Проверка при открытии страницы: подписка браузера обновляется, только если это устройство уже включено
        // этим сотрудником. Чужая подписка (общий компьютер) молча не переходит к вошедшему — только по кнопке.
        if (true === ($data['refresh'] ?? false) && (null === $subscription || $subscription->getUser()->getId() !== $user->getId())) {
            return $this->json(['ok' => true, 'known' => false]);
        }
        if (null === $subscription) {
            $subscription = new PushSubscription($user, $endpoint, $p256dh, $auth, $device);
            $this->em->persist($subscription);
            $this->auditLogger->info('Включены уведомления в браузере', ['user' => $user->getUsername(), 'device' => $device]);
        } else {
            // Сотрудник сам включил уведомления в этом браузере — подписка переходит к нему.
            $subscription->setUser($user)->update($endpoint, $p256dh, $auth, $device);
        }
        $this->em->flush();
        $this->subscriptions->trimForUser($user, PushNotifier::MAX_PER_USER, (int) $subscription->getId());
        // При выходе из портала подписка этого устройства удаляется — на общем компьютере уведомления не
        // достанутся следующему сотруднику.
        if ($request->hasSession()) {
            $session = $request->getSession();
            $ids = (array) $session->get(PushNotifier::SESSION_KEY, []);
            $ids[] = (int) $subscription->getId();
            $session->set(PushNotifier::SESSION_KEY, array_values(array_unique(array_map('intval', $ids))));
        }

        return $this->json(['ok' => true, 'known' => true, 'id' => $subscription->getId(), 'device' => $device]);
    }

    #[Route('/unsubscribe', name: 'app_push_unsubscribe', methods: ['POST'])]
    public function unsubscribe(Request $request, #[CurrentUser] User $user): Response
    {
        $json = str_starts_with((string) $request->headers->get('Content-Type'), 'application/json');
        $token = $json ? (string) $request->headers->get('X-CSRF-Token') : (string) $request->request->get('_token');
        if (!$this->isCsrfTokenValid('push', $token)) {
            return $json ? $this->json(['error' => 'Страница устарела — обновите её и повторите.'], 400) : throw $this->createAccessDeniedException('Неверный CSRF-токен.');
        }
        $subscription = null;
        if ($json) {
            $data = json_decode($request->getContent(), true);
            $subscription = $this->subscriptions->findByEndpoint(\is_array($data) ? (string) ($data['endpoint'] ?? '') : '');
        } else {
            $subscription = $this->subscriptions->find($request->request->getInt('id'));
        }
        if (null !== $subscription && $subscription->getUser()->getId() === $user->getId()) {
            $this->em->remove($subscription);
            $this->em->flush();
            $this->auditLogger->info('Отключены уведомления в браузере', ['user' => $user->getUsername(), 'device' => $subscription->getDevice()]);
        }
        if ($json) {
            return $this->json(['ok' => true]);
        }
        $this->addFlash('success', 'Уведомления на этом устройстве отключены.');

        return $this->redirectToRoute('app_profile_subscriptions');
    }

    #[Route('/test', name: 'app_push_test', methods: ['POST'])]
    public function test(Request $request, #[CurrentUser] User $user): Response
    {
        if (!$this->isCsrfTokenValid('push', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Неверный CSRF-токен.');
        }
        $result = $this->push->sendTest($user);
        if ($result['sent'] > 0) {
            $this->addFlash('success', \sprintf('Пробное уведомление отправлено на устройства: %d.', $result['sent']));
        } elseif ($result['removed'] > 0 && 0 === $result['failed']) {
            $this->addFlash('warning', 'Подписка устарела — включите уведомления на этом устройстве заново.');
        } else {
            $this->addFlash('warning', 'Уведомление не отправлено: устройств нет или служба уведомлений браузера недоступна с сервера портала.');
        }

        return $this->redirectToRoute('app_profile_subscriptions');
    }

    /** «Chrome, Windows» по заголовку User-Agent — чтобы сотрудник узнал устройство в списке. */
    public static function device(string $userAgent): string
    {
        $browser = match (true) {
            str_contains($userAgent, 'YaBrowser') => 'Яндекс Браузер',
            str_contains($userAgent, 'Edg/') => 'Edge',
            str_contains($userAgent, 'OPR/') => 'Opera',
            str_contains($userAgent, 'Firefox/') => 'Firefox',
            str_contains($userAgent, 'Chrome/') => 'Chrome',
            str_contains($userAgent, 'Safari/') => 'Safari',
            default => 'Браузер',
        };
        $os = match (true) {
            str_contains($userAgent, 'Android') => 'Android',
            str_contains($userAgent, 'iPhone') || str_contains($userAgent, 'iPad') => 'iOS',
            str_contains($userAgent, 'Windows') => 'Windows',
            str_contains($userAgent, 'Mac OS X') => 'macOS',
            str_contains($userAgent, 'Linux') => 'Linux',
            default => '',
        };

        return '' !== $os ? $browser.', '.$os : $browser;
    }
}
