<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Presentation;
use App\Entity\PresentationAsset;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PresentationAsset>
 */
final class PresentationAssetRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PresentationAsset::class);
    }

    public function findOneByToken(string $token): ?PresentationAsset
    {
        return preg_match('/^[a-f0-9]{24}$/', $token) ? $this->findOneBy(['token' => $token]) : null;
    }

    /**
     * @param list<string> $tokens
     *
     * @return array<string, PresentationAsset>
     */
    public function findByTokens(array $tokens): array
    {
        $tokens = array_values(array_unique(array_filter($tokens, static fn (string $t): bool => 1 === preg_match('/^[a-f0-9]{24}$/', $t))));
        if ([] === $tokens) {
            return [];
        }
        $out = [];
        foreach ($this->findBy(['token' => $tokens]) as $asset) {
            $out[$asset->getToken()] = $asset;
        }

        return $out;
    }

    /** @return list<PresentationAsset> */
    public function findByPresentation(Presentation $presentation): array
    {
        return $this->findBy(['presentation' => $presentation]);
    }

    /** Такое же изображение уже загружено в эту презентацию — переиспользуем файл. */
    public function findDuplicate(Presentation $presentation, string $sha256): ?PresentationAsset
    {
        return $this->findOneBy(['presentation' => $presentation, 'sha256' => $sha256]);
    }
}
