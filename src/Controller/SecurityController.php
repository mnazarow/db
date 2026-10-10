<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\Sso\OidcClient;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;
use Symfony\Component\Security\Http\Util\TargetPathTrait;

final class SecurityController extends AbstractController
{
    use TargetPathTrait;

    public function __construct(private readonly OidcClient $oidc)
    {
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

        return $this->render('security/login.html.twig', [
            'last_username' => $authenticationUtils->getLastUsername(),
            'error' => $authenticationUtils->getLastAuthenticationError(),
            'sso_enabled' => $this->oidc->isEnabled(),
            'sso_button' => $this->oidc->buttonLabel(),
        ]);
    }

    #[Route('/logout', name: 'app_logout', methods: ['POST'])]
    public function logout(): never
    {
        throw new \LogicException('Выход обрабатывается компонентом безопасности.');
    }
}
