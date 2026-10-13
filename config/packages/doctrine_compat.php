<?php

declare(strict_types=1);

use Composer\InstalledVersions;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

/*
 * Настройки Doctrine, зависящие от версий PHP и DoctrineBundle (переход на Symfony 8 — РУКОВОДСТВО, 8.4).
 *
 * Прокси сущностей (ленивая загрузка связей):
 *  - PHP 8.4+ — встроенные ленивые объекты PHP, классы прокси не генерируются (в DoctrineBundle 3 / ORM 4 — только так);
 *  - PHP 8.2–8.3 — прежние «призраки» (lazy ghost) со сгенерированными классами: в рабочем окружении они создаются
 *    при прогреве кэша в каталоге сборки.
 * Автоматический поиск сущностей по аргументам контроллеров выключен (в DoctrineBundle 3 его уже нет).
 */
return static function (ContainerConfigurator $container): void {
    $bundle3 = version_compare(InstalledVersions::getVersion('doctrine/doctrine-bundle') ?? '2', '3.0', '>=');
    if ($bundle3) {
        return;
    }
    $orm = ['controller_resolver' => ['auto_mapping' => false]];
    if (\PHP_VERSION_ID >= 80400) {
        $orm['enable_native_lazy_objects'] = true;
    } else {
        $prod = 'prod' === $container->env();
        $orm += ['enable_lazy_ghost_objects' => true, 'auto_generate_proxy_classes' => !$prod];
        if ($prod) {
            $orm['proxy_dir'] = '%kernel.build_dir%/doctrine/orm/Proxies';
        }
    }
    $container->extension('doctrine', ['orm' => $orm]);
};
