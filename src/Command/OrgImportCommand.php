<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\UserRepository;
use App\Service\Org\OrgImporter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Загрузка оргструктуры из выгрузки кадровой системы (1С:ЗУП и др.) — для регулярного запуска по расписанию:
 *   docportal console app:org:import /srv/hr/staff.csv --deactivate
 * Код возврата: 0 — загружено, 1 — файл не прочитан, 2 — загружено, но есть строки с проблемами.
 */
#[AsCommand(name: 'app:org:import', description: 'Загрузить оргструктуру (подразделения, должности, руководителей) из CSV или Excel')]
final class OrgImportCommand extends Command
{
    public function __construct(
        private readonly OrgImporter $importer,
        private readonly UserRepository $users,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('file', InputArgument::REQUIRED, 'Файл .csv или .xlsx (выгрузка из 1С:ЗУП или другой кадровой системы)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Показать, что изменится, ничего не сохраняя')
            ->addOption('create', null, InputOption::VALUE_NONE, 'Создать учётные записи для сотрудников с логином, которых нет в портале')
            ->addOption('no-deactivate', null, InputOption::VALUE_NONE, 'Не блокировать уволенных')
            ->addOption('names', null, InputOption::VALUE_NONE, 'Заменить ФИО данными из файла (по умолчанию ФИО заполняется, только если пустое)')
            ->addOption('user', 'u', InputOption::VALUE_REQUIRED, 'От чьего имени записать изменения в журнал', 'admin')
            ->setHelp(<<<'HELP'
                Столбцы узнаются по заголовкам: Логин, Почта, ФИО (Сотрудник), Подразделение, Должность,
                Руководитель (логин, почта, табельный номер или ФИО), Табельный номер, Дата увольнения (Уволен, Состояние).
                Сотрудник находится по логину, почте, табельному номеру или ФИО. CSV — в UTF-8 или Windows-1251,
                разделитель «;», «,» или табуляция.
                HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $file = (string) $input->getArgument('file');
        if (!is_file($file) || !is_readable($file)) {
            $io->error('Файл не найден или недоступен для чтения: '.$file);

            return Command::FAILURE;
        }
        $actor = $this->users->findOneByUsername((string) $input->getOption('user'));
        try {
            $table = $this->importer->read($file, basename($file));
            $report = $this->importer->import($table, [
                'create' => (bool) $input->getOption('create'),
                'deactivate' => !$input->getOption('no-deactivate'),
                'names' => (bool) $input->getOption('names'),
            ], $actor, (bool) $input->getOption('dry-run'));
        } catch (\Throwable $e) {
            $io->error('Оргструктура не загружена: '.$e->getMessage());

            return Command::FAILURE;
        }

        $io->writeln('Столбцы: '.implode(', ', $report['columns']));
        foreach ($report['changes'] as $change) {
            $fields = [];
            foreach ($change['fields'] as $label => [$old, $new]) {
                $fields[] = \sprintf('%s: «%s» → «%s»', $label, $old, $new);
            }
            $io->writeln(\sprintf(' %s строка %d, %s: %s', $change['created'] ? '+' : '~', $change['line'], $change['login'], $change['created'] ? 'новая учётная запись' : implode('; ', $fields)));
        }
        foreach ($report['problems'] as $problem) {
            $io->writeln(\sprintf(' <comment>! строка %d: %s</comment>', $problem['line'], $problem['message']));
        }
        $summary = \sprintf('Строк: %d; найдено сотрудников: %d, создано: %d, изменено: %d, без изменений: %d, заблокировано уволенных: %d, назначено руководителей: %d, пропущено строк: %d.',
            $report['rows'], $report['matched'], $report['created'], $report['updated'], $report['unchanged'], $report['deactivated'], $report['managers'], $report['skipped']);
        if ($report['dry_run']) {
            $io->note('Пробный запуск — ничего не сохранено. '.$summary);
        } elseif ([] === $report['problems']) {
            $io->success($summary);
        } else {
            $io->warning($summary.' Есть строки с проблемами — см. выше.');
        }

        return [] === $report['problems'] ? Command::SUCCESS : 2;
    }
}
