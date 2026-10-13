<?php

declare(strict_types=1);

namespace App\Service\Notification;

use App\Entity\PushSubscription;
use App\Entity\User;
use App\Repository\PushSubscriptionRepository;
use App\Service\Http\HttpTransportInterface;
use App\Service\PortalSettings;
use App\Service\PortalUrl;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Уведомления в браузере (Web Push): сотрудник включает их в «Подписки и уведомления» на своём устройстве,
 * портал шлёт их о тех же событиях, что и в Telegram, и о назначенных ознакомлениях.
 *
 * Сообщения копятся в очереди и уходят после ответа на запрос (kernel.terminate) или по завершении консольной
 * команды — параллельно, чтобы назначение ознакомления сотням сотрудников не задерживало страницу.
 * Отправка идёт в службу уведомлений браузера (Google, Mozilla, Apple, Microsoft): серверу портала нужен
 * исходящий доступ к ней. Принимаются только адреса этих служб — портал не отправит запрос на произвольный адрес.
 */
final class PushNotifier implements EventSubscriberInterface, ResetInterface
{
    /** Службы уведомлений браузеров, на адреса которых портал отправляет уведомления. */
    public const PUSH_SERVICE_HOSTS = ['fcm.googleapis.com', 'android.googleapis.com', 'updates.push.services.mozilla.com', 'push.services.mozilla.com', 'notify.windows.com', 'push.apple.com'];

    /** После стольких неудачных отправок подряд подписка удаляется (браузер давно не на связи). */
    private const MAX_FAILURES = 10;

    private const TIMEOUT = 10;

    /** Подписок (устройств) у одного сотрудника не больше — старые удаляются при подписке нового. */
    public const MAX_PER_USER = 10;

    /** Сколько секунд всего на отправку: после ответа на запрос и в консольной команде. */
    private const BUDGET_WEB = 20;
    private const BUDGET_CONSOLE = 120;

    /** Ключ сессии: подписки, включённые в этой сессии, удаляются при выходе (общий компьютер). */
    public const SESSION_KEY = 'push_subscriptions';

    /** @var list<array{user: int, payload: string}> */
    private array $queue = [];

    public function __construct(
        private readonly PortalSettings $settings,
        private readonly PushSubscriptionRepository $subscriptions,
        private readonly HttpTransportInterface $http,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
        private readonly PortalUrl $portalUrl,
        private readonly string $mailFrom = '',
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        // Позже остальных обработчиков завершения: уведомления о подписках (SubscriptionNotifier, -110) и
        // другие, которые ставят сообщения в очередь в это же время, должны успеть в неё попасть.
        return [KernelEvents::TERMINATE => ['onTerminate', -1000], ConsoleEvents::TERMINATE => ['onTerminate', -1000]];
    }

    public function onTerminate(): void
    {
        $this->flush();
    }

    public function isEnabled(): bool
    {
        return $this->settings->push()['ready'];
    }

    public function publicKey(): ?string
    {
        $push = $this->settings->push();

        return $push['ready'] ? $push['public_key'] : null;
    }

