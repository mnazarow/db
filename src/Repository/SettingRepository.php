<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Setting;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Setting>
 */
final class SettingRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Setting::class);
    }

    /** @return array<string, mixed> все настройки: имя → значение */
    public function findAllValues(): array
    {
        $out = [];
        foreach ($this->findAll() as $setting) {
            $out[$setting->getName()] = $setting->getValue();
        }

        return $out;
    }
}
