<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AiQuestion;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Вопросы к ИИ: сводка, вопросы без ответа по темам, неполезные ответы, очистка по сроку хранения.
 *
 * @extends ServiceEntityRepository<AiQuestion>
 */
final class AiQuestionRepository extends ServiceEntityRepository
{
    private const UNANSWERED = [AiQuestion::STATUS_NOT_FOUND, AiQuestion::STATUS_NO_SOURCES];

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AiQuestion::class);
    }

    /**
     * Сводка за период: всего вопросов, сотрудников, по итогам и оценкам.
     *
     * @return array{total: int, users: int, answered: int, not_found: int, no_sources: int, error: int, helpful: int, unhelpful: int, semantic: int}
     */
    public function stats(?\DateTimeImmutable $since): array
    {
        $sql = 'SELECT COUNT(*) AS total, COUNT(DISTINCT user_id) AS users,
                    SUM(CASE WHEN status = :answered THEN 1 ELSE 0 END) AS answered,
                    SUM(CASE WHEN status = :not_found THEN 1 ELSE 0 END) AS not_found,
                    SUM(CASE WHEN status = :no_sources THEN 1 ELSE 0 END) AS no_sources,
                    SUM(CASE WHEN status = :error THEN 1 ELSE 0 END) AS error,
                    SUM(CASE WHEN rating = 1 THEN 1 ELSE 0 END) AS helpful,
                    SUM(CASE WHEN rating = -1 THEN 1 ELSE 0 END) AS unhelpful,
                    SUM(CASE WHEN semantic = 1 THEN 1 ELSE 0 END) AS semantic
                FROM ai_question'.(null !== $since ? ' WHERE created_at >= :since' : '');
        $params = ['answered' => AiQuestion::STATUS_ANSWERED, 'not_found' => AiQuestion::STATUS_NOT_FOUND, 'no_sources' => AiQuestion::STATUS_NO_SOURCES, 'error' => AiQuestion::STATUS_ERROR];
        if (null !== $since) {
            $params['since'] = $since->format('Y-m-d H:i:s');
        }
        $row = $this->getEntityManager()->getConnection()->fetchAssociative($sql, $params) ?: [];

        return array_map(static fn ($v): int => (int) $v, array_merge(['total' => 0, 'users' => 0, 'answered' => 0, 'not_found' => 0, 'no_sources' => 0, 'error' => 0, 'helpful' => 0, 'unhelpful' => 0, 'semantic' => 0], $row));
    }

    /**
     * Темы вопросов, на которые в документах не нашлось ответа: сколько раз спрашивали, сколько сотрудников,
     * когда в последний раз, последняя формулировка; resolved — позже на такой вопрос ответ уже нашёлся.
     * Похожие темы объединяются: «дней отпус» и «дней отпус полож» — одна тема (см. similar()).
     *
     * @return list<array{topic: string, question: string, asked: int, users: int, last_at: \DateTimeImmutable, resolved: bool}>
     */
    public function unansweredTopics(?\DateTimeImmutable $since, int $limit = 200): array
    {
        $connection = $this->getEntityManager()->getConnection();
        $params = ['unanswered' => self::UNANSWERED, 'answered' => AiQuestion::STATUS_ANSWERED];
        $types = ['unanswered' => ArrayParameterType::STRING];
        $where = '';
        if (null !== $since) {
            $where = ' WHERE created_at >= :since';
            $params['since'] = $since->format('Y-m-d H:i:s');
        }
        $rows = $connection->fetchAllAssociative(
            'SELECT topic,
                    SUM(CASE WHEN status IN (:unanswered) THEN 1 ELSE 0 END) AS asked,
                    MAX(CASE WHEN status IN (:unanswered) THEN created_at END) AS last_at,
                    MAX(CASE WHEN status IN (:unanswered) THEN id END) AS last_id,
                    MAX(CASE WHEN status = :answered AND (rating IS NULL OR rating > 0) THEN created_at END) AS answered_at
             FROM ai_question'.$where.'
             GROUP BY topic
             ORDER BY asked DESC, last_at DESC',
            $params, $types);
        $ids = [];
        foreach ($rows as $r) {
            if (null !== $r['last_id']) {
                $ids[] = (int) $r['last_id'];
            }
        }
        $questions = [] === $ids ? [] : $connection->fetchAllKeyValue('SELECT id, question FROM ai_question WHERE id IN (:ids)', ['ids' => $ids], ['ids' => ArrayParameterType::INTEGER]);
        // Сотрудники по темам — отдельным запросом (GROUP_CONCAT в MySQL обрезается на 1024 символах).
        $usersByTopic = [];
        foreach ($connection->fetchAllAssociative('SELECT DISTINCT topic, user_id FROM ai_question WHERE status IN (:unanswered) AND user_id IS NOT NULL'.('' !== $where ? ' AND created_at >= :since' : ''),
            array_intersect_key($params, ['unanswered' => 1, 'since' => 1]), $types) as $pair) {
            $usersByTopic[(string) $pair['topic']][] = (string) $pair['user_id'];
        }

        // Объединение похожих тем (сравниваются только темы с общими словами — по обратному индексу «слово → темы»).
        // Темы, где ответ был, а вопросов без ответа нет, нужны только для пометки «позже ответ нашёлся».
        $clusters = [];
        $byStem = [];
        $answeredOnly = [];
        $answeredByStem = [];
        foreach ($rows as $r) {
            $stems = array_values(array_filter(explode(' ', (string) $r['topic'])));
            if ((int) $r['asked'] <= 0) {
                if (null !== $r['answered_at'] && \count($answeredOnly) < 5000) {
                    $k = \count($answeredOnly);
                    $answeredOnly[] = ['stems' => $stems, 'answered_at' => (string) $r['answered_at']];
                    foreach ($stems as $stem) {
                        $answeredByStem[$stem][] = $k;
                    }
                }
                continue;
            }
            $entry = [
                'stems' => $stems,
                'topic' => (string) $r['topic'],
                'question' => (string) ($questions[(int) $r['last_id']] ?? $r['topic']),
                'asked' => (int) $r['asked'],
                'users' => $usersByTopic[(string) $r['topic']] ?? [],
                'last_at' => (string) $r['last_at'],
                'last_id' => (int) $r['last_id'],
                'answered_at' => (string) $r['answered_at'],
            ];
            $candidates = [];
            foreach ($stems as $stem) {
                foreach ($byStem[$stem] ?? [] as $i) {
                    $candidates[$i] = true;
                }
            }
            ksort($candidates);
            foreach (array_keys($candidates) as $i) {
                $cluster = $clusters[$i];
                if (self::similar($cluster['stems'], $stems)) {
                    $clusters[$i]['asked'] += $entry['asked'];
                    $clusters[$i]['users'] = array_values(array_unique([...$cluster['users'], ...$entry['users']]));
                    // Время хранится с точностью до секунды: при равном времени позже задан вопрос с большим id.
                    if ([$entry['last_at'], $entry['last_id']] > [$cluster['last_at'], $cluster['last_id']]) {
                        $clusters[$i]['last_at'] = $entry['last_at'];
                        $clusters[$i]['last_id'] = $entry['last_id'];
                        $clusters[$i]['question'] = $entry['question'];
                    }
                    $clusters[$i]['answered_at'] = max($cluster['answered_at'], $entry['answered_at']);
                    continue 2;
                }
            }
            $clusters[] = $entry;
            foreach ($stems as $stem) {
                $byStem[$stem][] = \count($clusters) - 1;
            }
        }
        $out = [];
        foreach ($clusters as $cluster) {
            $answeredAt = $cluster['answered_at'];
            $seen = [];
            foreach ($cluster['stems'] as $stem) {
                foreach ($answeredByStem[$stem] ?? [] as $k) {
                    if (isset($seen[$k])) {
                        continue;
                    }
                    $seen[$k] = true;
                    if (self::similar($cluster['stems'], $answeredOnly[$k]['stems'])) {
                        $answeredAt = max($answeredAt, $answeredOnly[$k]['answered_at']);
                    }
                }
            }
            $out[] = [
                'topic' => $cluster['topic'],
                'question' => $cluster['question'],
                'asked' => $cluster['asked'],
                'users' => \count($cluster['users']),
                'last_at' => new \DateTimeImmutable($cluster['last_at']),
                'resolved' => '' !== $answeredAt && $answeredAt > $cluster['last_at'],
            ];
        }
        usort($out, static fn (array $a, array $b): int => [$b['asked'], $b['last_at']] <=> [$a['asked'], $a['last_at']]);

        return \array_slice($out, 0, $limit);
    }

    /**
     * Похожи ли темы (наборы основ слов): одна содержит другую (хотя бы два общих слова) или общих слов не меньше 60 %.
     *
     * @param list<string> $a
     * @param list<string> $b
     */
    public static function similar(array $a, array $b): bool
    {
        if ([] === $a || [] === $b) {
            return false;
        }
        $common = \count(array_intersect($a, $b));
        if ($a === $b || ($common >= 2 && $common >= min(\count($a), \count($b)))) {
            return true;
        }

        return $common > 0 && $common / \count(array_unique([...$a, ...$b])) >= 0.6;
    }

    /**
     * Ответы, которые сотрудники отметили как неполезные (новые сверху).
     *
     * @return list<AiQuestion>
     */
    public function unhelpful(?\DateTimeImmutable $since, int $limit = 200): array
    {
        $qb = $this->createQueryBuilder('q')->andWhere('q.rating = -1')->orderBy('q.createdAt', \SortDirection::Descending)->setMaxResults($limit);
        if (null !== $since) {
            $qb->andWhere('q.createdAt >= :since')->setParameter('since', $since);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Последние вопросы (новые сверху).
     *
     * @return list<AiQuestion>
     */
    public function recent(?\DateTimeImmutable $since, int $limit = 200): array
    {
        $qb = $this->createQueryBuilder('q')->orderBy('q.createdAt', \SortDirection::Descending)->addOrderBy('q.id', \SortDirection::Descending)->setMaxResults($limit);
        if (null !== $since) {
            $qb->andWhere('q.createdAt >= :since')->setParameter('since', $since);
        }

        return $qb->getQuery()->getResult();
    }

    /** Удаляет вопросы старше $before; возвращает число удалённых. */
    public function purgeBefore(\DateTimeImmutable $before): int
    {
        return (int) $this->getEntityManager()->getConnection()->executeStatement('DELETE FROM ai_question WHERE created_at < :before', ['before' => $before->format('Y-m-d H:i:s')]);
    }
}
