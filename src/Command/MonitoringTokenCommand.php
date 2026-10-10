<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\PortalSettings;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Токен мониторинга для /health/details и /metrics — то же, что кнопка в панели администратора → Мониторинг,
 * для автоматической настройки сервера (Ansible и т. п.).
 */
#[AsCommand(name: 'app:monitoring:token', description: 'Показать, создать или удалить токен мониторинга (/health/details, /metrics)')]
final class MonitoringTokenCommand extends Command
{
    public function __construct(private readonly PortalSettings $settings)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('regenerate', null, InputOption::VALUE_NONE, 'Создать новый токен (прежний перестанет действовать)')
            ->addOption('delete', null, InputOption::VALUE_NONE, 'Удалить токен (отключить подробный мониторинг)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($input->getOption('delete')) {
            $this->settings->set(PortalSettings::MONITORING_TOKEN, '');
            $output->writeln('Токен мониторинга удалён.');

            return Command::SUCCESS;
        }
        $token = $this->settings->monitoringToken();
        if ($input->getOption('regenerate') || '' === $token) {
            $token = $this->settings->regenerateMonitoringToken();
        }
        $output->writeln($token);

        return Command::SUCCESS;
    }
}
