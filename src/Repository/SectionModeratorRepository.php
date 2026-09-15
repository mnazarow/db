<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Section;
use App\Entity\SectionModerator;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SectionModerator>
 */
final class SectionModeratorRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SectionModerator::class);
    }

    /** @return list<int> идентификаторы разделов, где пользователь назначен модератором напрямую */
    public function findSectionIdsForUser(User $user): array
    {
        $rows = $this->createQueryBuilder('m')->select('IDENTITY(m.section) AS sid')
            ->andWhere('m.user = :u')->setParameter('u', $user)
            ->getQuery()->getArrayResult();

        return array_map(static fn (array $r) => (int) $r['sid'], $rows);
    }

    /** @return list<SectionModerator> */
    public function findForUser(User $user): array
    {
        return $this->createQueryBuilder('m')
            ->join('m.section', 's')->addSelect('s')
            ->andWhere('m.user = :u')->setParameter('u', $user)
            ->orderBy('s.path', 'ASC')
            ->getQuery()->getResult();
    }

    /** @return list<SectionModerator> все назначения с пользователями и разделами */
    public function findAllWithRelations(): array
    {
        return $this->createQueryBuilder('m')
            ->join('m.section', 's')->addSelect('s')
            ->join('m.user', 'u')->addSelect('u')
            ->orderBy('s.path', 'ASC')->addOrderBy('u.displayName', 'ASC')
            ->getQuery()->getResult();
    }

    public function findOneBySectionAndUser(Section $section, User $user): ?SectionModerator
    {
        return $this->findOneBy(['section' => $section, 'user' => $user]);
    }

    /**
     * Модераторы, отвечающие за раздел (назначенные на него или на любого из предков).
     *
     * @return list<User>
     */
    public function findResponsibleUsers(Section $section): array
    {
        $ids = $section->getPathIds();
        if ([] === $ids) {
            return [];
        }
        /** @var list<SectionModerator> $rows */
        $rows = $this->createQueryBuilder('m')
            ->join('m.user', 'u')->addSelect('u')
            ->andWhere('m.section IN (:ids)')->setParameter('ids', $ids)
            ->andWhere('u.active = true')
            ->getQuery()->getResult();
        $users = [];
        foreach ($rows as $row) {
            $users[$row->getUser()->getId()] = $row->getUser();
        }

        return array_values($users);
    }

    public function countAll(): int
    {
        return (int) $this->createQueryBuilder('m')->select('COUNT(m.id)')->getQuery()->getSingleScalarResult();
    }
}
