<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Document;
use App\Entity\DocumentKind;
use App\Entity\MaterialParameter;
use App\Entity\MaterialRow;
use App\Service\BulkDocumentService;
use App\Service\DocumentKindService;
use App\Service\DocumentManager;
use App\Service\Material\MaterialExporter;
use App\Service\Material\MaterialImporter;
use App\Service\Material\MaterialService;
use App\Service\Material\MaterialSummary;
use App\Service\SectionManager;
use App\Service\Xlsx\XlsxReader;
use App\Tests\PortalTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Таблицы материалов разделов верхнего уровня (как листы «Продуктовые материалы.xlsx»), сводная
 * по категориям, типы файлов, загрузка файлов из строк, импорт и выгрузка Excel.
 */
final class MaterialsTest extends PortalTestCase
{
    private function materials(): MaterialService
    {
        return static::getContainer()->get(MaterialService::class);
    }

    private function summary(): MaterialSummary
    {
        return static::getContainer()->get(MaterialSummary::class);
    }

    private function kinds(): DocumentKindService
    {
        return static::getContainer()->get(DocumentKindService::class);
    }

    private function row(string $section, string $title): MaterialRow
    {
        foreach ($this->materials()->rows($this->section($section)) as $row) {
            if ($row->getTitle() === $title) {
                return $row;
            }
        }
        self::fail(\sprintf('Строка «%s» не найдена в разделе «%s»', $title, $section));
    }

    private function kind(string $name): DocumentKind
    {
        $kind = $this->kinds()->match($name);
        self::assertNotNull($kind, 'Тип не найден: '.$name);

        return $kind;
    }

    /** Токен таблицы — со страницы, как его берёт браузер. */
    private function tableToken(int $sectionId): string
    {
        $crawler = $this->client->request('GET', '/sections/'.$sectionId.'/materials');
        self::assertResponseIsSuccessful();
        $config = json_decode($crawler->filter('#mt-config')->text(), true, 512, \JSON_THROW_ON_ERROR);

        return $config['token'];
    }

