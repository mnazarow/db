<?php

declare(strict_types=1);

use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__).'/vendor/autoload.php';

if (method_exists(Dotenv::class, 'bootEnv')) {
    (new Dotenv())->bootEnv(dirname(__DIR__).'/.env');
}

if (!@date_default_timezone_set((string) ($_SERVER['APP_TIMEZONE'] ?? 'Europe/Moscow'))) {
    date_default_timezone_set('UTC');
}

if ($_SERVER['APP_DEBUG'] ?? false) {
    umask(0000);
}
