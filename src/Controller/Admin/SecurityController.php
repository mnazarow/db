<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\Pdf\PdfWatermarker;
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
        private readonly PdfWatermarker $watermarker,
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
            'kerberos' => $this->settings->kerberos(),
            'kerberos_url' => $this->generateUrl('app_login_kerberos', [], UrlGeneratorInterface::ABSOLUTE_URL),
            'watermark' => $this->settings->watermark(),
            'watermark_modes' => PdfWatermarker::MODES,
            'watermark_available' => $this->watermarker->isAvailable(),
            'watermark_sample' => $this->watermarker->texts($this->getUser() instanceof User ? $this->getUser() : null, '10.0.0.15', null, 'Инструкция по охране труда'),
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
            'marketer_value' => (string) $request->request->get('marketer_value'),
            'assistant_value' => (string) $request->request->get('assistant_value'),
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

    #[Route('/kerberos', name: 'admin_security_kerberos', methods: ['POST'])]
    public function kerberos(Request $request, #[CurrentUser] User $user): Response
    {
        $this->checkToken($request);
        try {
            $this->settings->setKerberos([
                'enabled' => $request->request->has('enabled'),
                'source' => (string) $request->request->get('source'),
                'header' => (string) $request->request->get('header'),
                'trusted_proxies' => (string) $request->request->get('trusted_proxies'),
                'realms' => (string) $request->request->get('realms'),
                'auto' => $request->request->has('auto'),
                'create_users' => $request->request->has('create_users'),
                'allow_local' => $request->request->has('allow_local'),
            ], $user);
            $this->addFlash('success', 'Настройки входа через Windows (Kerberos) сохранены.');
        } catch (\InvalidArgumentException $e) {
            $this->addFlash('danger', $e->getMessage());
        }

        return $this->redirectToRoute('admin_security', ['_fragment' => 'kerberos']);
    }

    #[Route('/watermark', name: 'admin_security_watermark', methods: ['POST'])]
    public function watermark(Request $request, #[CurrentUser] User $user): Response
    {
        $this->checkToken($request);
        $mode = (string) $request->request->get('mode', PdfWatermarker::MODE_OFF);
        if (!\array_key_exists($mode, PdfWatermarker::MODES)) {
            $mode = PdfWatermarker::MODE_OFF;
        }
        $this->settings->setWatermark($mode, (string) $request->request->get('text', ''), $request->request->has('skip_editors'), $user);
        if (PdfWatermarker::MODE_OFF === $mode) {
            $this->addFlash('success', 'Водяной знак на PDF выключен.');
        } elseif ($this->watermarker->isAvailable()) {
            $this->addFlash('success', 'Водяной знак включён: «'.PdfWatermarker::MODES[$mode].'».');
        } else {
            $this->addFlash('warning', 'Настройка сохранена, но на сервере нет программы qpdf: пока её не установят (пакет qpdf), PDF отдаются без знака.');
        }

        return $this->redirectToRoute('admin_security');
    }

    private function checkToken(Request $request): void
    {
        if (!$this->isCsrfTokenValid('security', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Неверный CSRF-токен.');
        }
    }
}
