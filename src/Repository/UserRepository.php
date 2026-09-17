<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<User>
 */
final class UserRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    /**
     * Сводка по двухфакторной аутентификации: сколько всего активных, сколько включили, сколько администраторов без неё.
     *
     * @return array{active: int, enabled: int, admins: int, admins_without: int}
     */
    public function twoFactorSummary(): array
    {
        $rows = $this->createQueryBuilder('u')
            ->select('u.roles AS roles', 'u.totpConfirmedAt AS confirmed')
            ->andWhere('u.active = true')
            ->getQuery()->getArrayResult();
        $summary = ['active' => 0, 'enabled' => 0, 'admins' => 0, 'admins_without' => 0];
        foreach ($rows as $row) {
            ++$summary['active'];
            $enabled = null !== $row['confirmed'];
            $admin = \in_array('ROLE_ADMIN', (array) $row['roles'], true);
            $summary['enabled'] += $enabled ? 1 : 0;
            $summary['admins'] += $admin ? 1 : 0;
            $summary['admins_without'] += $admin && !$enabled ? 1 : 0;
        }

        return $summary;
    }

    public function findOneByUsername(string $username): ?User
    {
        return $this->findOneBy(['username' => User::normalizeUsername($username)]);
    }

    /** Ищет сотрудника по коду привязки Telegram (код одноразовый, регистр не важен). */
    public function findOneByTelegramCode(string $code): ?User
    {
        $code = strtoupper(trim($code));

        return '' === $code ? null : $this->findOneBy(['telegramCode' => $code]);
    }

    /** @return list<User> активные сотрудники с привязанным Telegram */
    public function findWithTelegram(): array
    {
        return $this->createQueryBuilder('u')
            ->andWhere('u.active = true')->andWhere('u.telegramChatId IS NOT NULL')
            ->orderBy('u.displayName', 'ASC')
            ->getQuery()->getResult();
    }

    /** @return list<User> */
    public function findAllOrdered(): array
    {
        return $this->createQueryBuilder('u')->orderBy('u.displayName', 'ASC')->getQuery()->getResult();
    }

    /** @return list<User> */
    public function findActiveOrdered(): array
    {
        return $this->createQueryBuilder('u')->andWhere('u.active = true')->orderBy('u.displayName', 'ASC')->getQuery()->getResult();
    }

    public function countActiveAdmins(): int
    {
        $count = 0;
        /** @var User $user */
        foreach ($this->findBy(['active' => true]) as $user) {
            if ($user->isAdmin()) {
                ++$count;
            }
        }

        return $count;
    }

    /** @return list<User> активные администраторы (для уведомлений) */
    public function findActiveAdmins(): array
    {
        return array_values(array_filter($this->findBy(['active' => true]), static fn (User $u) => $u->isAdmin()));
    }

    public function countAll(): int
    {
        return (int) $this->createQueryBuilder('u')->select('COUNT(u.id)')->getQuery()->getSingleScalarResult();
    }

    /** @return array<string, int> число пользователей по источнику учётной записи */
    public function countBySource(): array
    {
        $rows = $this->createQueryBuilder('u')->select('u.authSource AS src, COUNT(u.id) AS cnt')->groupBy('u.authSource')->getQuery()->getArrayResult();
        $out = [User::SOURCE_LOCAL => 0, User::SOURCE_LDAP => 0];
        foreach ($rows as $row) {
            $out[$row['src']] = (int) $row['cnt'];
        }

        return $out;
    }
}
