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

    public function findOneByUsername(string $username): ?User
    {
        return $this->findOneBy(['username' => User::normalizeUsername($username)]);
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
