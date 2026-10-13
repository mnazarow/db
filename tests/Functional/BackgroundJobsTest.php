<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\EventSubscriber\WorkerHeartbeatSubscriber;
use App\Message\NotifyAcknowledgementAssigned;
use App\Message\RefreshStatistics;
use App\Message\SendMaterialsReminder;
use App\Message\SendTeamDigest;
use App\Service\AcknowledgementService;
use App\Service\BackgroundJobs;
use App\Service\Monitoring\HealthReport;
use App\Messenger\AtMostOnceMiddleware;
use App\Service\PortalSettings;
use App\Service\PortalUrl;
use App\Service\StatsAggregator;
use App\Service\StatsService;
use App\Tests\PortalTestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\SendMessageToTransportsEvent;
use Symfony\Component\Messenger\Event\WorkerStartedEvent;
use Symfony\Component\Messenger\Event\WorkerStoppedEvent;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Middleware\StackMiddleware;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Messenger\Worker;
use Symfony\Component\Routing\RequestContext;

/**
 * Фоновые задачи: обработчик считается работающим по учёту запусков, задачи уходят в очередь только при нём
 * (иначе операция выполняется сразу, как раньше), очередь и невыполненные задачи видны в «Мониторинге»;
 * уведомления о назначенном ознакомлении, «Отправить сейчас» для сводок и пересчёт итогов статистики — задачами.
 * В автотестах очередь синхронная (sync://): задача выполняется в момент постановки.
 */
final class BackgroundJobsTest extends PortalTestCase
{
    protected function tearDown(): void
    {
        $connection = $this->em()->getConnection();
        static::getContainer()->get(BackgroundJobs::class)->markWorkerStopped();
        $connection->executeStatement('DELETE FROM messenger_messages');
        $connection->executeStatement('DELETE FROM document_acknowledgement');
        foreach (static::getContainer()->get('App\Repository\UserRepository')->findAll() as $user) {
            $user->setManager(null);
        }
        $this->em()->flush();
        static::getContainer()->get(PortalSettings::class)->setTeamDigest(false, true);
        static::getContainer()->get('cache.app')->deleteItem('stats_refresh_requested');
        parent::tearDown();
    }

