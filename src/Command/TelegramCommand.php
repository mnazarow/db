<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\UserRepository;
use App\Service\Notification\TelegramNotifier;
use App\Service\PortalSettings;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Обслуживание уведомлений в Telegram.
 *
 * Основной режим — разбор входящих сообщений бота (getUpdates): так сотрудники привязывают свои
 * чаты по коду из профиля. Команду ставят в cron раз в минуту; открывать порт наружу под webhook
 * не требуется, все обращения исходящие.
 */
#[AsCommand(name: 'app:telegram:poll', description: 'Разобрать сообщения бота Telegram (привязка чатов) и проверить связь')]
final class TelegramCommand extends Command
{
    public function __construct(
        private readonly TelegramNotifier $telegram,
        private readonly PortalSettings $settings,
        private readonly UserRepository $users,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('check', null, InputOption::VALUE_NONE, 'Проверить токен бота и отправить сообщение в чат администратора')
            ->addOption('send', null, InputOption::VALUE_REQUIRED, 'Отправить произвольное сообщение (с --chat или --user)')
            ->addOption('chat', null, InputOption::VALUE_REQUIRED, 'Идентификатор чата для --send')
            ->addOption('user', null, InputOption::VALUE_REQUIRED, 'Логин сотрудника для --send')
            ->addOption('status', null, InputOption::VALUE_NONE, 'Показать настройки и число привязанных чатов')
            ->addOption('limit', 'l', InputOption::VALUE_REQUIRED, 'Сколько сообщений разбирать за раз', '50');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $telegram = $this->settings->telegram();

        if ($input->getOption('status')) {
            $linked = $this->users->findWithTelegram();
            $io->definitionList(
                ['Уведомления в Telegram' => $telegram['enabled'] ? 'включены' : 'выключены'],
                ['Токен бота' => '' !== $telegram['token'] ? PortalSettings::mask($telegram['token']) : '— не задан'],
                ['Имя бота' => '' !== $telegram['bot_name'] ? '@'.$telegram['bot_name'] : '—'],
                ['Адрес Bot API' => $telegram['api_url']],
                ['Чат администратора' => '' !== $telegram['admin_chat'] ? $telegram['admin_chat'] : '—'],
                ['Привязанных чатов' => \count($linked)],
            );
            foreach ($linked as $user) {
                $io->writeln(\sprintf(' • %s (%s) — чат %s', $user->getDisplayName(), $user->getUsername(), (string) $user->getTelegramChatId()));
            }

            return Command::SUCCESS;
        }

        if ($input->getOption('check')) {
            $result = $this->telegram->check();
            $result['ok'] ? $io->success($result['message']) : $io->error($result['message']);

            return $result['ok'] ? Command::SUCCESS : Command::FAILURE;
        }

        $text = (string) $input->getOption('send');
        if ('' !== $text) {
            $chat = trim((string) $input->getOption('chat'));
            $login = trim((string) $input->getOption('user'));
            if ('' !== $login) {
                $user = $this->users->findOneByUsername($login);
                if (null === $user || !$user->hasTelegram()) {
                    $io->error('У сотрудника нет привязанного чата Telegram.');

                    return Command::FAILURE;
                }
                $chat = (string) $user->getTelegramChatId();
            }
            if ('' === $chat) {
                $io->error('Укажите --chat или --user.');

                return Command::FAILURE;
            }
            $result = $this->telegram->send($chat, $text, true);
            $result['ok'] ? $io->success($result['message']) : $io->error($result['message']);

            return $result['ok'] ? Command::SUCCESS : Command::FAILURE;
        }

        $stats = $this->telegram->poll(max(1, (int) $input->getOption('limit')));
        foreach ($stats['messages'] as $message) {
            $io->warning($message);
        }
        $io->writeln(\sprintf('Разобрано сообщений: %d, привязано чатов: %d, ошибок: %d.', $stats['updates'], $stats['linked'], $stats['errors']));

        return $stats['errors'] > 0 && 0 === $stats['updates'] ? Command::FAILURE : Command::SUCCESS;
    }
}
