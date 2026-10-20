<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\Pdf\PdfWatermarker;
use App\Service\PortalSettings;
use App\Service\Sso\OidcClient;
use App\Service\PortalUrl;
use App\Service\Sso\SamlService;
use App\Service\Sso\SsoException;
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
        private readonly SamlService $saml,
        private readonly PortalUrl $portalUrl,
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
            'saml' => $this->settings->saml() + [
                'ready' => $this->saml->isEnabled(),
                'sp_entity_id' => $this->saml->spEntityId() ?: '— (не задан адрес портала)',
                'base_ready' => null !== $this->saml->spBaseUrl(),
                'default_base' => $this->portalUrl->baseUri(),
                'acs_url' => $this->saml->acsUrl() ?: '—',
                'sls_url' => $this->saml->slsUrl() ?: '—',
                'certs' => $this->saml->certificateInfo(),
                'sp_cert_info' => $this->saml->hasSpKey() ? $this->saml->spCertificateInfo() : null,
            ],
        ]);
    }

    #[Route('/saml', name: 'admin_security_saml', methods: ['POST'])]
    public function saml(Request $request, #[CurrentUser] User $user): Response
    {
        $this->checkToken($request);
        $cert = (string) $request->request->get('idp_cert');
        if ('' !== trim($cert) && [] === $this->saml->certificates($cert)) {
            $this->addFlash('danger', 'SAML: сертификат провайдера не распознан — вставьте его в формате PEM (BEGIN CERTIFICATE) или base64.');

            return $this->redirectToRoute('admin_security', ['_fragment' => 'saml']);
        }
        $ssoUrl = trim((string) $request->request->get('idp_sso_url'));
        if ('' !== $ssoUrl && !preg_match('~^https://~i', $ssoUrl)) {
            $this->addFlash('danger', 'SAML: адрес входа провайдера должен начинаться с https://.');

            return $this->redirectToRoute('admin_security', ['_fragment' => 'saml']);
        }
        $sloUrl = trim((string) $request->request->get('idp_slo_url'));
        if ('' !== $sloUrl && !preg_match('~^https://~i', $sloUrl)) {
            $this->addFlash('danger', 'SAML: адрес выхода провайдера должен начинаться с https://.');

            return $this->redirectToRoute('admin_security', ['_fragment' => 'saml']);
        }
        $base = rtrim(trim((string) $request->request->get('sp_base_url')), '/');
        if ('' !== $base && !preg_match('~^https?://[^/\s?#]+(/[^\s?#]*)?$~i', $base)) {
            $this->addFlash('danger', 'SAML: адрес портала — вида https://docs.example.ru (без параметров).');

            return $this->redirectToRoute('admin_security', ['_fragment' => 'saml']);
        }
        $this->settings->setSaml([
            'enabled' => $request->request->has('enabled'),
            'idp_entity_id' => (string) $request->request->get('idp_entity_id'),
            'idp_sso_url' => $ssoUrl,
            'idp_cert' => implode("\n", $this->saml->certificates($cert)),
            'button' => (string) $request->request->get('button'),
            'create_users' => $request->request->has('create_users'),
            'username_attr' => (string) $request->request->get('username_attr'),
            'name_attr' => (string) $request->request->get('name_attr'),
            'email_attr' => (string) $request->request->get('email_attr'),
            'department_attr' => (string) $request->request->get('department_attr'),
            'roles_attr' => (string) $request->request->get('roles_attr'),
            'admin_value' => (string) $request->request->get('admin_value'),
            'marketer_value' => (string) $request->request->get('marketer_value'),
            'assistant_value' => (string) $request->request->get('assistant_value'),
            'sp_base_url' => $base,
            'allow_idp_initiated' => $request->request->has('allow_idp_initiated'),
            'domains' => (string) $request->request->get('domains'),
            'idp_slo_url' => $sloUrl,
            'slo' => $request->request->has('slo'),
            'want_encrypted' => $request->request->has('want_encrypted') && $this->saml->hasSpKey(),
        ], $user);
        if ($request->request->has('slo') && '' === $sloUrl) {
            $this->addFlash('warning', 'SAML: единый выход не заработает, пока не задан адрес выхода провайдера.');
        }
        $this->addFlash('success', $this->saml->isEnabled() || !$request->request->has('enabled') ? 'Настройки входа через SAML сохранены.' : 'Настройки сохранены, но вход через SAML не заработает, пока не заданы идентификатор провайдера, адрес входа, сертификат и адрес портала (здесь или в APP_DEFAULT_URI).');

        return $this->redirectToRoute('admin_security', ['_fragment' => 'saml']);
    }

    /** Заполнение настроек SAML по метаданным провайдера (XML или адрес). */
    #[Route('/saml/metadata', name: 'admin_security_saml_metadata', methods: ['POST'])]
    public function samlMetadata(Request $request, #[CurrentUser] User $user): Response
    {
        $this->checkToken($request);
        try {
            $parsed = $this->saml->parseMetadata((string) $request->request->get('metadata'));
        } catch (SsoException $e) {
            $this->addFlash('danger', 'SAML: '.$e->getMessage());

            return $this->redirectToRoute('admin_security', ['_fragment' => 'saml']);
        }
        $this->settings->setSaml(['idp_entity_id' => $parsed['entity_id'], 'idp_sso_url' => $parsed['sso_url'], 'idp_cert' => implode("\n", $parsed['certs'])]
            + ('' !== $parsed['slo_url'] ? ['idp_slo_url' => $parsed['slo_url']] : []) + $this->settings->saml(), $user);
        $this->addFlash('success', \sprintf('SAML: из метаданных взяты провайдер %s, адрес входа и сертификаты (%d). Проверьте остальные настройки и включите вход.', $parsed['entity_id'], \count($parsed['certs'])));

        return $this->redirectToRoute('admin_security', ['_fragment' => 'saml']);
    }

    /** Ключ портала для SAML: создать (заменить) или удалить. */
    #[Route('/saml/key', name: 'admin_security_saml_key', methods: ['POST'])]
    public function samlKey(Request $request, #[CurrentUser] User $user): Response
    {
        $this->checkToken($request);
        if ('delete' === $request->request->get('action')) {
            $this->settings->setSaml(['sp_key' => '', 'sp_cert' => '', 'want_encrypted' => false] + $this->settings->saml(), $user);
            $this->addFlash('success', 'SAML: ключ портала удалён.');

            return $this->redirectToRoute('admin_security', ['_fragment' => 'saml']);
        }
        try {
            $pair = $this->saml->generateSpKey();
        } catch (SsoException $e) {
            $this->addFlash('danger', 'SAML: '.$e->getMessage());

            return $this->redirectToRoute('admin_security', ['_fragment' => 'saml']);
        }
        $this->settings->setSaml(['sp_key' => $pair['key'], 'sp_cert' => $pair['cert']] + $this->settings->saml(), $user);
        $this->addFlash('success', 'SAML: создан ключ портала. Обновите регистрацию портала у провайдера по метаданным портала — в них появился сертификат для шифрования и подписи.');

        return $this->redirectToRoute('admin_security', ['_fragment' => 'saml']);
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
            'domains' => (string) $request->request->get('domains'),
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
