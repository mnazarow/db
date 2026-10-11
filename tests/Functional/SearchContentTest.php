<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Document;
use App\Repository\DocumentTextRepository;
use App\Service\Text\TextExtractor;
use App\Service\DocumentManager;
use App\Service\Text\SearchQuery;
use App\Service\Text\TextIndexer;
use App\Tests\PortalTestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Полнотекстовый поиск по содержимому файлов: индекс document_text, выдача с фрагментами и права доступа.
 */
final class SearchContentTest extends PortalTestCase
{
    private function upload(string $name, string $content): UploadedFile
    {
        $path = sys_get_temp_dir().'/'.bin2hex(random_bytes(4)).'-'.$name;
        file_put_contents($path, $content);

        return new UploadedFile($path, $name, null, null, true);
    }

    private function manager(): DocumentManager
    {
        return static::getContainer()->get(DocumentManager::class);
    }

    /** @return list<string> заголовки документов из блока «Найдено в тексте файлов» */
    private function contentHits(string $query): array
    {
        $crawler = $this->client->request('GET', '/search?q='.urlencode($query));
        self::assertResponseIsSuccessful();

        return $crawler->filter('.snippet__title')->each(static fn ($node): string => trim($node->text()));
    }

    public function testQueryParsingAndSnippets(): void
    {
        $query = SearchQuery::parse('  Утилизация ЛЮМИНЕСЦЕНТНЫХ ламп, утилизация!  ');
        self::assertSame(['утилизация', 'люминесцентных', 'ламп'], $query->terms, 'регистр снимается, повторы отбрасываются');
        self::assertSame('+утилизац* +люминесцентн* +ламп*', $query->booleanMode());
        self::assertFalse($query->isEmpty());

        // Слова короче трёх символов в индекс FULLTEXT не попадают и из запроса к индексу убираются.
        $short = SearchQuery::parse('об отходах');
        self::assertSame(['об', 'отходах'], $short->terms);
        self::assertSame(['отходах'], $short->indexableTerms());
        self::assertSame('+отход*', $short->booleanMode());
        self::assertSame('', SearchQuery::parse('и я')->booleanMode(), 'по одним коротким словам индекс не спрашиваем');
        self::assertTrue(SearchQuery::parse('  ,,,  ')->isEmpty());

        // Обозначения с дефисами: дефис в булевом режиме — оператор «кроме», в запрос он попасть не должен.
        $code = SearchQuery::parse('ПР-2026-01');
        self::assertSame(['пр-2026-01'], $code->terms, 'для подсветки обозначение остаётся целым');
        self::assertSame(['2026'], $code->indexableTerms());
        self::assertSame('+2026*', $code->booleanMode());
        foreach (['ПР-2026-01', 'ИТ-РМ-002', '--- ---', 'a+b "c" ~d (e)', 'тест-2'] as $raw) {
            self::assertDoesNotMatchRegularExpression('/[-+~<>()"@]/', str_replace(['+', '*'], '', SearchQuery::parse($raw)->booleanMode()), $raw);
        }

        self::assertSame('дом', SearchQuery::stem('дом'), 'короткие слова не режутся');
        self::assertSame('ламп', SearchQuery::stem('лампам'));
        self::assertSame('инструкц', SearchQuery::stem('инструкции'));

        $snippet = SearchQuery::parse('лампы')->snippet('Порядок сдачи: лампы сдают на склад.');
        self::assertSame('Порядок сдачи: <mark>лампы</mark> сдают на склад.', $snippet);
        self::assertNull(SearchQuery::parse('насос')->snippet('Про лампы'), 'если слова нет — фрагмента нет');
        self::assertNull(SearchQuery::parse('насос')->snippet(null));

        // Текст экранируется, и подсветка не портит уже вставленные теги (основа «mar» внутри <mark>).
        $escaped = SearchQuery::parse('лампы <b>')->snippet('Сдать <b>лампы</b> на склад');
        self::assertSame('Сдать &lt;b&gt;<mark>лампы</mark>&lt;/b&gt; на склад', $escaped);
        $twice = SearchQuery::parse('маркировка марка')->snippet('Маркировка и марка изделия');
        self::assertSame(1, substr_count($twice ?? '', '<mark>Маркировка</mark>'));
        self::assertStringNotContainsString('<<mark>', (string) $twice);
        self::assertSame(2, substr_count((string) $twice, '<mark>'), 'каждое слово подсвечено один раз');

        // Длинный текст обрезается вокруг найденного слова.
        $long = SearchQuery::parse('иголка')->snippet(str_repeat('слово ', 200).'иголка'.str_repeat(' слово', 200), 40);
        self::assertStringStartsWith('…', (string) $long);
        self::assertStringEndsWith('…', (string) $long);
        self::assertStringContainsString('<mark>иголка</mark>', (string) $long);
        self::assertLessThan(140, mb_strlen((string) $long));
    }

