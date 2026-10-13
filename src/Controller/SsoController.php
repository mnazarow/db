<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Security\PortalAuthenticator;
use App\Service\PortalSettings;
use App\Service\Sso\OidcClient;
use App\Service\Sso\SsoException;
use App\Service\Sso\SsoUserProvisioner;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Вход через внешнего провайдера (OpenID Connect): переход к провайдеру и обработка ответа.
 */
final class SsoController extends AbstractController
{
    private const SESSION_STATE = 'sso_state';
    private const SESSION_NONCE = 'sso_nonce';
    private const SESSION_VERIFIER = 'sso_verifier';

    public function __construct(
        private readonly OidcClient $oidc,
        private readonly PortalSettings $settings,
        private readonly Security $security,
        private readonly LoggerInterface $auditLogger,
        private readonly SsoUserProvisioner $provisioner,
    ) {
    }

    /** Кнопка «Войти через SSO»: уводим пользователя на страницу входа провайдера. */
    #[Route('/login/sso', name: 'app_login_sso', methods: ['GET'])]
    public function start(Request $request): Response
    {
        if (!$this->oidc->isEnabled()) {
            throw $this->createNotFoundException('Вход через внешнего провайдера не настроен.');
        }
        $session = $request->getSession();
        $state = bin2hex(random_bytes(16));
        $nonce = bin2hex(random_bytes(16));
        $verifier = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
        $session->set(self::SESSION_STATE, $state);
        $session->set(self::SESSION_NONCE, $nonce);
        $session->set(self::SESSION_VERIFIER, $verifier);
        try {
            return $this->redirect($this->oidc->authorizationUrl($this->redirectUri(), $state, $nonce, $verifier));
        } catch (SsoException $e) {
            $this->addFlash('danger', 'Вход через SSO недоступен: '.$e->getMessage());

            return $this->redirectToRoute('app_login');
        }
    }

    /** Ответ провайдера: проверяем состояние, меняем код на токены и входим. */
    #[Route('/login/sso/callback', name: 'app_login_sso_callback', methods: ['GET'])]
    public function callback(Request $request): Response
    {
        if (!$this->oidc->isEnabled()) {
            throw $this->createNotFoundException('Вход через внешнего провайдера не настроен.');
        }
        $session = $request->getSession();
        $state = (string) $session->remove(self::SESSION_STATE);
        $nonce = (string) $session->remove(self::SESSION_NONCE);
        $verifier = (string) $session->remove(self::SESSION_VERIFIER);
        $error = (string) $request->query->get('error');
        if ('' !== $error) {
            $this->auditLogger->warning('SSO: провайдер вернул ошибку', ['error' => $error, 'ip' => $request->getClientIp()]);
            $this->addFlash('danger', 'Провайдер отказал во входе: '.mb_substr($error, 0, 200));

            return $this->redirectToRoute('app_login');
        }
        $code = (string) $request->query->get('code');
        if ('' === $code || '' === $state || !hash_equals($state, (string) $request->query->get('state'))) {
            $this->auditLogger->warning('SSO: не совпало состояние запроса', ['ip' => $request->getClientIp()]);
            $this->addFlash('danger', 'Вход через SSO не удался: попробуйте ещё раз.');

            return $this->redirectToRoute('app_login');
        }
        try {
            $claims = $this->oidc->claims($code, $this->redirectUri(), $verifier, $nonce);
            $user = $this->userFromClaims($claims);
        } catch (SsoException $e) {
            $this->auditLogger->warning('SSO: вход не выполнен', ['error' => $e->getMessage(), 'ip' => $request->getClientIp()]);
            $this->addFlash('danger', 'Вход через SSO не удался: '.$e->getMessage());

            return $this->redirectToRoute('app_login');
        }
        if (!$user->isActive()) {
            $this->addFlash('danger', 'Учётная запись заблокирована. Обратитесь к администратору портала.');

            return $this->redirectToRoute('app_login');
        }
        // Программный вход под учётной записью, полученной от провайдера: события входа
        // (журнал аудита, второй фактор) отрабатывают как при обычном входе.
        $this->security->login($user, PortalAuthenticator::class, 'main');

        return $this->redirectToRoute('app_home');
    }

    /**
     * Находит или создаёт пользователя по утверждениям провайдера.
     *
     * @param array<string, mixed> $claims
     *
     * @throws SsoException
     */
    private function userFromClaims(array $claims): User
    {
        $sso = $this->settings->sso();
        $username = trim((string) ($claims[$sso['username_claim']] ?? ''));
        if ('' === $username) {
            throw new SsoException('Провайдер не передал логин пользователя (утверждение '.$sso['username_claim'].').');
        }
        // Учётная запись привязывается к постоянному идентификатору пользователя у провайдера (iss + sub),
        // а не только к логину: логин из утверждений может совпасть с чужой учётной записью портала.
        $sub = trim((string) ($claims['sub'] ?? ''));
        if ('' === $sub) {
            throw new SsoException('Провайдер не передал идентификатор пользователя (утверждение sub).');
        }
        $subject = rtrim(trim((string) ($claims['iss'] ?? $sso['issuer'])), '/').'|'.$sub;
        $roles = null;
        if ('' !== $sso['admin_claim']) {
            $value = $claims[$sso['admin_claim']] ?? null;
            $roles = array_values(array_map('strval', \is_array($value) ? $value : (null === $value ? [] : [$value])));
        }

        return $this->provisioner->provision($subject, [
            'username' => $username,
            'name' => (string) ($claims[$sso['name_claim']] ?? ''),
            'email' => (string) ($claims[$sso['email_claim']] ?? ''),
            'department' => '' !== $sso['department_claim'] ? (string) ($claims[$sso['department_claim']] ?? '') : null,
            'roles' => $roles,
        ], $sso, 'SSO');
    }

    private function redirectUri(): string
    {
        return $this->generateUrl('app_login_sso_callback', [], UrlGeneratorInterface::ABSOLUTE_URL);
    }
}
