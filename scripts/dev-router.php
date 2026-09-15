<?php
// Маршрутизатор для встроенного сервера PHP (только для разработки):
//   php -S 127.0.0.1:8080 -t public scripts/dev-router.php
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$file = __DIR__.'/../public'.$path;
if ('/' !== $path && is_file($file)) {
    return false; // статический файл отдаёт сам сервер
}
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['SCRIPT_FILENAME'] = __DIR__.'/../public/index.php';
require __DIR__.'/../public/index.php';
