<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\UserRepository;
use App\Service\Org\TeamDigest;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Еженедельная сводка руководителям: кто из сотрудников не ознакомился с документами и не посмотрел
 * обязательные видео. Запускается по cron по понедельникам; выключенная сводка ничего не отправляет.
 */
#[AsCommand(name: 'app:team:digest', description: 'Сводка руководителям: кто из сотрудников не ознакомился с документами и не посмотрел обязательные видео')]
final class TeamDigestCommand extends Command
{
    public function __construct(
        private readonly TeamDigest $digest,
        private readonly UserRepository $users,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Показать, кому ушла бы сводка, ничего не отправляя')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Отправить сейчас, даже если сводка выключена или уже отправлялась на этой неделе')
            ->addOption('manager', null, InputOption::VALUE_REQUIRED, 'Только этому руководителю (логин) — например, чтобы проверить письмо');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $only = null;
        if (null !== ($login = $input->getOption('manager'))) {
            $only = $this->users->findOneByUsername((string) $login);
            if (null === $only) {
                $io->error(\sprintf('Сотрудник «%s» не найден.', $login));

                return Command::FAILURE;
            }
        }
        $dryRun = (bool) $input->getOption('dry-run');
        $result = $this->digest->run($dryRun, (bool) $input->getOption('force') || null !== $only, $only);
        if ('disabled' === $result['status'] || 'skipped' === $result['status']) {
            $io->note($result['message']);

            return Command::SUCCESS;
        }
        if ('error' === $result['status']) {
            $io->error($result['message']);

            return Command::FAILURE;
        }
        if ($dryRun && [] !== $result['plan']) {
            $io->table(['Руководитель', 'Сотрудников', 'Не выполнено', 'Просрочено'], array_map(
                static fn (array $p): array => [$p['user']->getDisplayName().' ('.$p['user']->getUsername().')', $p['people'], $p['pending'], $p['overdue']],
                $result['plan'],
            ));
        }
        $io->success($result['message']);

        return Command::SUCCESS;
    }
}
