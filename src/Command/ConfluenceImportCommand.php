<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\SectionRepository;
use App\Repository\UserRepository;
use App\Service\Import\Confluence\ConfluenceException;
use App\Service\Import\Confluence\ConfluenceImporter;
use App\Service\Import\Confluence\ConfluenceJob;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:import:confluence',
    description: 'Импортировать пространство Confluence: страницы — документами, ветки — подразделами, вложения — файлами',
)]
final class ConfluenceImportCommand extends Command
{
    public function __construct(
        private readonly ConfluenceImporter $importer,
        private readonly SectionRepository $sections,
        private readonly UserRepository $users,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('space', InputArgument::OPTIONAL, 'Ключ пространства (без него — список доступных пространств)')
            ->addOption('section', 's', InputOption::VALUE_REQUIRED, 'Раздел портала (id); по умолчанию — раздел с названием пространства (создаётся)')
            ->addOption('root', null, InputOption::VALUE_REQUIRED, 'Только ветка страницы с этим номером')
            ->addOption('user', 'u', InputOption::VALUE_REQUIRED, 'Логин, от имени которого создаются документы (по умолчанию — первый администратор)')
            ->addOption('draft', null, InputOption::VALUE_NONE, 'Черновики (по умолчанию документы публикуются)')
            ->addOption('flat', null, InputOption::VALUE_NONE, 'Без подразделов: все страницы — документами одного раздела')
            ->addOption('no-attachments', null, InputOption::VALUE_NONE, 'Не импортировать вложения (изображения встраиваются в страницы всегда)')
            ->setHelp(<<<'HELP'
Подключение к Confluence задаётся в панели администратора → Импорт → «Из Confluence» (адрес, способ входа,
токен). Повторный запуск для того же пространства обновляет только изменившиеся страницы и вложения.

  <info>php bin/console app:import:confluence</info>               список пространств
  <info>php bin/console app:import:confluence DOCS</info>          всё пространство DOCS в раздел «<название DOCS>»
  <info>php bin/console app:import:confluence DOCS -s 12 --root=123456 --draft</info>
HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        if (!$this->importer->isConfigured()) {
            $io->error('Подключение к Confluence не задано: панель администратора → Импорт → «Из Confluence».');

            return Command::FAILURE;
        }
        $space = trim((string) $input->getArgument('space'));
        try {
            if ('' === $space) {
                $rows = array_map(static fn (array $s): array => [$s['key'], $s['name']], $this->importer->api()->spaces());
                $io->table(['Ключ', 'Пространство'], $rows);

                return Command::SUCCESS;
            }
            $login = (string) ($input->getOption('user') ?? '');
            $actor = '' !== $login ? $this->users->findOneBy(['username' => $login]) : ($this->users->findActiveAdmins()[0] ?? null);
            if (null === $actor) {
                $io->error('' !== $login ? 'Пользователь '.$login.' не найден.' : 'В портале нет активного администратора — укажите --user.');

                return Command::FAILURE;
            }
            $section = $input->getOption('section');
            if (null !== $section && null === $this->sections->find((int) $section)) {
                $io->error('Раздел '.$section.' не найден.');

                return Command::FAILURE;
            }
            $job = $this->importer->start([
                'space' => $space,
                'root' => (string) ($input->getOption('root') ?? ''),
                'section' => null !== $section ? (int) $section : null,
                'attachments' => !$input->getOption('no-attachments'),
                'publish' => !$input->getOption('draft'),
                'flat' => (bool) $input->getOption('flat'),
            ], $actor);
        } catch (ConfluenceException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }
        $io->writeln(\sprintf('Пространство %s: страниц — %d. Задание %s.', $job->label(), $job->total(), $job->id));
        $progress = $io->createProgressBar($job->total());
        $this->importer->runAll($job, static fn (ConfluenceJob $j) => $progress->setProgress($j->position));
        $progress->finish();
        $io->newLine(2);
        $io->definitionList(...array_map(static fn (string $key, string $label): array => [$label => (string) $job->counters[$key]], array_keys(ConfluenceJob::COUNTER_LABELS), ConfluenceJob::COUNTER_LABELS));
        foreach (array_filter($job->log, static fn (array $l): bool => 'error' === $l['result']) as $line) {
            $io->writeln('<error>Ошибка</error> '.$line['path'].': '.$line['message']);
        }
        if (null !== $job->error) {
            $io->error($job->error);

            return Command::FAILURE;
        }
        $io->success('Импорт завершён.');

        return $job->counters['errors'] > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
