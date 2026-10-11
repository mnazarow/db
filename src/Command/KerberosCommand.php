<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\PortalSettings;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Настройка входа через Windows (Kerberos) из консоли — её вызывает «docportal kerberos on|off» после настройки nginx.
 */
#[AsCommand(name: 'app:kerberos', description: 'Вход через Windows (Kerberos): включить, выключить, показать настройки')]
final class KerberosCommand extends Command
{
    public function __construct(private readonly PortalSettings $settings)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('enable', null, InputOption::VALUE_NONE, 'Включить вход через Windows (учётная запись — из REMOTE_USER от nginx)')
            ->addOption('disable', null, InputOption::VALUE_NONE, 'Выключить вход через Windows')
            ->addOption('realm', null, InputOption::VALUE_REQUIRED, 'Допустимые домены (области Kerberos) через запятую')
            ->addOption('auto', null, InputOption::VALUE_REQUIRED, 'Входить сразу при открытии страницы входа: 1 или 0');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $current = $this->settings->kerberos();
        if ($input->getOption('enable') || $input->getOption('disable')) {
            $values = $current;
            $values['enabled'] = (bool) $input->getOption('enable');
            if ($input->getOption('enable')) {
                $values['source'] = 'remote_user';
            }
            if (null !== $input->getOption('realm')) {
                $values['realms'] = (string) $input->getOption('realm');
            }
            if (null !== $input->getOption('auto')) {
                $values['auto'] = filter_var($input->getOption('auto'), \FILTER_VALIDATE_BOOL);
            }
            try {
                $this->settings->setKerberos($values);
            } catch (\InvalidArgumentException $e) {
                $io->error($e->getMessage());

                return Command::FAILURE;
            }
            $current = $this->settings->kerberos();
        }
        $io->definitionList(
            ['Вход через Windows' => $current['enabled'] ? 'включён' : 'выключен'],
            ['Учётная запись' => 'header' === $current['source'] ? 'заголовок '.$current['header'].' от '.($current['trusted_proxies'] ?: '— (не задан доверенный прокси)') : 'REMOTE_USER от nginx'],
            ['Домены' => $current['realms'] ?: 'любые'],
            ['Сразу при открытии страницы входа' => $current['auto'] ? 'да' : 'нет'],
            ['Заводить учётные записи' => $current['create_users'] ? 'да' : 'нет'],
        );

        return Command::SUCCESS;
    }
}
