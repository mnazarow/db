<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Document;
use App\Entity\DocumentEvent;
use App\Repository\DocumentEventRepository;
use App\Repository\DocumentRepository;
use App\Repository\DocumentVersionRepository;
use App\Repository\SectionModeratorRepository;
use App\Repository\SectionRepository;
use App\Repository\UserRepository;
use App\Service\DocumentManager;
use App\Service\StatsAggregator;
use App\Service\StatsService;
use App\Service\Validity;
use App\Tests\PortalTestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Ежедневные итоги статистики: страница «Статистика» по итогам показывает то же, что подсчёт по журналу событий
 * (за все периоды, с сегодняшними событиями), итоги пересчитываются командой и сами — при первом обращении за день.
 */
final class StatsAggregatesTest extends PortalTestCase
{
    public function testAggregatesMatchLiveCounts(): void
    {
        $c = static::getContainer();
        $aggregator = $c->get(StatsAggregator::class);
        $stats = $c->get(StatsService::class);
        $live = new StatsService($c->get(DocumentRepository::class), $c->get(DocumentVersionRepository::class), $c->get(DocumentEventRepository::class),
            $c->get(SectionRepository::class), $c->get(SectionModeratorRepository::class), $c->get(UserRepository::class), $c->get(Validity::class));

        // События в разные дни: позавчера, 20, 100 и 400 дней назад, — и разных сотрудников и типов.
        $docs = [$this->document('ПОЛ-001'), $this->document('ОТ-001'), $this->document('ИБ-002')];
        $users = [$this->user('ivanov'), $this->user('smirnov'), $this->user('sidorov')];
        $types = [DocumentEvent::VIEW, DocumentEvent::VIEW, DocumentEvent::DOWNLOAD, DocumentEvent::UPDATE, DocumentEvent::EXPIRY_NOTICE, DocumentEvent::NEW_VERSION, DocumentEvent::CREATE];
        $n = 0;
        foreach (['-2 days 10:00', '-20 days 23:59:59', '-100 days 00:00:00', '-400 days 12:00'] as $when) {
            foreach ($docs as $i => $doc) {
                foreach ($types as $j => $type) {
                    $this->event($doc->getId(), 0 === ($i + $j) % 4 ? null : $users[($i + $j) % 3]->getId(), $type, new \DateTimeImmutable($when));
                    ++$n;
                }
            }
        }

        $result = $aggregator->refresh();
        self::assertSame('ok', $result['status']);
        self::assertGreaterThan(0, $result['days']);
        self::assertTrue($aggregator->isFresh());
        $this->assertSameStats($live, $stats, 'итоги без сегодняшних событий');

        // Сегодняшние события добавляются вживую — без пересчёта итогов.
        foreach ([DocumentEvent::VIEW, DocumentEvent::DOWNLOAD, DocumentEvent::VIEW, DocumentEvent::UPDATE] as $k => $type) {
            $this->event($docs[$k % 3]->getId(), $users[$k % 3]->getId(), $type, new \DateTimeImmutable('today 00:00:01'));
            $this->event($docs[2]->getId(), $users[2]->getId(), $type, new \DateTimeImmutable('now'));
        }
        $this->assertSameStats($live, $stats, 'с сегодняшними событиями');
        $before = $stats->overview()['views_30d'];
        $this->event($docs[0]->getId(), $users[0]->getId(), DocumentEvent::VIEW, new \DateTimeImmutable('now'));
        self::assertSame($before + 1, $stats->overview()['views_30d']);

        // Итоги устарели (журнал изменён задним числом) — пересчитываются при первом же обращении к статистике.
        $aggregator->invalidate();
        self::assertFalse($aggregator->isFresh());
        $this->event($docs[1]->getId(), $users[1]->getId(), DocumentEvent::DOWNLOAD, new \DateTimeImmutable('-3 days'));
        $this->assertSameStats($live, $stats, 'после пересчёта по требованию');
        self::assertTrue($aggregator->isFresh());

        // Удалён документ (вместе с его событиями) — итоги устарели и пересчитаются при следующем обращении.
        $manager = $c->get(DocumentManager::class);
        $temp = (new Document($this->section('Охрана труда')))->setTitle('Временная памятка для итогов')->setCode('ВРМ-777')->setType(Document::TYPE_PAGE);
        $manager->create($temp, null, '<p>Памятка</p>', null, $this->user('admin'), true);
        $this->event($temp->getId(), $users[0]->getId(), DocumentEvent::VIEW, new \DateTimeImmutable('-5 days'));
        $aggregator->refresh();
        self::assertTrue($aggregator->isFresh());
        $manager->delete($temp, $this->user('admin'));
        $this->em()->clear();
        $aggregator->reset();
        self::assertFalse($aggregator->isFresh(), 'после удаления документа');
        $this->assertSameStats($live, $stats, 'после удаления документа');

        // Команда для cron.
        $tester = new CommandTester((new Application(static::$kernel))->find('app:stats:aggregate'));
        self::assertSame(0, $tester->execute([]));
        self::assertStringContainsString('Итоги статистики пересчитаны', preg_replace('/\s+/u', ' ', $tester->getDisplay()));
        $tester->execute(['--status' => true]);
        self::assertStringContainsString('посчитаны на сегодня', $tester->getDisplay());

        // Страница статистики за все периоды.
        $this->loginAs('admin');
        foreach ([7, 30, 90, 365, 0] as $period) {
            $this->client->request('GET', '/admin/statistics?period='.$period);
            self::assertResponseIsSuccessful();
        }
        self::assertSelectorTextContains('body', $docs[2]->getTitle());
    }

