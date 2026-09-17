<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\AcknowledgementService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Напоминания сотрудникам о неподтверждённых ознакомлениях (по одному письму на сотрудника).
 * Запускается по cron ежедневно; повторно одному человеку не чаще, чем раз в несколько дней.
 */
#[AsCommand(name: 'app:documents:acknowledge-remind', description: 'Напомнить сотрудникам о документах, с которыми они не ознакомились')]
final class AcknowledgeRemindCommand extends Command
{
    public function __construct(private readonly AcknowledgementService $service)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('days', 'd', InputOption::VALUE_REQUIRED, 'За сколько дней до срока напоминать', '3')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Показать, кому ушли бы письма, ничего не отправляя');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $stats = $this->service->remind(max(0, (int) $input->getOption('days')), (bool) $input->getOption('dry-run'));
        if ($input->getOption('dry-run')) {
            $io->note(\sprintf('Режим --dry-run: писем не отправлено. Ожидают ознакомления: %d записей у %d сотрудников.', $stats['items'], $stats['users']));

            return Command::SUCCESS;
        }
        $io->success(\sprintf('Напоминания об ознакомлении: сотрудников %d, документов %d, отправлено писем %d.', $stats['users'], $stats['items'], $stats['emails']));

        return Command::SUCCESS;
    }
}
