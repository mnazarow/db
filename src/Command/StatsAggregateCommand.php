<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\StatsAggregator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Ежедневные итоги статистики (cron после полуночи): журнал событий сводится по дням и за периоды страницы
 * «Статистика», чтобы она открывалась быстро и на крупном портале.
 */
#[AsCommand(name: 'app:stats:aggregate', description: 'Пересчитать ежедневные итоги статистики по журналу событий')]
final class StatsAggregateCommand extends Command
{
    public function __construct(private readonly StatsAggregator $aggregator)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('status', null, InputOption::VALUE_NONE, 'Только показать, посчитаны ли итоги на сегодня');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        if ($input->getOption('status')) {
            $io->writeln($this->aggregator->isFresh() ? 'Итоги статистики посчитаны на сегодня.' : 'Итоги статистики устарели — статистика считается по журналу событий до пересчёта.');

            return Command::SUCCESS;
        }
        $result = $this->aggregator->refresh();
        if ('busy' === $result['status']) {
            $io->error('Итоги уже пересчитываются другим процессом дольше минуты.');

            return Command::FAILURE;
        }
        $io->success(\sprintf('Итоги статистики пересчитаны за %.1f с: дней и типов событий — %d, строк по документам — %d, по сотрудникам — %d.',
            $result['seconds'], $result['days'], $result['documents'], $result['users']));

        return Command::SUCCESS;
    }
}
