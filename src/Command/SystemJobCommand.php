<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\SystemJob;
use App\Service\Monitoring\SystemJobRecorder;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Отметка о запуске задания, которое выполняется не командой портала, а скриптом на сервере
 * (резервная копия, копия во внешнем хранилище, проверка восстановления). Её видит мониторинг.
 */
#[AsCommand(name: 'app:system:job', description: 'Отметить запуск регулярного задания (для скриптов обслуживания)')]
final class SystemJobCommand extends Command
{
    public function __construct(private readonly SystemJobRecorder $recorder)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('name', InputArgument::REQUIRED, 'Имя задания: backup, backup-remote, backup-verify…')
            ->addOption('status', null, InputOption::VALUE_REQUIRED, 'ok, warning, error или running (начало)', SystemJob::STATUS_OK)
            ->addOption('message', null, InputOption::VALUE_REQUIRED, 'Пояснение (имя архива, текст ошибки)')
            ->addOption('duration', null, InputOption::VALUE_REQUIRED, 'Длительность в секундах');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $name = (string) $input->getArgument('name');
        $status = (string) $input->getOption('status');
        if (!SystemJobRecorder::isValidName($name)) {
            $output->writeln('<error>Имя задания: латинские буквы, цифры, «-», «_» и «:».</error>');

            return Command::INVALID;
        }
        if (!\in_array($status, SystemJobRecorder::STATUSES, true)) {
            $output->writeln('<error>Статус: '.implode(', ', SystemJobRecorder::STATUSES).'.</error>');

            return Command::INVALID;
        }
        if (SystemJob::STATUS_RUNNING === $status) {
            $this->recorder->start($name);
        } else {
            $duration = $input->getOption('duration');
            $this->recorder->finish($name, $status, $input->getOption('message'), null === $duration ? null : (int) round((float) $duration * 1000));
        }

        return Command::SUCCESS;
    }
}
