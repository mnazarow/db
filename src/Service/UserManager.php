<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Security\Ldap\LdapUserInfo;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Операции над пользователями с проверкой бизнес-правил
 * (нельзя удалить/заблокировать последнего администратора и самого себя; синхронизация с LDAP).
 */
final class UserManager
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserRepository $users,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly LoggerInterface $auditLogger,
        private readonly int $passwordMinLength,
    ) {
    }

    public function getPasswordMinLength(): int
    {
        return $this->passwordMinLength;
    }

    /**
     * @throws \InvalidArgumentException если пароль не соответствует требованиям
     */
    public function validatePassword(string $plainPassword): void
    {
        if (mb_strlen($plainPassword) < $this->passwordMinLength) {
            throw new \InvalidArgumentException(\sprintf('Пароль должен содержать не менее %d символов.', $this->passwordMinLength));
        }
        if (mb_strlen($plainPassword) > 4096) {
            throw new \InvalidArgumentException('Пароль слишком длинный.');
        }
        if (preg_match('/^\s|\s$/u', $plainPassword)) {
            throw new \InvalidArgumentException('Пароль не должен начинаться или заканчиваться пробелом.');
        }
    }

    public function setPassword(User $user, string $plainPassword, bool $mustChange = false): void
    {
        $this->validatePassword($plainPassword);
        $user->setPassword($this->hasher->hashPassword($user, $plainPassword));
        $user->setMustChangePassword($mustChange);
    }

    public function isPasswordValid(User $user, string $plainPassword): bool
    {
        return '' !== $user->getPassword() && $this->hasher->isPasswordValid($user, $plainPassword);
    }

    /** Создаёт локального пользователя. */
    public function create(string $username, string $displayName, string $plainPassword, bool $admin, ?string $email = null, bool $mustChange = false, ?User $actor = null): User
    {
        $user = (new User())
            ->setUsername($username)
            ->setDisplayName('' !== trim($displayName) ? $displayName : $username)
            ->setEmail($email)
            ->setAdmin($admin)
            ->setAuthSource(User::SOURCE_LOCAL)
            ->setActive(true);

        $this->setPassword($user, $plainPassword, $mustChange);

        $this->em->persist($user);
        $this->em->flush();

        $this->auditLogger->info('Создан пользователь', ['user' => $user->getUsername(), 'admin' => $admin, 'by' => $actor?->getUsername()]);

        return $user;
    }

    /** Создаёт доменную учётную запись заранее (до первого входа), чтобы назначить её модератором. */
    public function createLdapUser(string $username, string $displayName, bool $admin = false, ?string $email = null, ?User $actor = null): User
    {
        $user = (new User())
            ->setUsername($username)
            ->setDisplayName('' !== trim($displayName) ? $displayName : $username)
            ->setEmail($email)
            ->setAdmin($admin)
            ->setAuthSource(User::SOURCE_LDAP)
            ->setPassword('')
            ->setActive(true);

        $this->em->persist($user);
        $this->em->flush();

        $this->auditLogger->info('Создана доменная учётная запись', ['user' => $user->getUsername(), 'by' => $actor?->getUsername()]);

        return $user;
    }

    /**
     * Создаёт или обновляет пользователя по данным из каталога после успешной проверки пароля.
     */
    public function upsertFromLdap(LdapUserInfo $info, ?User $existing): User
    {
        $user = $existing ?? (new User())->setUsername($info->username)->setActive(true);
        $created = null === $existing;
        $user->setAuthSource(User::SOURCE_LDAP)
            ->setPassword('')
            ->setMustChangePassword(false)
            ->setDisplayName($info->displayName)
            ->setLdapDn($info->dn)
            ->setDepartment($info->department);
        if (null !== $info->email) {
            $user->setEmail($info->email);
        }
        // Права администратора синхронизируются с группой AD, только если группа задана в настройках:
        // вошёл в группу — права выдаются, исключён из группы — снимаются при следующем входе.
        if ($info->adminGroupConfigured) {
            $this->syncAdminFromDirectory($user, $info->isAdmin, 'AD');
        }
        // Роли «Маркетолог» и «Помощник маркетолога» — по группам AD, если они заданы.
        if (null !== $info->position) {
            $this->syncPositionFromDirectory($user, $info->position, 'AD');
        }
        if ($created) {
            $this->em->persist($user);
        }
        $this->em->flush();
        $this->auditLogger->info($created ? 'Создана учётная запись из домена' : 'Обновлена учётная запись из домена', ['user' => $user->getUsername(), 'admin' => $user->isAdmin()]);

        return $user;
    }

    /**
     * Права администратора по данным каталога (группа AD, утверждение SSO). Последнего активного
     * администратора не разжалует — иначе портал остался бы без управления; в журнал пишется предупреждение.
     */
    public function syncAdminFromDirectory(User $user, bool $isAdmin, string $source): void
    {
        if ($user->isAdmin() === $isAdmin) {
            return;
        }
        if (!$isAdmin && null !== $user->getId() && $user->isActive() && $this->users->countActiveAdmins() <= 1) {
            $this->auditLogger->warning('Права администратора не сняты: это последний активный администратор', ['user' => $user->getUsername(), 'source' => $source]);

            return;
        }
        $user->setAdmin($isAdmin);
        $this->auditLogger->info($isAdmin ? 'Выданы права администратора по данным каталога' : 'Сняты права администратора по данным каталога', ['user' => $user->getUsername(), 'source' => $source]);
    }

    /**
     * Роль маркетолога или помощника по данным каталога (группы AD, утверждение SSO). Администратора не
     * трогает: права администратора синхронизируются отдельно (syncAdminFromDirectory()).
     */
    public function syncPositionFromDirectory(User $user, string $position, string $source): void
    {
        if ($user->isAdmin() || !\in_array($position, [User::POSITION_USER, User::POSITION_ASSISTANT, User::POSITION_MARKETER], true) || $user->getPosition() === $position) {
            return;
        }
        $previous = $user->getPosition();
        $user->setPosition($position);
        $this->auditLogger->info('Роль изменена по данным каталога', ['user' => $user->getUsername(), 'from' => $previous, 'to' => $position, 'source' => $source]);
    }

    public function save(User $user, ?User $actor = null): void
    {
        $this->em->persist($user);
        $this->em->flush();

        $this->auditLogger->info('Изменён пользователь', ['user' => $user->getUsername(), 'by' => $actor?->getUsername()]);
    }

    /**
     * @throws \DomainException если удаление нарушает правила
     */
    public function delete(User $user, ?User $actor = null): void
    {
        if (null !== $actor && $actor->getId() === $user->getId()) {
            throw new \DomainException('Нельзя удалить собственную учётную запись.');
        }
        $this->assertNotLastAdmin($user);
        // Записи об ознакомлении «под подпись» и решения по согласованию — юридически значимая история:
        // при удалении учётной записи они удалились бы вместе с ней (каскадом). Такую учётную запись блокируют.
        $history = $this->historyCounts($user);
        if ($history['acknowledgements'] > 0 || $history['approvals'] > 0) {
            throw new \DomainException(\sprintf(
                'У сотрудника есть история, которую нужно сохранить: ознакомлений — %d, согласований — %d. Удаление стёрло бы эти записи. Заблокируйте учётную запись вместо удаления.',
                $history['acknowledgements'],
                $history['approvals'],
            ));
        }

        $username = $user->getUsername();
        $this->em->remove($user);
        $this->em->flush();

        $this->auditLogger->info('Удалён пользователь', ['user' => $username, 'by' => $actor?->getUsername()]);
    }

    /**
     * Сколько записей, которые нельзя терять, связано с сотрудником.
     *
     * @return array{acknowledgements: int, approvals: int}
     */
    public function historyCounts(User $user): array
    {
        if (null === $user->getId()) {
            return ['acknowledgements' => 0, 'approvals' => 0];
        }

        return [
            'acknowledgements' => (int) $this->em->createQuery('SELECT COUNT(a.id) FROM App\Entity\DocumentAcknowledgement a WHERE a.user = :u')->setParameter('u', $user)->getSingleScalarResult(),
            'approvals' => (int) $this->em->createQuery('SELECT COUNT(a.id) FROM App\Entity\DocumentApproval a WHERE a.approver = :u OR a.decidedBy = :u')->setParameter('u', $user)->getSingleScalarResult(),
        ];
    }

    /**
     * @throws \DomainException если изменение оставит систему без администраторов
     */
    public function assertNotLastAdmin(User $user, bool $willBeAdmin = false, bool $willBeActive = false): void
    {
        if (!$user->isAdmin() || !$user->isActive()) {
            return;
        }
        if ($willBeAdmin && $willBeActive) {
            return;
        }
        if ($this->users->countActiveAdmins() <= 1) {
            throw new \DomainException('Это последний активный администратор. Сначала назначьте другого администратора.');
        }
    }
}
