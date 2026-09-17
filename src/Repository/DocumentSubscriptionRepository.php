<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Document;
use App\Entity\DocumentSubscription;
use App\Entity\Section;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DocumentSubscription>
 */
final class DocumentSubscriptionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DocumentSubscription::class);
    }

    public function findForDocument(User $user, Document $document): ?DocumentSubscription
    {
        return $this->findOneBy(['user' => $user, 'document' => $document]);
    }

    public function findForSection(User $user, Section $section): ?DocumentSubscription
    {
        return $this->findOneBy(['user' => $user, 'section' => $section]);
    }

    /**
     * Все подписки сотрудника: сначала разделы, потом документы.
     *
     * @return list<DocumentSubscription>
     */
    public function findForUser(User $user): array
    {
        /** @var list<DocumentSubscription> $all */
        $all = $this->createQueryBuilder('s')
            ->andWhere('s.user = :user')->setParameter('user', $user)
            ->leftJoin('s.document', 'd')->addSelect('d')
            ->leftJoin('s.section', 'sec')->addSelect('sec')
            ->orderBy('s.createdAt', 'DESC')
            ->getQuery()->getResult();

        usort($all, static fn (DocumentSubscription $a, DocumentSubscription $b): int => [$b->isForSection(), $a->getTitle()] <=> [$a->isForSection(), $b->getTitle()]);

        return $all;
    }

    /**
     * Подписчики документа: подписавшиеся на сам документ и на любой из разделов над ним.
     *
     * @param list<int> $sectionIds раздел документа и все его предки
     *
     * @return list<User>
     */
    public function subscribers(Document $document, array $sectionIds): array
    {
        $qb = $this->createQueryBuilder('s')
            ->select('DISTINCT IDENTITY(s.user) AS id')
            ->join('s.user', 'u')->andWhere('u.active = true');
        if ([] === $sectionIds) {
            $qb->andWhere('s.document = :document')->setParameter('document', $document);
        } else {
            $qb->andWhere('(s.document = :document OR s.section IN (:sections))')
                ->setParameter('document', $document)
                ->setParameter('sections', $sectionIds);
        }
        $ids = array_map(static fn (array $row): int => (int) $row['id'], $qb->getQuery()->getArrayResult());
        if ([] === $ids) {
            return [];
        }

        /** @var list<User> $users */
        $users = $this->getEntityManager()->getRepository(User::class)->findBy(['id' => $ids]);

        return $users;
    }

    /** Сколько подписок у сотрудника (для профиля). */
    public function countForUser(User $user): int
    {
        return (int) $this->createQueryBuilder('s')->select('COUNT(s.id)')
            ->andWhere('s.user = :user')->setParameter('user', $user)
            ->getQuery()->getSingleScalarResult();
    }

    /** @return array{documents: int, sections: int, users: int} сводка для панели администратора */
    public function summary(): array
    {
        $row = $this->createQueryBuilder('s')
            ->select('SUM(CASE WHEN s.document IS NOT NULL THEN 1 ELSE 0 END) AS documents', 'SUM(CASE WHEN s.section IS NOT NULL THEN 1 ELSE 0 END) AS sections', 'COUNT(DISTINCT s.user) AS users')
            ->getQuery()->getSingleResult();

        return ['documents' => (int) $row['documents'], 'sections' => (int) $row['sections'], 'users' => (int) $row['users']];
    }
}
