<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Security\PortalAuthenticator;
use App\Service\PortalSettings;
use App\Service\Sso\OidcClient;
use App\Service\Sso\SsoException;
use Doctrine\ORM\EntityManagerInterface;
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
        private readonly UserRepository $users,
        private readonly EntityManagerInterface $em,
        private readonly Security $security,
        private readonly LoggerInterface $auditLogger,
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
        // Логин из UPN вида user@domain приводим к короткому виду — так его заводят в портале.
        $username = mb_strtolower(explode('@', $username)[0]);
        $existing = $this->users->findOneByUsername($username);
        if (null === $existing && !$sso['create_users']) {
            throw new SsoException('Пользователь «'.$username.'» не заведён в портале, а автоматическое создание выключено.');
        }
        $user = $existing ?? (new User())->setUsername($username)->setActive(true);
        $user->setAuthSource(User::SOURCE_SSO)->setPassword('')->setMustChangePassword(false);
        $name = trim((string) ($claims[$sso['name_claim']] ?? ''));
        $user->setDisplayName('' !== $name ? $name : $username);
        $email = trim((string) ($claims[$sso['email_claim']] ?? ''));
        if ('' !== $email) {
            $user->setEmail($email);
        }
        if ('' !== $sso['department_claim']) {
            $department = trim((string) ($claims[$sso['department_claim']] ?? ''));
            if ('' !== $department) {
                $user->setDepartment($department);
            }
        }
        // Права администратора синхронизируются, только если задано утверждение и его значение.
        if ('' !== $sso['admin_claim'] && '' !== $sso['admin_value']) {
            $value = $claims[$sso['admin_claim']] ?? null;
            $values = array_map('strval', \is_array($value) ? $value : [$value]);
            $user->setAdmin(\in_array($sso['admin_value'], $values, true));
        }
        if (null === $existing) {
            $this->em->persist($user);
        }
        $this->em->flush();
        $this->auditLogger->info(null === $existing ? 'SSO: создана учётная запись' : 'SSO: вход', ['user' => $user->getUsername()]);

        return $user;
    }

    private function redirectUri(): string
    {
        return $this->generateUrl('app_login_sso_callback', [], UrlGeneratorInterface::ABSOLUTE_URL);
    }
}
