<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\ApiKey;
use App\Repository\ApiKeyRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Ключи REST API из консоли: список, создание, отключение, удаление.
 * То же самое доступно в панели администратора → Интеграции.
 */
#[AsCommand(name: 'app:api-key', description: 'Ключи REST API: list | create <название> [--internal] | disable <id> | enable <id> | delete <id>')]
final class ApiKeyCommand extends Command
{
    public function __construct(private readonly ApiKeyRepository $keys, private readonly EntityManagerInterface $em)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('action', InputArgument::OPTIONAL, 'list | create | disable | enable | delete', 'list')
            ->addArgument('value', InputArgument::OPTIONAL, 'Название ключа (create) или id (disable/enable/delete)')
            ->addOption('internal', null, InputOption::VALUE_NONE, 'Ключ видит и внутренние документы (по умолчанию — только открытые)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $action = (string) $input->getArgument('action');
        $value = (string) $input->getArgument('value');

        switch ($action) {
            case 'list':
                $rows = [];
                foreach ($this->keys->findAllOrdered() as $key) {
                    $rows[] = [$key->getId(), $key->getName(), $key->getTokenPrefix().'…', $key->isIncludeInternal() ? 'все' : 'открытые', $key->isEnabled() ? 'да' : 'нет', $key->getRequestCount(), $key->getLastUsedAt()?->format('d.m.Y H:i') ?? '—', $key->getLastUsedIp() ?? '—'];
                }
                if ([] === $rows) {
                    $io->note('Ключей API нет. Создайте: app:api-key create "Индексатор RAG" [--internal]');
                } else {
                    $io->table(['ID', 'Название', 'Ключ', 'Документы', 'Активен', 'Запросов', 'Последний запрос', 'IP'], $rows);
                }

                return Command::SUCCESS;

            case 'create':
                if ('' === trim($value)) {
                    $io->error('Укажите название ключа: app:api-key create "Индексатор RAG"');

                    return Command::INVALID;
                }
                $token = ApiKey::generateToken();
                $key = new ApiKey($value, $token);
                $key->setIncludeInternal((bool) $input->getOption('internal'));
                $this->em->persist($key);
                $this->em->flush();
                $io->success(\sprintf('Ключ «%s» создан (id %d, документы: %s).', $key->getName(), $key->getId(), $key->isIncludeInternal() ? 'все, включая внутренние' : 'только открытые'));
                $io->writeln('Скопируйте ключ сейчас — повторно он не показывается:');
                $io->writeln('');
                $io->writeln('  <info>'.$token.'</info>');
                $io->writeln('');
                $io->writeln('Пример запроса: curl -H "Authorization: Bearer '.$token.'" https://портал/api/v1/documents');

                return Command::SUCCESS;

            case 'disable':
            case 'enable':
            case 'delete':
                $key = ctype_digit($value) ? $this->keys->find((int) $value) : null;
                if (null === $key) {
                    $io->error('Укажите id существующего ключа (см. app:api-key list).');

                    return Command::INVALID;
                }
                if ('delete' === $action) {
                    $this->em->remove($key);
                    $this->em->flush();
                    $io->success(\sprintf('Ключ «%s» удалён.', $key->getName()));
                } else {
                    $key->setEnabled('enable' === $action);
                    $this->em->flush();
                    $io->success(\sprintf('Ключ «%s» %s.', $key->getName(), $key->isEnabled() ? 'включён' : 'отключён'));
                }

                return Command::SUCCESS;

            default:
                $io->error('Неизвестное действие. Допустимо: list, create, disable, enable, delete.');

                return Command::INVALID;
        }
    }
}
