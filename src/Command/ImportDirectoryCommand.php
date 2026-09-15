<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\User;
use App\Repository\SectionRepository;
use App\Repository\UserRepository;
use App\Service\FileStorage;
use App\Service\Import\DirectoryImporter;
use App\Service\Import\ImportOptions;
use App\Service\Import\ImportPlan;
use App\Service\Import\ImportState;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:import:directory',
    description: 'Импортировать документы из каталога на диске: папки становятся разделами, файлы — документами',
)]
final class ImportDirectoryCommand extends Command
{
    public function __construct(
        private readonly DirectoryImporter $importer,
        private readonly SectionRepository $sections,
        private readonly UserRepository $users,
        private readonly FileStorage $storage,
        private readonly string $importDir,
        private readonly int $defaultValidityMonths,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('dir', InputArgument::OPTIONAL, 'Каталог с документами (по умолчанию — каталог импорта IMPORT_DIR)')
            ->addOption('section', 's', InputOption::VALUE_REQUIRED, 'Целевой раздел: id или slug (по умолчанию — верхний уровень)')
            ->addOption('user', 'u', InputOption::VALUE_REQUIRED, 'Логин пользователя, от имени которого создаются разделы и документы (по умолчанию — первый администратор)')
            ->addOption('root-as-section', 'r', InputOption::VALUE_NONE, 'Создать раздел с именем самого каталога (иначе его содержимое попадает прямо в целевой раздел)')
            ->addOption('draft', null, InputOption::VALUE_NONE, 'Импортировать как черновики (по умолчанию документы сразу публикуются)')
            ->addOption('validity', null, InputOption::VALUE_REQUIRED, 'Срок актуальности в месяцах от сегодня; 0 — бессрочно (по умолчанию DEFAULT_VALIDITY_MONTHS)')
            ->addOption('no-update', null, InputOption::VALUE_NONE, 'Не загружать новые версии для изменившихся файлов, которые уже есть в разделе')
            ->addOption('delete-source', null, InputOption::VALUE_NONE, 'Удалять исходные файлы после успешного импорта (перенос) и опустевшие папки')
            ->addOption('any-extension', null, InputOption::VALUE_NONE, 'Импортировать файлы с любыми расширениями, не только из ALLOWED_EXTENSIONS')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Только показать план (что будет создано, обновлено, пропущено), ничего не менять')
            ->addOption('yes', 'y', InputOption::VALUE_NONE, 'Не спрашивать подтверждения')
            ->setHelp(<<<'HELP'
Структура папок каталога становится деревом разделов (подпапки — подразделы любой вложенности),
каждый файл — документом-файлом с названием по имени файла (без расширения, подчёркивания → пробелы).
Файл README.txt / README.md / описание.txt внутри папки становится описанием раздела.

Повторный запуск безопасен: существующие разделы и неизменившиеся файлы пропускаются,
для изменившихся файлов загружается новая версия документа.

Примеры:

  <info>php bin/console app:import:directory /mnt/share/Документы --dry-run</info>
      показать, что будет импортировано

  <info>php bin/console app:import:directory /mnt/share/Документы --section=proizvodstvo --root-as-section</info>
      создать раздел «Документы» внутри раздела «Производство» и перенести в него дерево папок

  <info>php bin/console app:import:directory --delete-source --user=admin -y</info>
      импортировать каталог IMPORT_DIR, удалив исходные файлы после переноса
HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dir = (string) ($input->getArgument('dir') ?? '');
        if ('' === $dir) {
            $dir = $this->importDir;
        }
        if (!is_dir($dir)) {
            $io->error('Каталог не найден: '.$dir);

            return Command::FAILURE;
        }

        $target = null;
        $sectionRef = (string) ($input->getOption('section') ?? '');
        if ('' !== $sectionRef) {
            $target = ctype_digit($sectionRef) ? $this->sections->find((int) $sectionRef) : $this->sections->findOneBySlug($sectionRef);
            if (null === $target) {
                $io->error('Раздел не найден: '.$sectionRef.' (укажите id или slug, список — в панели администратора).');

                return Command::FAILURE;
            }
        }

        $actor = $this->resolveActor((string) ($input->getOption('user') ?? ''), $io);
        if (null === $actor) {
            return Command::FAILURE;
        }

        $validity = null !== $input->getOption('validity') ? max(0, (int) $input->getOption('validity')) : max(0, $this->defaultValidityMonths);
        $options = new ImportOptions(
            targetSectionId: $target?->getId(),
            rootAsSection: (bool) $input->getOption('root-as-section'),
            publish: !$input->getOption('draft'),
            validityMonths: $validity,
            updateExisting: !$input->getOption('no-update'),
            deleteSource: (bool) $input->getOption('delete-source'),
            anyExtension: (bool) $input->getOption('any-extension'),
        );

        try {
            $plan = $this->importer->preview($this->importer->scan($dir, $options), $options);
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $summary = $plan->summary();
        $io->title('Импорт из каталога');
        $io->definitionList(
            ['Каталог' => $plan->dir],
            ['Целевой раздел' => null !== $target ? $target->getFullName() : '(верхний уровень)'.($options->rootAsSection ? '' : ' — папки первого уровня станут разделами верхнего уровня')],
            ['Раздел по имени каталога' => $options->rootAsSection ? 'да («'.$plan->label.'»)' : 'нет'],
            ['От имени' => $actor->getDisplayName().' ('.$actor->getUsername().')'],
            ['Статус документов' => $options->publish ? 'опубликованы' : 'черновики'],
            ['Срок актуальности' => $options->validityMonths > 0 ? $options->validityMonths.' мес.' : 'бессрочно'],
            ['Изменившиеся файлы' => $options->updateExisting ? 'новая версия' : 'пропускаются'],
            ['Исходные файлы' => $options->deleteSource ? 'удаляются после импорта' : 'остаются на месте'],
            ['Папок → разделов' => \sprintf('%d (пропущено %d)', $summary['dirs'], $summary['dirsSkipped'])],
            ['Файлов → документов' => \sprintf('%d, %s (пропущено %d)', $summary['files'], FileStorage::humanSize($summary['bytes']), $summary['filesSkipped'])],
        );

        $expected = $this->expectedCounts($plan);
        $io->text(\sprintf('Ожидается: новых разделов %d, существующих %d; новых документов %d, новых версий %d, без изменений %d, пропусков %d.',
            $expected[ImportPlan::RESULT_SECTION_NEW], $expected[ImportPlan::RESULT_SECTION_EXISTS], $expected[ImportPlan::RESULT_DOC_NEW],
            $expected[ImportPlan::RESULT_VERSION_NEW], $expected[ImportPlan::RESULT_UNCHANGED], $expected[ImportPlan::RESULT_SKIPPED] + $expected[ImportPlan::RESULT_EXISTS]));
        if ([] !== $summary['skipped']) {
            $io->newLine();
            foreach ($summary['skipped'] as $reason => $count) {
                $io->text(\sprintf('  пропуск: %s — %d', ImportPlan::SKIP_LABELS[$reason] ?? $reason, $count));
            }
        }

        if ($input->getOption('dry-run') || $output->isVerbose()) {
            $io->newLine();
            $this->printPlan($plan, $io, $output->isVerbose() ? \PHP_INT_MAX : 300);
        }
        if ($input->getOption('dry-run')) {
            $io->note('Режим проверки (--dry-run): ничего не изменено.');

            return Command::SUCCESS;
        }
        if (0 === $summary['dirs'] + $summary['files']) {
            $io->warning('Импортировать нечего.');

            return Command::SUCCESS;
        }
        if (!$input->getOption('yes') && $input->isInteractive() && !$io->confirm('Начать импорт?', true)) {
            $io->text('Отменено.');

            return Command::SUCCESS;
        }

        $io->newLine();
        $bar = new ProgressBar($output, $plan->count());
        $bar->setFormat(' %current%/%max% [%bar%] %percent:3s%% %message%');
        $bar->setMessage('');
        $bar->start();
        $problems = [];
        try {
            $state = $this->importer->run($plan, $options, $actor, static function (int $done, int $total, array $entry, string $result, string $message) use ($bar, &$problems): void {
                $bar->setMessage(mb_substr($entry['path'], 0, 60));
                $bar->setProgress($done);
                if (ImportPlan::RESULT_ERROR === $result) {
                    $problems[] = [$entry['path'], $message];
                }
            });
        } catch (\Throwable $e) {
            $bar->clear();
            $io->error($e->getMessage());

            return Command::FAILURE;
        }
        $bar->finish();
        $io->newLine(2);

        $rows = [];
        foreach (ImportState::COUNTER_LABELS as $key => $label) {
            $rows[] = [$label, $state->counters[$key]];
        }
        $io->table(['Итог', 'Количество'], $rows);
        if ([] !== $problems) {
            $io->section('Ошибки');
            $io->table(['Файл', 'Ошибка'], \array_slice($problems, 0, 50));
            if (\count($problems) > 50) {
                $io->text(\sprintf('… и ещё %d (см. журнал аудита).', \count($problems) - 50));
            }
        }
        $io->success('Импорт завершён.');

        return $state->counters['errors'] > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    private function resolveActor(string $username, SymfonyStyle $io): ?User
    {
        if ('' !== $username) {
            $user = $this->users->findOneByUsername($username);
            if (null === $user) {
                $io->error('Пользователь не найден: '.$username);

                return null;
            }

            return $user;
        }
        $admins = $this->users->findActiveAdmins();
        if ([] === $admins) {
            $io->error('В портале нет ни одного активного администратора — укажите пользователя параметром --user.');

            return null;
        }

        return $admins[0];
    }

    /** @return array<string, int> */
    private function expectedCounts(ImportPlan $plan): array
    {
        $counts = array_fill_keys(array_keys(ImportPlan::RESULT_LABELS), 0);
        foreach ($plan->entries as $entry) {
            $result = $entry['result'] ?? ImportPlan::RESULT_SKIPPED;
            $counts[$result] = ($counts[$result] ?? 0) + 1;
        }

        return $counts;
    }

    private function printPlan(ImportPlan $plan, SymfonyStyle $io, int $limit): void
    {
        $rows = [];
        foreach ($plan->entries as $i => $entry) {
            if ($i >= $limit) {
                $rows[] = ['…', \sprintf('ещё %d элементов (запустите с -v, чтобы увидеть всё)', $plan->count() - $limit), ''];
                break;
            }
            $depth = substr_count($entry['path'], '/') + ('' === $entry['path'] ? 0 : 1);
            $indent = str_repeat('  ', max(0, $depth - ('dir' === $entry['type'] ? 1 : 0)));
            $label = 'dir' === $entry['type'] ? '▸ '.('' === $entry['path'] ? $plan->label : $entry['name']) : $entry['name'];
            $result = $entry['result'] ?? '';
            $text = ImportPlan::RESULT_LABELS[$result] ?? $result;
            if (isset($entry['message']) && '' !== $entry['message']) {
                $text .= ': '.$entry['message'];
            }
            $rows[] = [$indent.$label, 'dir' === $entry['type'] ? 'раздел' : FileStorage::humanSize((int) ($entry['size'] ?? 0)), $text];
        }
        $io->table(['Элемент', 'Тип / размер', 'Действие'], $rows);
    }
}
