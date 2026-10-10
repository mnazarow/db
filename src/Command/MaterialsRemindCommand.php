<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Material\MaterialReminder;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Еженедельная сводка по таблицам материалов (cron: понедельник, 09:10). Ничего не делает, пока сводка
 * выключена в панели администратора → Материалы.
 */
#[AsCommand(name: 'app:materials:remind', description: 'Сводка по таблицам материалов: материалы в состояниях «Не соответствует», «Отсутствует»… — модераторам разделов')]
final class MaterialsRemindCommand extends Command
{
    public function __construct(private readonly MaterialReminder $reminder)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Показать, кому и что было бы отправлено, ничего не отправляя')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Отправить сейчас, даже если сводка выключена или уже отправлялась на этой неделе');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');
        $result = $this->reminder->run($dryRun, (bool) $input->getOption('force'));
        if ($dryRun && [] !== $result['plan']) {
            $io->table(['Получатель', 'Почта', 'Разделы', 'Материалов'], array_map(static fn (array $p): array => [
                $p['user']->getDisplayName(),
                (string) $p['user']->getEmail(),
                implode(', ', $p['sections']),
                $p['rows'],
            ], $result['plan']));
        }
        $io->writeln($result['message']);

        return Command::SUCCESS;
    }
}
