<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Service\Audit\DatabaseAuditHandler;
use App\Service\Integration\WebhookNotifier;
use App\Service\Notification\PushNotifier;
use App\Service\Notification\SubscriptionNotifier;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;

/**
 * Обработчик фоновых задач работает до минуты, а уведомления подписчикам, webhook, уведомления в браузере
 * и журнал аудита копятся до конца запроса или команды. После каждой задачи они отправляются и записываются
 * сразу — не через минуту и не теряются, если обработчик прервут.
 */
final class WorkerFlushSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly ?SubscriptionNotifier $subscriptions = null,
        private readonly ?WebhookNotifier $webhooks = null,
        private readonly ?PushNotifier $push = null,
        private readonly ?DatabaseAuditHandler $audit = null,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            WorkerMessageHandledEvent::class => 'flush',
            WorkerMessageFailedEvent::class => 'flush',
        ];
    }

    public function flush(): void
    {
        foreach ([fn () => $this->subscriptions?->flush(), fn () => $this->webhooks?->flush(), fn () => $this->push?->flush(), fn () => $this->audit?->flush()] as $step) {
            try {
                $step();
            } catch (\Throwable) {
                // Ошибки каждого из них уже записаны в журнал; остальные шаги выполняются.
            }
        }
    }
}
