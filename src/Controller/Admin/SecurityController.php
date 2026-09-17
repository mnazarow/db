<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\PortalSettings;
use App\Service\Sso\OidcClient;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Вход и безопасность: SSO по OpenID Connect и политика двухфакторной аутентификации.
 */
#[Route('/admin/security')]
#[IsGranted('ROLE_ADMIN')]
final class SecurityController extends AbstractController
{
    public function __construct(
        private readonly PortalSettings $settings,
        private readonly OidcClient $oidc,
        private readonly UserRepository $users,
    ) {
    }

    #[Route('', name: 'admin_security', methods: ['GET'])]
    public function index(): Response
    {
        $sso = $this->settings->sso();
        $sso['secret_masked'] = PortalSettings::mask($sso['client_secret']);
        $sso['secret_set'] = '' !== $sso['client_secret'];
        unset($sso['client_secret']);

        return $this->render('admin/security/index.html.twig', [
            'sso' => $sso,
            'redirect_uri' => $this->generateUrl('app_login_sso_callback', [], UrlGeneratorInterface::ABSOLUTE_URL),
            'require_2fa_admins' => $this->settings->requireAdminTwoFactor(),
            'two_factor' => $this->users->twoFactorSummary(),
        ]);
    }

    #[Route('/sso', name: 'admin_security_sso', methods: ['POST'])]
    public function sso(Request $request, #[CurrentUser] User $user): Response
    {
        $this->checkToken($request);
        $secret = (string) $request->request->get('client_secret');
        $this->settings->setSso([
            'enabled' => $request->request->has('enabled'),
            'issuer' => (string) $request->request->get('issuer'),
            'client_id' => (string) $request->request->get('client_id'),
            'client_secret' => '' !== $secret ? $secret : ($request->request->has('clear_secret') ? '' : null),
            'scopes' => (string) $request->request->get('scopes'),
            'button' => (string) $request->request->get('button'),
            'create_users' => $request->request->has('create_users'),
            'username_claim' => (string) $request->request->get('username_claim'),
            'name_claim' => (string) $request->request->get('name_claim'),
            'email_claim' => (string) $request->request->get('email_claim'),
            'department_claim' => (string) $request->request->get('department_claim'),
            'admin_claim' => (string) $request->request->get('admin_claim'),
            'admin_value' => (string) $request->request->get('admin_value'),
        ], $user);
        $this->addFlash('success', 'Настройки входа через SSO сохранены.');

        return $this->redirectToRoute('admin_security');
    }

    #[Route('/sso-test', name: 'admin_security_sso_test', methods: ['POST'])]
    public function ssoTest(Request $request): Response
    {
        $this->checkToken($request);
        $result = $this->oidc->test();
        $this->addFlash($result['ok'] ? 'success' : 'danger', 'SSO: '.$result['message']);

        return $this->redirectToRoute('admin_security');
    }

    #[Route('/two-factor', name: 'admin_security_two_factor', methods: ['POST'])]
    public function twoFactor(Request $request, #[CurrentUser] User $user): Response
    {
        $this->checkToken($request);
        $required = $request->request->has('require_2fa_admins');
        $this->settings->setRequireAdminTwoFactor($required, $user);
        $this->addFlash('success', $required
            ? 'Двухфакторная аутентификация для администраторов обязательна. Тем, у кого она не включена, портал предложит настроить её при следующем входе.'
            : 'Обязательная двухфакторная аутентификация для администраторов выключена.');

        return $this->redirectToRoute('admin_security');
    }

    private function checkToken(Request $request): void
    {
        if (!$this->isCsrfTokenValid('security', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Неверный CSRF-токен.');
        }
    }
}
