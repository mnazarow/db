#!/usr/bin/env bash
# Проба следующей мажорной версии Symfony на копии зависимостей (РУКОВОДСТВО, 8.4 «Переход на Symfony 8»).
#
#   scripts/symfony-next.sh [8.1]
#
# Переписывает composer.json: компоненты Symfony 7.4.* → <версия>.*, DoctrineBundle 3, DoctrineMigrationsBundle 4,
# MonologBundle 4, PHP ≥ 8.4 — и обновляет vendor/ (composer update -W). Нужны PHP 8.4+ и доступ к Packagist.
# Только для проверки: после пробы верните файлы — git checkout composer.json composer.lock && composer install.
# В CI — задание «Проба Symfony 8» (.github/workflows/ci.yml): автотесты должны пройти без устаревших вызовов.
set -euo pipefail

TARGET="${1:-8.1}"
if [[ ! "${TARGET}" =~ ^[0-9]+\.[0-9]+$ ]]; then
    echo "Версия Symfony — в виде 8.1" >&2
    exit 2
fi
cd "$(dirname "$0")/.."

php -r '
if (\PHP_VERSION_ID < 80400) {
    fwrite(\STDERR, "Symfony 8 требует PHP 8.4 или новее (сейчас ".\PHP_VERSION.").\n");
    exit(1);
}
$target = $argv[1];
$file = "composer.json";
// Объекты, а не массивы: пустые {} в composer.json должны остаться объектами.
$json = json_decode(file_get_contents($file), false, 512, \JSON_THROW_ON_ERROR);
foreach (["require", "require-dev"] as $section) {
    foreach ($json->{$section} ?? [] as $name => $constraint) {
        if (str_starts_with($name, "symfony/") && "7.4.*" === $constraint) {
            $json->{$section}->{$name} = $target.".*";
        }
    }
}
$json->require->php = ">=8.4";
$json->require->{"doctrine/doctrine-bundle"} = "^3.0";
$json->require->{"doctrine/doctrine-migrations-bundle"} = "^4.0";
$json->require->{"symfony/monolog-bundle"} = "^4.0";
unset($json->config->platform);
$json->extra->symfony->require = $target.".*";
file_put_contents($file, json_encode($json, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE)."\n");
echo "composer.json: Symfony ".$target.".*, DoctrineBundle 3, DoctrineMigrationsBundle 4, MonologBundle 4\n";
' "${TARGET}"

composer update --no-interaction --no-progress --with-all-dependencies
php bin/console about | sed -n '1,8p'
