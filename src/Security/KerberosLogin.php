<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Security\Ldap\LdapClient;
use App\Service\PortalSettings;
use App\Service\UserManager;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Request;

/**
 * Вход через Kerberos (Windows, без пароля). Портал сам Kerberos не проверяет — это делает веб-сервер:
 *  - nginx с модулем SPNEGO на адресе /login/kerberos (docportal kerberos on) передаёт проверенную учётную
 *    запись в переменной REMOTE_USER — подделать её из браузера нельзя;
 *  - или прокси перед порталом (Apache mod_auth_gssapi, IIS, балансировщик) передаёт её заголовком —
 *    заголовок принимается только с адресов доверенных прокси.
 * Учётная запись «ivanov@CORP.LOCAL» или «CORP\ivanov» становится логином «ivanov».
 */
final class KerberosLogin
{
    /** Cookie «не пробовать вход через Windows автоматически» — после выхода и неудачной попытки. */
    public const SKIP_COOKIE = 'dp_krb_skip';
    public const SKIP_SECONDS = 900;

    public function __construct(
        private readonly PortalSettings $settings,
        private readonly UserRepository $users,
        private readonly UserManager $userManager,
        private readonly LdapClient $ldap,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $auditLogger,
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->settings->kerberos()['enabled'];
    }

    /** Пробовать ли вход через Windows сразу при открытии страницы входа. */
    public function isAuto(): bool
    {
        $settings = $this->settings->kerberos();

        return $settings['enabled'] && $settings['auto'];
    }

    /**
     * Проверенная веб-сервером учётная запись из запроса.
     *
     * @return array{username: ?string, raw: string, error: ?string}
     */
    public function identity(Request $request): array
    {
        $settings = $this->settings->kerberos();
        if ('header' === $settings['source']) {
            $remote = (string) $request->server->get('REMOTE_ADDR', '');
            $proxies = array_values(array_filter(preg_split('/[\s,;]+/', $settings['trusted_proxies']) ?: []));
            $raw = trim((string) $request->headers->get($settings['header'], ''));
            if ([] === $proxies || '' === $remote || !IpUtils::checkIp($remote, $proxies)) {
                if ('' !== $raw) {
                    $this->auditLogger->warning('Kerberos: заголовок с учётной записью пришёл не от доверенного прокси — отклонён', ['ip' => $remote, 'header' => $settings['header']]);
                }

                return ['username' => null, 'raw' => '', 'error' => 'Запрос пришёл не через доверенный прокси, который проверяет учётную запись Windows.'];
            }
        } else {
            $raw = trim((string) ($request->server->get('REMOTE_USER') ?? $request->server->get('REDIRECT_REMOTE_USER') ?? ''));
        }
        if ('' === $raw) {
            return ['username' => null, 'raw' => '', 'error' => 'Браузер не передал учётную запись Windows: компьютер не в домене, вход выполнен не доменной учётной записью или адрес портала не добавлен в зону «Местная интрасеть».'];
        }
        [$username, $realm] = self::split($raw);
        $allowed = array_values(array_filter(array_map(static fn (string $r): string => strtoupper(trim($r)), preg_split('/[\s,;]+/', $settings['realms']) ?: [])));
        if (null !== $realm && [] !== $allowed && !\in_array(strtoupper($realm), $allowed, true)) {
            $this->auditLogger->warning('Kerberos: учётная запись из недопустимой области', ['principal' => mb_substr($raw, 0, 128), 'ip' => $request->getClientIp()]);

            return ['username' => null, 'raw' => $raw, 'error' => \sprintf('Учётные записи домена %s в портал не допускаются.', $realm)];
        }
        if ('' === $username || !preg_match('/^[\p{L}\p{N}._$-]{1,64}$/u', $username)) {
            return ['username' => null, 'raw' => $raw, 'error' => 'Некорректное имя учётной записи Windows.'];
        }

        return ['username' => $username, 'raw' => $raw, 'error' => null];
    }

    /**
     * «ivanov@CORP.LOCAL» → [ivanov, CORP.LOCAL]; «CORP\ivanov» → [ivanov, CORP]; «ivanov» → [ivanov, null].
     *
     * @return array{0: string, 1: ?string}
     */
    public static function split(string $principal): array
    {
        $principal = trim($principal);
        $realm = null;
        if (false !== ($at = strrpos($principal, '@'))) {
            $realm = substr($principal, $at + 1);
            $principal = substr($principal, 0, $at);
        } elseif (false !== ($slash = strpos($principal, '\\'))) {
            $realm = substr($principal, 0, $slash);
            $principal = substr($principal, $slash + 1);
        }

        return [User::normalizeUsername($principal), null !== $realm && '' !== $realm ? $realm : null];
    }

    /**
     * Учётная запись портала для проверенного логина: существующая или новая (из каталога LDAP, если он настроен).
     *
     * @throws \DomainException войти нельзя — текст для пользователя
     */
    public function user(string $username, ?string $ip = null): User
    {
        $settings = $this->settings->kerberos();
        $user = $this->users->findOneByUsername($username);
        if (null !== $user) {
            if (!$user->isActive()) {
                throw new \DomainException('Учётная запись заблокирована. Обратитесь к администратору портала.');
            }
            if ($user->isLocal() && !$settings['allow_local']) {
                $this->auditLogger->warning('Kerberos: вход в локальную учётную запись не разрешён', ['user' => $username, 'ip' => $ip]);

                throw new \DomainException(\sprintf('Учётная запись «%s» в портале — локальная (с паролем портала), вход через Windows для неё не разрешён. Войдите по паролю или попросите администратора сделать учётную запись доменной.', $username));
            }

            return $user;
        }
        if (!$settings['create_users']) {
            throw new \DomainException(\sprintf('Учётной записи «%s» в портале нет. Обратитесь к администратору портала.', $username));
        }
        if ($this->ldap->isEnabled()) {
            try {
                $info = $this->ldap->findUser($username);
            } catch (\Throwable $e) {
                $this->auditLogger->warning('Kerberos: каталог LDAP недоступен при создании учётной записи', ['user' => $username, 'error' => mb_substr($e->getMessage(), 0, 300)]);

                throw new \DomainException('Каталог пользователей (Active Directory) сейчас недоступен — попробуйте позже или войдите по паролю.');
            }
            if (null === $info) {
                throw new \DomainException(\sprintf('Учётная запись «%s» не найдена в каталоге (или не входит в разрешённую группу). Обратитесь к администратору портала.', $username));
            }
            $user = $this->userManager->upsertFromLdap($info, null);
        } else {
            $user = (new User())->setUsername($username)->setDisplayName($username)->setAuthSource(User::SOURCE_LDAP)->setPassword('')->setActive(true);
            $this->em->persist($user);
            $this->em->flush();
        }
        $this->auditLogger->info('Kerberos: создана учётная запись при первом входе через Windows', ['user' => $user->getUsername(), 'ip' => $ip]);

        return $user;
    }
}
