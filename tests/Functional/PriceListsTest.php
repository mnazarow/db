<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\PriceList;
use App\Entity\PriceRevision;
use App\Repository\PriceItemRepository;
use App\Repository\PriceListRepository;
use App\Repository\PriceRevisionRepository;
use App\Service\Price\PriceListService;
use App\Service\Price\PriceMapper;
use App\Service\Price\PriceSheetReader;
use App\Service\Process\Shell;
use App\Service\Xlsx\XlsxReader;
use App\Service\Xlsx\XlsxWriter;
use App\Tests\PortalTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Раздел «Прайс-листы»: загрузка из Excel с сопоставлением столбцов, группы, поиск, гости, выгрузка,
 * модерация редакций помощника маркетолога, сравнение редакций и возврат прежней.
 */
final class PriceListsTest extends PortalTestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/price-test-'.bin2hex(random_bytes(4));
        @mkdir($this->dir, 0700, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir.'/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
        parent::tearDown();
    }

    public function testMapperRecognisesTypicalPriceSheet(): void
    {
        self::assertSame('1234.56', PriceMapper::price('1 234,56 ₽'));
        self::assertSame('1234.56', PriceMapper::price('1,234.56'));
        self::assertSame('1234.5', PriceMapper::price('1.234,50 руб.'));
        self::assertSame('15000', PriceMapper::price("15\u{00A0}000"));
        self::assertSame('по запросу', PriceMapper::price('по запросу'));
        self::assertSame('', PriceMapper::price('  '));
        self::assertSame('skip', PriceMapper::roleOf('№ п/п'));
        self::assertSame('extra', PriceMapper::roleOf('Код ТН ВЭД'));
        self::assertSame('price', PriceMapper::roleOf('Цена за ед., руб.'));

        $mapper = new PriceMapper();
        $rows = $this->sampleRows();
        $mapping = $mapper->guess($rows);
        self::assertSame(4, $mapping['header']);
        self::assertSame(['skip', 'sku', 'name', 'unit', 'price', 'price', 'extra'], array_values(array_column($mapping['columns'], 'role')));
        $result = $mapper->apply($rows, $mapping);
        self::assertCount(5, $result['items']);
        self::assertSame(2, $result['groups']);
        self::assertSame(['Цена опт, руб.', 'Цена розн., руб.'], $result['priceColumns']);
        self::assertSame('Насосные станции', $result['items'][0]['group']);
        self::assertSame('Комплектующие', $result['items'][4]['group']);
        self::assertSame(['по запросу', ''], $result['items'][4]['prices']);
        self::assertNull($result['items'][4]['price']);
        self::assertStringContainsString('Без цены: 1', implode(' ', $result['warnings']));

        // Без строки заголовков: наименование — самый «текстовый» столбец, цена — числовой.
        $plain = [1 => [1 => 'Насос погружной Акватор-1', 2 => '12500'], 2 => [1 => 'Насос поверхностный Джет-60', 2 => '9 800,00'], 3 => [1 => 'Реле давления', 2 => '1250']];
        $guess = $mapper->guess($plain);
        self::assertSame(0, $guess['header']);
        self::assertSame(['name', 'price'], array_values(array_column($guess['columns'], 'role')));
        self::assertCount(3, $mapper->apply($plain, $guess)['items']);
    }

    public function testCsvInWindows1251WithSemicolons(): void
    {
        $path = $this->dir.'/price.csv';
        file_put_contents($path, mb_convert_encoding("Артикул;Наименование;Цена\nA-1;Насос «Малыш»;1 500,00\nA-2;Шланг 1/2\";99\n", 'Windows-1251', 'UTF-8'));
        $rows = PriceSheetReader::csv($path);
        self::assertSame('Насос «Малыш»', $rows[2][2]);
        self::assertSame('Шланг 1/2"', $rows[3][2]);
        self::assertSame('1 500,00', $rows[2][3]);
    }

    public function testFormatIsDetectedByContent(): void
    {
        $xlsx = $this->sampleXlsx('a.xlsx');
        copy($xlsx, $this->dir.'/export-from-1c.xls');
        copy($xlsx, $this->dir.'/noext');
        file_put_contents($this->dir.'/plain', "Наименование;Цена\nНасос;100\n");
        self::assertSame('xlsx', PriceSheetReader::detect($this->dir.'/export-from-1c.xls', 'Прайс.xls'));
        self::assertSame('xlsx', PriceSheetReader::detect($this->dir.'/noext', 'blob'));
        self::assertSame('csv', PriceSheetReader::detect($this->dir.'/plain', 'plain'));
        $service = static::getContainer()->get(PriceListService::class);
        $list = $service->create($this->user('markova'), 'Из учётной системы', null, 'RUB', true);
        $revision = $service->store($list, $this->dir.'/export-from-1c.xls', 'Прайс.xls', $this->user('markova'));
        self::assertSame('xlsx', $revision->getExtension());
        self::assertSame('Прайс.xls', $revision->getOriginalName());
        self::assertSame(5, $service->preview($revision, null, null, null)['result']['count']);
        $blob = $service->store($list, $this->dir.'/noext', 'blob', $this->user('markova'));
        self::assertSame('blob.xlsx', $blob->getOriginalName());
        $service->delete($list, $this->user('markova'));
    }

    public function testMarketerPublishesListAndEveryoneFindsPrices(): void
    {
        $this->loginAs('markova');
        $crawler = $this->client->request('GET', '/prices/new');
        self::assertResponseIsSuccessful();
        $form = $crawler->filter('form.card--form')->form();
        $form['title'] = 'Оптовый прайс-лист';
        $form['description'] = 'Цены с НДС для дилеров';
        $form['file']->upload($this->sampleXlsx('dealer.xlsx'));
        $this->client->submit($form);
        self::assertResponseRedirects();
        $mappingUrl = (string) $this->client->getResponse()->headers->get('Location');
        self::assertMatchesRegularExpression('#/prices/\d+/revisions/1/mapping$#', $mappingUrl);

        $crawler = $this->client->request('GET', $mappingUrl);
        self::assertResponseIsSuccessful();
        self::assertSame('sku', $crawler->filter('select[name="roles[2]"] option[selected]')->attr('value'));
        self::assertSame('name', $crawler->filter('select[name="roles[3]"] option[selected]')->attr('value'));
        self::assertSame('5', $crawler->filter('input[name="header"]')->attr('value'));
        self::assertStringContainsString('Станция «Джилекс 60/32»', $crawler->filter('.price-map')->text());
        self::assertStringContainsString('Показаны строки 5–12 из 12', $crawler->filter('.price-map')->text());

        // Строка заголовков вручную, роль столбца «Примечание» — не загружать: предпросмотр это учитывает.
        $crawler = $this->client->request('GET', $mappingUrl, ['sheet' => 'Прайс', 'header' => '5', 'roles' => [1 => 'skip', 2 => 'sku', 3 => 'name', 4 => 'unit', 5 => 'price', 6 => 'price', 7 => 'skip']]);
        self::assertSame('skip', $crawler->filter('select[name="roles[7]"] option[selected]')->attr('value'));

        $form = $crawler->filter('#map-form')->form();
        $form['effective_from'] = '2026-10-01';
        $form['note'] = 'Новые цены на станции';
        $this->client->submit($form);
        self::assertResponseRedirects();
        $crawler = $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.flashes', 'редакция 1, 5 позиций');
        $text = $crawler->filter('.price-table')->text();
        self::assertStringContainsString('Насосные станции', $text);
        self::assertStringContainsString("1\u{00A0}234,50", $text);
        self::assertStringContainsString('по запросу', $text);
        self::assertStringNotContainsString('Примечание', $crawler->filter('.price-table thead')->text());
        self::assertStringContainsString('действует с 01.10.2026', $crawler->filter('.hero__meta')->text());

        $list = static::getContainer()->get(PriceListRepository::class)->findOneBy(['title' => 'Оптовый прайс-лист']);
        self::assertInstanceOf(PriceList::class, $list);
        $id = $list->getId();
        self::assertSame(5, $list->getCurrentRevision()?->getItemCount());

        // Поиск в прайс-листе, группа, сортировка.
        $crawler = $this->client->request('GET', '/prices/'.$id, ['q' => 'джилекс']);
        self::assertSame(1, $crawler->filter('.price-table tbody tr td.price-table__name')->count());
        $crawler = $this->client->request('GET', '/prices/'.$id, ['group' => 'Комплектующие']);
        self::assertSame(2, $crawler->filter('.price-table tbody tr td.price-table__name')->count());
        $crawler = $this->client->request('GET', '/prices/'.$id, ['sort' => 'price_desc']);
        self::assertStringContainsString('Станция', $crawler->filter('.price-table tbody tr td.price-table__name')->first()->text());

        // Выгрузка в Excel и исходный файл.
        $this->client->request('GET', '/prices/'.$id.'/export');
        self::assertResponseIsSuccessful();
        $copy = $this->dir.'/export.xlsx';
        file_put_contents($copy, (string) $this->client->getInternalResponse()->getContent());
        $sheets = (new XlsxReader())->read($copy);
        self::assertSame('Наименование', $sheets[0]['rows'][1][3]);
        self::assertSame('Станция «Джилекс 60/32»', $sheets[0]['rows'][2][3] ?? $sheets[0]['rows'][3][3]);
        $this->client->request('GET', '/prices/'.$id.'/revisions/1/file');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('dealer.xlsx', (string) $this->client->getResponse()->headers->get('Content-Disposition'));

        // Общий поиск портала и поиск по всем прайс-листам.
        $crawler = $this->client->request('GET', '/search', ['q' => 'реле давления']);
        self::assertStringContainsString('В прайс-листах', $crawler->filter('.section')->text());
        self::assertStringContainsString('Реле давления РДМ-5', $crawler->filter('.section')->text());
        $crawler = $this->client->request('GET', '/prices', ['q' => 'РДМ']);
        self::assertSame(1, $crawler->filter('.price-table tbody tr')->count());

        // Закрытый прайс-лист гость не видит; открытый — видит (просмотр без входа разрешён по умолчанию).
        $closed = static::getContainer()->get(PriceListService::class)->create($this->user('markova'), 'Закрытые цены', null, 'RUB', false);
        $revision = static::getContainer()->get(PriceListService::class)->store($closed, $this->sampleXlsx('closed.xlsx'), 'closed.xlsx', $this->user('markova'));
        $preview = static::getContainer()->get(PriceListService::class)->preview($revision, null, null, null);
        static::getContainer()->get(PriceListService::class)->import($revision, $preview['sheet'], $preview['mapping'], null, null, $this->user('markova'));
        $this->client->request('GET', '/logout');
        $this->client->getCookieJar()->clear();
        $crawler = $this->client->request('GET', '/prices');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Оптовый прайс-лист', $crawler->filter('.price-grid')->text());
        self::assertStringNotContainsString('Закрытые цены', $crawler->filter('.price-grid')->text());
        $this->client->request('GET', '/prices/'.$closed->getId());
        self::assertResponseStatusCodeSame(404);
        $crawler = $this->client->request('GET', '/prices/'.$id);
        self::assertResponseIsSuccessful();
        self::assertSame(0, $crawler->filter('.price-upload')->count());
        // Исходный файл (в нём могут быть скрытые столбцы) — только если разрешено в настройках; выгрузка — всем.
        self::assertSame(0, $crawler->filter('a[href$="/revisions/1/file"]')->count());
        $this->client->request('GET', '/prices/'.$id.'/revisions/1/file');
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', '/prices/'.$id.'/export');
        self::assertResponseIsSuccessful();
        $list = static::getContainer()->get(PriceListRepository::class)->find($id);
        $list->setOriginalDownload(true);
        $this->em()->flush();
        $this->client->request('GET', '/prices/'.$id.'/revisions/1/file');
        self::assertResponseIsSuccessful();
        $crawler = $this->client->request('GET', '/prices', ['q' => 'реле']);
        self::assertSame(1, $crawler->filter('.price-table tbody tr')->count(), 'гость находит только позиции открытых прайс-листов');
        $this->client->request('GET', '/prices/new');
        self::assertResponseRedirects();

        // Сотрудник видит закрытый, но не может загружать.
        $this->loginAs('smirnov');
        $this->client->request('GET', '/prices/'.$closed->getId());
        self::assertResponseIsSuccessful();
        $this->client->request('GET', '/prices/new');
        self::assertResponseStatusCodeSame(403);
    }

    public function testAssistantRevisionIsModeratedAndComparedWithCurrent(): void
    {
        $service = static::getContainer()->get(PriceListService::class);
        $markova = $this->user('markova');
        $list = $service->create($markova, 'Розница', null, 'RUB', true);
        $first = $service->store($list, $this->sampleXlsx('retail.xlsx'), 'retail.xlsx', $markova);
        $preview = $service->preview($first, null, null, null);
        self::assertSame(5, $preview['mapping']['header']);
        $service->import($first, $preview['sheet'], $preview['mapping'], null, null, $markova);
        self::assertSame(5, $first->getItemCount());
        $id = $list->getId();

        // Помощник загружает CSV: одна позиция ушла, одна новая, цена станции выросла, реле подешевело.
        $csv = $this->dir.'/new.csv';
        file_put_contents($csv, "№;Артикул;Наименование товара;Ед. изм.;Цена опт, руб.;Цена розн., руб.\n"
            .";;Насосные станции;;;\n1;DJ-60;Станция «Джилекс 60/32»;шт;16 000;18 500\n2;AQ-1;Насос «Акватор-1»;шт;7 200;8 900\n"
            .";;Комплектующие;;;\n3;RDM-5;Реле давления РДМ-5;шт;1 100;1 300\n4;MN-5;Манометр 0–10 бар;шт;450;600\n");
        $this->loginAs('belov');
        $this->client->request('POST', '/prices/'.$id.'/upload', ['_token' => $this->csrf()], ['file' => new UploadedFile($csv, 'new.csv', 'text/csv', null, true)]);
        self::assertResponseRedirects('/prices/'.$id.'/revisions/2/mapping');
        $crawler = $this->client->followRedirect();
        self::assertSame('name', $crawler->filter('select[name="roles[3]"] option[selected]')->attr('value'), 'роли перенесены по названиям столбцов');
        self::assertStringContainsString('По сравнению с действующей редакцией', $crawler->filter('.price-map__result')->text());
        $form = $crawler->filter('#map-form')->form();
        self::assertStringContainsString('Отправить на модерацию', $crawler->filter('#map-form .price-map__result button[type=submit]')->text());
        $this->client->submit($form);
        self::assertResponseRedirects('/prices/'.$id.'/revisions/2');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flashes', 'отправлена на модерацию');

        $revision = static::getContainer()->get(PriceRevisionRepository::class)->findOneBy(['priceList' => $list, 'number' => 2]);
        self::assertSame(PriceRevision::STATUS_PENDING, $revision->getStatus());
        self::assertEquals(['added' => 1, 'removed' => 2, 'changed' => 2, 'up' => 1, 'down' => 1, 'base' => 1], $revision->getStats());

        // Сотрудники пока видят прежние цены.
        $this->loginAs('smirnov');
        $crawler = $this->client->request('GET', '/prices/'.$id, ['q' => 'джилекс']);
        self::assertStringContainsString("15\u{00A0}000", $crawler->filter('.price-table')->text());
        $this->client->request('GET', '/prices/'.$id.'/revisions/2');
        self::assertResponseStatusCodeSame(404);

        // Маркетолог: значок в шапке, блок на странице модерации, отличия; возврат без комментария не проходит.
        $this->loginAs('markova');
        $crawler = $this->client->request('GET', '/moderation');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Розница — редакция 2', $crawler->filter('#prices')->text());
        self::assertGreaterThanOrEqual(1, (int) $crawler->filter('.site-header .ack-chip--approval .ack-chip__count')->first()->text());
        $crawler = $this->client->request('GET', '/prices/'.$id.'/revisions/2');
        self::assertStringContainsString('+1 новая', $crawler->filter('.section')->text());
        self::assertStringContainsString('−2 убрано', $crawler->filter('.section')->text());
        self::assertStringContainsString("+6,7\u{00A0}%", $crawler->filter('.section')->text());
        self::assertStringContainsString('Насос «Малыш»', $crawler->filter('.section')->text(), 'убранная позиция');
        $this->client->request('POST', '/prices/'.$id.'/revisions/2/decide', ['_token' => $this->csrf(), 'decision' => 'reject', 'note' => '']);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flashes', 'Напишите, что исправить');
        $this->client->request('POST', '/prices/'.$id.'/revisions/2/decide', ['_token' => $this->csrf(), 'decision' => 'reject', 'note' => 'Укажите дату начала действия']);
        self::assertSame(PriceRevision::STATUS_REJECTED, $this->reload($revision)->getStatus());

        // Помощник правит сопоставление и отправляет снова; маркетолог одобряет из списка модерации.
        $this->loginAs('belov');
        $crawler = $this->client->request('GET', '/prices/'.$id.'/revisions/2');
        self::assertStringContainsString('Укажите дату начала действия', $crawler->filter('.section')->text());
        $crawler = $this->client->request('GET', '/prices/'.$id.'/revisions/2/mapping');
        $form = $crawler->filter('#map-form')->form();
        $form['effective_from'] = '2026-11-01';
        $this->client->submit($form);
        self::assertSame(PriceRevision::STATUS_PENDING, $this->reload($revision)->getStatus());
        self::assertNull($this->reload($revision)->getDecisionNote());

        $this->loginAs('markova');
        $this->client->request('POST', '/prices/'.$id.'/revisions/2/decide', ['_token' => $this->csrf(), 'decision' => 'approve', 'note' => '', 'back' => '/moderation#prices']);
        self::assertResponseRedirects('/moderation#prices');
        $list = static::getContainer()->get(PriceListRepository::class)->find($id);
        self::assertSame(2, $list->getCurrentRevision()?->getNumber());
        $crawler = $this->client->request('GET', '/prices/'.$id, ['q' => 'джилекс']);
        self::assertStringContainsString("16\u{00A0}000", $crawler->filter('.price-table')->text());

        // История: вернуть первую редакцию, удалить вторую.
        $crawler = $this->client->request('GET', '/prices/'.$id.'/revisions');
        self::assertSame(2, $crawler->filter('.admin-table tbody tr')->count());
        $this->client->request('POST', '/prices/'.$id.'/revisions/1/current', ['_token' => $this->csrf()]);
        self::assertSame(1, static::getContainer()->get(PriceListRepository::class)->find($id)->getCurrentRevision()?->getNumber());
        $this->client->request('POST', '/prices/'.$id.'/revisions/1/delete', ['_token' => $this->csrf()]);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flashes', 'Действующую редакцию удалить нельзя');
        $path = $service->path($revision);
        self::assertFileExists($path);
        $this->client->request('POST', '/prices/'.$id.'/revisions/2/delete', ['_token' => $this->csrf()]);
        self::assertFileDoesNotExist($path);
        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM price_item WHERE revision_id = ?', [$revision->getId()]));

        // Архив и удаление прайс-листа.
        $this->client->request('POST', '/prices/'.$id.'/archive', ['_token' => $this->csrf(), 'archived' => '1']);
        $this->loginAs('smirnov');
        $this->client->request('GET', '/prices/'.$id);
        self::assertResponseStatusCodeSame(404);
        self::assertSame([], static::getContainer()->get(PriceItemRepository::class)->searchCurrent('джилекс', false, 10, $list));
        $this->loginAs('markova');
        $this->client->request('POST', '/prices/'.$id.'/delete', ['_token' => $this->csrf()]);
        self::assertResponseRedirects('/prices');
        $this->em()->clear();
        self::assertNull(static::getContainer()->get(PriceListRepository::class)->find($id));
    }

    public function testBadFileAndCancelledFirstUploadLeaveNothing(): void
    {
        $this->loginAs('belov');
        $bad = $this->dir.'/broken.xlsx';
        file_put_contents($bad, 'not a zip');
        $crawler = $this->client->request('GET', '/prices/new');
        $form = $crawler->filter('form.card--form')->form();
        $form['title'] = 'Битый прайс';
        $form['file']->upload($bad);
        $crawler = $this->client->submit($form);
        self::assertResponseIsSuccessful();
        self::assertSame(1, $crawler->filter('.alert--danger')->count());
        self::assertNull(static::getContainer()->get(PriceListRepository::class)->findOneBy(['title' => 'Битый прайс']));

        $crawler = $this->client->request('GET', '/prices/new');
        $form = $crawler->filter('form.card--form')->form();
        $form['title'] = 'Передумали';
        $form['file']->upload($this->sampleXlsx('cancel.xlsx'));
        $this->client->submit($form);
        $crawler = $this->client->followRedirect();
        $this->client->submit($crawler->filter('form[action$="/delete"]')->form());
        self::assertResponseRedirects('/prices');
        self::assertNull(static::getContainer()->get(PriceListRepository::class)->findOneBy(['title' => 'Передумали']));
    }

    public function testOldExcelFormatIsConvertedWithLibreOffice(): void
    {
        if (!Shell::exists('soffice')) {
            self::markTestSkipped('LibreOffice не установлен.');
        }
        $xlsx = $this->sampleXlsx('old.xlsx');
        Shell::run(['soffice', '-env:UserInstallation=file://'.$this->dir.'/profile', '--headless', '--convert-to', 'xls', '--outdir', $this->dir, $xlsx], 180, $this->dir, ['HOME' => $this->dir]);
        if (!is_file($this->dir.'/old.xls')) {
            self::markTestSkipped('LibreOffice не смог сохранить .xls.');
        }
        $service = static::getContainer()->get(PriceListService::class);
        $list = $service->create($this->user('markova'), 'Старый формат', null, 'USD', true);
        $revision = $service->store($list, $this->dir.'/old.xls', 'old.xls', $this->user('markova'));
        $preview = $service->preview($revision, null, null, null);
        self::assertSame(5, $preview['result']['count']);
        $service->import($revision, $preview['sheet'], $preview['mapping'], null, null, $this->user('markova'));
        self::assertSame(5, $revision->getItemCount());
        self::assertFileExists(preg_replace('/\.xls$/', '.work.xlsx', $service->path($revision)));
        $service->delete($list, $this->user('markova'));
        self::assertFileDoesNotExist($service->path($revision));
        foreach (glob($this->dir.'/profile/*') ?: [] as $f) {
            Shell::run(['rm', '-rf', $f], 10);
        }
        @rmdir($this->dir.'/profile');
    }

    /** @return array<int, array<int, string>> */
    private function sampleRows(): array
    {
        return [
            1 => [3 => 'ООО «Водокомфорт»'],
            2 => [3 => 'Прайс-лист для дилеров на 01.10.2026'],
            4 => [1 => '№', 2 => 'Артикул', 3 => 'Наименование товара', 4 => 'Ед. изм.', 5 => 'Цена опт, руб.', 6 => 'Цена розн., руб.', 7 => 'Примечание'],
            5 => [3 => 'Насосные станции'],
            6 => [1 => '1', 2 => 'DJ-60', 3 => 'Станция «Джилекс 60/32»', 4 => 'шт', 5 => '15000', 6 => '17 500', 7 => 'хит'],
            7 => [1 => '2', 2 => 'AQ-1', 3 => 'Насос «Акватор-1»', 4 => 'шт', 5 => '7200', 6 => '8900'],
            8 => [1 => '3', 2 => 'ML-1', 3 => 'Насос «Малыш»', 4 => 'шт', 5 => '3100', 6 => '3900'],
            9 => [3 => 'Комплектующие'],
            10 => [1 => '4', 2 => 'RDM-5', 3 => 'Реле давления РДМ-5', 4 => 'шт', 5 => '1 234,50', 6 => '1500'],
            11 => [1 => '5', 2 => 'KIT-9', 3 => 'Комплект монтажный', 4 => 'компл', 5 => 'по запросу'],
        ];
    }

    /** Файл с шапкой: заголовки — в 5-й строке Excel (на строку ниже, чем в sampleRows). */
    private function sampleXlsx(string $name): string
    {
        $writer = new XlsxWriter();
        $sheet = $writer->addSheet('Прайс');
        foreach ($this->sampleRows() as $r => $cells) {
            foreach ($cells as $c => $value) {
                $sheet->set($r >= 4 ? $r + 1 : $r, $c, is_numeric($value) ? $value + 0 : $value);
            }
        }
        $writer->addSheet('Условия')->set(1, 1, 'Доставка бесплатно от 50 000 ₽');
        $path = $this->dir.'/'.$name;
        $writer->save($path);

        return $path;
    }

    /** Ключ форм раздела (одинаковый у всех форм «Прайс-листов») — со страницы нового прайс-листа. */
    private function csrf(): string
    {
        return (string) $this->client->request('GET', '/prices/new')->filter('form.card--form input[name="_token"]')->attr('value');
    }

    private function reload(PriceRevision $revision): PriceRevision
    {
        $this->em()->clear();

        return static::getContainer()->get(PriceRevisionRepository::class)->find($revision->getId());
    }
}
