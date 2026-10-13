<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\PushSubscription;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PushSubscription>
 */
final class PushSubscriptionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PushSubscription::class);
    }

    public function findByEndpoint(string $endpoint): ?PushSubscription
    {
        return $this->findOneBy(['endpointHash' => PushSubscription::hash($endpoint)]);
    }

    /**
     * @param list<int> $userIds
     *
     * @return list<PushSubscription>
     */
    public function findForUsers(array $userIds): array
    {
        if ([] === $userIds) {
            return [];
        }

        return $this->createQueryBuilder('s')->andWhere('s.user IN (:users)')->setParameter('users', $userIds)->orderBy('s.id', \SortDirection::Ascending)->getQuery()->getResult();
    }

    /** @return list<PushSubscription> */
    public function findForUser(User $user): array
    {
        return $this->createQueryBuilder('s')->andWhere('s.user = :user')->setParameter('user', $user)->orderBy('s.createdAt', \SortDirection::Descending)->getQuery()->getResult();
    }

    public function countAll(): int
    {
        return $this->count([]);
    }

    public function countUsers(): int
    {
        return (int) $this->createQueryBuilder('s')->select('COUNT(DISTINCT IDENTITY(s.user))')->getQuery()->getSingleScalarResult();
    }

    /**
     * Итог отправки — прямыми запросами (без сохранения прочих изменений Doctrine): успешные сбрасывают счётчик
     * неудач, неудачные его увеличивают, после $maxFailures подряд и отписанные (404/410) удаляются.
     *
     * @param list<int> $sent
     * @param list<int> $failed
     * @param list<int> $remove
     *
     * @return int сколько подписок удалено
     */
    public function applyResults(array $sent, array $failed, array $remove, int $maxFailures): int
    {
        $c = $this->getEntityManager()->getConnection();
        $ids = ['ids' => ArrayParameterType::INTEGER];
        if ([] !== $sent) {
            $c->executeStatement('UPDATE push_subscription SET last_sent_at = :now, failures = 0 WHERE id IN (:ids)', ['now' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'), 'ids' => $sent], $ids);
        }
        $removed = 0;
        if ([] !== $failed) {
            $c->executeStatement('UPDATE push_subscription SET failures = failures + 1 WHERE id IN (:ids)', ['ids' => $failed], $ids);
            $removed += (int) $c->executeStatement('DELETE FROM push_subscription WHERE id IN (:ids) AND failures >= :max', ['ids' => $failed, 'max' => $maxFailures], $ids);
        }
        if ([] !== $remove) {
            $removed += (int) $c->executeStatement('DELETE FROM push_subscription WHERE id IN (:ids)', ['ids' => $remove], $ids);
        }

        return $removed;
    }

    /** Оставляет сотруднику не больше $keep последних подписок (устройств); только что включённая остаётся всегда. */
    public function trimForUser(User $user, int $keep, ?int $currentId = null): int
    {
        $c = $this->getEntityManager()->getConnection();
        $ids = $c->fetchFirstColumn('SELECT id FROM push_subscription WHERE user_id = ? ORDER BY CASE WHEN id = ? THEN 0 ELSE 1 END, created_at DESC, id DESC', [$user->getId(), $currentId ?? 0]);
        $old = array_map('intval', \array_slice($ids, $keep));

        return [] === $old ? 0 : (int) $c->executeStatement('DELETE FROM push_subscription WHERE id IN (:ids)', ['ids' => $old], ['ids' => ArrayParameterType::INTEGER]);
    }

    /**
     * Удаляет подписки сотрудника с указанными номерами (включённые в завершаемой сессии).
     *
     * @param list<int> $ids
     */
    public function deleteForUser(User $user, array $ids): int
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));

        return [] === $ids ? 0 : (int) $this->getEntityManager()->getConnection()->executeStatement(
            'DELETE FROM push_subscription WHERE user_id = :user AND id IN (:ids)',
            ['user' => $user->getId(), 'ids' => $ids],
            ['ids' => ArrayParameterType::INTEGER],
        );
    }

    public function deleteAll(): int
    {
        return (int) $this->getEntityManager()->getConnection()->executeStatement('DELETE FROM push_subscription');
    }
}