    private function assertSameStats(StatsService $live, StatsService $aggregated, string $message): void
    {
        $a = $live->overview();
        $b = $aggregated->overview();
        foreach (['views_30d', 'downloads_30d', 'new_versions_30d'] as $key) {
            self::assertSame($a[$key], $b[$key], $message.': '.$key);
        }
        ksort($a['events_by_type']);
        ksort($b['events_by_type']);
        self::assertSame(array_map('intval', $a['events_by_type']), array_map('intval', $b['events_by_type']), $message.': события по типам');
        foreach ([14, 30, 90] as $days) {
            self::assertSame($live->activityByDay($days), $aggregated->activityByDay($days), $message.': по дням, '.$days);
        }
        self::assertSame($live->activityByMonth(12), $aggregated->activityByMonth(12), $message.': по месяцам');
        foreach ([7, 30, 90, 365, null] as $days) {
            $label = $message.': период '.($days ?? 'всё время');
            self::assertSame(self::top($live->topDocuments($days, 1000)), self::top($aggregated->topDocuments($days, 1000)), $label.' — документы');
            // Лидеры: при равных суммах подсчёт по журналу берёт кого угодно — сравниваются суммы.
            $sums = static fn (array $rows): array => array_map(static fn (array $r): int => $r['views'] + $r['downloads'], $rows);
            self::assertSame($sums($live->topDocuments($days, 2)), $sums($aggregated->topDocuments($days, 2)), $label.' — лидеры');
            self::assertSame(self::sections($live->perSection($days)), self::sections($aggregated->perSection($days)), $label.' — разделы');
            self::assertSame(self::users($live->userActivity($days, 1000)), self::users($aggregated->userActivity($days, 1000)), $label.' — сотрудники');
        }
    }

    /** @return list<string> «id: просмотры/скачивания», по убыванию суммы, при равенстве — по id */
    private static function top(array $rows): array
    {
        usort($rows, static fn (array $x, array $y): int => [$y['views'] + $y['downloads'], $x['document']->getId()] <=> [$x['views'] + $x['downloads'], $y['document']->getId()]);

        return array_map(static fn (array $r): string => $r['document']->getId().': '.$r['views'].'/'.$r['downloads'], $rows);
    }

    /** @return array<int, string> */
    private static function sections(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $out[$row['section']->getId()] = \sprintf('%d/%d %d/%d', $row['views'], $row['downloads'], $row['expired'], $row['soon']);
        }
        ksort($out);

        return $out;
    }

    /** @return array<string, string> */
    private static function users(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row['username']] = \sprintf('%d/%d/%d %s', $row['views'], $row['downloads'], $row['changes'], $row['last']->format('Y-m-d H:i:s'));
        }
        ksort($out);

        return $out;
    }

    private function event(?int $document, ?int $user, string $type, \DateTimeImmutable $at): void
    {
        $this->em()->getConnection()->insert('document_event', [
            'document_id' => $document,
            'user_id' => $user,
            'type' => $type,
            'created_at' => $at->format('Y-m-d H:i:s'),
            'actor_name' => null === $user ? 'Гость' : null,
        ]);
    }
}
