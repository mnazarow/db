<?php

declare(strict_types=1);

namespace App;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

final class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    /**
     * На PHP 8.4+ контейнер собирается со встроенными ленивыми объектами Doctrine, на PHP 8.2–8.3 — с классами
     * прокси (config/packages/doctrine_compat.php), и собранный одной веткой PHP на другой не работает. У каждой
     * ветки свой класс контейнера: консоль или cron, запущенные другой версией PHP, чем PHP-FPM, не портят кэш сайта.
     */
    protected function getContainerClass(): string
    {
        $class = parent::getContainerClass();

        return \PHP_VERSION_ID >= 80400 ? $class : substr($class, 0, -\strlen('Container')).'LegacyProxiesContainer';
    }
}
