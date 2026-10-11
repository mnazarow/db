<?php

declare(strict_types=1);

namespace App\Controller;

use App\Security\KerberosLogin;
use App\Security\PortalAuthenticator;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Util\TargetPathTrait;

/**
 * Вход через Windows (Kerberos): адрес /login/kerberos защищён веб-сервером (SPNEGO) — сюда браузер приходит уже
 * с проверенной учётной записью. Не удалось — страница входа с объяснением, автоматически больше не пробуем
 * (cookie на 15 минут), чтобы не зациклиться.
 */
final class KerberosController extends AbstractController
{
    use TargetPathTrait;

    public function __construct(
        private readonly KerberosLogin $kerberos,
        private readonly Security $security,
        private readonly LoggerInterface $auditLogger,
    ) {
    }

    #[Route('/login/kerberos', name: 'app_login_kerberos', methods: ['GET'])]
    public function login(Request $request): Response
    {
        if (!$this->kerberos->isEnabled()) {
            throw $this->createNotFoundException('Вход через Windows (Kerberos) не настроен.');
        }
        if (null !== $this->getUser()) {
            return $this->redirectToRoute('app_home');
        }
        $identity = $this->kerberos->identity($request);
        if (null === $identity['username']) {
            $this->auditLogger->notice('Kerberos: вход через Windows не выполнен', ['reason' => $identity['error'], 'principal' => mb_substr($identity['raw'], 0, 128), 'ip' => $request->getClientIp()]);

            return $this->fail($request, (string) $identity['error']);
        }
        try {
            $user = $this->kerberos->user($identity['username'], $request->getClientIp());
        } catch (\DomainException $e) {
            return $this->fail($request, $e->getMessage());
        }
        // Программный вход: события входа (журнал, время входа, второй фактор) — как при входе по паролю.
        $this->security->login($user, PortalAuthenticator::class, 'main');
        $this->auditLogger->info('Вход через Windows (Kerberos)', ['user' => $user->getUsername(), 'principal' => mb_substr($identity['raw'], 0, 128), 'ip' => $request->getClientIp()]);
        $target = $request->hasSession() ? $this->getTargetPath($request->getSession(), 'main') : null;
        if (null !== $target && $request->hasSession()) {
            $this->removeTargetPath($request->getSession(), 'main');
        }

        return $this->redirect(\is_string($target) && '' !== $target ? $target : $this->generateUrl('app_home'));
    }

    private function fail(Request $request, string $message): RedirectResponse
    {
        $this->addFlash('warning', 'Не удалось войти через Windows: '.$message.' Войдите по логину и паролю.');
        $response = $this->redirectToRoute('app_login', ['kerberos' => 'failed']);
        $response->headers->setCookie(self::skipCookie($request));

        return $response;
    }

    /** Cookie «не пробовать вход через Windows автоматически» (после неудачи и после выхода). */
    public static function skipCookie(Request $request): Cookie
    {
        return Cookie::create(KerberosLogin::SKIP_COOKIE, '1', time() + KerberosLogin::SKIP_SECONDS, '/', null, $request->isSecure(), true, false, Cookie::SAMESITE_LAX);
    }
}
