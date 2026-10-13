<?php

declare(strict_types=1);

namespace App\Service\Monitoring;

use App\Entity\SystemJob;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Event\ConsoleErrorEvent;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Отмечает запуски регулярных заданий (их вызывает cron) в таблице system_job: мониторинг видит,
 * когда задание последний раз отработало и чем закончилось. Пробные запуски (--dry-run, --status) не учитываются.
 */
final class ConsoleJobSubscriber implements EventSubscriberInterface
{
    /** Команда → имя задания в system_job. */
    public const COMMANDS = [
        'app:documents:expiry' => 'expiry',
        'app:search:reindex' => 'reindex',
        'app:documents:describe' => 'describe',
        'app:documents:acknowledge-remind' => 'acknowledge-remind',
        'app:telegram:poll' => 'telegram',
        'app:materials:remind' => 'materials-remind',
        'app:ai:index' => 'ai-index',
        'app:team:digest' => 'team-digest',
        'app:stats:aggregate' => 'stats',
        // Обработчик фоновых задач (cron запускает его каждую минуту, в Docker — процесс в контейнере app).
        'messenger:consume' => 'worker',
    ];

    /** @var array<string, float> имя задания → время старта */
    private array $started = [];

    /** @var array<string, string> имя задания → текст ошибки */
    private array $errors = [];

    public function __construct(private readonly SystemJobRecorder $recorder)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            ConsoleEvents::COMMAND => 'onCommand',
            ConsoleEvents::ERROR => 'onError',
            ConsoleEvents::TERMINATE => 'onTerminate',
        ];
    }

    public function onCommand(ConsoleCommandEvent $event): void
    {
        $job = $this->job($event->getCommand()?->getName(), $event->getInput());
        if (null === $job) {
            return;
        }
        $this->started[$job] = microtime(true);
        $this->recorder->start($job);
    }

    public function onError(ConsoleErrorEvent $event): void
    {
        $job = self::COMMANDS[$event->getCommand()?->getName() ?? ''] ?? null;
        if (null !== $job && isset($this->started[$job])) {
            $this->errors[$job] = $event->getError()->getMessage();
        }
    }

    public function onTerminate(ConsoleTerminateEvent $event): void
    {
        $job = self::COMMANDS[$event->getCommand()?->getName() ?? ''] ?? null;
        if (null === $job || !isset($this->started[$job])) {
            return;
        }
        $duration = (int) round((microtime(true) - $this->started[$job]) * 1000);
        $ok = 0 === $event->getExitCode();
        $this->recorder->finish(
            $job,
            $ok ? SystemJob::STATUS_OK : SystemJob::STATUS_ERROR,
            $ok ? null : ($this->errors[$job] ?? 'Код завершения '.$event->getExitCode()),
            $duration,
        );
        unset($this->started[$job], $this->errors[$job]);
    }

    private function job(?string $command, InputInterface $input): ?string
    {
        $job = self::COMMANDS[$command ?? ''] ?? null;
        if (null === $job) {
            return null;
        }
        // Пробные и ручные запуски (одному руководителю — --manager) не считаются выполнением задания по расписанию.
        foreach (['dry-run', 'status', 'help', 'manager'] as $option) {
            if ($input->hasOption($option) && $input->getOption($option)) {
                return null;
            }
        }

        return $job;
    }
}
