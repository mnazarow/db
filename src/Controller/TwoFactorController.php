<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Security\TwoFactorSubscriber;
use App\Service\Security\QrCode;
use App\Service\Security\Totp;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Двухфакторная аутентификация: настройка в профиле и подтверждение кода при входе.
 */
final class TwoFactorController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $auditLogger,
        private readonly string $appName,
    ) {
    }

    /** Подтверждение кода после входа по паролю. */
    #[Route('/login/2fa', name: 'app_2fa_verify', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_USER')]
    public function verify(Request $request, #[CurrentUser] User $user): Response
    {
        $session = $request->getSession();
        if (true !== $session->get(TwoFactorSubscriber::SESSION_PENDING)) {
            return $this->redirectToRoute('app_home');
        }
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('two_factor', (string) $request->request->get('_token'))) {
                throw $this->createAccessDeniedException('Неверный CSRF-токен.');
            }
            $code = trim((string) $request->request->get('code'));
            $attempts = (int) $session->get(TwoFactorSubscriber::SESSION_ATTEMPTS, 0);
            $byRecovery = false;
            $ok = Totp::verify((string) $user->getTotpSecret(), $code);
            if (!$ok && $user->useRecoveryCode($code)) {
                $ok = true;
                $byRecovery = true;
                $this->em->flush();
            }
            if ($ok) {
                $session->remove(TwoFactorSubscriber::SESSION_PENDING);
                $session->remove(TwoFactorSubscriber::SESSION_ATTEMPTS);
                $this->auditLogger->info('Подтверждён второй фактор', ['user' => $user->getUsername(), 'ip' => $request->getClientIp(), 'recovery' => $byRecovery]);
                if ($byRecovery) {
                    $this->addFlash('warning', \sprintf('Вход по резервному коду. Осталось резервных кодов: %d — создайте новые в профиле.', $user->countRecoveryCodes()));
                }

                return $this->redirectToRoute('app_home');
            }
            ++$attempts;
            $session->set(TwoFactorSubscriber::SESSION_ATTEMPTS, $attempts);
            $this->auditLogger->warning('Неверный код второго фактора', ['user' => $user->getUsername(), 'ip' => $request->getClientIp(), 'attempt' => $attempts]);
            if ($attempts >= TwoFactorSubscriber::MAX_ATTEMPTS) {
                // Слишком много попыток: сбрасываем вход целиком, нужно входить заново.
                $this->auditLogger->warning('Вход отменён: исчерпаны попытки ввода кода', ['user' => $user->getUsername(), 'ip' => $request->getClientIp()]);
                $session->invalidate();
                $this->addFlash('danger', 'Слишком много неверных кодов. Войдите заново.');

                return $this->redirectToRoute('app_login');
            }
            $this->addFlash('danger', \sprintf('Неверный код. Осталось попыток: %d.', TwoFactorSubscriber::MAX_ATTEMPTS - $attempts));
        }

        return $this->render('security/two_factor.html.twig', [
            'recovery_left' => $user->countRecoveryCodes(),
        ]);
    }

    /** Настройка второго фактора в профиле: ключ, QR-код, подтверждение и резервные коды. */
    #[Route('/profile/2fa', name: 'app_2fa_setup', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_USER')]
    public function setup(Request $request, #[CurrentUser] User $user): Response
    {
        $session = $request->getSession();
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('two_factor_setup', (string) $request->request->get('_token'))) {
                throw $this->createAccessDeniedException('Неверный CSRF-токен.');
            }
            $action = (string) $request->request->get('action');
            if ('disable' === $action) {
                return $this->disable($user, $request);
            }
            if ('recovery' === $action) {
                return $this->regenerateRecovery($user, $request);
            }
            // Подтверждение нового секрета кодом из приложения.
            $secret = (string) $session->get('2fa_setup_secret');
            if ('' === $secret) {
                $this->addFlash('danger', 'Настройка началась слишком давно — откройте страницу заново.');

                return $this->redirectToRoute('app_2fa_setup');
            }
            if (!Totp::verify($secret, (string) $request->request->get('code'))) {
                $this->addFlash('danger', 'Код не подошёл. Проверьте время на телефоне и введите код ещё раз.');

                return $this->redirectToRoute('app_2fa_setup');
            }
            $codes = Totp::recoveryCodes();
            $user->setTotpSecret($secret)->confirmTotp()->setRecoveryCodes($codes);
            $this->em->flush();
            $session->remove('2fa_setup_secret');
            $session->set('2fa_recovery_shown', $codes);
            $this->auditLogger->info('Включена двухфакторная аутентификация', ['user' => $user->getUsername(), 'ip' => $request->getClientIp()]);
            $this->addFlash('success', 'Двухфакторная аутентификация включена. Сохраните резервные коды — они показываются один раз.');

            return $this->redirectToRoute('app_2fa_setup');
        }

        $secret = null;
        $uri = null;
        $qr = null;
        if (!$user->isTotpEnabled()) {
            $secret = (string) $session->get('2fa_setup_secret');
            if ('' === $secret) {
                $secret = Totp::generateSecret();
                $session->set('2fa_setup_secret', $secret);
            }
            $uri = Totp::uri($secret, $user->getUsername(), $this->appName);
            $qr = QrCode::svg($uri, 5);
        }
        $codes = (array) $session->remove('2fa_recovery_shown');

        return $this->render('profile/two_factor.html.twig', [
            'secret' => $secret,
            'secret_human' => null !== $secret ? Totp::humanSecret($secret) : null,
            'uri' => $uri,
            'qr' => $qr,
            'codes' => $codes,
            'recovery_left' => $user->countRecoveryCodes(),
        ]);
    }

    private function disable(User $user, Request $request): Response
    {
        if (!$user->isTotpEnabled()) {
            return $this->redirectToRoute('app_2fa_setup');
        }
        if (!Totp::verify((string) $user->getTotpSecret(), (string) $request->request->get('code'))) {
            $this->addFlash('danger', 'Чтобы выключить второй фактор, введите текущий код из приложения.');

            return $this->redirectToRoute('app_2fa_setup');
        }
        $user->setTotpSecret(null);
        $this->em->flush();
        $this->auditLogger->warning('Выключена двухфакторная аутентификация', ['user' => $user->getUsername(), 'ip' => $request->getClientIp()]);
        $this->addFlash('info', 'Двухфакторная аутентификация выключена.');

        return $this->redirectToRoute('app_2fa_setup');
    }

    private function regenerateRecovery(User $user, Request $request): Response
    {
        if (!$user->isTotpEnabled()) {
            return $this->redirectToRoute('app_2fa_setup');
        }
        $codes = Totp::recoveryCodes();
        $user->setRecoveryCodes($codes);
        $this->em->flush();
        $this->auditLogger->info('Созданы новые резервные коды', ['user' => $user->getUsername()]);
        $this->addFlash('success', 'Созданы новые резервные коды. Прежние больше не действуют.');
        $request->getSession()->set('2fa_recovery_shown', $codes);

        return $this->redirectToRoute('app_2fa_setup');
    }
}
