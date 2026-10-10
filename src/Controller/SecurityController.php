<?php

declare(strict_types=1);

namespace App\Controller;

use App\Security\KerberosLogin;
use App\Service\Sso\OidcClient;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;
use Symfony\Component\Security\Http\Util\TargetPathTrait;

final class SecurityController extends AbstractController
{
    use TargetPathTrait;

    public function __construct(
        private readonly OidcClient $oidc,
        private readonly ?KerberosLogin $kerberos = null,
    ) {
    }


    #[Route('/login', name: 'app_login', methods: ['GET', 'POST'])]
    public function login(Request $request, AuthenticationUtils $authenticationUtils, #[CurrentUser] ?object $user): Response
    {
        // ?next=/video/watch/… — куда вернуться после входа (раздел «Видео» отправляет сюда со своей страницы).
        // Принимаются только пути этого сайта: «//host», «\\host» и адреса со схемой отбрасываются.
        $next = (string) $request->query->get('next', '');
        $safeNext = '' !== $next && str_starts_with($next, '/') && !str_starts_with($next, '//') && !str_contains($next, '\\') && !preg_match('/[\x00-\x1F]/', $next) ? $next : null;
        if (null !== $user) {
            return null !== $safeNext ? $this->redirect($safeNext) : $this->redirectToRoute('app_home');
        }
        if (null !== $safeNext && $request->hasSession()) {
            $this->saveTargetPath($request->getSession(), 'main', $safeNext);
        }
        $error = $authenticationUtils->getLastAuthenticationError();
        $kerberos = null !== $this->kerberos && $this->kerberos->isEnabled();
        // Вход через Windows без нажатия кнопки: только при открытии страницы, без ошибки входа по паролю,
        // не сразу после выхода или неудачной попытки (cookie) и не по ссылке «войти по паролю» (?kerberos=off).
        if ($kerberos && $this->kerberos->isAuto() && $request->isMethod('GET') && null === $error
            && !$request->cookies->has(KerberosLogin::SKIP_COOKIE) && !$request->query->has('kerberos')) {
            return $this->redirectToRoute('app_login_kerberos');
        }

        return $this->render('security/login.html.twig', [
            'last_username' => $authenticationUtils->getLastUsername(),
            'error' => $error,
            'sso_enabled' => $this->oidc->isEnabled(),
            'kerberos_enabled' => $kerberos,
            // Браузер не прошёл проверку Kerberos на веб-сервере (страница kerberos-401.html) — портал причину не знает.
            'kerberos_failed' => $kerberos && 'failed' === $request->query->get('kerberos')
                && !($request->hasSession() && $request->getSession() instanceof FlashBagAwareSessionInterface && [] !== $request->getSession()->getFlashBag()->peek('warning')),
            'sso_button' => $this->oidc->buttonLabel(),
        ]);
    }

    #[Route('/logout', name: 'app_logout', methods: ['POST'])]
    public function logout(): never
    {
        throw new \LogicException('Выход обрабатывается компонентом безопасности.');
    }
}
