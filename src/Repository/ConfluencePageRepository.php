<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ConfluencePage;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ConfluencePage>
 */
class ConfluencePageRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ConfluencePage::class);
    }

    public function findOne(string $baseHash, string $kind, string $externalId): ?ConfluencePage
    {
        return $this->findOneBy(['baseHash' => $baseHash, 'kind' => $kind, 'externalId' => $externalId]);
    }

    /** Страница по пространству и названию — для ссылок между страницами (в Confluence они по названию). */
    public function findPageByTitle(string $spaceKey, string $title): ?ConfluencePage
    {
        return $this->createQueryBuilder('p')
            ->where('p.kind = :kind AND p.spaceKey = :space AND p.title = :title')
            ->setParameter('kind', ConfluencePage::KIND_PAGE)->setParameter('space', $spaceKey)->setParameter('title', mb_substr($title, 0, 255))
            ->orderBy('p.id', \SortDirection::Descending)->setMaxResults(1)
            ->getQuery()->getOneOrNullResult();
    }

    /** Вложение страницы по имени файла. */
    public function findAttachment(string $pageId, string $fileName): ?ConfluencePage
    {
        return $this->createQueryBuilder('p')
            ->where('p.kind = :kind AND p.parentId = :page AND p.title = :title')
            ->setParameter('kind', ConfluencePage::KIND_ATTACHMENT)->setParameter('page', $pageId)->setParameter('title', mb_substr($fileName, 0, 255))
            ->orderBy('p.id', \SortDirection::Descending)->setMaxResults(1)
            ->getQuery()->getOneOrNullResult();
    }

    /** @return array<string, int> пространство → сколько страниц импортировано */
    public function countBySpace(): array
    {
        $out = [];
        foreach ($this->createQueryBuilder('p')->select('p.spaceKey AS s, COUNT(p.id) AS n')->where('p.kind = :k')->setParameter('k', ConfluencePage::KIND_PAGE)->groupBy('p.spaceKey')->getQuery()->getScalarResult() as $row) {
            $out[(string) $row['s']] = (int) $row['n'];
        }

        return $out;
    }
}