    public function testContentIsIndexedAndFound(): void
    {
        $manager = $this->manager();
        $petrova = $this->user('petrova');
        $document = (new Document($this->section('ИТ')))->setTitle('Памятка по расходным материалам')->setCode('ИТ-РАС-001')->setType(Document::TYPE_FILE)->setPublic(true);
        $manager->create($document, $this->upload('pamyatka.txt', "Утилизация люминесцентных ламп\n\nЛампы сдают кладовщику по накладной; хранение дольше 30 суток запрещено."), null, null, $petrova, true);

        $text = static::getContainer()->get(DocumentTextRepository::class)->findForDocument($document);
        self::assertNotNull($text, 'после загрузки файла документ попадает в индекс');
        self::assertSame(TextExtractor::STATUS_OK, $text->getStatus());
        self::assertStringContainsString('люминесцентных', $text->getContent());
        self::assertSame(1, $text->getVersionNumber());

        // Слова из текста файла нет ни в названии, ни в реквизитах — документ находится только по содержимому.
        $crawler = $this->client->request('GET', '/search?q='.urlencode('люминесцентных ламп'));
        self::assertResponseIsSuccessful();
        self::assertSame(0, $crawler->filter('.doc-table__title')->count(), 'по реквизитам ничего не находится');
        self::assertSame(['Памятка по расходным материалам'], $crawler->filter('.snippet__title')->each(static fn ($n): string => trim($n->text())));
        self::assertStringContainsString('<mark>люминесцентн', $crawler->filter('.snippet__text')->html());

        // Морфология по префиксу: другая форма слова тоже находит документ.
        self::assertContains('Памятка по расходным материалам', $this->contentHits('лампа'));
        self::assertContains('Памятка по расходным материалам', $this->contentHits('кладовщику накладной'));
        self::assertSame([], $this->contentHits('экскаватор'), 'лишнего не находим');
        self::assertSame([], $this->contentHits('лампы экскаватор'), 'все слова запроса обязательны');
        // Запрос с дефисами и служебными символами не должен ломать поиск (раньше был 500 от MATCH … AGAINST).
        foreach (['ИТ-РАС-001', 'лампы -склад', 'а"б (в)', '***'] as $raw) {
            $this->client->request('GET', '/search?q='.urlencode($raw));
            self::assertResponseIsSuccessful($raw);
        }

        // Найденное по названию не дублируется в блоке «в тексте файлов».
        $crawler = $this->client->request('GET', '/search?q='.urlencode('Памятка'));
        self::assertGreaterThan(0, $crawler->filter('.doc-table__title')->count());
        self::assertSame(0, $crawler->filter('.snippet__title')->count());
    }

