<?php

declare(strict_types=1);

namespace App\Command;

use App\Security\Ldap\LdapClient;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:ldap:test', description: 'Проверить подключение к Active Directory / LDAP и (необязательно) вход пользователя')]
final class LdapTestCommand extends Command
{
    public function __construct(private readonly LdapClient $ldap)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('username', InputArgument::OPTIONAL, 'Логин пользователя для проверки входа (пароль будет запрошен)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $settings = $this->ldap->getSettings();
        $io->title('Проверка LDAP');
        $rows = [];
        foreach ($settings->summary() as $k => $v) {
            $rows[] = [$k, \is_bool($v) ? ($v ? 'да' : 'нет') : (string) $v];
        }
        $io->table(['Параметр', 'Значение'], $rows);

        $result = $this->ldap->testConnection();
        $result['ok'] ? $io->success($result['message']) : $io->error($result['message']);
        if (!$result['ok']) {
            return Command::FAILURE;
        }

        $username = $input->getArgument('username');
        if (\is_string($username) && '' !== $username) {
            $password = (string) $io->askHidden('Пароль пользователя '.$username);
            try {
                $info = $this->ldap->authenticate($username, $password);
                $io->success(\sprintf('Вход выполнен: %s <%s>, DN: %s, администратор: %s', $info->displayName, $info->email ?? '—', $info->dn, $info->isAdmin ? 'да' : 'нет'));
                if ([] !== $info->groups) {
                    $io->writeln('Группы: '.implode('; ', \array_slice($info->groups, 0, 20)));
                }
            } catch (\Throwable $e) {
                $io->error('Вход не выполнен: '.$e->getMessage());

                return Command::FAILURE;
            }
        }

        return Command::SUCCESS;
    }
}