    /**
     * @param array<string, string>       $data
     * @param array<string, UploadedFile> $files
     *
     * @return array<string, mixed>
     */
    private function api(string $url, array $data, string $token, array $files = []): array
    {
        $this->client->request('POST', $url, $data, $files, ['HTTP_X_CSRF_TOKEN' => $token, 'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);

        return json_decode((string) $this->client->getResponse()->getContent(), true) ?? [];
    }

    private function pdf(string $name, string $text = 'demo'): UploadedFile
    {
        $path = sys_get_temp_dir().'/'.bin2hex(random_bytes(4)).'-'.$name;
        file_put_contents($path, "%PDF-1.4\n% ".$text."\n%%EOF\n");

        return new UploadedFile($path, $name, 'application/pdf', null, true);
    }

    /** @param array<int, int> $byKind */
    private function kindNames(array $byKind): array
    {
        $names = [];
        foreach ($byKind as $id => $count) {
            $names[(string) $this->kinds()->find($id)?->getName()] = $count;
        }
        ksort($names);

        return $names;
    }

    public function testDemoCategoriesReproduceExcelSummary(): void
    {
        $pumps = $this->section('Насосные станции');
        self::assertTrue($pumps->isMaterialsEnabled());
        self::assertSame(['Версия', 'Дата обнов.', 'Требования PM', 'Дизайн-требования', 'Ссылка', 'Комментарий'], array_map(static fn (MaterialParameter $p): string => $p->getName(), $this->materials()->parameters($pumps)));

        $summary = $this->summary()->forSection($pumps);
        self::assertSame(8, $summary['total']);
        self::assertSame(2, $summary['files']);
        self::assertSame(['Готово' => 3, 'Допустимо' => 2, 'Не соответствует' => 2, 'Отсутствует' => 1, 'Неизвестно' => 0], $summary['states']);
        self::assertSame(3, $summary['problems']);
        self::assertSame(['Опросные листы и ТКП' => 1, 'Паспорта и руководства' => 1, 'Презентации' => 1], $this->kindNames($summary['problemsByKind']));

        $tanks = $this->summary()->forSection($this->section('Предохранительные клапаны'));
        self::assertSame([6, 1, 1], [$tanks['total'], $tanks['files'], $tanks['unrated']]);
        self::assertSame(['Готово' => 2, 'Допустимо' => 1, 'Не соответствует' => 1, 'Отсутствует' => 1, 'Неизвестно' => 0], $tanks['states']);

        $overview = $this->summary()->overview();
        self::assertSame(['Насосные станции', 'Предохранительные клапаны'], array_map(static fn (array $c): string => $c['section']->getName(), $overview['categories']));
        self::assertSame(14, $overview['totals']['total']);
        self::assertSame(5, $overview['totals']['problems']);

        // Написание типов из листа приведено к справочнику, «Презентации» добавлены перед «Прочим».
        self::assertSame('Опросные листы и ТКП', $this->row('Насосные станции', 'Опросный лист подбора насосной станции')->getKind()?->getName());
        self::assertSame('Паспорта и руководства', $this->row('Насосные станции', 'Руководство по монтажу и эксплуатации НС-40')->getKind()?->getName());
        self::assertSame(['Каталоги и брошюры', 'Паспорта и руководства', 'Опросные листы и ТКП', 'Фотографии', 'Презентации', 'Прочее'], array_map(static fn (DocumentKind $k): string => $k->getName(), $this->kinds()->all()));

        // Файл, загруженный к строке, — документ раздела с названием и типом строки.
        $document = $this->row('Насосные станции', 'Каталог насосных станций НС')->getDocument();
        self::assertNotNull($document);
        self::assertSame('Каталог насосных станций НС', $document->getTitle());
        self::assertSame('Каталоги и брошюры', $document->getKind()?->getName());
        self::assertSame($pumps->getId(), $document->getSection()->getId());
        self::assertTrue($document->isPublished());
    }

    public function testKindsAreGuessedAndNormalized(): void
    {
        self::assertSame('Опросные листы и ТКП', $this->kinds()->match('Опросные листы')?->getName());
        self::assertSame('Паспорта и руководства', $this->kinds()->match('паспорта и руководство')?->getName());
        self::assertSame('Каталоги и брошюры', $this->kinds()->match('  Каталоги   и брошюры ')?->getName());
        self::assertNull($this->kinds()->match('Сертификаты'));

        $guess = fn (string $title, ?string $ext = 'pdf'): string => $this->kinds()->guess($title, $ext)->getName();
        self::assertSame('Фотографии', $guess('Объект в Казани', 'JPG'));
        self::assertSame('Опросные листы и ТКП', $guess('ТКП на поставку НС-40'));
        self::assertSame('Опросные листы и ТКП', $guess('Опросный лист подбора клапана'));
        self::assertSame('Паспорта и руководства', $guess('Руководство по эксплуатации'));
        self::assertSame('Каталоги и брошюры', $guess('Каталог 2026'));
        self::assertSame('Презентации', $guess('Презентация для дилеров'));
        self::assertSame('Прочее', $guess('Приказ о графике отпусков'));

        // Новый документ без типа получает тип по названию.
        $document = (new Document($this->section('Производство')))->setTitle('Паспорт насоса НС-10')->setType(Document::TYPE_FILE);
        static::getContainer()->get(DocumentManager::class)->create($document, $this->pdf('pasport.pdf'), null, null, $this->user('sidorov'), true);
        self::assertSame('Паспорта и руководства', $document->getKind()?->getName());

        // После EntityManager::clear() (пакетный импорт из каталога) справочник перечитывается,
        // а не подставляет отсоединённые типы.
        $this->em()->clear();
        $next = (new Document($this->section('Производство')))->setTitle('Каталог насосов 2026')->setType(Document::TYPE_FILE);
        static::getContainer()->get(DocumentManager::class)->create($next, $this->pdf('catalog.pdf'), null, null, $this->user('sidorov'), true);
        self::assertSame('Каталоги и брошюры', $next->getKind()?->getName());
        self::assertTrue($this->em()->contains($next->getKind()));
    }

    public function testTableAccessForModeratorsAndAdmins(): void
    {
        $pumps = $this->section('Насосные станции');
        $url = '/sections/'.$pumps->getId().'/materials';

        $this->client->request('GET', $url);
        self::assertResponseRedirects();

        foreach (['ivanov', 'smirnov'] as $user) {
            $this->loginAs($user);
            $this->client->request('GET', $url);
            self::assertResponseStatusCodeSame(403, $user);
        }
        $this->loginAs('sidorov');
        $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Насосные станции');
        $this->client->request('GET', '/admin/materials');
        self::assertResponseStatusCodeSame(403);

        // Чужой модератор не может править таблицу, даже с токеном.
        $token = $this->tableToken($pumps->getId());
        $this->loginAs('ivanov');
        $row = $this->row('Насосные станции', 'Паспорт насосной станции НС-40');
        $this->api($url.'/rows/'.$row->getId(), ['field' => 'title', 'value' => 'Взлом'], $token);
        self::assertResponseStatusCodeSame(403);

        $this->loginAs('admin');
        $crawler = $this->client->request('GET', '/admin/materials');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Сводная по категориям');
        self::assertSame(2, $crawler->filter('table.ms-pivot')->count());
        self::assertStringContainsString('Несоответствие / Отсутствие по типу', $crawler->text());
        self::assertSame(2, $crawler->filter('.ms-block svg.donut')->count());
        self::assertStringContainsString('Материалы', $crawler->filter('.subnav')->text());

        // Для подраздела таблицы нет — переход в раздел.
        $sub = $this->section('Технологические карты');
        $this->client->request('GET', '/sections/'.$sub->getId().'/materials');
        self::assertResponseRedirects('/sections/'.$sub->getId());
        try {
            $this->materials()->enable($sub);
            self::fail('подразделу таблицу включить нельзя');
        } catch (\DomainException $e) {
            self::assertStringContainsString('верхнего уровня', $e->getMessage());
        }
    }

    public function testCellsAreEditedLikeExcel(): void
    {
        $pumps = $this->section('Насосные станции');
        $url = '/sections/'.$pumps->getId().'/materials';
        $this->loginAs('sidorov');
        $token = $this->tableToken($pumps->getId());
        $pm = $this->materials()->roleParameter($pumps, MaterialParameter::ROLE_PM);
        $design = $this->materials()->roleParameter($pumps, MaterialParameter::ROLE_DESIGN);
        $updated = $this->materials()->roleParameter($pumps, MaterialParameter::ROLE_UPDATED);
        self::assertNotNull($pm);
        self::assertNotNull($design);
        self::assertNotNull($updated);
        $row = $this->row('Насосные станции', 'Опросный лист подбора насосной станции');

        $result = $this->api($url.'/rows/'.$row->getId(), ['field' => $pm->key(), 'value' => 'соответствует'], $token);
        self::assertTrue($result['ok']);
        self::assertSame('Соответствует', $result['row']['values'][$pm->key()]['value']);
        self::assertSame('', $result['row']['state'], 'дизайн «Не выбрано» — состояние пустое, как в Excel');

        $result = $this->api($url.'/rows/'.$row->getId(), ['field' => $design->key(), 'value' => 'Соответствует'], $token);
        self::assertSame(['Готово', 'ready'], [$result['row']['state'], $result['row']['stateClass']]);
        self::assertSame(4, $result['summary']['states']['Готово']);

        $result = $this->api($url.'/rows/'.$row->getId(), ['field' => $design->key(), 'value' => 'Хорошо'], $token);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('из списка', $result['error']);
        self::assertSame('Соответствует', $result['row']['values'][$design->key()]['value']);

        $result = $this->api($url.'/rows/'.$row->getId(), ['field' => $updated->key(), 'value' => '05.03.2026'], $token);
        self::assertSame(['value' => '2026-03-05', 'display' => '05.03.2026'], $result['row']['values'][$updated->key()]);

        $result = $this->api($url.'/rows/'.$row->getId(), ['field' => 'kind', 'value' => (string) $this->kind('Презентации')->getId()], $token);
        self::assertSame('Презентации', $result['row']['kind']['name']);
        $result = $this->api($url.'/rows/'.$row->getId(), ['field' => 'kind', 'value' => 'Опросные листы'], $token);
        self::assertSame('Опросные листы и ТКП', $result['row']['kind']['name']);
        $this->api($url.'/rows/'.$row->getId(), ['field' => 'kind', 'value' => 'Несуществующий тип'], $token);
        self::assertResponseStatusCodeSame(422);

        $result = $this->api($url.'/rows/'.$row->getId(), ['field' => 'title', 'value' => '  Опросный лист подбора НС  '], $token);
        self::assertSame('Опросный лист подбора НС', $result['row']['title']);
        self::assertSame('Сидоров Алексей Николаевич', $result['row']['updatedBy']);

        // Без токена правки не принимаются.
        $this->client->request('POST', $url.'/rows/'.$row->getId(), ['field' => 'title', 'value' => 'x']);
        self::assertResponseStatusCodeSame(403);
    }

    public function testRowsAndColumnsCanBeAddedEditedAndRemoved(): void
    {
        $tanks = $this->section('Предохранительные клапаны');
        $url = '/sections/'.$tanks->getId().'/materials';
        $this->loginAs('admin');
        $token = $this->tableToken($tanks->getId());
        $first = $this->row('Предохранительные клапаны', 'Каталог предохранительных клапанов');

        // Строка вставляется сразу после выбранной.
        $result = $this->api($url.'/rows', ['after' => (string) $first->getId()], $token);
        self::assertTrue($result['ok']);
        $newId = $result['row']['id'];
        $this->api($url.'/rows/'.$newId, ['field' => 'title', 'value' => 'Сертификат на клапаны'], $token);
        $titles = array_map(static fn (MaterialRow $r): string => $r->getTitle(), $this->materials()->rows($tanks));
        self::assertSame(['Каталог предохранительных клапанов', 'Сертификат на клапаны'], \array_slice($titles, 0, 2));
        $this->api($url.'/rows/'.$newId.'/move', ['direction' => '-1'], $token);
        self::assertSame('Сертификат на клапаны', $this->materials()->rows($tanks)[0]->getTitle());

        // Столбец-список: добавить, заполнить, переименовать, сдвинуть, удалить.
        $result = $this->api($url.'/parameters', ['name' => 'Язык', 'kind' => 'list', 'options' => "RU\nEN\nRU/EN"], $token);
        self::assertTrue($result['ok']);
        $column = $result['parameter'];
        self::assertSame(['RU', 'EN', 'RU/EN'], $column['options']);
        $this->api($url.'/parameters', ['name' => 'язык', 'kind' => 'text'], $token);
        self::assertResponseStatusCodeSame(422);
        $result = $this->api($url.'/parameters', ['name' => 'Формат', 'kind' => 'list', 'options' => ''], $token);
        self::assertStringContainsString('перечислите значения', $result['error']);

        $result = $this->api($url.'/rows/'.$newId, ['field' => $column['key'], 'value' => 'en'], $token);
        self::assertSame('EN', $result['row']['values'][$column['key']]['value']);
        $result = $this->api($url.'/parameters/'.$column['id'], ['name' => 'Язык документа', 'options' => "RU\nEN\nRU/EN\nCN", 'width' => '16'], $token);
        self::assertSame(['Язык документа', ['RU', 'EN', 'RU/EN', 'CN'], 16], [$result['parameter']['name'], $result['parameter']['options'], $result['parameter']['width']]);

        // Значения «Требований PM» фиксированы: по ним считается состояние.
        $pm = $this->materials()->roleParameter($tanks, MaterialParameter::ROLE_PM);
        $result = $this->api($url.'/parameters/'.$pm?->getId(), ['name' => 'Требования PM', 'options' => "А\nБ"], $token);
        self::assertSame(['Не выбрано', 'Соответствует', 'Не соответствует', 'Отсутствует'], $result['parameter']['options']);

        $this->api($url.'/parameters/'.$column['id'].'/move', ['direction' => '-1'], $token);
        $names = array_map(static fn (MaterialParameter $p): string => $p->getName(), $this->materials()->parameters($tanks));
        self::assertSame(['Версия', 'Дата обнов.', 'Требования PM', 'Дизайн-требования', 'Ссылка', 'Язык документа', 'Комментарий'], $names);

        $this->api($url.'/parameters/'.$column['id'].'/delete', [], $token);
        self::assertTrue($this->client->getResponse()->isSuccessful());
        self::assertCount(6, $this->materials()->parameters($tanks));
        $this->em()->clear();
        $row = $this->em()->find(MaterialRow::class, $newId);
        self::assertArrayNotHasKey($column['key'], $row?->getValues() ?? []);

        $result = $this->api($url.'/rows/'.$newId.'/delete', [], $token);
        self::assertSame(6, $result['summary']['total']);
    }

    public function testFileUploadedFromRowTakesTitleAndKindFromRow(): void
    {
        $pumps = $this->section('Насосные станции');
        $url = '/sections/'.$pumps->getId().'/materials';
        $this->loginAs('sidorov');
        $token = $this->tableToken($pumps->getId());
        $row = $this->row('Насосные станции', 'Брошюра НС-40 для дилеров');
        $updated = $this->materials()->roleParameter($pumps, MaterialParameter::ROLE_UPDATED);
        $link = $this->materials()->roleParameter($pumps, MaterialParameter::ROLE_LINK);

        $result = $this->api($url.'/rows/'.$row->getId().'/upload', [], $token, ['file' => $this->pdf('Брошюра_НС-40.pdf')]);
        self::assertTrue($result['ok'], json_encode($result, \JSON_UNESCAPED_UNICODE));
        $documentId = $result['row']['document']['id'];
        $document = $this->em()->find(Document::class, $documentId);
        self::assertSame('Брошюра НС-40 для дилеров', $document?->getTitle());
        self::assertSame('Каталоги и брошюры', $document->getKind()?->getName());
        self::assertSame('Насосные станции', $document->getSection()->getName());
        self::assertSame('Брошюра_НС-40.pdf', $document->getCurrentVersion()?->getOriginalName());
        self::assertStringContainsString('версия 1.4', (string) $document->getCurrentVersion()?->getChangeNote());
        self::assertSame((new \DateTimeImmutable('today'))->format('Y-m-d'), $result['row']['values'][$updated?->key()]['value']);
        self::assertStringEndsWith('/documents/'.$documentId, $result['row']['values'][$link?->key()]['value']);

        // Повторная загрузка — новая версия того же документа.
        $result = $this->api($url.'/rows/'.$row->getId().'/upload', [], $token, ['file' => $this->pdf('Брошюра_НС-40_v2.pdf', 'v2')]);
        self::assertSame([$documentId, 2], [$result['row']['document']['id'], $result['row']['document']['version']]);

        // Карточка документа показывает строку таблицы; правка карточки переносится в строку.
        $crawler = $this->client->request('GET', '/documents/'.$documentId);
        self::assertStringContainsString('Таблица материалов', $crawler->filter('.doc-side')->text());
        $crawler = $this->client->request('GET', '/documents/'.$documentId.'/edit');
        $form = $crawler->selectButton('Сохранить изменения')->form();
        $form['document[title]'] = 'Брошюра НС-40 (дилерская)';
        $form['document[kind]'] = (string) $this->kind('Презентации')->getId();
        $this->client->submit($form);
        self::assertResponseRedirects('/documents/'.$documentId);
        $this->em()->clear();
        $fresh = $this->em()->find(MaterialRow::class, $row->getId());
        self::assertSame(['Брошюра НС-40 (дилерская)', 'Презентации'], [$fresh?->getTitle(), $fresh?->getKind()?->getName()]);

        // Массовая смена типа в реестре тоже доходит до строк.
        $report = static::getContainer()->get(BulkDocumentService::class)->run('kind', [$documentId], ['kind' => $this->kind('Каталоги и брошюры')], $this->user('admin'));
        self::assertSame(1, $report['done']);
        $this->em()->clear();
        self::assertSame('Каталоги и брошюры', $this->em()->find(MaterialRow::class, $row->getId())?->getKind()?->getName());

        // Отвязка оставляет документ в разделе.
        $result = $this->api($url.'/rows/'.$row->getId().'/detach', [], $token);
        self::assertNull($result['row']['document']);
        self::assertNotNull($this->em()->find(Document::class, $documentId));
    }

    public function testFileTypesAdministration(): void
    {
        $this->loginAs('admin');
        $crawler = $this->client->request('GET', '/admin/materials/kinds');
        self::assertResponseIsSuccessful();
        $token = $crawler->filter('form.kinds-add input[name=_token]')->attr('value');

        $this->client->request('POST', '/admin/materials/kinds/add', ['_token' => $token, 'name' => '  сертификаты ']);
        self::assertResponseRedirects('/admin/materials/kinds');
        $certificates = $this->kinds()->match('Сертификаты');
        self::assertNotNull($certificates);
        self::assertSame('Сертификаты', $certificates->getName());
        $names = array_map(static fn (DocumentKind $k): string => $k->getName(), $this->kinds()->all());
        self::assertSame('Прочее', end($names), 'новый тип встаёт перед «Прочим»');
        $this->client->request('POST', '/admin/materials/kinds/add', ['_token' => $token, 'name' => 'Сертификаты']);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flashes', 'уже есть');

        // Документ и строка с этим типом при удалении получают выбранный тип.
        // (После каждого запроса EntityManager очищается — тип берём заново.)
        $certificates = $this->kind('Сертификаты');
        $document = $this->document('ТК-041');
        $document->setKind($certificates);
        $row = $this->row('Насосные станции', 'Фото НС-40 на объекте');
        $row->setKind($certificates);
        $this->em()->flush();
        $this->client->request('POST', '/admin/materials/kinds/'.$certificates->getId().'/delete', ['_token' => $token, 'replacement' => (string) $this->kind('Фотографии')->getId()]);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flashes', 'Тип удалён');
        $this->em()->clear();
        $this->kinds()->reset();
        self::assertNull($this->kinds()->match('Сертификаты'));
        self::assertSame('Фотографии', $this->document('ТК-041')->getKind()?->getName());
        self::assertSame('Фотографии', $this->row('Насосные станции', 'Фото НС-40 на объекте')->getKind()?->getName());

        // «Прочее» — служебный тип, удалить нельзя.
        try {
            $this->kinds()->delete($this->kinds()->fallback());
            self::fail('служебный тип удалять нельзя');
        } catch (\DomainException $e) {
            self::assertStringContainsString('служебный', $e->getMessage());
        }
        $this->client->request('POST', '/admin/materials/kinds/add', ['_token' => 'неверный', 'name' => 'X']);
        self::assertResponseStatusCodeSame(403);
    }

    public function testExcelExportAndImportRoundTrip(): void
    {
        $pumps = $this->section('Насосные станции');
        $tanks = $this->section('Предохранительные клапаны');
        $path = sys_get_temp_dir().'/'.bin2hex(random_bytes(4)).'-materials.xlsx';
        static::getContainer()->get(MaterialExporter::class)->build([$pumps, $tanks], $path);
        $sheets = (new XlsxReader())->read($path);
        @unlink($path);
        self::assertSame(['Сводная по категориям', 'Насосные станции', 'Предохранительные клапаны', 'Служебный — не изменять'], array_column($sheets, 'name'));
        self::assertSame([false, false, false, true], array_column($sheets, 'hidden'), 'служебный лист с id разделов скрыт');

        $importer = static::getContainer()->get(MaterialImporter::class);
        $plan = $importer->plan($sheets);
        self::assertSame([0, 0, 0, 14], [$plan['totals']['sectionsCreated'], $plan['totals']['created'], $plan['totals']['updated'], $plan['totals']['unchanged']]);
        self::assertSame('Сводная по категориям', $plan['skipped'][0]['name']);

        // Правка «в Excel»: значение, новая строка с новым типом, новый столбец-список.
        $sheet = &$sheets[1];
        $header = $sheet['rows'][1];
        $col = array_flip($header);
        foreach ($sheet['rows'] as $index => $cells) {
            if ('Презентация линейки НС' === ($cells[1] ?? null)) {
                $sheet['rows'][$index][$col['Требования PM']] = 'Соответствует';
            }
        }
        $sheet['rows'][50] = [1 => 'Декларация соответствия НС-40', 2 => 'Декларации', $col['Требования PM'] => 'Соответствует', $col['Дизайн-требования'] => 'Соответствует'];
        $sheet['rows'][1][20] = 'Язык';
        $sheet['rows'][2][20] = 'EN';
        $sheet['lists'][20] = ['RU', 'EN'];
        unset($sheet);

        $plan = $importer->plan($sheets);
        self::assertSame([1, 2, 12, 1], [$plan['totals']['created'], $plan['totals']['updated'], $plan['totals']['unchanged'], $plan['totals']['parametersCreated']]);
        self::assertSame(['Декларации'], $plan['kindsCreated']);

        $report = $importer->apply($sheets, false, $this->user('admin'));
        self::assertSame(1, $report['totals']['created']);
        $this->em()->clear();
        $this->kinds()->reset();
        $this->materials()->forgetParameters();
        self::assertSame('Готово', $this->materials()->state($this->row('Насосные станции', 'Презентация линейки НС')));
        self::assertSame('Декларации', $this->row('Насосные станции', 'Декларация соответствия НС-40')->getKind()?->getName());
        $language = $this->materials()->parameters($this->section('Насосные станции'));
        self::assertSame(['Язык', MaterialParameter::KIND_LIST, ['RU', 'EN']], [end($language)->getName(), end($language)->getKind(), end($language)->getOptions()]);
        self::assertCount(7, $language, 'служебный столбец «Документ портала» не становится параметром');

        // Строки, которых нет в файле, можно удалить; загруженный документ остаётся в разделе.
        $catalog = $this->row('Насосные станции', 'Каталог насосных станций НС');
        $catalogId = $catalog->getId();
        $documentId = $catalog->getDocument()?->getId();
        self::assertNotNull($documentId);
        foreach ($sheets[1]['rows'] as $index => $cells) {
            if ('Каталог насосных станций НС' === ($cells[1] ?? null)) {
                unset($sheets[1]['rows'][$index]);
            }
        }
        $plan = $importer->plan($sheets, true);
        self::assertSame(1, $plan['totals']['deleted']);
        $importer->apply($sheets, true, $this->user('admin'));
        $this->em()->clear();
        self::assertNull($this->em()->find(MaterialRow::class, $catalogId));
        self::assertNotNull($this->em()->find(Document::class, $documentId));
    }

    public function testWorkbookImportThroughAdminPanelCreatesTopLevelSections(): void
    {
        // Книга «как в Excel»: сводная (пропускается) и лист новой категории.
        $writer = new \App\Service\Xlsx\XlsxWriter();
        $writer->addSheet('Сводная по категориям')->set(1, 1, 'Общее состояние');
        $sheet = $writer->addSheet('Радиаторы');
        foreach (['Материал', 'Тип', 'Состояние', 'Версия', 'Дата обнов.', 'Требования PM', 'Дизайн-требования', 'Ссылка', 'Комментарий'] as $i => $title) {
            $sheet->set(1, $i + 1, $title, \App\Service\Xlsx\XlsxWriter::STYLE_HEADER);
        }
        $sheet->set(2, 1, 'Каталог радиаторов')->set(2, 2, 'Каталоги и брошюры')->set(2, 5, new \DateTimeImmutable('2026-08-02'), \App\Service\Xlsx\XlsxWriter::STYLE_DATE)->set(2, 6, 'Соответствует')->set(2, 7, 'Не соответствует');
        $sheet->set(3, 1, 'Паспорт радиатора')->set(3, 2, 'Паспорта и руководство')->set(3, 6, 'Не соответствует')->set(3, 9, 'Нет раздела о гарантии');
        $path = sys_get_temp_dir().'/'.bin2hex(random_bytes(4)).'-book.xlsx';
        $writer->save($path);

        $this->loginAs('admin');
        $crawler = $this->client->request('GET', '/admin/materials/import');
        $token = $crawler->filter('form input[name=_token]')->attr('value');
        $crawler = $this->client->request('POST', '/admin/materials/import', ['_token' => $token], ['file' => new UploadedFile($path, 'Продуктовые материалы.xlsx', null, null, true)]);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'План импорта');
        self::assertStringContainsString('новый раздел верхнего уровня', $crawler->filter('table.plan-table')->text());
        self::assertStringContainsString('Сводная по категориям', $crawler->filter('.bullets')->text());

        $form = $crawler->filter('form[action*="/admin/materials/import/"]')->form();
        $this->client->submit($form);
        self::assertResponseRedirects('/admin/materials');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flashes', 'разделов создано 1, строк добавлено 2');

        $radiators = $this->section('Радиаторы');
        self::assertNull($radiators->getParent());
        self::assertTrue($radiators->isMaterialsEnabled());
        $row = $this->row('Радиаторы', 'Каталог радиаторов');
        self::assertSame('Допустимо', $this->materials()->state($row));
        self::assertSame('2026-08-02', $row->getValue($this->materials()->roleParameter($radiators, MaterialParameter::ROLE_UPDATED)));
        self::assertSame('Паспорта и руководства', $this->row('Радиаторы', 'Паспорт радиатора')->getKind()?->getName());

        // Не .xlsx — понятная ошибка.
        $this->client->request('POST', '/admin/materials/import', ['_token' => $token], ['file' => $this->pdf('book.pdf')]);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flashes', '.xlsx');

        // Выгрузка из панели — книга со сводной и диаграммами.
        $this->client->request('GET', '/admin/materials/export.xlsx');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('spreadsheetml', (string) $this->client->getResponse()->headers->get('Content-Type'));
    }

    public function testTableCanBeEnabledForTopLevelSection(): void
    {
        $common = $this->section('Общие документы');
        self::assertFalse($common->isMaterialsEnabled());
        $url = '/sections/'.$common->getId().'/materials';

        $this->loginAs('ivanov');
        $crawler = $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Таблица материалов не включена', $crawler->text());
        $token = $crawler->filter('form[action$="/enable"] input[name=_token]')->attr('value');

        // Пока таблица выключена, правки не принимаются.
        $this->api($url.'/rows', [], (string) $token);
        self::assertResponseStatusCodeSame(404);

        $this->client->request('POST', $url.'/enable', ['_token' => $token]);
        self::assertResponseRedirects($url);
        $this->em()->clear();
        $common = $this->section('Общие документы');
        self::assertTrue($common->isMaterialsEnabled());
        self::assertCount(6, $this->materials()->parameters($common));

        $crawler = $this->client->request('GET', '/sections/'.$common->getId());
        self::assertSame(1, $crawler->filter('a[href="'.$url.'"]')->count(), 'кнопка «Таблица материалов» в разделе');

        // Сводная показывает новую категорию; скрыть таблицу может администратор.
        $this->loginAs('admin');
        $crawler = $this->client->request('GET', '/admin/materials');
        self::assertStringContainsString('Общие документы', $crawler->filter('table.ms-pivot')->first()->text());
        $crawler = $this->client->request('GET', $url);
        $this->client->submit($crawler->filter('.mt-disable form')->first()->form());
        $this->em()->clear();
        self::assertFalse($this->section('Общие документы')->isMaterialsEnabled());
    }

    public function testSectionWithMaterialRowsIsNotDeletedSilently(): void
    {
        $manager = static::getContainer()->get(SectionManager::class);
        $section = $manager->create('Каталоги поставщиков', null, null, $this->user('admin'));
        $this->materials()->enable($section);
        $this->materials()->addRow($section, null, 'Каталог поставщика');
        try {
            $manager->delete($section);
            self::fail('раздел с таблицей материалов не должен удаляться');
        } catch (\DomainException $e) {
            self::assertStringContainsString('таблица материалов', $e->getMessage());
        }
        foreach ($this->materials()->rows($section) as $row) {
            $this->materials()->deleteRow($row);
        }
        $manager->delete($section);
        self::assertNull(static::getContainer()->get(\App\Repository\SectionRepository::class)->findOneBy(['name' => 'Каталоги поставщиков']));
    }
}