    public function testPhrasesInQuotesAreMatchedExactly(): void
    {
        // Разбор: фразы в любых кавычках, слова вне кавычек — с отбрасыванием окончаний.
        $query = SearchQuery::parse('план «эвакуации людей» "по охране   труда" охрана');
        self::assertSame(['эвакуации людей', 'по охране труда'], $query->phrases);
        self::assertSame(['план', 'охрана'], $query->looseTerms);
        self::assertSame('+план* +охра* +"эвакуации людей" +"охране труда"', $query->booleanMode(), 'короткие слова фразы индекс не хранит');
        self::assertSame([], SearchQuery::parse('незакрытая "кавычка')->phrases, 'непарная кавычка — обычный символ');
        self::assertSame(['пр-2026/01'], SearchQuery::parse('“ПР-2026/01”')->phrases);
        self::assertTrue(SearchQuery::parse('"по охране труда"')->matchesPhrases('Инструкция ПО   охране, труда для всех'));
        self::assertFalse(SearchQuery::parse('"по охране труда"')->matchesPhrases('охране по труда'), 'порядок слов важен');
        self::assertFalse(SearchQuery::parse('"охране труд"')->matchesPhrases('по охране труда'), 'фраза — целыми словами');
        self::assertTrue(SearchQuery::parse('«ПР 2026 01»')->matchesPhrases('Приказ ПР-2026/01 от января'), 'знаки препинания не мешают');
        self::assertSame('Есть инструкция <mark>по охране труда</mark> и прочее', SearchQuery::parse('"по охране труда"')->snippet('Есть инструкция по охране   труда и прочее'));

        $manager = $this->manager();
        $petrova = $this->user('petrova');
        $first = (new Document($this->section('ИТ')))->setTitle('Памятка дежурному')->setCode('ИТ-ДЕЖ-001')->setType(Document::TYPE_FILE)->setPublic(true);
        $manager->create($first, $this->upload('duty.txt', "Резервное копирование серверов выполняется ежедневно.\nПроверка журналов — еженедельно."), null, null, $petrova, true);
        $second = (new Document($this->section('ИТ')))->setTitle('Памятка администратору')->setCode('ИТ-АДМ-002')->setType(Document::TYPE_FILE)->setPublic(true);
        $manager->create($second, $this->upload('admin.txt', "Копирование резервное не требуется для серверов тестовой среды."), null, null, $petrova, true);

        self::assertEqualsCanonicalizing(['Памятка дежурному', 'Памятка администратору'], $this->contentHits('резервное копирование серверов'), 'без кавычек — все слова в любом порядке');
        self::assertSame(['Памятка дежурному'], $this->contentHits('"резервное копирование серверов"'), 'в кавычках — слова подряд');
        self::assertSame(['Памятка дежурному'], $this->contentHits('«Резервное копирование» журналов'));
        self::assertSame([], $this->contentHits('"копирование резервное серверов"'));

        // Короткое слово или стоп-слово внутри фразы: фраза делится на части для индекса, а целиком
        // проверяется по тексту.
        self::assertSame('+договор +поставку', SearchQuery::parse('"договор на поставку"')->booleanMode());
        self::assertSame('+статья +закона', SearchQuery::parse('"Статья 5 закона"')->booleanMode());
        self::assertSame('+terms +use', SearchQuery::parse('"terms of the use"')->booleanMode(), 'стоп-слова InnoDB не передаются');
        $third = (new Document($this->section('ИТ')))->setTitle('Памятка по закупкам')->setCode('ИТ-ЗАК-003')->setType(Document::TYPE_FILE)->setPublic(true);
        $manager->create($third, $this->upload('buy.txt', "Договор на поставку оборудования заключается после согласования. Статья 5 закона о закупках.\nSee the terms of the use."), null, null, $this->user('petrova'), true);
        self::assertSame(['Памятка по закупкам'], $this->contentHits('"договор на поставку"'));
        self::assertSame(['Памятка по закупкам'], $this->contentHits('"статья 5 закона"'));
        self::assertSame(['Памятка по закупкам'], $this->contentHits('"terms of the use"'));
        self::assertSame([], $this->contentHits('"договор поставку"'), 'слова фразы — подряд');
        self::assertSame(['Памятка по закупкам'], $this->contentHits('the закупках'), 'стоп-слово вне кавычек не обнуляет поиск');

        // Реквизиты: фраза ищется в названии целиком.
        $crawler = $this->client->request('GET', '/search?q='.urlencode('"памятка дежурному"'));
        self::assertSame(['Памятка дежурному'], $crawler->filter('.doc-table__title')->each(static fn ($n): string => trim($n->text())));
        $crawler = $this->client->request('GET', '/search?q='.urlencode('"дежурному памятка"'));
        self::assertSame(0, $crawler->filter('.doc-table__title')->count());
    }

    public function testIndexFollowsVersionsStatusAndDeletion(): void
    {
        $texts = static::getContainer()->get(DocumentTextRepository::class);
        $document = (new Document($this->section('ИТ')))->setTitle('Регламент выдачи техники')->setCode('ИТ-ВЫД-001')->setType(Document::TYPE_FILE)->setPublic(true);
        $this->manager()->create($document, $this->upload('v1.txt', 'Выдаётся ноутбук с инвентарным номером.'), null, null, $this->user('petrova'), true);
        self::assertContains('Регламент выдачи техники', $this->contentHits('инвентарным'));

        // Новая версия переиндексируется: старый текст больше не находится, новый находится.
        // (После HTTP-запроса EntityManager очищается, поэтому сущности берём заново.)
        $document = $this->document('ИТ-ВЫД-001');
        $this->manager()->addFileVersion($document, $this->upload('v2.txt', 'Выдаётся планшет с гравировкой подразделения.'), 'Замена', $this->user('petrova'));
        self::assertSame(2, $texts->findForDocument($document)?->getVersionNumber());
        self::assertSame([], $this->contentHits('инвентарным'));
        self::assertContains('Регламент выдачи техники', $this->contentHits('гравировкой'));

        // Черновик виден в поиске только модератору своего раздела.
        $this->manager()->unpublish($this->document('ИТ-ВЫД-001'), $this->user('petrova'));
        self::assertSame([], $this->contentHits('гравировкой'), 'гостю черновик не показывается');
        $this->loginAs('smirnov');
        self::assertSame([], $this->contentHits('гравировкой'), 'читателю черновик не показывается');
        $this->loginAs('petrova');
        self::assertContains('Регламент выдачи техники', $this->contentHits('гравировкой'), 'модератор раздела черновик видит');

        // Удаление документа убирает и запись индекса (ON DELETE CASCADE + TextIndexer::remove).
        $document = $this->document('ИТ-ВЫД-001');
        $id = (int) $document->getId();
        $this->manager()->publish($document, $this->user('petrova'));
        $this->manager()->delete($this->document('ИТ-ВЫД-001'), $this->user('petrova'));
        self::assertNull($texts->find($id));
        self::assertSame([], $this->contentHits('гравировкой'));
    }

