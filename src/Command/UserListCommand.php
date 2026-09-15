<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\User;
use App\Repository\SectionModeratorRepository;
use App\Repository\UserRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:user:list', description: 'Список пользователей')]
final class UserListCommand extends Command
{
    public function __construct(private readonly UserRepository $users, private readonly SectionModeratorRepository $moderators)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $moderated = [];
        foreach ($this->moderators->findAllWithRelations() as $m) {
            $moderated[$m->getUser()->getId()][] = $m->getSection()->getName();
        }
        $rows = [];
        foreach ($this->users->findAllOrdered() as $u) {
            $rows[] = [$u->getUsername(), $u->getDisplayName(), $u->getEmail() ?? '', User::SOURCE_LDAP === $u->getAuthSource() ? 'домен' : 'локальная', $u->isAdmin() ? 'администратор' : 'пользователь', implode(', ', $moderated[$u->getId()] ?? []), $u->isActive() ? 'да' : 'нет', $u->getLastLoginAt()?->format('d.m.Y H:i') ?? '—'];
        }
        $io->table(['Логин', 'Имя', 'E-mail', 'Источник', 'Роль', 'Модерирует', 'Активна', 'Последний вход'], $rows);

        return Command::SUCCESS;
    }
}