    public function testWorkerLivenessQueueAndMonitoring(): void
    {
        $jobs = static::getContainer()->get(BackgroundJobs::class);
        $jobs->markWorkerStopped();
        self::assertFalse($jobs->isWorkerAlive(), 'обработчик не запущен');
        self::assertFalse($jobs->dispatch(new RefreshStatistics()), 'без обработчика задача не ставится — её выполняет вызывающий');

        // Отметку ставит обработчик очереди async, пока работает, и снимает при остановке.
        $heartbeat = new WorkerHeartbeatSubscriber($jobs);
        $worker = static fn (string $transport): Worker => new Worker([$transport => new InMemoryTransport()], new MessageBus(), new EventDispatcher());
        $heartbeat->onStarted(new WorkerStartedEvent($worker('failed')));
        self::assertFalse($jobs->isWorkerAlive(), 'обработчик другой очереди — не тот');
        $heartbeat->onStarted(new WorkerStartedEvent($worker('async')));
        self::assertTrue($jobs->isWorkerAlive());
        $heartbeat->onStopped(new WorkerStoppedEvent($worker('async')));
        self::assertTrue($jobs->isWorkerAlive(), 'остановился по ограничению времени — cron перезапустит его в течение минуты');
        $jobs->markWorkerStopped();
        self::assertFalse($jobs->isWorkerAlive(), 'остановлен совсем — задачи снова выполняются сразу');

        // Задача, на которой обработчик прервали, при повторной выдаче той же записи очереди не выполняется.
        $once = new AtMostOnceMiddleware(static::getContainer()->get('cache.app'));
        $handled = 0;
        $counter = new class($handled) implements MiddlewareInterface {
            public function __construct(private int &$handled)
            {
            }

            public function handle(Envelope $envelope, StackInterface $stack): Envelope
            {
                ++$this->handled;

                return $envelope;
            }
        };
        $id = 'test-'.bin2hex(random_bytes(4));
        $envelope = (new Envelope(new RefreshStatistics()))->with(new ReceivedStamp('async'), new TransportMessageIdStamp($id));
        $once->handle($envelope, new StackMiddleware($counter));
        $once->handle($envelope, new StackMiddleware($counter));
        self::assertSame(1, $handled, 'та же запись очереди — один раз');
        $once->handle($envelope->with(new TransportMessageIdStamp($id.'-retry')), new StackMiddleware($counter));
        self::assertSame(2, $handled, 'повтор после ошибки — новая запись, выполняется');

        // Очередь: задача ждёт 20 минут, ещё одна не выполнена после повторов — «Мониторинг» предупреждает.
        self::assertSame(['pending' => 0, 'oldest' => null, 'failed' => 0], $jobs->queueStats());
        $connection = $this->em()->getConnection();
        $utc = static fn (string $when): string => (new \DateTimeImmutable($when, new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
        $connection->insert('messenger_messages', ['body' => 'x', 'headers' => '[]', 'queue_name' => 'default', 'created_at' => $utc('-20 minutes'), 'available_at' => $utc('-20 minutes')]);
        $connection->insert('messenger_messages', ['body' => 'x', 'headers' => '[]', 'queue_name' => 'failed', 'created_at' => $utc('-1 day'), 'available_at' => $utc('-1 day')]);
        $stats = $jobs->queueStats();
        self::assertSame(1, $stats['pending']);
        self::assertSame(1, $stats['failed']);
        self::assertEqualsWithDelta(time() - 1200, $stats['oldest']?->getTimestamp(), 5);
        $report = static::getContainer()->get(HealthReport::class)->build();
        $queue = $this->check($report, 'queue');
        self::assertSame(HealthReport::WARNING, $queue['status']);
        self::assertStringContainsString('обработчик не работает', $queue['message']);
        self::assertSame(1, $report['metrics']['queue_pending']);
        self::assertSame(1, $report['metrics']['queue_failed']);
        self::assertContains('Обработчик фоновых задач', array_column($report['jobs'], 'label'));
        self::assertStringContainsString('docportal_queue_failed 1', HealthReport::prometheus($report));

        $connection->executeStatement("DELETE FROM messenger_messages WHERE queue_name = 'default'");
        $queue = $this->check(static::getContainer()->get(HealthReport::class)->build(), 'queue');
        self::assertSame(HealthReport::WARNING, $queue['status']);
        self::assertStringContainsString('messenger:failed:show', $queue['message']);
        $connection->executeStatement('DELETE FROM messenger_messages');
        self::assertSame(HealthReport::OK, $this->check(static::getContainer()->get(HealthReport::class)->build(), 'queue')['status']);
    }

    public function testTasksGoToWorkerWhenItRuns(): void
    {
        $c = static::getContainer();
        $c->get(BackgroundJobs::class)->markWorkerAlive();
        // Задачи со ссылками на портал уходят обработчику, только если задан постоянный адрес портала.
        $url = $c->get(PortalUrl::class);
        self::assertFalse($c->get(BackgroundJobs::class)->dispatch(new NotifyAcknowledgementAssigned(1, [])), 'адрес портала не задан — ссылки были бы на localhost');
        (new \ReflectionProperty(PortalUrl::class, 'context'))->setValue($url, RequestContext::fromUri('https://docs.example.ru'));
        $sent = [];
        $c->get('event_dispatcher')->addListener(SendMessageToTransportsEvent::class, static function (SendMessageToTransportsEvent $e) use (&$sent): void {
            $sent[] = $e->getEnvelope()->getMessage()::class;
        });

        // Назначение ознакомления: письма сотрудникам — фоновой задачей.
        $users = [$this->user('ivanov'), $this->user('smirnov'), $this->user('sidorov')];
        $withMail = \count(array_filter($users, static fn ($u): bool => null !== $u->getEmail() && '' !== $u->getEmail()));
        $result = $c->get(AcknowledgementService::class)->assign($this->document('ПОЛ-001'), $users, new \DateTimeImmutable('+5 days'), $this->user('admin'));
        self::assertSame(3, $result['created']);
        self::assertSame([NotifyAcknowledgementAssigned::class], $sent, 'уведомления — одной фоновой задачей');
        self::assertEmailCount($withMail);
        self::assertStringContainsString('ознакомьтесь с документом', (string) self::getMailerMessage()?->getSubject());

        // Итоги статистики устарели — пересчитывает обработчик, страница считает по журналу.
        $aggregator = $c->get(StatsAggregator::class);
        $aggregator->invalidate();
        self::assertFalse($aggregator->isFresh());
        $c->get(StatsService::class)->overview();
        self::assertTrue($aggregator->isFresh(), 'задача пересчёта выполнена (очередь в автотестах синхронная)');
        self::assertContains(RefreshStatistics::class, $sent);

        // «Отправить сейчас» для сводки руководителям — в фоне.
        $this->user('ivanov')->setManager($this->user('sidorov'));
        $this->em()->flush();
        $this->loginAs('admin');
        $crawler = $this->client->request('GET', '/admin/acknowledgements');
        $form = $crawler->filter('#team-digest form[action$="/send"]')->last()->form();
        $this->client->submit($form);
        self::assertResponseRedirects();
        self::assertEmailCount(null !== $this->user('sidorov')->getEmail() ? 1 : 0);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert', 'отправляется в фоне');
        self::assertNotNull($c->get(PortalSettings::class)->teamDigest()['last_run'], 'сводка отправлена обработчиком');
        self::assertContains(SendTeamDigest::class, $sent);

        // «Отправить сейчас» для сводки по таблицам материалов — тоже.
        $crawler = $this->client->request('GET', '/admin/materials');
        $token = $crawler->filter('#remind input[name=_token]')->first()->attr('value');
        $this->client->request('POST', '/admin/materials/remind/send', ['_token' => $token, 'mode' => 'send']);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert', 'отправляется в фоне');
        self::assertContains(SendMaterialsReminder::class, $sent);
    }

    /** @param array<string, mixed> $report @return array<string, mixed> */
    private function check(array $report, string $key): array
    {
        foreach ($report['checks'] as $check) {
            if ($key === $check['key']) {
                return $check;
            }
        }
        self::fail('Нет проверки '.$key);
    }
}
