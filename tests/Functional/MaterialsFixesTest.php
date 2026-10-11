<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Document;
use App\Entity\MaterialParameter;
use App\Entity\MaterialRow;
use App\Service\DocumentKindService;
use App\Service\Material\MaterialExporter;
use App\Service\Material\MaterialImporter;
use App\Service\Material\MaterialService;
use App\Service\PortalSettings;
use App\Service\SectionManager;
use App\Service\Xlsx\XlsxReader;
use App\Service\Xlsx\XlsxWriter;
use App\Tests\PortalTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Исправления таблиц материалов (1.6.1): права на документ строки, устойчивость импорта Excel,
 * сопоставление листов с разделами, выгрузка, даты и числа в книге, удаление таблицы.
 */
final class MaterialsFixesTest extends PortalTestCase
{
    /**
     * Демо-данные общие для всех тестов процесса, а эти тесты переносят документы, удаляют таблицы и заводят
     * типы — каждый тест идёт в транзакции, которая в конце откатывается.
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->em()->getConnection()->beginTransaction();
    }

    protected function tearDown(): void
    {
        $connection = $this->em()->getConnection();
        while ($connection->isTransactionActive()) {
            $connection->rollBack();
        }
        $this->em()->clear();
        static::getContainer()->get(DocumentKindService::class)->reset();
        $this->materials()->forgetParameters();
        parent::tearDown();
    }

    private function materials(): MaterialService
    {
        return static::getContainer()->get(MaterialService::class);
    }

    private function importer(): MaterialImporter
    {
        return static::getContainer()->get(MaterialImporter::class);
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

    private function tableToken(int $sectionId): string
    {
        $crawler = $this->client->request('GET', '/sections/'.$sectionId.'/materials');
        self::assertResponseIsSuccessful();

        return json_decode($crawler->filter('#mt-config')->text(), true, 512, \JSON_THROW_ON_ERROR)['token'];
    }

    /** @return array<string, mixed> */
    private function api(string $url, array $data, string $token, array $files = []): array
    {
        $this->client->request('POST', $url, $data, $files, ['HTTP_X_CSRF_TOKEN' => $token, 'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);

        return json_decode((string) $this->client->getResponse()->getContent(), true) ?? [];
    }

    private function pdf(string $name): UploadedFile
    {
        $path = sys_get_temp_dir().'/'.bin2hex(random_bytes(4)).'-'.$name;
        file_put_contents($path, "%PDF-1.4\n% test\n%%EOF\n");

        return new UploadedFile($path, $name, 'application/pdf', null, true);
    }

    /**
     * Лист в том виде, в каком его возвращает XlsxReader.
     *
     * @param list<string>             $header
     * @param list<list<string>>       $rows
     * @param array<int, list<string>> $lists
     *
     * @return array{name: string, hidden: bool, rows: array<int, array<int, string>>, lists: array<int, list<string>>, dates: array<int, bool>}
     */
    private static function sheet(string $name, array $header, array $rows, array $lists = []): array
    {
        $out = [1 => array_combine(range(1, \count($header)), $header)];
        foreach ($rows as $i => $cells) {
            $out[$i + 2] = array_combine(range(1, \count($cells)), $cells);
        }

        return ['name' => $name, 'hidden' => false, 'rows' => $out, 'lists' => $lists, 'dates' => []];
    }

    public function testRowCannotEditDocumentMovedOutOfItsSection(): void
    {
        $row = $this->row('Насосные станции', 'Каталог насосных станций НС');
        $document = $row->getDocument();
        self::assertNotNull($document);
        $title = $document->getTitle();
        $documentId = (int) $document->getId();
        $rowId = (int) $row->getId();
        $pumps = $this->section('Насосные станции');

        // Администратор перенёс документ в раздел «ИТ», где sidorov не модератор.
        $document->setSection($this->section('ИТ'));
        $this->em()->flush();

        $this->loginAs('sidorov');
        $token = $this->tableToken((int) $pumps->getId());
        $url = '/sections/'.$pumps->getId().'/materials/rows/'.$rowId;

        $res = $this->api($url, ['field' => 'title', 'value' => 'Каталог НС (новое название)'], $token);
        self::assertTrue($res['ok']);
        self::assertFalse($res['row']['document']['manageable'], 'документ из чужого раздела через строку не правится');
        $this->em()->clear();
        self::assertSame($title, $this->em()->find(Document::class, $documentId)->getTitle(), 'название документа не изменилось');

        $res = $this->api($url.'/upload', [], $token, ['file' => $this->pdf('catalog-v2.pdf')]);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('перенесён', $res['error']);
        $this->em()->clear();
        self::assertSame(1, $this->em()->find(Document::class, $documentId)->getVersionCount(), 'новая версия не загружена');

        // Документ с ограниченным доступом: реквизиты в таблице не раскрываются.
        $moved = $this->em()->find(Document::class, $documentId);
        $moved->setRestricted(true)->setAllowedDepartments(['Отдел кадров']);
        $this->em()->flush();
        $crawler = $this->client->request('GET', '/sections/'.$pumps->getId().'/materials');
        $rows = json_decode($crawler->filter('#mt-rows')->text(), true, 512, \JSON_THROW_ON_ERROR);
        $serialized = array_values(array_filter($rows, static fn (array $r): bool => $r['id'] === $rowId))[0];
        self::assertTrue($serialized['document']['hidden']);
        self::assertNull($serialized['document']['url']);
        self::assertStringNotContainsString($title, (string) json_encode($rows, \JSON_UNESCAPED_UNICODE), 'название чужого документа не попадает на страницу');

        // Отвязать такой документ можно.
        $res = $this->api($url.'/detach', [], $token);
        self::assertTrue($res['ok']);
        self::assertNull($res['row']['document']);
    }

    public function testUploadMessageTellsThatNewFileAwaitsApproval(): void
    {
        $settings = static::getContainer()->get(PortalSettings::class);
        $was = $settings->approval();
        $settings->setApproval(true, $was['auto_publish']);
        try {
            $pumps = $this->section('Насосные станции');
            $row = $this->row('Насосные станции', 'Каталог насосных станций НС');
            $document = $row->getDocument();
            self::assertTrue($document?->isPublished());
            $current = (int) $document->getCurrentVersion()?->getNumber();

            $this->loginAs('sidorov');
            $token = $this->tableToken((int) $pumps->getId());
            $res = $this->api('/sections/'.$pumps->getId().'/materials/rows/'.$row->getId().'/upload', [], $token, ['file' => $this->pdf('catalog-v2.pdf')]);
            self::assertTrue($res['ok'], json_encode($res, \JSON_UNESCAPED_UNICODE));
            self::assertStringContainsString('версия '.($current + 1), $res['message'], 'номер загруженной версии, а не прежней текущей');
            self::assertStringContainsString('ждёт согласования', $res['message']);
            $this->em()->clear();
            self::assertSame($current, $this->em()->find(Document::class, $document->getId())?->getCurrentVersion()?->getNumber(), 'сотрудники видят прежнюю редакцию');
        } finally {
            $settings->setApproval($was['required'], $was['auto_publish']);
        }
    }

    public function testImportSurvivesMessyListValuesAndKeepsLockedListsIntact(): void
    {
        $long = str_repeat('Очень длинное значение ', 12); // больше 200 символов
        $values = ['Вариант  с двумя пробелами', "Вариант\nс переводом строки", $long];
        for ($i = 1; $i <= 55; ++$i) {
            $values[] = 'Значение '.$i;
        }
        $rows = [];
        foreach ($values as $i => $value) {
            $rows[] = ['Материал '.$i, 'Каталоги и брошюры', 0 === $i ? 'Проверено вручную' : 'Соответствует', $value];
        }
        $sheet = self::sheet('Импорт со списками', ['Материал', 'Тип', 'Требования PM', 'Серия'], $rows, [4 => ['Вариант с двумя пробелами']]);

        $plan = $this->importer()->plan([$sheet]);
        self::assertSame(\count($values), $plan['totals']['created']);

        $report = $this->importer()->apply([$sheet], false, $this->user('admin'));
        self::assertSame(\count($values), $report['totals']['created'], 'импорт не падает на значениях списков');
        $this->em()->clear();
        $this->materials()->forgetParameters();
        $section = $this->section('Импорт со списками');
        $pm = $this->materials()->roleParameter($section, MaterialParameter::ROLE_PM);
        self::assertNotNull($pm);
        self::assertNotContains('Проверено вручную', $pm->getOptions(), 'фиксированный список «Требования PM» не пополняется');
        self::assertSame('Проверено вручную', $this->row('Импорт со списками', 'Материал 0')->getValue($pm), 'значение не теряется');
        $series = $this->materials()->findParameter($section, $this->materials()->parameters($section)[6]->key());
        self::assertNotNull($series);
        self::assertSame('Серия', $series->getName());
        self::assertLessThanOrEqual(MaterialParameter::MAX_OPTIONS, \count($series->getOptions()));
        self::assertSame('Вариант с двумя пробелами', $this->row('Импорт со списками', 'Материал 0')->getValue($series));
        self::assertSame('Вариант с переводом строки', $this->row('Импорт со списками', 'Материал 1')->getValue($series));
        self::assertSame(MaterialParameter::MAX_OPTION, mb_strlen($this->row('Импорт со списками', 'Материал 2')->getValue($series)));
        self::assertSame('Значение 55', $this->row('Импорт со списками', 'Материал 57')->getValue($series), 'значение сверх 50 вариантов сохраняется как есть');
        self::assertNotEmpty($report['warnings']);
    }

    public function testExportedSheetsAreMatchedBackToTheirSections(): void
    {
        $manager = static::getContainer()->get(SectionManager::class);
        $long = $manager->create('Системы водоснабжения и водоотведения / канализация', null, null, $this->user('admin'));
        $this->materials()->enable($long);
        $this->materials()->addRow($long, null, 'Каталог систем');
        $history = $manager->create('History', null, null, $this->user('admin'));
        $this->materials()->enable($history);
        $this->materials()->addRow($history, null, 'Архив');

        $path = sys_get_temp_dir().'/'.bin2hex(random_bytes(4)).'-export.xlsx';
        static::getContainer()->get(MaterialExporter::class)->build([$long, $history], $path);
        $sheets = (new XlsxReader())->read($path);
        @unlink($path);
        $names = array_column($sheets, 'name');
        self::assertContains(XlsxWriter::sheetName($long->getName()), $names);
        self::assertLessThanOrEqual(31, mb_strlen(XlsxWriter::sheetName($long->getName())));
        self::assertNotContains('History', $names, 'имя History в Excel зарезервировано');

        $plan = $this->importer()->plan($sheets);
        self::assertSame(0, $plan['totals']['sectionsCreated'], 'листы находят свои разделы по служебному листу');
        self::assertSame(2, $plan['totals']['unchanged']);
        self::assertNotContains('Служебный — не изменять', array_column($plan['skipped'], 'name'));

        // Служебный лист потерялся (книгу собрали заново) — раздел находится по укороченному названию.
        $withoutMapping = array_values(array_filter($sheets, static fn (array $s): bool => !$s['hidden']));
        $plan = $this->importer()->plan($withoutMapping);
        self::assertSame(0, $plan['totals']['sectionsCreated']);

        // Выгрузка одного раздела с «/» в названии отдаётся файлом, а не ошибкой 500.
        $this->loginAs('admin');
        $this->client->request('GET', '/sections/'.$long->getId().'/materials.xlsx');
        self::assertResponseIsSuccessful();
        $disposition = (string) $this->client->getResponse()->headers->get('Content-Disposition');
        self::assertStringContainsString('attachment', $disposition);
        self::assertStringNotContainsString('%2F', $disposition);
    }

    public function testSimilarColumnsAreNotMergedAndKindsIgnoreYo(): void
    {
        $kinds = static::getContainer()->get(DocumentKindService::class);
        $kinds->create('Отчёты');
        $sheet = self::sheet('Проверка столбцов', ['Материал', 'Тип', 'Комментарий', 'Примечание'], [
            ['Отчёт об испытаниях', 'Отчеты', 'комментарий PM', 'примечание дизайнера'],
        ]);
        $plan = $this->importer()->plan([$sheet]);
        self::assertSame([], $plan['kindsCreated'], '«Отчеты» — тот же тип, что «Отчёты»');
        self::assertSame(['Примечание'], $plan['sheets'][0]['parametersCreated']);

        $this->importer()->apply([$sheet], false, $this->user('admin'));
        $this->em()->clear();
        $this->materials()->forgetParameters();
        $kinds->reset();
        $row = $this->row('Проверка столбцов', 'Отчёт об испытаниях');
        self::assertSame('Отчёты', $row->getKind()?->getName());
        $values = [];
        foreach ($this->materials()->parameters($this->section('Проверка столбцов')) as $parameter) {
            $values[$parameter->getName()] = $row->getValue($parameter);
        }
        self::assertSame('комментарий PM', $values['Комментарий']);
        self::assertSame('примечание дизайнера', $values['Примечание'], 'оба значения сохранены');
    }

    public function testPreviewWithoutKindColumnDoesNotReportChanges(): void
    {
        $pumps = $this->section('Насосные станции');
        $rows = [];
        foreach ($this->materials()->rows($pumps) as $row) {
            $rows[] = [$row->getTitle(), $row->getValue($this->materials()->roleParameter($pumps, MaterialParameter::ROLE_VERSION))];
        }
        $plan = $this->importer()->plan([self::sheet('Насосные станции', ['Материал', 'Версия'], $rows)]);
        self::assertSame(0, $plan['totals']['updated'], 'без столбца «Тип» тип строк не меняется — и в плане тоже');
        self::assertSame(\count($rows), $plan['totals']['unchanged']);
    }

    public function testWorkbookKeepsDatesAndNumbersValid(): void
    {
        self::assertNull(XlsxWriter::serial(new \DateTimeImmutable('1899-05-01')));
        self::assertSame('61', XlsxWriter::serial(new \DateTimeImmutable('1900-03-01')));
        self::assertSame('46266', XlsxWriter::serial(new \DateTimeImmutable('2026-09-01')));

        $section = static::getContainer()->get(SectionManager::class)->create('Числа и даты', null, null, $this->user('admin'));
        $this->materials()->enable($section);
        $number = $this->materials()->addParameter($section, 'Количество', MaterialParameter::KIND_NUMBER);
        $date = $this->materials()->roleParameter($section, MaterialParameter::ROLE_UPDATED);
        self::assertNotNull($date);
        $row = $this->materials()->addRow($section, null, 'Строка');
        // Значения, записанные прежними версиями без проверок, — длинное число и дата до 1900 года.
        $row->setValue($number, '1'.str_repeat('0', 400))->setValue($date, '1899-05-01');
        $this->em()->flush();
        try {
            $number->normalize(str_repeat('9', 40));
            self::fail('слишком длинное число не принимается');
        } catch (\DomainException $e) {
            self::assertStringContainsString('длинное', $e->getMessage());
        }

        $path = sys_get_temp_dir().'/'.bin2hex(random_bytes(4)).'-numbers.xlsx';
        static::getContainer()->get(MaterialExporter::class)->build([$section], $path, false);
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($path));
        $xml = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        self::assertStringNotContainsString('INF', $xml, 'длинное число пишется текстом, файл не портится');
        $sheets = (new XlsxReader())->read($path);
        @unlink($path);
        $cells = $sheets[0]['rows'][2];
        self::assertContains('1'.str_repeat('0', 400), $cells);
        self::assertContains('01.05.1899', $cells, 'дата до 1900 года выгружается текстом');
    }

    public function testReaderClampsHugeValidationRanges(): void
    {
        $path = sys_get_temp_dir().'/'.bin2hex(random_bytes(4)).'-crafted.xlsx';
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($path, \ZipArchive::CREATE));
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Лист" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>');
        $zip->addFromString('xl/worksheets/sheet1.xml', '<?xml version="1.0"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData><row r="1"><c r="A1" t="inlineStr"><is><t>Материал</t></is></c><c r="B1" s="0"><v>60</v></c></row></sheetData>'
            .'<dataValidations count="2"><dataValidation type="list" sqref="A1:XFD1048576"><formula1>"да,нет"</formula1></dataValidation><dataValidation type="list" sqref="ZZZZZZZZ1:ZZZZZZZZZ9"><formula1>"x"</formula1></dataValidation></dataValidations></worksheet>');
        $zip->close();
        $started = microtime(true);
        $sheets = (new XlsxReader())->read($path);
        @unlink($path);
        self::assertLessThan(5.0, microtime(true) - $started, 'разбор не зависает на огромных диапазонах');
        self::assertCount(16384, $sheets[0]['lists'], 'не дальше последнего столбца XFD');
    }

