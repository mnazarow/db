<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\PriceItem;
use App\Entity\PriceList;
use App\Entity\PriceRevision;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PriceItem>
 */
class PriceItemRepository extends ServiceEntityRepository
{
    public const SORTS = ['position', 'name', 'sku', 'price_asc', 'price_desc'];

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PriceItem::class);
    }

    /**
     * Позиции редакции: поиск по словам (все слова — в артикуле, наименовании, группе или доп. столбцах),
     * фильтр по группе, сортировка, страница.
     *
     * @return array{items: list<PriceItem>, total: int}
     */
    public function page(PriceRevision $revision, string $q, ?string $group, string $sort, int $offset, int $limit): array
    {
        $qb = $this->createQueryBuilder('i')->where('i.revision = :r')->setParameter('r', $revision);
        self::words($qb, $q);
        if (null !== $group && '' !== $group) {
            $qb->andWhere('i.groupName = :g')->setParameter('g', $group);
        }
        $total = (int) (clone $qb)->select('COUNT(i.id)')->getQuery()->getSingleScalarResult();
        match ($sort) {
            'name' => $qb->orderBy('i.name', \SortDirection::Ascending),
            'sku' => $qb->orderBy('i.sku', \SortDirection::Ascending),
            'price_asc' => $qb->orderBy('i.price', \SortDirection::Ascending),
            'price_desc' => $qb->orderBy('i.price', \SortDirection::Descending),
            default => $qb->orderBy('i.position', \SortDirection::Ascending),
        };
        $qb->addOrderBy('i.position', \SortDirection::Ascending)->setFirstResult($offset)->setMaxResults($limit);

        return ['items' => $qb->getQuery()->getResult(), 'total' => $total];
    }

    /** @return list<string> группы редакции в порядке появления */
    public function groups(PriceRevision $revision): array
    {
        $rows = $this->createQueryBuilder('i')->select('i.groupName AS g, MIN(i.position) AS p')
            ->where('i.revision = :r AND i.groupName IS NOT NULL')->setParameter('r', $revision)
            ->groupBy('i.groupName')->orderBy('p', \SortDirection::Ascending)->getQuery()->getScalarResult();

        return array_map(static fn (array $r): string => (string) $r['g'], $rows);
    }

    /**
     * Поиск по действующим редакциям всех видимых прайс-листов (раздел «Прайс-листы», общий поиск, ответы ИИ).
     *
     * @return list<PriceItem>
     */
    public function searchCurrent(string $q, bool $guest, int $limit, ?PriceList $only = null): array
    {
        $qb = $this->createQueryBuilder('i')->join('i.revision', 'r')->addSelect('r')->join('r.priceList', 'l')->addSelect('l')
            ->where('l.currentRevision = r AND l.status = :active')->setParameter('active', PriceList::STATUS_ACTIVE);
        if ($guest) {
            $qb->andWhere('l.public = true');
        }
        if (null !== $only) {
            $qb->andWhere('l = :only')->setParameter('only', $only);
        }
        if (!self::words($qb, $q)) {
            return [];
        }

        return $qb->orderBy('l.position', \SortDirection::Ascending)->addOrderBy('l.title', \SortDirection::Ascending)->addOrderBy('i.position', \SortDirection::Ascending)
            ->setMaxResults($limit)->getQuery()->getResult();
    }

    /**
     * Позиции действующих прайс-листов, где встречается хотя бы одна из основ слов (для ответов помощника:
     * подходящие позиции отбираются по числу совпавших слов).
     *
     * @param list<string> $stems
     *
     * @return list<PriceItem>
     */
    public function searchAnyCurrent(array $stems, bool $guest, int $limit): array
    {
        $stems = array_values(array_filter(array_map(static fn (string $s): string => self::normalize($s), $stems), static fn (string $s): bool => mb_strlen($s) >= 2));
        if ([] === $stems) {
            return [];
        }
        $qb = $this->createQueryBuilder('i')->join('i.revision', 'r')->addSelect('r')->join('r.priceList', 'l')->addSelect('l')
            ->where('l.currentRevision = r AND l.status = :active')->setParameter('active', PriceList::STATUS_ACTIVE);
        if ($guest) {
            $qb->andWhere('l.public = true');
        }
        $or = $qb->expr()->orX();
        foreach (\array_slice($stems, 0, 10) as $n => $stem) {
            $or->add('i.searchText LIKE :s'.$n);
            $qb->setParameter('s'.$n, '%'.addcslashes($stem, '%_\\').'%');
        }

        return $qb->andWhere($or)->orderBy('i.position', \SortDirection::Ascending)->setMaxResults($limit)->getQuery()->getResult();
    }

    /**
     * Все позиции редакции (массивы) — для сравнения редакций и выгрузки.
     *
     * @return list<array{sku: ?string, name: string, groupName: ?string, unit: ?string, price: ?string, prices: list<string>, extra: list<string>}>
     */
    public function rows(PriceRevision $revision): array
    {
        return $this->createQueryBuilder('i')->select('i.sku, i.name, i.groupName, i.unit, i.price, i.prices, i.extra')
            ->where('i.revision = :r')->setParameter('r', $revision)->orderBy('i.position', \SortDirection::Ascending)
            ->getQuery()->getArrayResult();
    }

    /** Условия «каждое слово — в строке поиска»; false — слов нет. */
    private static function words(QueryBuilder $qb, string $q): bool
    {
        $words = array_slice(array_values(array_filter(preg_split('/\s+/u', self::normalize($q)) ?: [], static fn (string $w): bool => '' !== $w)), 0, 8);
        foreach ($words as $n => $word) {
            $qb->andWhere('i.searchText LIKE :w'.$n)->setParameter('w'.$n, '%'.addcslashes($word, '%_\\').'%');
        }

        return [] !== $words;
    }

    /** Строка для поиска: нижний регистр, «ё» → «е», без лишних пробелов. */
    public static function normalize(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', str_replace('ё', 'е', mb_strtolower($text))));
    }
}
