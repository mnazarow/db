<?php

declare(strict_types=1);

namespace App\Service\Sso;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\UserManager;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Учётная запись портала по данным внешнего провайдера входа (OpenID Connect или SAML): находит её по
 * постоянному идентификатору у провайдера (или по логину — при первом входе), создаёт новую (если разрешено),
 * обновляет имя, почту, подразделение и — если настроено — роль из групп провайдера.
 */
final class SsoUserProvisioner
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly EntityManagerInterface $em,
        private readonly UserManager $userManager,
        private readonly LoggerInterface $auditLogger,
    ) {
    }

    /**
     * @param string                                                                                                  $subject  постоянный идентификатор: «провайдер|идентификатор пользователя»
     * @param array{username: string, name: ?string, email: ?string, department: ?string, roles: list<string>|null} $identity roles = null — роли провайдером не назначаются
     * @param array{create_users: bool, admin_value: string, marketer_value: string, assistant_value: string, domains?: string} $policy domains — допустимые домены логинов через запятую
     * @param string                                                                                                  $label    «SSO» или «SAML» — для сообщений и журнала
     *
     * @throws SsoException
     */
    public function provision(string $subject, array $identity, array $policy, string $label): User
    {
        // Логин из UPN вида user@domain или DOMAIN\user приводим к короткому виду — так его заводят в портале.
        $full = mb_strtolower(trim($identity['username']));
        $domain = null;
        $username = $full;
        if (str_contains($username, '\\')) {
            $domain = substr($username, 0, (int) strrpos($username, '\\'));
            $username = substr($username, (int) strrpos($username, '\\') + 1);
        }
        if (str_contains($username, '@')) {
            [$username, $domain] = explode('@', $username, 2);
        }
        $username = trim($username);
        if ('' === $username) {
            throw new SsoException('Провайдер не передал логин пользователя.');
        }
        $domains = array_values(array_filter(array_map('trim', explode(',', (string) ($policy['domains'] ?? '')))));
        // Учётные записи чужих доменов (гости, партнёры у того же провайдера) в портал не пускаются.
        if (null !== $domain && [] !== $domains && !\in_array($domain, $domains, true)) {
            throw new SsoException('Учётные записи домена «'.$domain.'» не допускаются в портал. Обратитесь к администратору портала.');
        }
        $existing = $this->users->findOneBy(['ssoSubject' => $subject]);
        if (null === $existing) {
            $byName = $this->users->findOneByUsername($username);
            if (null !== $byName) {
                if (null !== $byName->getSsoSubject()) {
                    throw new SsoException('Логин «'.$username.'» уже связан с другой учётной записью провайдера. Обратитесь к администратору портала.');
                }
                if ($byName->isLocal()) {
                    // Локальную учётную запись (например, администратора портала) нельзя «перехватить» через внешний вход.
                    throw new SsoException('Учётная запись «'.$username.'» — локальная учётная запись портала, вход в неё через '.$label.' не разрешён. Войдите по паролю или обратитесь к администратору.');
                }
                // Существующая учётная запись (например, из каталога LDAP) привязывается к провайдеру по короткому
                // логину, только если домен подтверждён: логин без домена, домен из списка допустимых или совпала
                // почта. Иначе kuzmin@partner.example занял бы учётную запись сотрудника kuzmin.
                $email = mb_strtolower(trim((string) $identity['email']));
                $confirmed = null === $domain
                    || ([] !== $domains && \in_array($domain, $domains, true))
                    || ('' !== $email && '' !== (string) $byName->getEmail() && mb_strtolower((string) $byName->getEmail()) === $email);
                if (!$confirmed) {
                    throw new SsoException('Учётная запись «'.$username.'» уже есть в портале, а провайдер прислал логин другого вида ('.$full.'). Чтобы связать их, администратору нужно указать допустимые домены в настройках '.$label.'.');
                }
                $existing = $byName;
            }
        }
        if (null === $existing && !$policy['create_users']) {
            throw new SsoException('Пользователь «'.$username.'» не заведён в портале, а автоматическое создание выключено.');
        }
        $user = $existing ?? (new User())->setUsername($username)->setActive(true);
        $user->setAuthSource(User::SOURCE_SSO)->setPassword('')->setMustChangePassword(false)->setSsoSubject($subject);
        $name = trim((string) $identity['name']);
        $user->setDisplayName('' !== $name ? $name : $username);
        $email = trim((string) $identity['email']);
        if ('' !== $email) {
            $user->setEmail($email);
        }
        $department = trim((string) $identity['department']);
        if ('' !== $department) {
            $user->setDepartment($department);
        }
        if (null !== $identity['roles']) {
            $values = array_map('strval', $identity['roles']);
            // Права администратора синхронизируются, только если задано значение для них.
            if ('' !== $policy['admin_value']) {
                $this->userManager->syncAdminFromDirectory($user, \in_array($policy['admin_value'], $values, true), $label);
            }
            // Роли «Маркетолог» и «Помощник маркетолога» — по тем же группам, если заданы их значения.
            if ('' !== $policy['marketer_value'] || '' !== $policy['assistant_value']) {
                $position = match (true) {
                    '' !== $policy['marketer_value'] && \in_array($policy['marketer_value'], $values, true) => User::POSITION_MARKETER,
                    '' !== $policy['assistant_value'] && \in_array($policy['assistant_value'], $values, true) => User::POSITION_ASSISTANT,
                    default => User::POSITION_USER,
                };
                $this->userManager->syncPositionFromDirectory($user, $position, $label);
            }
        }
        if (null === $existing) {
            $this->em->persist($user);
        }
        $this->em->flush();
        $this->auditLogger->info(null === $existing ? $label.': создана учётная запись' : $label.': вход', ['user' => $user->getUsername()]);

        return $user;
    }
}
