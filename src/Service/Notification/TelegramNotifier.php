<?php

declare(strict_types=1);

namespace App\Service\Notification;

use App\Entity\ChatConversation;
use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\Assistant\ChatAssistant;
use App\Service\Http\HttpTransportInterface;
use App\Service\PortalSettings;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

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
 *
 * Сотруднику, привязавшему чат, бот отвечает и на вопросы — как помощник на портале (ChatAssistant): по
 * документам, видео и прайс-листам, которые этому сотруднику доступны, со ссылками на них. Команда /new
 * начинает новый разговор, /help — подсказка. Чтобы ответ приходил сразу, а не через минуту, команда разбора
 * может ждать новых сообщений (длинный опрос getUpdates, ключ --wait).
 */
final class TelegramNotifier
{
    /** Сколько живёт код привязки. */
    public const CODE_TTL_MINUTES = 30;

    /** Ограничение Telegram на длину сообщения (с запасом: Bot API считает длину в символах UTF-16). */
    private const MAX_MESSAGE = 4000;

    public function __construct(
        private readonly HttpTransportInterface $http,
        private readonly PortalSettings $settings,
        private readonly UserRepository $users,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $auditLogger,
        private readonly int $timeoutSeconds = 15,
        private readonly ?PushNotifier $push = null,
        private readonly ?ChatAssistant $assistant = null,
        #[Target('assistant_chat')]
        private readonly ?RateLimiterFactoryInterface $assistantLimiter = null,
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->settings->isTelegramEnabled();
    }

