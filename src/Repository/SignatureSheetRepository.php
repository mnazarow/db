<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Document;
use App\Entity\SignatureSheet;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SignatureSheet>
 */
final class SignatureSheetRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SignatureSheet::class);
    }

    public function findOneByCode(string $code): ?SignatureSheet
    {
        return $this->findOneBy(['code' => $code]);
    }

    /** Лист с теми же записями (тот же код при повторной выгрузке, если ничего не изменилось). */
    public function findSame(Document $document, int $versionNumber, string $contentHash): ?SignatureSheet
    {
        return $this->findOneBy(['document' => $document, 'versionNumber' => $versionNumber, 'contentHash' => $contentHash], ['id' => 'ASC']);
    }
}
