<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Document;
use App\Repository\DocumentRepository;
use App\Repository\SectionRepository;
use App\Service\StatsService;
use App\Tests\PortalTestCase;

/**
 * Статистика актуальности по дереву разделов: сводка, счётчики по поддеревьям, фильтры, список устаревших, CSV.
 */
final class ValidityStatisticsTest extends PortalTestCase
{
    public function testServiceAggregatesSubtrees(): void
    {
        $stats = static::getContainer()->get(StatsService::class);
        $result = $stats->validityBySection();
        // Демо-данные: просрочены ПОЛ-003, ИТ-ПТ-002, ОТ-002; истекают ИБ-002, ОТ-001, ТК-041 (архив и черновики не учитываются).
        self::assertSame(3, $result['totals']['expired']);
        self::assertSame(3, $result['totals']['soon']);
        $published = static::getContainer()->get(DocumentRepository::class)->countByStatus()[Document::STATUS_PUBLISHED];
        self::assertSame($published, $result['totals']['documents'], 'все опубликованные документы демо-данных');
        self::assertCount(static::getContainer()->get(SectionRepository::class)->countAll(), $result['rows'], 'по строке на каждый раздел');

        $byName = [];
        foreach ($result['rows'] as $row) {
            $byName[$row['section']->getName()] = $row;
        }
        self::assertSame(3, $byName['Общие документы']['subtree']['expired']);
        self::assertSame(2, $byName['Общие документы']['subtree']['soon']);
        self::assertSame(0, $byName['Общие документы']['direct']['expired'], 'в самом корневом разделе документов нет');
        self::assertSame(1, $byName['Охрана труда']['direct']['expired']);
        self::assertSame(1, $byName['Охрана труда']['direct']['soon']);
        self::assertSame(45, $byName['Охрана труда']['max_overdue']);
        self::assertSame(45, $byName['Инструкции']['max_overdue'], 'максимальная просрочка поднимается к предкам');
        self::assertSame(0, $byName['Кадры']['problems']);
        self::assertSame(1, $byName['Производство']['subtree']['soon']);

        $onlyProblems = $stats->validityBySection(null, true);
        foreach ($onlyProblems['rows'] as $row) {
            self::assertGreaterThan(0, $row['problems'], $row['section']->getName());
        }
        self::assertLessThan(\count($result['rows']), \count($onlyProblems['rows']));

        $root = $this->section('Производство');
        $sub = $stats->validityBySection($root);
        self::assertSame(0, $sub['totals']['expired']);
        self::assertSame(1, $sub['totals']['soon']);
        self::assertSame('Производство', $sub['rows'][0]['section']->getName());

        $outdated = $stats->outdatedDocuments();
        self::assertCount(6, $outdated['documents']);
        $owners = array_column($outdated['owners'], null, 'name');
        self::assertSame(2, $owners['Иванов Игорь Петрович']['expired']);
        self::assertSame(1, $owners['Иванов Игорь Петрович']['soon']);
        self::assertSame(1, $owners['Петрова Мария Сергеевна']['expired']);
        self::assertSame('Иванов Игорь Петрович', $outdated['owners'][0]['name'], 'сортировка по числу просроченных');
        self::assertCount(1, $stats->outdatedDocuments($root)['documents'], 'в разделе Производство истекает только ТК-041');
    }

    public function testPageFiltersAndCsv(): void
    {
        $this->loginAs('sidorov');
        $this->client->request('GET', '/admin/statistics/validity');
        self::assertResponseStatusCodeSame(403);

        $this->loginAs('admin');
        $crawler = $this->client->request('GET', '/admin/statistics/validity');
        self::assertResponseIsSuccessful();
        $tiles = $crawler->filter('.grid--stats .stat__value')->each(static fn ($n) => trim($n->text()));
        self::assertSame(['3', '3'], \array_slice($tiles, 0, 2), 'просрочено / истекает');
        self::assertSame(static::getContainer()->get(SectionRepository::class)->countAll(), $crawler->filter('.validity-table tbody tr')->count(), 'все разделы дерева');
        self::assertSame(6, $crawler->filter('.outdated-table tbody tr:not(.outdated-table__group)')->count());
        self::assertStringContainsString('Инструкция по пожарной безопасности', $crawler->text());

        $crawler = $this->client->request('GET', '/admin/statistics/validity?section=&only_problems=1');
        self::assertResponseIsSuccessful();
        $rows = $crawler->filter('.validity-table tbody tr');
        self::assertGreaterThan(0, $rows->count());
        self::assertSame(0, $crawler->filter('.validity-table tbody tr.is-inactive')->count(), 'остаются только разделы с проблемами');

        $section = $this->section('Кадры');
        $crawler = $this->client->request('GET', '/admin/statistics/validity?section='.$section->getId());
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Просроченных и истекающих документов нет', $crawler->text());
        self::assertSame(['0', '0'], \array_slice($crawler->filter('.grid--stats .stat__value')->each(static fn ($n) => trim($n->text())), 0, 2));

        $this->client->request('GET', '/admin/statistics/validity.csv');
        self::assertResponseIsSuccessful();
        // StreamedResponse: содержимое уже перехвачено BrowserKit при отправке.
        $csv = $this->client->getInternalResponse()->getContent();
        self::assertStringContainsString('text/csv', (string) $this->client->getResponse()->headers->get('Content-Type'));
        self::assertSame(7, \count(array_filter(explode("\n", trim($csv)))), 'заголовок + 6 документов');
        self::assertStringContainsString('просрочен', $csv);
        self::assertStringContainsString('ОТ-002', $csv);

        // Пустое значение фильтра (форма с «Все разделы») не должно приводить к ошибке 400.
        $this->client->request('GET', '/admin/documents?section=&status=&type=&validity=&owner=&access=&sort=updated');
        self::assertResponseIsSuccessful();
        $this->client->request('GET', '/admin/events?user=&section=&document=');
        self::assertResponseIsSuccessful();
    }
}
