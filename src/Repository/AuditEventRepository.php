<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AuditEvent;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AuditEvent>
 */
final class AuditEventRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AuditEvent::class);
    }

    /**
     * Журнал с фильтрами.
     *
     * @param array{q?: ?string, level?: ?string, actor?: ?string, from?: ?\DateTimeImmutable, to?: ?\DateTimeImmutable} $filters
     *
     * @return list<AuditEvent>
     */
    public function findFiltered(array $filters, int $limit = 200, int $offset = 0): array
    {
        return $this->filtered($filters)
            ->orderBy('a.occurredAt', 'DESC')->addOrderBy('a.id', 'DESC')
            ->setMaxResults($limit)->setFirstResult($offset)
            ->getQuery()->getResult();
    }

    /** @param array{q?: ?string, level?: ?string, actor?: ?string, from?: ?\DateTimeImmutable, to?: ?\DateTimeImmutable} $filters */
    public function countFiltered(array $filters): int
    {
        return (int) $this->filtered($filters)->select('COUNT(a.id)')->getQuery()->getSingleScalarResult();
    }

    /**
     * Записи для выгрузки в SIEM: строго по возрастанию, начиная с указанного момента.
     *
     * @return list<AuditEvent>
     */
    public function findSince(?\DateTimeImmutable $since, int $limit = 5000): array
    {
        $qb = $this->createQueryBuilder('a')->orderBy('a.occurredAt', 'ASC')->addOrderBy('a.id', 'ASC')->setMaxResults($limit);
        if (null !== $since) {
            $qb->andWhere('a.occurredAt >= :since')->setParameter('since', $since);
        }

        return $qb->getQuery()->getResult();
    }

    /** @return list<string> логины, встречающиеся в журнале (для фильтра) */
    public function actors(int $limit = 200): array
    {
        $rows = $this->createQueryBuilder('a')->select('DISTINCT a.actorName AS name')
            ->andWhere('a.actorName IS NOT NULL')
            ->orderBy('a.actorName', 'ASC')->setMaxResults($limit)
            ->getQuery()->getArrayResult();

        return array_values(array_filter(array_column($rows, 'name')));
    }

    /** @return array{total: int, warnings: int, last: ?\DateTimeImmutable} сводка журнала */
    public function summary(): array
    {
        $row = $this->createQueryBuilder('a')
            ->select('COUNT(a.id) AS total', 'SUM(CASE WHEN a.level <> :info THEN 1 ELSE 0 END) AS warnings', 'MAX(a.occurredAt) AS last')
            ->setParameter('info', AuditEvent::LEVEL_INFO)
            ->getQuery()->getSingleResult();

        return [
            'total' => (int) $row['total'],
            'warnings' => (int) $row['warnings'],
            'last' => null !== $row['last'] ? new \DateTimeImmutable((string) $row['last']) : null,
        ];
    }

    /** Удаляет записи старше указанной даты; возвращает число удалённых. */
    public function purgeOlderThan(\DateTimeImmutable $before): int
    {
        return (int) $this->createQueryBuilder('a')->delete()
            ->andWhere('a.occurredAt < :before')->setParameter('before', $before)
            ->getQuery()->execute();
    }

    /** @param array{q?: ?string, level?: ?string, actor?: ?string, from?: ?\DateTimeImmutable, to?: ?\DateTimeImmutable} $filters */
    private function filtered(array $filters): QueryBuilder
    {
        $qb = $this->createQueryBuilder('a');
        if (!empty($filters['q'])) {
            $qb->andWhere('LOWER(a.action) LIKE :q OR LOWER(a.details) LIKE :q')
                ->setParameter('q', '%'.mb_strtolower(trim((string) $filters['q'])).'%');
        }
        if (!empty($filters['level'])) {
            $qb->andWhere('a.level = :level')->setParameter('level', $filters['level']);
        }
        if (!empty($filters['actor'])) {
            $qb->andWhere('a.actorName = :actor')->setParameter('actor', $filters['actor']);
        }
        if (!empty($filters['from'])) {
            $qb->andWhere('a.occurredAt >= :from')->setParameter('from', $filters['from']);
        }
        if (!empty($filters['to'])) {
            $qb->andWhere('a.occurredAt <= :to')->setParameter('to', $filters['to']);
        }

        return $qb;
    }
}
