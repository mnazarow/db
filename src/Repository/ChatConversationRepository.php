<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ChatConversation;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ChatConversation>
 */
class ChatConversationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ChatConversation::class);
    }

    /** @return list<ChatConversation> последние разговоры сотрудника */
    public function findRecent(User $user, int $limit = 30): array
    {
        return $this->createQueryBuilder('c')->where('c.user = :u AND c.messageCount > 0')->setParameter('u', $user)
            ->orderBy('c.updatedAt', \SortDirection::Descending)->addOrderBy('c.id', \SortDirection::Descending)->setMaxResults($limit)->getQuery()->getResult();
    }

    /** Разговор в Telegram, который продолжается (последнее сообщение не раньше $since). */
    public function findOngoing(User $user, string $channel, \DateTimeImmutable $since): ?ChatConversation
    {
        return $this->createQueryBuilder('c')->where('c.user = :u AND c.channel = :ch AND c.updatedAt >= :since')
            ->setParameter('u', $user)->setParameter('ch', $channel)->setParameter('since', $since)
            ->orderBy('c.updatedAt', \SortDirection::Descending)->setMaxResults(1)->getQuery()->getOneOrNullResult();
    }

    /** Удалить разговоры, последнее сообщение которых старше $before. */
    public function purgeBefore(\DateTimeImmutable $before): int
    {
        return (int) $this->getEntityManager()->getConnection()->executeStatement('DELETE FROM chat_conversation WHERE updated_at < ?', [$before->format('Y-m-d H:i:s')]);
    }
}
