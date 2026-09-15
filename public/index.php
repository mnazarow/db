<?php

declare(strict_types=1);

use App\Kernel;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\ErrorHandler\Debug;
use Symfony\Component\HttpFoundation\Request;

require dirname(__DIR__).'/vendor/autoload.php';

(new Dotenv())->bootEnv(dirname(__DIR__).'/.env');

if (!@date_default_timezone_set((string) ($_SERVER['APP_TIMEZONE'] ?? 'Europe/Moscow'))) {
    date_default_timezone_set('UTC');
}

$debug = (bool) ($_SERVER['APP_DEBUG'] ?? false);

if ($debug) {
    umask(0000);
    Debug::enable();
}

$kernel = new Kernel($_SERVER['APP_ENV'] ?? 'prod', $debug);
$request = Request::createFromGlobals();
$response = $kernel->handle($request);
$response->send();
$kernel->terminate($request, $response);
