<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\UserManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[AsCommand(
    name: 'app:user:create',
    description: 'Создать пользователя (например, первого администратора)',
)]
final class UserCreateCommand extends Command
{
    public function __construct(
        private readonly UserManager $userManager,
        private readonly UserRepository $users,
        private readonly ValidatorInterface $validator,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('username', InputArgument::REQUIRED, 'Логин (латиница, цифры, . _ - @)')
            ->addOption('password', 'p', InputOption::VALUE_REQUIRED, 'Пароль (если не указан — берётся из переменной окружения ADMIN_PASSWORD, запрашивается или генерируется с --generate)')
            ->addOption('generate', 'g', InputOption::VALUE_NONE, 'Сгенерировать случайный пароль и вывести его')
            ->addOption('name', null, InputOption::VALUE_REQUIRED, 'Отображаемое имя')
            ->addOption('email', null, InputOption::VALUE_REQUIRED, 'E-mail (необязательно)')
            ->addOption('admin', 'a', InputOption::VALUE_NONE, 'Выдать права администратора (то же, что --role=admin)')
            ->addOption('role', 'r', InputOption::VALUE_REQUIRED, 'Роль: user (пользователь), assistant (помощник маркетолога), marketer (маркетолог), admin (администратор)')
            ->addOption('ldap', null, InputOption::VALUE_NONE, 'Доменная учётная запись (пароль проверяется в Active Directory)')
            ->addOption('must-change', null, InputOption::VALUE_NONE, 'Потребовать смену пароля при первом входе')
            ->addOption('if-not-exists', null, InputOption::VALUE_NONE, 'Не считать ошибкой, если пользователь уже существует (для скриптов)')
            ->setHelp(<<<'HELP'
Примеры:

  <info>php bin/console app:user:create admin --admin --generate</info>
      создать администратора со случайным паролем (пароль будет напечатан)

  <info>php bin/console app:user:create ivanov --name "Иванов И.И." --password 'Secret123'</info>
      создать обычного пользователя с заданным паролем

  <info>php bin/console app:user:create petrov --ldap --name "Петров П.П." --admin</info>
      добавить доменную учётную запись (пароль проверит домен) и сразу сделать её администратором

  <info>php bin/console app:user:create markova --name "Маркова А.В." --role=marketer --generate</info>
      маркетолог (модератор всех разделов и видео); помощник маркетолога — --role=assistant

  <info>php bin/console app:user:create admin --admin --password "$ADMIN_PASSWORD" --if-not-exists</info>
      идемпотентное создание из скрипта установки
HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $username = User::normalizeUsername((string) $input->getArgument('username'));

        if (null !== $this->users->findOneByUsername($username)) {
            if ($input->getOption('if-not-exists')) {
                $io->note(\sprintf('Пользователь «%s» уже существует — пропускаем.', $username));

                return Command::SUCCESS;
            }
            $io->error(\sprintf('Пользователь «%s» уже существует.', $username));

            return Command::FAILURE;
        }

        $position = $input->getOption('admin') ? User::POSITION_ADMIN : (string) ($input->getOption('role') ?? User::POSITION_USER);
        if (!\in_array($position, User::POSITIONS, true)) {
            $io->error(\sprintf('Неизвестная роль «%s». Допустимо: %s.', $position, implode(', ', User::POSITIONS)));

            return Command::FAILURE;
        }
        $isAdmin = User::POSITION_ADMIN === $position;
        $label = mb_strtolower(User::POSITION_LABELS[$position]);
        $name = (string) ($input->getOption('name') ?: $username);
        $user = (new User())->setUsername($username)->setDisplayName($name)->setEmail($input->getOption('email'))->setAdmin($isAdmin);
        $violations = $this->validator->validate($user);
        if (\count($violations) > 0) {
            foreach ($violations as $violation) {
                $io->error($violation->getPropertyPath().': '.$violation->getMessage());
            }

            return Command::FAILURE;
        }

        if ($input->getOption('ldap')) {
            $created = $this->userManager->createLdapUser($username, $name, $isAdmin, $input->getOption('email'));
            if (User::POSITION_USER !== $position && !$isAdmin) {
                $this->userManager->save($created->setPosition($position));
            }
            $io->success(\sprintf('Доменная учётная запись «%s» добавлена (%s).', $username, $label));

            return Command::SUCCESS;
        }

        $password = $input->getOption('password');
        if ((!\is_string($password) || '' === $password) && \is_string($env = getenv('ADMIN_PASSWORD')) && '' !== $env) {
            $password = $env;
        }
        $generated = false;
        if (!\is_string($password) || '' === $password) {
            if ($input->getOption('generate') || !$input->isInteractive()) {
                $password = self::generatePassword();
                $generated = true;
            } else {
                $password = (string) $io->askHidden('Пароль', static function (?string $value): string {
                    if (null === $value || '' === trim($value)) {
                        throw new \RuntimeException('Пароль не может быть пустым.');
                    }

                    return $value;
                });
            }
        }

        try {
            $created = $this->userManager->create($username, $name, $password, $isAdmin, $input->getOption('email'), (bool) $input->getOption('must-change'));
            if (User::POSITION_USER !== $position && !$isAdmin) {
                $this->userManager->save($created->setPosition($position));
            }
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        } catch (\Throwable $e) {
            $io->error('Не удалось создать пользователя: '.$e->getMessage());

            return Command::FAILURE;
        }

        $io->success(\sprintf('Пользователь «%s» создан (%s).', $username, $label));
        if ($generated) {
            $io->writeln('Пароль: <comment>'.$password.'</comment>');
            $io->writeln('Сохраните его — повторно он не показывается. Сменить: <info>php bin/console app:user:password '.$username.'</info>');
        }

        return Command::SUCCESS;
    }

    public static function generatePassword(int $length = 14): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';
        $password = '';
        $max = \strlen($alphabet) - 1;
        for ($i = 0; $i < $length; ++$i) {
            $password .= $alphabet[random_int(0, $max)];
        }

        return $password;
    }
}
