<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\UserRepository;
use App\Repository\ApiKeyRepository;
use App\Security\Ldap\LdapSettings;
use App\Service\PortalSettings;
use App\Service\Text\TextExtractor;
use App\Service\FileStorage;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:check', description: 'Проверить готовность установки: PHP-расширения, база данных, каталоги, администратор')]
final class CheckCommand extends Command
{
    private const REQUIRED_EXTENSIONS = ['pdo_mysql', 'mbstring', 'ctype', 'iconv', 'json', 'intl', 'fileinfo', 'dom', 'xml'];

    public function __construct(
        private readonly Connection $connection,
        private readonly FileStorage $storage,
        private readonly UserRepository $users,
        private readonly LdapSettings $ldap,
        private readonly TextExtractor $extractor,
        private readonly PortalSettings $settings,
        private readonly ApiKeyRepository $apiKeys,
        private readonly string $projectDir,
        private readonly string $importDir,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $ok = true;
        $io->title('Проверка установки портала документации');

        $io->section('PHP');
        $io->writeln(\sprintf('Версия PHP: %s', \PHP_VERSION));
        if (version_compare(\PHP_VERSION, '8.2.0', '<')) {
            $io->error('Требуется PHP 8.2 или новее.');
            $ok = false;
        }
        $missing = array_filter(self::REQUIRED_EXTENSIONS, static fn (string $ext) => !\extension_loaded($ext));
        if ([] !== $missing) {
            $io->error('Отсутствуют расширения PHP: '.implode(', ', $missing));
            $ok = false;
        } else {
            $io->writeln('Расширения PHP: <info>все необходимые установлены</info>');
        }
        if ($this->ldap->enabled && !$this->ldap->isExtensionLoaded()) {
            $io->error('Включён вход через домен (LDAP_ENABLED=1), но расширение PHP ldap не установлено (пакет php-ldap).');
            $ok = false;
        } elseif ($this->ldap->enabled) {
            $io->writeln(\sprintf('LDAP: <info>включён</info> (%s:%d, %s)', $this->ldap->host, $this->ldap->port, $this->ldap->usesServiceAccount() ? 'служебная учётная запись' : 'привязка от имени пользователя'));
        } else {
            $io->writeln('LDAP: выключен (только локальные учётные записи)');
        }
        $io->writeln(\sprintf('upload_max_filesize = %s, post_max_size = %s, memory_limit = %s (лимит портала UPLOAD_MAX_MB = %d)', \ini_get('upload_max_filesize'), \ini_get('post_max_size'), \ini_get('memory_limit'), $this->storage->getUploadMaxMb()));

        $io->section('База данных');
        try {
            $version = (string) $this->connection->executeQuery('SELECT VERSION()')->fetchOne();
            $io->writeln('Подключение: <info>успешно</info> ('.$version.')');
            $tables = $this->connection->createSchemaManager()->listTableNames();
            foreach (['user', 'section', 'section_moderator', 'document', 'document_version', 'document_event', 'doctrine_migration_versions'] as $table) {
                if (!\in_array($table, $tables, true)) {
                    $io->error(\sprintf('Таблица «%s» не найдена. Выполните миграции: php bin/console doctrine:migrations:migrate -n', $table));
                    $ok = false;
                }
            }
            if ($ok) {
                $io->writeln('Схема: <info>таблицы на месте</info>');
            }
        } catch (\Throwable $e) {
            $io->error('Ошибка подключения к базе данных: '.$e->getMessage());
            $io->writeln('Проверьте DATABASE_URL в .env.local');
            $ok = false;
        }

        $io->section('Каталоги');
        foreach (['var/cache', 'var/log'] as $dir) {
            $path = $this->projectDir.'/'.$dir;
            if (!is_dir($path) && !@mkdir($path, 0775, true)) {
                $io->error(\sprintf('Каталог %s не существует и не может быть создан.', $path));
                $ok = false;
            } elseif (!is_writable($path)) {
                $io->error(\sprintf('Каталог %s недоступен для записи.', $path));
                $ok = false;
            } else {
                $io->writeln(\sprintf('%s: <info>ок</info>', $dir));
            }
        }
        $storage = $this->storage->getStorageDir();
        if (!is_dir($storage) && !@mkdir($storage, 0750, true)) {
            $io->error(\sprintf('Каталог хранилища %s не существует и не может быть создан.', $storage));
            $ok = false;
        } elseif (!is_writable($storage)) {
            $io->error(\sprintf('Каталог хранилища %s недоступен для записи.', $storage));
            $ok = false;
        } else {
            $io->writeln(\sprintf('Хранилище документов %s: <info>ок</info>', $storage));
        }
        if (!is_dir($this->importDir) && !@mkdir($this->importDir, 0770, true)) {
            $io->warning(\sprintf('Каталог импорта %s не существует и не может быть создан (импорт из каталога будет недоступен).', $this->importDir));
        } elseif (!is_writable($this->importDir)) {
            $io->writeln(\sprintf('Каталог импорта %s: <comment>только чтение</comment> (перенос файлов с удалением исходников работать не будет)', $this->importDir));
        } else {
            $pending = 0;
            foreach (@scandir($this->importDir, \SCANDIR_SORT_NONE) ?: [] as $entry) {
                if ('.' !== $entry && '..' !== $entry && !str_starts_with($entry, '.')) {
                    ++$pending;
                }
            }
            $io->writeln(\sprintf('Каталог импорта %s: <info>ок</info>%s', $this->importDir, $pending > 0 ? \sprintf(' (ожидает элементов: %d — панель администратора → Импорт)', $pending) : ''));
        }

        $io->section('Пользователи');
        try {
            $admins = $this->users->countActiveAdmins();
            if (0 === $admins) {
                $io->warning('Нет ни одного активного администратора. Создайте: php bin/console app:user:create admin --admin --generate');
            } else {
                $io->writeln(\sprintf('Активных администраторов: <info>%d</info>', $admins));
            }
        } catch (\Throwable $e) {
            $io->warning('Не удалось проверить пользователей: '.$e->getMessage());
        }

        $io->section('Интеграции');
        $io->writeln($this->extractor->hasPdftotext()
            ? 'Извлечение текста из PDF (pdftotext): <info>доступно</info>'
            : 'Извлечение текста из PDF: <comment>pdftotext не найден</comment> — установите пакет poppler-utils (иначе API и LLM не получат текст PDF-файлов)');
        $io->writeln(\sprintf('Расширения PHP curl/zip (LLM, webhook, docx/xlsx/pptx): %s', \function_exists('curl_init') && class_exists(\ZipArchive::class) ? '<info>установлены</info>' : '<comment>'.(!\function_exists('curl_init') ? 'нет curl ' : '').(!class_exists(\ZipArchive::class) ? 'нет zip' : '').'</comment>'));
        try {
            $keys = $this->apiKeys->findAllOrdered();
            $active = \count(array_filter($keys, static fn ($k) => $k->isEnabled()));
            $io->writeln(\sprintf('Ключи REST API: %s', [] === $keys ? 'не созданы (панель администратора → Интеграции или app:api-key create)' : \sprintf('<info>%d</info>, активных %d', \count($keys), $active)));
            $llm = $this->settings->llm();
            $io->writeln(\sprintf('Описания через LLM: %s', $llm['enabled'] ? \sprintf('<info>включены</info> (%s, модель %s%s)', $llm['base_url'], $llm['model'], $llm['auto_describe'] ? ', автоописание новых документов' : '') : 'выключены'));
            $webhook = $this->settings->webhook();
            $io->writeln(\sprintf('Webhook об изменениях: %s', $webhook['enabled'] ? '<info>включён</info> ('.$webhook['url'].')' : 'выключен'));
        } catch (\Throwable $e) {
            $io->writeln('<comment>Настройки интеграций недоступны: '.$e->getMessage().'</comment>');
        }

        if ($ok) {
            $io->success('Установка готова к работе.');

            return Command::SUCCESS;
        }
        $io->error('Обнаружены проблемы. Исправьте их и повторите проверку.');

        return Command::FAILURE;
    }
}
