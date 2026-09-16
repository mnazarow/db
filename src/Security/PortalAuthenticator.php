<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Security\Ldap\LdapClient;
use App\Security\Ldap\LdapException;
use App\Service\UserManager;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Http\Authenticator\AbstractLoginFormAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\CsrfTokenBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\RememberMeBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Credentials\CustomCredentials;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\SecurityRequestAttributes;
use Symfony\Component\Security\Http\Util\TargetPathTrait;

/**
 * Единая форма входа для локальных и доменных учётных записей.
 *
 * Порядок проверки:
 *  1. Если в портале есть локальная учётная запись с таким логином — проверяется её пароль.
 *  2. Иначе (учётная запись доменная или ещё не создана) и включён LDAP — пароль проверяется
 *     в Active Directory; при первом успешном входе учётная запись создаётся автоматически,
 *     при последующих — обновляются имя, почта, подразделение и (если задана группа) права администратора.
 */
final class PortalAuthenticator extends AbstractLoginFormAuthenticator
{
    use TargetPathTrait;

    public function __construct(
        private readonly UserRepository $users,
        private readonly UserManager $userManager,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly LdapClient $ldap,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly LoggerInterface $auditLogger,
    ) {
    }

    protected function getLoginUrl(Request $request): string
    {
        return $this->urlGenerator->generate('app_login');
    }

    public function supports(Request $request): bool
    {
        return $request->isMethod('POST') && 'app_login' === $request->attributes->get('_route');
    }

    public function authenticate(Request $request): Passport
    {
        $username = User::normalizeUsername((string) $request->request->get('_username', ''));
        $password = (string) $request->request->get('_password', '');
        $request->getSession()->set(SecurityRequestAttributes::LAST_USERNAME, $username);

        if ('' === $username) {
            throw new BadCredentialsException('Логин не указан.');
        }

        $ldapVerified = false;

        $userBadge = new UserBadge($username, function (string $identifier) use ($password, &$ldapVerified): User {
            $user = $this->users->findOneByUsername($identifier);
            if (null !== $user && $user->isLocal()) {
                return $user;
            }
            if (!$this->ldap->isEnabled()) {
                if (null === $user) {
                    throw new UserNotFoundException(\sprintf('Пользователь «%s» не найден.', $identifier));
                }
                throw new CustomUserMessageAuthenticationException('Вход по доменной учётной записи отключён. Обратитесь к администратору.');
            }
            if (!$this->ldap->isAvailable()) {
                $this->auditLogger->error('LDAP включён, но расширение PHP ldap не установлено.');
                throw new CustomUserMessageAuthenticationException('Вход через домен временно недоступен. Обратитесь к администратору.');
            }
            try {
                $info = $this->ldap->authenticate($identifier, $password);
            } catch (LdapException $e) {
                $this->auditLogger->error('Ошибка LDAP при входе', ['user' => $identifier, 'error' => $e->getMessage()]);
                throw new CustomUserMessageAuthenticationException('Не удалось связаться с контроллером домена. Попробуйте позже или обратитесь к администратору.');
            }
            $ldapVerified = true;

            return $this->userManager->upsertFromLdap($info, $user);
        });

        $credentials = new CustomCredentials(function (string $password, User $user) use (&$ldapVerified): bool {
            if ($user->isLdap()) {
                return $ldapVerified;
            }
            if ('' === $user->getPassword()) {
                return false;
            }

            return $this->hasher->isPasswordValid($user, $password);
        }, $password);

        return new Passport($userBadge, $credentials, [
            new CsrfTokenBadge('authenticate', (string) $request->request->get('_csrf_token', '')),
            new RememberMeBadge(),
        ]);
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        $target = $this->getTargetPath($request->getSession(), $firewallName);
        if (null !== $target && '' !== $target) {
            $this->removeTargetPath($request->getSession(), $firewallName);
            $user = $token->getUser();
            // Страницу панели администратора после входа показываем только администратору.
            $adminPage = str_contains((string) parse_url($target, \PHP_URL_PATH), '/admin');
            if (!$adminPage || ($user instanceof User && $user->isAdmin())) {
                return new RedirectResponse($target);
            }
        }

        return new RedirectResponse($this->urlGenerator->generate('app_home'));
    }
}