    /** Включены ли уведомления в браузер — тогда сообщения стоит готовить и без Telegram. */
    public function hasPush(): bool
    {
        return null !== $this->push && $this->push->isEnabled();
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
     * $html — текст размечен HTML в том виде, который принимает Bot API (спецсимволы экранированы);
     * иначе текст уходит как есть, без разметки (например, произвольное сообщение из консоли —
     * знак «<» в нём не должен ломать отправку).
     *
     * @return array{ok: bool, message: string}
     */
    public function send(string $chatId, string $text, bool $force = false, bool $html = true): array
    {
        $telegram = $this->settings->telegram();
        if ('' === $telegram['token'] || (!$force && !$telegram['enabled'])) {
            return ['ok' => false, 'message' => 'Уведомления в Telegram выключены.'];
        }
        if ('' === trim($chatId)) {
            return ['ok' => false, 'message' => 'Не указан чат получателя.'];
        }
        if ($html && self::length($text) > self::MAX_MESSAGE) {
            // Разметку, обрезанную посередине тега или сущности (&amp;), Bot API не разбирает и
            // отклоняет всё сообщение — длинный текст уходит без разметки.
            $text = self::plain($text);
            $html = false;
        }
        $response = $this->call('sendMessage', self::message($chatId, $text, $html), $force);
        if (!$response['ok'] && $html && str_contains($response['message'], 'parse entities')) {
            // Разметку не удалось разобрать — повторяем простым текстом, чтобы уведомление не потерялось.
            $response = $this->call('sendMessage', self::message($chatId, self::plain($text), false), $force);
        }
        if ($response['ok']) {
            return ['ok' => true, 'message' => 'Сообщение отправлено.'];
        }
        $this->auditLogger->warning('Ошибка отправки в Telegram', ['chat' => $chatId, 'error' => $response['message']]);

        return $response;
    }

    /**
     * Сообщение сотруднику в Telegram (если он привязал чат и не отключил уведомления) и то же — уведомлением
     * в браузер на его устройства. Возвращает, ушло ли сообщение в Telegram.
     */
    public function notify(User $user, string $text): bool
    {
        // То же сообщение — уведомлением в браузер, если сотрудник включил их на своих устройствах.
        $this->push?->notifyHtml($user, $text);
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
     * skipped — почему разбор не выполнялся: disabled (уведомления выключены) или busy (сообщения
     * уже разбирает другой процесс); messages — ошибки и пояснения.
     *
     * $wait — сколько секунд ждать новых сообщений (длинный опрос; 0 — забрать накопившиеся и выйти).
     *
     * @return array{updates: int, linked: int, answered: int, errors: int, skipped: ?string, messages: list<string>}
     */
    public function poll(int $limit = 50, int $wait = 0): array
    {
        $stats = ['updates' => 0, 'linked' => 0, 'answered' => 0, 'errors' => 0, 'skipped' => null, 'messages' => []];
        if (!$this->isEnabled()) {
            $stats['skipped'] = 'disabled';
            $stats['messages'][] = 'Уведомления в Telegram выключены.';

            return $stats;
        }
        // Разбор ведёт один процесс: cron запускает команду каждую минуту, и при медленном ответе Bot API
        // (или одновременном нажатии кнопки в панели администратора) одни и те же сообщения разбирались бы
        // дважды — с повторными ответами сотрудникам.
        $lock = $this->acquireLock();
        if (false === $lock) {
            $stats['skipped'] = 'busy';
            $stats['messages'][] = 'Сообщения бота уже разбирает другой процесс — повторите через минуту.';

            return $stats;
        }
        try {
            $until = time() + max(0, min(300, $wait));
            do {
                // Длинный опрос: Telegram держит запрос, пока не придёт сообщение (не дольше 25 с).
                $hold = $wait > 0 ? max(0, min(25, $until - time())) : 0;
                if (!$this->pollUpdates(max(1, min(100, $limit)), $stats, $hold)) {
                    break; // Bot API не ответил — следующий запуск по расписанию попробует снова
                }
            } while ($wait > 0 && time() < $until - 1);
        } finally {
            if (true === $lock) {
                $this->releaseLock();
            }
        }

        return $stats;
    }

    /**
     * @param array{updates: int, linked: int, answered: int, errors: int, skipped: ?string, messages: list<string>} $stats
     */
    private function pollUpdates(int $limit, array &$stats, int $hold = 0): bool
    {
        $bot = self::botId($this->settings->telegram()['token']);
        // Позиция читается прямо из базы: кэш настроек мог быть прочитан до того, как её сдвинул
        // предыдущий запуск.
        $offset = self::storedOffset($this->settings->fresh(PortalSettings::TELEGRAM_OFFSET), $bot);
        $response = $this->call('getUpdates', array_filter([
            'offset' => $offset > 0 ? $offset : null,
            'limit' => $limit,
            'timeout' => $hold,
            'allowed_updates' => '["message"]',
        ], static fn ($value): bool => null !== $value), false, $hold + $this->timeoutSeconds);
        if (!$response['ok']) {
            ++$stats['errors'];
            $stats['messages'][] = $response['message'];

            return false;
        }
        $updates = \is_array($response['result'] ?? null) ? $response['result'] : [];
        $maxId = $offset;
        try {
            foreach ($updates as $update) {
                ++$stats['updates'];
                // Позиция сдвигается до разбора: сообщение, на котором разбор упал, не будет повторяться
                // при каждом запуске и не остановит очередь.
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
                // Позиция сохраняется до ответа: ответ помощника может идти долго, а повторно разбирать уже
                // принятые сообщения (и отвечать дважды) нельзя, если процесс прервут.
                if ($maxId > $offset) {
                    $this->settings->set(PortalSettings::TELEGRAM_OFFSET, '' !== $bot ? $bot.':'.$maxId : $maxId);
                    $offset = $maxId;
                }
                $this->handleMessage($chatId, $text, $message, $stats);
            }
        } finally {
            if ($maxId > $offset) {
                $this->settings->set(PortalSettings::TELEGRAM_OFFSET, '' !== $bot ? $bot.':'.$maxId : $maxId);
            }
        }

        return true;
    }

    /**
     * Сохранённая позиция разбора сообщений: «идентификатор_бота:номер». Позиция другого бота (токен
     * заменили) не годится: номера его сообщений другие, и с чужой позицией Telegram считал бы новые
     * сообщения уже прочитанными — привязка чатов перестала бы работать.
     */
    private static function storedOffset(mixed $value, string $bot): int
    {
        $value = \is_scalar($value) ? trim((string) $value) : '';
        if (1 === preg_match('/^(\d+):(\d+)$/', $value, $m)) {
            return $m[1] === $bot ? (int) $m[2] : 0;
        }

        // Значение прежних версий — просто номер, без привязки к боту.
        return ctype_digit($value) ? (int) $value : 0;
    }

    /** Идентификатор бота — числовая часть токена до двоеточия. */
    private static function botId(string $token): string
    {
        $id = strstr($token, ':', true);

        return false !== $id && ctype_digit($id) ? $id : '';
    }

    /**
     * Блокировка разбора сообщений — в базе данных (GET_LOCK), а не файлом: в Docker кнопка в панели
     * администратора и задание cron работают в разных контейнерах и файлов друг друга не видят.
     * true — блокировка получена, false — разбор уже идёт в другом процессе, null — база не поддерживает
     * блокировки (тогда разбор идёт без неё).
     */
    private function acquireLock(): ?bool
    {
        try {
            return 1 === (int) $this->em->getConnection()->fetchOne('SELECT GET_LOCK(?, 0)', [$this->lockName()]);
        } catch (\Throwable) {
            return null;
        }
    }

    private function releaseLock(): void
    {
        try {
            $this->em->getConnection()->fetchOne('SELECT RELEASE_LOCK(?)', [$this->lockName()]);
        } catch (\Throwable) {
            // Блокировка снимется сама при закрытии соединения.
        }
    }

    /** Имя блокировки: в нём имя базы — несколько порталов на одном сервере MySQL не мешают друг другу. */
    public function lockName(): string
    {
        $database = '';
        try {
            $database = (string) $this->em->getConnection()->getDatabase();
        } catch (\Throwable) {
        }

        return mb_substr('docportal:telegram:'.$database, 0, 64);
    }

    /**
     * Входящее сообщение: команды (/start с кодом привязки, /stop, /help, /new), код привязки от непривязанного
     * чата или вопрос помощнику от привязанного.
     *
     * @param array<string, mixed>                                                                                  $message
     * @param array{updates: int, linked: int, answered: int, errors: int, skipped: ?string, messages: list<string>} $stats
     */
    private function handleMessage(string $chatId, string $text, array $message, array &$stats): void
    {
        $user = $this->users->findOneBy(['telegramChatId' => $chatId]);
        $first = (string) (preg_split('/\s+/u', $text, 2)[0] ?? '');
        $command = str_starts_with($first, '/') ? strtolower((string) preg_replace('/@\S*$/', '', $first)) : null;
        $argument = trim(mb_substr($text, mb_strlen($first)));
        switch ($command) {
            case '/stop':
                if (null !== $user) {
                    $user->unlinkTelegram();
                    $this->em->flush();
                    $this->send($chatId, 'Уведомления отключены. Чтобы снова получать сообщения, привяжите чат в профиле портала.');
                    $this->auditLogger->info('Отвязан Telegram', ['user' => $user->getUsername(), 'chat' => $chatId]);

                    return;
                }
                $this->send($chatId, 'Отправьте код привязки из профиля портала документации.');

                return;
            case '/start':
                if ('' !== $argument) {
                    $this->link($chatId, $argument, $message, $stats);
                } elseif (null !== $user) {
                    $this->send($chatId, $this->help($user));
                } else {
                    $this->send($chatId, 'Отправьте код привязки из профиля портала документации.');
                }

                return;
            case '/help':
                $this->send($chatId, $this->help($user));

                return;
            case '/new':
                if (null !== $user && null !== $this->assistant) {
                    $this->assistant->finishTelegram($user);
                    $this->send($chatId, 'Начат новый разговор — задайте вопрос.');
                } else {
                    $this->send($chatId, $this->help($user));
                }

                return;
            case null:
                break;
            default:
                $this->send($chatId, 'Такой команды нет. '.$this->help($user));

                return;
        }
        if (null === $user) {
            // Одно «слово» от непривязанного чата — код привязки; иначе — подсказка, как привязать чат.
            if (1 === preg_match('/^\S{4,32}$/u', $text)) {
                $this->link($chatId, $text, $message, $stats);
            } else {
                $this->send($chatId, $this->help(null));
            }

            return;
        }
        $this->answer($user, $chatId, $text, $message, $stats);
    }

    /**
     * Вопрос помощнику из Telegram.
     *
     * @param array<string, mixed>                                                                                  $message
     * @param array{updates: int, linked: int, answered: int, errors: int, skipped: ?string, messages: list<string>} $stats
     */
    private function answer(User $user, string $chatId, string $text, array $message, array &$stats): void
    {
        if ('private' !== (string) ($message['chat']['type'] ?? 'private')) {
            return; // в группах помощник не отвечает: ответ зависит от прав конкретного сотрудника
        }
        if (null === $this->assistant || !$this->assistant->isAvailable()) {
            $this->send($chatId, 'Помощник по документам выключен администратором портала — сюда приходят только уведомления. Команда /stop отключает их.');

            return;
        }
        if (!$user->isActive()) {
            $this->send($chatId, 'Учётная запись на портале отключена.');

            return;
        }
        if (null !== $this->assistantLimiter) {
            $limit = $this->assistantLimiter->create('assistant-'.$user->getId())->consume();
            if (!$limit->isAccepted()) {
                $this->send($chatId, \sprintf('Слишком много вопросов подряд — повторите через %d мин.', max(1, (int) ceil(($limit->getRetryAfter()->getTimestamp() - time()) / 60))));

                return;
            }
        }
        $this->call('sendChatAction', ['chat_id' => $chatId, 'action' => 'typing']);
        try {
            $result = $this->assistant->ask($user, $this->assistant->ongoingTelegram($user), $text, ChatConversation::CHANNEL_TELEGRAM);
        } catch (\InvalidArgumentException|\DomainException $e) {
            $this->send($chatId, htmlspecialchars($e->getMessage(), \ENT_QUOTES));

            return;
        } catch (\Throwable $e) {
            ++$stats['errors'];
            $stats['messages'][] = 'Помощник не ответил: '.$e->getMessage();
            $this->auditLogger->warning('Помощник в Telegram не ответил', ['user' => $user->getUsername(), 'error' => $e->getMessage()]);
            $this->send($chatId, 'Не получилось ответить — повторите вопрос чуть позже.');

            return;
        }
        if ($this->send($chatId, $this->assistant->telegramText($result['reply']))['ok']) {
            ++$stats['answered'];
        }
    }

    /** Подсказка: что умеет бот (привязанному чату — с помощником). */
    private function help(?User $user): string
    {
        if (null === $user) {
            return 'Это бот портала документации. Чтобы получать уведомления и задавать вопросы помощнику, привяжите чат: на портале откройте «Подписки и уведомления» → Telegram, получите код и отправьте его сюда.';
        }
        $assistant = null !== $this->assistant && $this->assistant->isAvailable();

        return ($assistant
            ? "Задайте вопрос — помощник ответит по документам, видео и прайс-листам портала, которые вам доступны, и пришлёт ссылки на них. Можно уточнять в том же разговоре.\n\n/new — начать новый разговор\n"
            : "Сюда приходят уведомления портала документации.\n\n")
            .'/stop — отключить уведомления и отвязать чат';
    }

    /**
     * Привязка чата по коду из профиля.
     *
     * @param array<string, mixed>                                                                                  $message
     * @param array{updates: int, linked: int, answered: int, errors: int, skipped: ?string, messages: list<string>} $stats
     */
    private function link(string $chatId, string $code, array $message, array &$stats): void
    {
        $code = strtoupper(trim($code));
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
        $assistant = null !== $this->assistant && $this->assistant->isAvailable();
        $this->send($chatId, \sprintf('Готово, %s. Уведомления портала документации будут приходить сюда.%s Команда /stop отключает их.',
            htmlspecialchars($user->getDisplayName(), \ENT_QUOTES), $assistant ? ' Здесь же можно задавать вопросы помощнику по документам и прайс-листам.' : ''));
        $this->auditLogger->info('Привязан Telegram', ['user' => $user->getUsername(), 'chat' => $chatId]);
    }

    public static function codeIsFresh(User $user): bool
    {
        $issued = $user->getTelegramCodeAt();

        return null !== $issued && $issued > new \DateTimeImmutable(\sprintf('-%d minutes', self::CODE_TTL_MINUTES));
    }

    /**
     * Параметры sendMessage: текст укорачивается до ограничения Telegram.
     *
     * @return array<string, scalar>
     */
    private static function message(string $chatId, string $text, bool $html): array
    {
        $params = ['chat_id' => $chatId, 'text' => self::truncate($text), 'disable_web_page_preview' => true];
        if ($html) {
            $params['parse_mode'] = 'HTML';
        }

        return $params;
    }

    /** Текст без HTML-разметки (для отправки без parse_mode). */
    private static function plain(string $html): string
    {
        return html_entity_decode(strip_tags($html), \ENT_QUOTES | \ENT_HTML5, 'UTF-8');
    }

    /** Укорачивает текст до MAX_MESSAGE символов UTF-16 (так длину считает Bot API) с многоточием в конце. */
    private static function truncate(string $text): string
    {
        if (self::length($text) <= self::MAX_MESSAGE) {
            return $text;
        }
        $limit = self::MAX_MESSAGE - 1;
        $cut = mb_substr($text, 0, $limit);
        while ('' !== $cut && ($excess = self::length($cut) - $limit) > 0) {
            // Символ занимает одну или две единицы UTF-16: убираем не меньше половины лишнего.
            $cut = mb_substr($cut, 0, mb_strlen($cut) - max(1, intdiv($excess + 1, 2)));
        }

        return rtrim($cut).'…';
    }

    /** Длина текста в единицах UTF-16. */
    private static function length(string $text): int
    {
        return intdiv(\strlen((string) mb_convert_encoding($text, 'UTF-16LE', 'UTF-8')), 2);
    }

    /**
     * Запрос к Bot API.
     *
     * @param array<string, scalar> $params
     *
     * @return array{ok: bool, message: string, result?: mixed}
     */
    private function call(string $method, array $params = [], bool $force = false, ?int $timeout = null): array
    {
        $telegram = $this->settings->telegram();
        if ('' === $telegram['token'] || (!$force && !$telegram['enabled'])) {
            return ['ok' => false, 'message' => 'Уведомления в Telegram выключены.'];
        }
        $url = \sprintf('%s/bot%s/%s', $telegram['api_url'], $telegram['token'], $method);
        $body = json_encode($params, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
        $response = $this->http->request('POST', $url, false !== $body ? $body : '{}', ['Content-Type' => 'application/json'], $timeout ?? $this->timeoutSeconds);
        $json = $response->json();
        if ($response->ok() && true === ($json['ok'] ?? false)) {
            return ['ok' => true, 'message' => 'Запрос выполнен.', 'result' => $json['result'] ?? null];
        }
        $description = \is_string($json['description'] ?? null) ? $json['description'] : $response->describeError();

        return ['ok' => false, 'message' => 'Telegram: '.$description];
    }
}