    public function testWidthResetAndTablePurge(): void
    {
        $pumps = $this->section('Насосные станции');
        $parameter = $this->materials()->roleParameter($pumps, MaterialParameter::ROLE_COMMENT);
        self::assertNotNull($parameter);
        self::assertNotNull($parameter->getWidth());
        $this->loginAs('admin');
        $token = $this->tableToken((int) $pumps->getId());
        $res = $this->api('/sections/'.$pumps->getId().'/materials/parameters/'.$parameter->getId(), ['name' => $parameter->getName(), 'width' => ''], $token);
        self::assertTrue($res['ok']);
        self::assertNull($res['parameter']['width'], 'пустая ширина — «по содержимому»');

        // Модератор удалить таблицу целиком не может, администратор — может; после этого раздел можно перенести.
        $csrf = $token;
        $this->loginAs('sidorov');
        $this->client->request('POST', '/sections/'.$pumps->getId().'/materials/purge', ['_token' => $csrf]);
        self::assertResponseStatusCodeSame(403);

        $this->loginAs('admin');
        $crawler = $this->client->request('GET', '/sections/'.$pumps->getId().'/materials');
        $form = $crawler->filter('form[action$="/materials/purge"]')->form();
        $this->client->submit($form);
        self::assertResponseRedirects('/sections/'.$pumps->getId());
        $this->em()->clear();
        $this->materials()->forgetParameters();
        $pumps = $this->section('Насосные станции');
        self::assertSame([], $this->materials()->rows($pumps));
        self::assertSame([], $this->materials()->parameters($pumps));
        self::assertFalse($pumps->isMaterialsEnabled());
        static::getContainer()->get(SectionManager::class)->assertCanMove($pumps, $this->section('Производство'));
    }

    public function testKeyboardAndEditorFixesInGridScript(): void
    {
        $js = (string) file_get_contents(static::getContainer()->getParameter('kernel.project_dir').'/public/js/materials.js');
        self::assertStringContainsString(".mt__editor') || cell.classList.contains('is-editing')", $js, 'двойной щелчок в редакторе не открывает его заново');
        self::assertStringContainsString('cellTarget !== e.target', $js, 'клавиши таблицы не перехватываются на кнопках');
        self::assertStringNotContainsString("case 'Tab': e.preventDefault(); move(", $js, 'Tab не заперт в таблице');
        self::assertStringContainsString('cell.focus({ preventScroll: true })', $js, 'после перерисовки строки фокус возвращается в таблицу без прокрутки');
        self::assertStringContainsString("if (e.key === 'Tab') { closeMenu(); focusMenuButton(id); }", $js, 'Tab в меню строки не уводит фокус в начало страницы');
        self::assertStringContainsString('restoreField(fresh, current.key, previous)', $js, 'при сбое откатывается только своё поле');
    }
}
