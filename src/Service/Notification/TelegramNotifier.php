<?php

declare(strict_types=1);

namespace App\Service\Notification;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\Http\HttpTransportInterface;
use App\Service\PortalSettings;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Уведомления в Telegram через бота компании.
 *
 * Портал только отправляет исходящие запросы к Bot API и сам забирает сообщения методом getUpdates
 * (консольная команда app:telegram:poll по расписанию) — открывать порт наружу под webhook не нужно,
 * это важно для закрытого контура. Адрес Bot API настраивается: в изолированной сети его заменяют
 * на внутренний прокси или локальный Bot API Server.
 *
 * Привязка чата: сотрудник получает в профиле одноразовый код и отправляет его боту; команда разбора
 * сообщений находит код, привязывает chat_id к учётной записи и отвечает подтверждением.
 */
final class TelegramNotifier
{
    /** Сколько живёт код привязки. */
    public const CODE_TTL_MINUTES = 30;

    /** Ограничение Telegram на длину сообщения. */
    private const MAX_MESSAGE = 4000;

    public function __construct(
        private readonly HttpTransportInterface $http,
        private readonly PortalSettings $settings,
        private readonly UserRepository $users,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $auditLogger,
        private readonly int $timeoutSeconds = 15,
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->settings->isTelegramEnabled();
    }

    /** Ссылка на бота для привязки: https://t.me/имя_бота?start=КОД */
    public function linkUrl(string $code): ?string
    {
        $bot = $this->settings->telegram()['bot_name'];

        return '' !== $bot ? \sprintf('https://t.me/%s?start=%s', $bot, rawurlencode($code)) : null;
    }

    /** Выдаёт сотруднику новый код привязки (старый перестаёт действовать). */
    public function issueCode(User $user): string
    {
        $code = strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
        $user->setTelegramCode($code);
        $this->em->flush();

        return $code;
    }

    /**
     * Отправка сообщения в чат.
     *
     * @return array{ok: bool, message: string}
     */
    public function send(string $chatId, string $text, bool $force = false): array
    {
        $telegram = $this->settings->telegram();
        if ('' === $telegram['token'] || (!$force && !$telegram['enabled'])) {
            return ['ok' => false, 'message' => 'Уведомления в Telegram выключены.'];
        }
        if ('' === trim($chatId)) {
            return ['ok' => false, 'message' => 'Не указан чат получателя.'];
        }
        $response = $this->call('sendMessage', [
            'chat_id' => $chatId,
            'text' => mb_substr($text, 0, self::MAX_MESSAGE),
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => true,
        ], $force);
        if ($response['ok']) {
            return ['ok' => true, 'message' => 'Сообщение отправлено.'];
        }
        $this->auditLogger->warning('Ошибка отправки в Telegram', ['chat' => $chatId, 'error' => $response['message']]);

        return $response;
    }

    /** Сообщение сотруднику, если он привязал Telegram и не отключил уведомления. */
    public function notify(User $user, string $text): bool
    {
        if (!$user->hasTelegram() || !$user->isNotifyTelegram() || !$this->isEnabled()) {
            return false;
        }

        return $this->send((string) $user->getTelegramChatId(), $text)['ok'];
    }

    /**
     * Проверка настроек: спрашивает у Bot API имя бота и (если задан чат администратора) шлёт туда сообщение.
     *
     * @return array{ok: bool, message: string}
     */
    public function check(): array
    {
        $telegram = $this->settings->telegram();
        if ('' === $telegram['token']) {
            return ['ok' => false, 'message' => 'Не указан токен бота.'];
        }
        $me = $this->call('getMe', [], true);
        if (!$me['ok']) {
            return $me;
        }
        $name = (string) ($me['result']['username'] ?? '');
        if ('' !== $name && $name !== $telegram['bot_name']) {
            $this->settings->set(PortalSettings::TELEGRAM_BOT_NAME, $name);
        }
        if ('' === $telegram['admin_chat']) {
            return ['ok' => true, 'message' => \sprintf('Бот доступен: @%s. Чат для проверки не указан.', $name)];
        }
        $sent = $this->send($telegram['admin_chat'], 'Проверка связи: портал документации подключён к боту @'.$name.'.', true);

        return $sent['ok']
            ? ['ok' => true, 'message' => \sprintf('Бот @%s доступен, проверочное сообщение отправлено.', $name)]
            : ['ok' => false, 'message' => \sprintf('Бот @%s доступен, но сообщение не отправлено: %s', $name, $sent['message'])];
    }

