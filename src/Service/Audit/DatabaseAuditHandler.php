<?php

declare(strict_types=1);

namespace App\Service\Audit;

use App\Entity\AuditEvent;
use Doctrine\ORM\EntityManagerInterface;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\LogRecord;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Запись журнала аудита в базу данных.
 *
 * Портал уже пишет значимые действия в отдельный канал журнала (app_audit); этот обработчик
 * складывает такие записи ещё и в таблицу audit_event — чтобы их можно было смотреть в панели
 * администратора, выгружать в CSV и передавать в SIEM.
 *
 * Записи копятся в памяти и сохраняются один раз в конце запроса: ошибка записи в журнал
 * не должна ломать сам запрос, а сохранение по одной записи замедляло бы работу.
 */
final class DatabaseAuditHandler extends AbstractProcessingHandler implements EventSubscriberInterface
{
    /** Защита от разрастания: больше записей за один запрос не сохраняем. */
    private const MAX_PER_REQUEST = 200;

    /** @var list<AuditEvent> */
    private array $pending = [];

    private bool $flushing = false;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly RequestStack $requests,
        bool $enabled = true,
    ) {
        parent::__construct($enabled ? Level::Info : Level::Emergency);
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::TERMINATE => ['flush', 0],
            ConsoleEvents::TERMINATE => ['flushConsole', 0],
        ];
    }

    public function flush(?TerminateEvent $event = null): void
    {
        if ([] === $this->pending || $this->flushing) {
            return;
        }
        $this->flushing = true;
        try {
            if (!$this->em->isOpen()) {
                return;
            }
            foreach ($this->pending as $entry) {
                $this->em->persist($entry);
            }
            $this->em->flush();
        } catch (\Throwable) {
            // Журнал аудита не должен мешать работе портала: запись в файл уже сделана.
        } finally {
            $this->pending = [];
            $this->flushing = false;
        }
    }

    public function flushConsole(ConsoleTerminateEvent $event): void
    {
        $this->flush();
    }

    protected function write(LogRecord $record): void
    {
        if (\count($this->pending) >= self::MAX_PER_REQUEST) {
            return;
        }
        $context = $record->context;
        $level = match (true) {
            $record->level->value >= Level::Error->value => AuditEvent::LEVEL_ERROR,
            $record->level->value >= Level::Warning->value => AuditEvent::LEVEL_WARNING,
            default => AuditEvent::LEVEL_INFO,
        };
        $entry = new AuditEvent($record->message, $level, $context, \DateTimeImmutable::createFromInterface($record->datetime));
        $entry->setActorName((string) ($context['user'] ?? $context['by'] ?? ''));
        $entry->setIp((string) ($context['ip'] ?? $this->requests->getMainRequest()?->getClientIp() ?? ''));
        $this->pending[] = $entry;
    }
}
