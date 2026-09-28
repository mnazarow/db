<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\UserRepository;
use App\Service\Material\MaterialExporter;
use App\Service\Material\MaterialImporter;
use App\Service\Material\MaterialSummary;
use App\Service\Xlsx\XlsxReader;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Импорт таблиц материалов из книги Excel (вида «Продуктовые материалы») и выгрузка обратно.
 */
#[AsCommand(name: 'app:materials:import', description: 'Импортировать таблицы материалов из Excel (.xlsx) или выгрузить их в Excel')]
final class MaterialsImportCommand extends Command
{
    public function __construct(
        private readonly XlsxReader $reader,
        private readonly MaterialImporter $importer,
        private readonly MaterialExporter $exporter,
        private readonly MaterialSummary $summary,
        private readonly UserRepository $users,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('file', InputArgument::REQUIRED, 'Файл .xlsx для импорта (или для записи с --export)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Показать, что изменится, ничего не сохраняя')
            ->addOption('delete-missing', null, InputOption::VALUE_NONE, 'Удалить строки, которых нет в файле')
            ->addOption('user', 'u', InputOption::VALUE_REQUIRED, 'От чьего имени записать изменения в журнал', 'admin')
            ->addOption('export', null, InputOption::VALUE_NONE, 'Не импортировать, а выгрузить все таблицы в указанный файл');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $file = (string) $input->getArgument('file');

        if ($input->getOption('export')) {
            $sections = $this->summary->categories();
            $this->exporter->build($sections, $file);
            $io->success(\sprintf('Выгружено разделов: %d → %s', \count($sections), $file));

            return Command::SUCCESS;
        }

        if (!is_file($file)) {
            $io->error('Файл не найден: '.$file);

            return Command::FAILURE;
        }
        try {
            $sheets = $this->reader->read($file);
        } catch (\RuntimeException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }
        $dryRun = (bool) $input->getOption('dry-run');
        $deleteMissing = (bool) $input->getOption('delete-missing');
        $user = $this->users->findOneByUsername((string) $input->getOption('user'));
        if (null === $user && !$dryRun) {
            $io->warning(\sprintf('Пользователь «%s» не найден — изменения запишутся в журнал без автора.', (string) $input->getOption('user')));
        }
        $report = $dryRun ? $this->importer->plan($sheets, $deleteMissing) : $this->importer->apply($sheets, $deleteMissing, $user);

        $io->title($dryRun ? 'План импорта (ничего не сохранено)' : 'Импорт выполнен');
        $headers = $dryRun
            ? ['Лист / раздел', 'Раздел', 'Строк', 'Добавится', 'Изменится', 'Без изменений', 'Удалится', 'Новые столбцы']
            : ['Лист / раздел', 'Раздел', 'Строк', 'Добавлено', 'Изменено', 'Без изменений', 'Удалено', 'Новые столбцы'];
        $io->table($headers, array_map(static fn (array $s): array => [
            $s['name'], $s['sectionCreated'] ? 'новый' : 'есть', $s['rows'], $s['created'], $s['updated'], $s['unchanged'], $s['deleted'], implode(', ', $s['parametersCreated']) ?: '—',
        ], $report['sheets']));
        foreach ($report['skipped'] as $skipped) {
            $io->writeln(\sprintf(' Пропущен лист «%s»: %s', $skipped['name'], $skipped['reason']));
        }
        if ([] !== $report['kindsCreated']) {
            $io->writeln(' Новые типы файлов: '.implode(', ', $report['kindsCreated']));
        }
        foreach ($report['warnings'] as $warning) {
            $io->warning($warning);
        }
        $totals = $report['totals'];
        if ($dryRun) {
            $io->note(\sprintf('Будет создано разделов: %d; строк добавится %d, изменится %d, без изменений %d, удалится %d. Чтобы выполнить импорт, запустите команду без --dry-run.', $totals['sectionsCreated'], $totals['created'], $totals['updated'], $totals['unchanged'], $totals['deleted']));
        } else {
            $io->success(\sprintf('Разделов создано: %d, строк: новых %d, обновлено %d, без изменений %d, удалено %d.', $totals['sectionsCreated'], $totals['created'], $totals['updated'], $totals['unchanged'], $totals['deleted']));
        }

        return Command::SUCCESS;
    }
}
