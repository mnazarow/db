<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Service\BackgroundJobs;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;
use Symfony\Component\Messenger\Event\WorkerStartedEvent;
use Symfony\Component\Messenger\Event\WorkerStoppedEvent;

/**
 * Отметка «обработчик фоновых задач работает» (App\Service\BackgroundJobs::isWorkerAlive): ставится, пока
 * обработчик очереди async принимает задачи. Остановившийся обработчик (ограничение времени работы, команда
 * messenger:stop-workers) перезапустят cron или цикл в контейнере — он считается работающим ещё полторы минуты;
 * убитый процесс — до истечения отметки (не дольше двух с половиной минут).
 */
final class WorkerHeartbeatSubscriber implements EventSubscriberInterface
{
    private bool $async = false;

    private float $lastBeat = 0.0;

    public function __construct(private readonly BackgroundJobs $jobs)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            WorkerStartedEvent::class => 'onStarted',
            WorkerRunningEvent::class => 'onRunning',
            WorkerMessageReceivedEvent::class => 'onReceived',
            WorkerStoppedEvent::class => 'onStopped',
        ];
    }

    public function onStarted(WorkerStartedEvent $event): void
    {
        $this->async = \in_array(BackgroundJobs::TRANSPORT, $event->getWorker()->getMetadata()->getTransportNames(), true);
        $this->beat(true);
    }

    public function onRunning(WorkerRunningEvent $event): void
    {
        $this->beat(false);
    }

    public function onReceived(WorkerMessageReceivedEvent $event): void
    {
        $this->beat(true);
    }

    public function onStopped(WorkerStoppedEvent $event): void
    {
        if ($this->async) {
            $this->jobs->markWorkerStopped(BackgroundJobs::RESTART_GRACE_SECONDS);
        }
    }

    private function beat(bool $force): void
    {
        if (!$this->async || (!$force && microtime(true) - $this->lastBeat < 10)) {
            return;
        }
        $this->lastBeat = microtime(true);
        $this->jobs->markWorkerAlive();
    }
}