    public function testInternalDocumentIsNotFoundByGuest(): void
    {
        $manager = $this->manager();
        $ivanov = $this->user('ivanov');
        $document = (new Document($this->section('Общие документы')))->setTitle('Служебная записка о переезде')->setCode('СЗ-2026-77')->setType(Document::TYPE_FILE)->setPublic(false);
        $manager->create($document, $this->upload('zapiska.txt', 'Переезд бухгалтерии в корпус 3 назначен на субботу.'), null, null, $ivanov, true);

        self::assertSame([], $this->contentHits('бухгалтерии корпус'), 'внутренний документ гостю не показывается');
        $this->loginAs('smirnov');
        self::assertContains('Служебная записка о переезде', $this->contentHits('бухгалтерии корпус'));
    }

    public function testReindexCommandAndStatistics(): void
    {
        $indexer = static::getContainer()->get(TextIndexer::class);
        $texts = static::getContainer()->get(DocumentTextRepository::class);
        $document = $this->document('ОТ-001');
        $indexer->remove($document);
        self::assertNull($texts->findForDocument($document));

        // В тестовой среде SHELL_VERBOSITY=-1, поэтому вывод запрашиваем явно (--verbose).
        $application = new Application(static::$kernel);
        $application->setAutoExit(false);
        $output = new BufferedOutput();
        self::assertSame(0, $application->run(new ArrayInput(['command' => 'app:search:reindex', '--verbose' => true]), $output));
        $result = $output->fetch();
        self::assertStringContainsString('Обработано документов: 1', $result, 'переиндексируются только отставшие документы');
        self::assertStringContainsString('Инструкция по охране труда', $result);
        self::assertNotNull($texts->findForDocument($this->document('ОТ-001')), 'команда достраивает пропущенные документы');

        $output = new BufferedOutput();
        self::assertSame(0, $application->run(new ArrayInput(['command' => 'app:search:reindex', '--status' => true, '--verbose' => true]), $output));
        $status = $output->fetch();
        self::assertStringContainsString('В индексе', $status);
        self::assertStringContainsString('Ожидают индексации', $status);

        $summary = $texts->summary();
        self::assertGreaterThan(0, $summary['indexed']);
        self::assertGreaterThan(0, $summary['with_text']);
        self::assertLessThanOrEqual($summary['documents'], $summary['indexed']);
        self::assertSame([], $texts->findOutdatedDocumentIds(), 'после переиндексации отставших документов нет');
    }

    public function testWebReindexAllMarksIndexOutdatedAndRebuildsFirstPortion(): void
    {
        $connection = $this->em()->getConnection();
        $connection->beginTransaction();
        try {
            static::getContainer()->get(TextIndexer::class)->reindex(500);
            self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM document_text WHERE version_number = 0'));
            $connection->executeStatement("UPDATE document_text SET content = 'устаревший текст' WHERE status = 'ok'");
            $documents = (int) $connection->fetchOne('SELECT COUNT(*) FROM document WHERE current_version_id IS NOT NULL');
            $limit = 200; // SettingsController::REINDEX_LIMIT

            $this->loginAs('admin');
            $crawler = $this->client->request('GET', '/admin/settings');
            self::assertResponseIsSuccessful();
            $this->client->submit($crawler->selectButton('Переиндексировать всё')->form());
            self::assertResponseRedirects('/admin/settings');
            $text = $this->client->followRedirect()->filter('.flashes')->text();
            self::assertStringContainsString(\sprintf('Проиндексировано документов: %d ', min($documents, $limit)), $text);
            self::assertStringContainsString(\sprintf('Осталось: %d', max(0, $documents - $limit)), $text);
            if ($documents <= $limit) {
                self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM document_text WHERE version_number = 0'));
                self::assertSame(0, (int) $connection->fetchOne("SELECT COUNT(*) FROM document_text WHERE content = 'устаревший текст'"));

                // «Проиндексировать изменившиеся» без отставших документов ничего не делает.
                $crawler = $this->client->request('GET', '/admin/settings');
                $this->client->submit($crawler->selectButton('Проиндексировать изменившиеся')->form());
                self::assertStringContainsString('Проиндексировано документов: 0', $this->client->followRedirect()->filter('.flashes')->text());
            }
        } finally {
            while ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
            $this->em()->clear();
        }
    }

}
