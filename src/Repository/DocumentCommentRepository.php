<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Document;
use App\Entity\DocumentComment;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DocumentComment>
 */
final class DocumentCommentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DocumentComment::class);
    }

    /**
     * Обсуждение документа сверху вниз: сначала реплики, внутри — ответы.
     *
     * @return list<DocumentComment>
     */
    public function findForDocument(Document $document, int $limit = 200): array
    {
        /** @var list<DocumentComment> $all */
        $all = $this->createQueryBuilder('c')
            ->andWhere('c.document = :document')->setParameter('document', $document)
            ->orderBy('c.createdAt', 'ASC')->addOrderBy('c.id', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()->getResult();

        $roots = [];
        $children = [];
        foreach ($all as $comment) {
            if ($comment->isReply()) {
                $children[(int) $comment->getParent()?->getId()][] = $comment;
            } else {
                $roots[] = $comment;
            }
        }
        $ordered = [];
        foreach ($roots as $root) {
            $ordered[] = $root;
            foreach ($children[(int) $root->getId()] ?? [] as $reply) {
                $ordered[] = $reply;
            }
        }

        return $ordered;
    }

    /** Сколько живых (не удалённых) реплик у документа. */
    public function countForDocument(Document $document): int
    {
        return (int) $this->createQueryBuilder('c')->select('COUNT(c.id)')
            ->andWhere('c.document = :document')->setParameter('document', $document)
            ->andWhere('c.deletedAt IS NULL')
            ->getQuery()->getSingleScalarResult();
    }

    /**
     * Счётчики сразу по нескольким документам (для списков).
     *
     * @param list<int> $documentIds
     *
     * @return array<int, int>
     */
    public function countByDocuments(array $documentIds): array
    {
        if ([] === $documentIds) {
            return [];
        }
        $rows = $this->createQueryBuilder('c')
            ->select('IDENTITY(c.document) AS id', 'COUNT(c.id) AS total')
            ->andWhere('c.document IN (:ids)')->setParameter('ids', $documentIds)
            ->andWhere('c.deletedAt IS NULL')
            ->groupBy('c.document')
            ->getQuery()->getArrayResult();

        $counts = [];
        foreach ($rows as $row) {
            $counts[(int) $row['id']] = (int) $row['total'];
        }

        return $counts;
    }

    /**
     * Последние реплики сотрудника — чтобы показать в профиле «мои обсуждения».
     *
     * @return list<DocumentComment>
     */
    public function findRecentForUser(User $user, int $limit = 20): array
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.author = :user')->setParameter('user', $user)
            ->andWhere('c.deletedAt IS NULL')
            ->orderBy('c.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()->getResult();
    }

    /**
     * Участники обсуждения документа (кроме удалённых учётных записей) — им приходят ответы.
     *
     * @return list<User>
     */
    public function participants(Document $document): array
    {
        $rows = $this->createQueryBuilder('c')
            ->select('DISTINCT IDENTITY(c.author) AS id')->join('c.author', 'u')
            ->andWhere('c.document = :document')->setParameter('document', $document)
            ->andWhere('c.deletedAt IS NULL')
            ->andWhere('u.active = true')
            ->getQuery()->getArrayResult();
        $ids = array_map(static fn (array $row): int => (int) $row['id'], $rows);
        if ([] === $ids) {
            return [];
        }

        /** @var list<User> $users */
        $users = $this->getEntityManager()->getRepository(User::class)->findBy(['id' => $ids]);

        return $users;
    }
}
