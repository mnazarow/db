<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\UserRepository;
use App\Service\UserManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:user:password', description: 'Сменить пароль пользователя (или разблокировать / выдать права администратора)')]
final class UserPasswordCommand extends Command
{
    public function __construct(private readonly UserRepository $users, private readonly UserManager $userManager)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('username', InputArgument::REQUIRED, 'Логин')
            ->addOption('password', 'p', InputOption::VALUE_REQUIRED, 'Новый пароль (иначе запрашивается или генерируется)')
            ->addOption('generate', 'g', InputOption::VALUE_NONE, 'Сгенерировать пароль')
            ->addOption('admin', null, InputOption::VALUE_NONE, 'Выдать права администратора')
            ->addOption('activate', null, InputOption::VALUE_NONE, 'Разблокировать учётную запись')
            ->addOption('must-change', null, InputOption::VALUE_NONE, 'Потребовать смену пароля при входе');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $user = $this->users->findOneByUsername((string) $input->getArgument('username'));
        if (null === $user) {
            $io->error('Пользователь не найден.');

            return Command::FAILURE;
        }
        if ($input->getOption('admin')) {
            $user->setAdmin(true);
        }
        if ($input->getOption('activate')) {
            $user->setActive(true);
        }
        $generated = false;
        if ($user->isLdap()) {
            $io->note('Доменная учётная запись: пароль меняется в Active Directory, здесь обновляются только роли/активность.');
        } else {
            $password = $input->getOption('password');
            if (!\is_string($password) || '' === $password) {
                if ($input->getOption('generate') || !$input->isInteractive()) {
                    $password = UserCreateCommand::generatePassword();
                    $generated = true;
                } else {
                    $password = (string) $io->askHidden('Новый пароль');
                }
            }
            try {
                $this->userManager->setPassword($user, $password, (bool) $input->getOption('must-change'));
            } catch (\InvalidArgumentException $e) {
                $io->error($e->getMessage());

                return Command::FAILURE;
            }
        }
        $this->userManager->save($user);
        $io->success(\sprintf('Учётная запись «%s» обновлена.', $user->getUsername()));
        if ($generated) {
            $io->writeln('Пароль: <comment>'.$password.'</comment>');
        }

        return Command::SUCCESS;
    }
}