    /** Адрес принадлежит службе уведомлений браузера (https, известный адрес). */
    public static function isAllowedEndpoint(string $endpoint): bool
    {
        $parts = parse_url($endpoint);
        if (!\is_array($parts) || 'https' !== ($parts['scheme'] ?? '') || !isset($parts['host']) || isset($parts['user']) || \strlen($endpoint) > 2000) {
            return false;
        }
        $host = strtolower($parts['host']);
        foreach (self::PUSH_SERVICE_HOSTS as $allowed) {
            if ($host === $allowed || str_ends_with($host, '.'.$allowed)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Уведомление сотруднику на все его устройства (отправка — после ответа на запрос).
     */
    public function notify(User $user, string $title, string $body, ?string $url = null, ?string $tag = null): void
    {
        if (null === $user->getId() || !$user->isActive() || !$this->isEnabled()) {
            return;
        }
        if ($this->settings->push()['hide_text']) {
            // Общие компьютеры: на экране — только что есть новое, подробности — на портале после входа.
            [$title, $body] = ['Новое уведомление портала', 'Откройте портал, чтобы посмотреть.'];
        }
        $payload = json_encode([
            'title' => mb_strimwidth(trim($title), 0, 120, '…'),
            'body' => mb_strimwidth(trim($body), 0, 400, '…'),
            'url' => $url ?? $this->portalUrl->absolute('app_home'),
            'tag' => $tag,
        ], \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
        $this->queue[] = ['user' => (int) $user->getId(), 'payload' => (string) $payload];
    }

    /**
     * То же сообщение, что уходит в Telegram (HTML бота): заголовок — первый жирный фрагмент, ссылка —
     * первая ссылка, текст — остальное без разметки.
     */
    public function notifyHtml(User $user, string $html): void
    {
        if (!$this->isEnabled()) {
            return;
        }
        $title = preg_match('~<b>(.*?)</b>~su', $html, $m) ? self::plain($m[1]) : '';
        $url = preg_match('~<a\s+href="([^"]+)"~u', $html, $m) ? html_entity_decode($m[1], \ENT_QUOTES | \ENT_HTML5, 'UTF-8') : null;
        $rest = $html;
        if ('' !== $title) {
            $rest = (string) preg_replace('~<b>.*?</b>~su', '', $html, 1);
        }
        $lines = [];
        foreach (preg_split('/\R/u', $rest) ?: [] as $line) {
            $line = trim(self::plain($line));
            if ('' === $line || (null === $url && preg_match('~^https?://\S+$~', $line))) {
                if (null === $url && '' !== $line) {
                    $url = $line;
                }
                continue;
            }
            if (null !== $url && $line === $url) {
                continue;
            }
            $lines[] = $line;
        }
        if ('' === $title) {
            $title = array_shift($lines) ?? 'Портал документации';
        }
        $this->notify($user, $title, implode("\n", $lines), $url);
    }

    /** Пробное уведомление на устройства сотрудника — сразу, с итогом для страницы. */
    public function sendTest(User $user): array
    {
        $this->notify($user, 'Уведомления включены', 'Так будут выглядеть уведомления портала на этом устройстве.', $this->portalUrl->absolute('app_profile_subscriptions'), 'test');

        return $this->flush();
    }

    /**
     * Отправка накопленных уведомлений. Состояние подписок записывается отдельными запросами — без общего
     * сохранения изменений Doctrine (после ответа на запрос в нём могут оставаться чужие несохранённые изменения).
     *
     * @return array{sent: int, failed: int, removed: int, skipped: int}
     */
    public function flush(?int $budgetSeconds = null): array
    {
        $stats = ['sent' => 0, 'failed' => 0, 'removed' => 0, 'skipped' => 0];
        if ([] === $this->queue) {
            return $stats;
        }
        $queue = $this->queue;
        $this->queue = [];
        $push = $this->settings->push();
        if (!$push['ready']) {
            return $stats;
        }
        $deadline = microtime(true) + ($budgetSeconds ?? ('cli' === \PHP_SAPI ? self::BUDGET_CONSOLE : self::BUDGET_WEB));
        $sent = $failed = $remove = [];
        try {
            $byUser = [];
            foreach ($this->subscriptions->findForUsers(array_values(array_unique(array_column($queue, 'user')))) as $subscription) {
                $byUser[(int) $subscription->getUser()->getId()][] = $subscription;
            }
            $requests = [];
            $targets = [];
            foreach ($queue as $item) {
                foreach ($byUser[$item['user']] ?? [] as $subscription) {
                    if (!self::isAllowedEndpoint($subscription->getEndpoint())) {
                        continue;
                    }
                    try {
                        $body = WebPush::encrypt($item['payload'], $subscription->getP256dh(), $subscription->getAuthSecret());
                        $authorization = WebPush::vapidHeader($subscription->getEndpoint(), $this->subject($push['subject']), $push['public_key'], $push['private_key']);
                    } catch (\Throwable $e) {
                        // Испорченные ключи подписки — она бесполезна.
                        $this->logger->warning('Уведомление в браузер не зашифровано', ['error' => $e->getMessage()]);
                        $remove[(int) $subscription->getId()] = true;
                        continue;
                    }
                    $requests[] = ['method' => 'POST', 'url' => $subscription->getEndpoint(), 'body' => $body, 'headers' => [
                        'Content-Type' => 'application/octet-stream',
                        'Content-Encoding' => 'aes128gcm',
                        'TTL' => '86400',
                        'Urgency' => 'normal',
                        'Authorization' => $authorization,
                    ]];
                    $targets[] = $subscription;
                }
            }
            foreach (array_chunk($requests, 100, true) as $chunk) {
                $left = (int) floor($deadline - microtime(true));
                if ($left < 2) {
                    // Время вышло (сотни получателей при недоступной службе уведомлений) — остальное не ждём.
                    $stats['skipped'] += \count($chunk);
                    continue;
                }
                // Все запросы части — одновременно: часть занимает не дольше тайм-аута, и общий срок соблюдается.
                $responses = $this->http->requestMany(array_values($chunk), min(self::TIMEOUT, $left), \count($chunk));
                foreach (array_keys($chunk) as $n => $i) {
                    $subscription = $targets[$i];
                    $response = $responses[$n];
                    $id = (int) $subscription->getId();
                    if ($response->ok()) {
                        $sent[$id] = true;
                        ++$stats['sent'];
                    } elseif (\in_array($response->status, [404, 410], true)) {
                        // Браузер отписался или подписка устарела.
                        $remove[$id] = true;
                    } else {
                        $failed[$id] = true;
                        ++$stats['failed'];
                        $this->logger->warning('Уведомление в браузер не доставлено', ['status' => $response->status, 'error' => $response->describeError()]);
                    }
                }
            }
            if ($stats['skipped'] > 0) {
                $this->logger->warning('Часть уведомлений в браузер не отправлена: истекло время на отправку', ['skipped' => $stats['skipped']]);
            }
            $stats['removed'] = $this->subscriptions->applyResults(array_keys($sent), array_keys(array_diff_key($failed, $sent)), array_keys($remove), self::MAX_FAILURES);
        } catch (\Throwable $e) {
            $this->logger->warning('Уведомления в браузер не отправлены', ['error' => $e->getMessage()]);
        }

        return $stats;
    }

    public function reset(): void
    {
        $this->queue = [];
    }

    /** Контакт для служб уведомлений: из настройки, иначе адрес отправителя писем, иначе адрес портала. */
    private function subject(string $configured): string
    {
        if ('' !== $configured) {
            return str_contains($configured, ':') ? $configured : 'mailto:'.$configured;
        }
        if (preg_match('/[\w.+-]+@[\w.-]+/u', $this->mailFrom, $m)) {
            return 'mailto:'.$m[0];
        }

        return $this->portalUrl->absolute('app_home');
    }

    private static function plain(string $html): string
    {
        return trim(html_entity_decode(strip_tags($html), \ENT_QUOTES | \ENT_HTML5, 'UTF-8'));
    }
}