    /**
     * Разбор входящих сообщений: привязка чатов по коду и ответы на команды.
     *
     * @return array{updates: int, linked: int, errors: int, messages: list<string>}
     */
    public function poll(int $limit = 50): array
    {
        $stats = ['updates' => 0, 'linked' => 0, 'errors' => 0, 'messages' => []];
        if (!$this->isEnabled()) {
            $stats['messages'][] = 'Уведомления в Telegram выключены.';

            return $stats;
        }
        $offset = (int) $this->settings->get(PortalSettings::TELEGRAM_OFFSET, 0);
        $response = $this->call('getUpdates', array_filter([
            'offset' => $offset > 0 ? $offset : null,
            'limit' => $limit,
            'timeout' => 0,
            'allowed_updates' => '["message"]',
        ], static fn ($value): bool => null !== $value));
        if (!$response['ok']) {
            ++$stats['errors'];
            $stats['messages'][] = $response['message'];

            return $stats;
        }
        $updates = \is_array($response['result'] ?? null) ? $response['result'] : [];
        $maxId = $offset;
        foreach ($updates as $update) {
            ++$stats['updates'];
            $maxId = max($maxId, (int) ($update['update_id'] ?? 0) + 1);
            $message = $update['message'] ?? null;
            if (!\is_array($message)) {
                continue;
            }
            $chatId = (string) ($message['chat']['id'] ?? '');
            $text = trim((string) ($message['text'] ?? ''));
            if ('' === $chatId || '' === $text) {
                continue;
            }
            $this->handleMessage($chatId, $text, $message, $stats);
        }
        if ($maxId > $offset) {
            $this->settings->set(PortalSettings::TELEGRAM_OFFSET, $maxId);
        }

        return $stats;
    }

    /**
     * @param array<string, mixed>                                                  $message
     * @param array{updates: int, linked: int, errors: int, messages: list<string>} $stats
     */
    private function handleMessage(string $chatId, string $text, array $message, array &$stats): void
    {
        $code = strtoupper(trim(preg_replace('/^\/start\s*/i', '', $text) ?? ''));
        if ('' === $code || '/STOP' === $code) {
            if ('/STOP' === $code) {
                $user = $this->users->findOneBy(['telegramChatId' => $chatId]);
                if (null !== $user) {
                    $user->unlinkTelegram();
                    $this->em->flush();
                    $this->send($chatId, 'Уведомления отключены. Чтобы снова получать сообщения, привяжите чат в профиле портала.');
                    $this->auditLogger->info('Отвязан Telegram', ['user' => $user->getUsername(), 'chat' => $chatId]);

                    return;
                }
            }
            $this->send($chatId, 'Отправьте код привязки из профиля портала документации.');

            return;
        }
        $user = $this->users->findOneByTelegramCode($code);
        if (null === $user || !self::codeIsFresh($user)) {
            ++$stats['errors'];
            $this->send($chatId, 'Код не найден или устарел. Получите новый код в профиле портала.');

            return;
        }
        $name = trim(((string) ($message['from']['first_name'] ?? '')).' '.((string) ($message['from']['last_name'] ?? '')));
        $user->linkTelegram($chatId, '' !== $name ? $name : (string) ($message['from']['username'] ?? ''));
        $this->em->flush();
        ++$stats['linked'];
        $this->send($chatId, \sprintf('Готово, %s. Уведомления портала документации будут приходить сюда. Команда /stop отключает их.', htmlspecialchars($user->getDisplayName(), \ENT_QUOTES)));
        $this->auditLogger->info('Привязан Telegram', ['user' => $user->getUsername(), 'chat' => $chatId]);
    }

    public static function codeIsFresh(User $user): bool
    {
        $issued = $user->getTelegramCodeAt();

        return null !== $issued && $issued > new \DateTimeImmutable(\sprintf('-%d minutes', self::CODE_TTL_MINUTES));
    }

    /**
     * Запрос к Bot API.
     *
     * @param array<string, scalar> $params
     *
     * @return array{ok: bool, message: string, result?: mixed}
     */
    private function call(string $method, array $params = [], bool $force = false): array
    {
        $telegram = $this->settings->telegram();
        if ('' === $telegram['token'] || (!$force && !$telegram['enabled'])) {
            return ['ok' => false, 'message' => 'Уведомления в Telegram выключены.'];
        }
        $url = \sprintf('%s/bot%s/%s', $telegram['api_url'], $telegram['token'], $method);
        $body = json_encode($params, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
        $response = $this->http->request('POST', $url, false !== $body ? $body : '{}', ['Content-Type' => 'application/json'], $this->timeoutSeconds);
        $json = $response->json();
        if ($response->ok() && true === ($json['ok'] ?? false)) {
            return ['ok' => true, 'message' => 'Запрос выполнен.', 'result' => $json['result'] ?? null];
        }
        $description = \is_string($json['description'] ?? null) ? $json['description'] : $response->describeError();

        return ['ok' => false, 'message' => 'Telegram: '.$description];
    }
}
