<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\ExpiryNotifier;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:documents:expiry', description: 'Проверить сроки актуальности документов и разослать уведомления ответственным (запускается по cron ежедневно)')]
final class ExpiryCommand extends Command
{
    public function __construct(private readonly ExpiryNotifier $notifier)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('force', null, InputOption::VALUE_NONE, 'Отправить уведомления заново, даже если они уже отправлялись')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Только показать, что было бы отправлено, ничего не менять')
            ->setHelp('Документ уведомляется один раз, когда срок попадает в окно EXPIRY_SOON_DAYS, и один раз после истечения срока. Стадия сбрасывается при изменении даты «Актуален до».');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $stats = $this->notifier->run((bool) $input->getOption('force'), (bool) $input->getOption('dry-run'));
        $io->table(['Показатель', 'Значение'], [
            ['Документов в окне контроля', $stats['checked']],
            ['— истекает', $stats['soon']],
            ['— просрочено', $stats['expired']],
            ['Уведомлений сформировано', $stats['notified']],
            ['Пропущено (уже уведомлены)', $stats['skipped']],
            ['Писем отправлено', $stats['emails']],
            ['Получатели', implode(', ', $stats['recipients']) ?: '—'],
        ]);
        if ($input->getOption('dry-run')) {
            $io->note('Режим проверки: письма не отправлялись, стадии не менялись.');
        }

        return Command::SUCCESS;
    }
}
