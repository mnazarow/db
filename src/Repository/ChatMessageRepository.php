<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ChatConversation;
use App\Entity\ChatMessage;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ChatMessage>
 */
class ChatMessageRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ChatMessage::class);
    }

    /** @return list<ChatMessage> по порядку */
    public function findForConversation(ChatConversation $conversation, int $limit = 200): array
    {
        $rows = $this->createQueryBuilder('m')->leftJoin('m.question', 'q')->addSelect('q')->where('m.conversation = :c')->setParameter('c', $conversation)
            ->orderBy('m.id', \SortDirection::Descending)->setMaxResults($limit)->getQuery()->getResult();

        return array_reverse($rows);
    }
}
