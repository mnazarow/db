<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\Sso\OidcClient;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

final class SecurityController extends AbstractController
{
    public function __construct(private readonly OidcClient $oidc)
    {
    }


    #[Route('/login', name: 'app_login', methods: ['GET', 'POST'])]
    public function login(AuthenticationUtils $authenticationUtils, #[CurrentUser] ?object $user): Response
    {
        if (null !== $user) {
            return $this->redirectToRoute('app_home');
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
