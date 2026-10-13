<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Document;
use App\Repository\DocumentTextRepository;
use App\Service\DocumentManager;
use App\Service\FileStorage;
use App\Service\PortalSettings;
use App\Service\Preview\DocumentPreviewer;
use App\Service\Process\Shell;
use App\Service\Text\OcrReader;
use App\Service\Text\TextExtractor;
use App\Service\Text\TextIndexer;
use App\Tests\PortalTestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Предпросмотр офисных файлов в браузере (LibreOffice → PDF) и распознавание сканов (tesseract).
 */
final class PreviewOcrTest extends PortalTestCase
{
    private function previewer(): DocumentPreviewer
    {
        return static::getContainer()->get(DocumentPreviewer::class);
    }

    private function settings(): PortalSettings
    {
        return static::getContainer()->get(PortalSettings::class);
    }

    private function upload(string $name, string $content): UploadedFile
    {
        $path = sys_get_temp_dir().'/'.bin2hex(random_bytes(4)).'-'.$name;
        file_put_contents($path, $content);

        return new UploadedFile($path, $name, null, null, true);
    }

    /** Минимальный docx: LibreOffice открывает его и переводит в PDF. */
    private function docx(string $text): string
    {
        $path = sys_get_temp_dir().'/docportal-test-'.bin2hex(random_bytes(4)).'.docx';
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($path, \ZipArchive::CREATE));
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/></Relationships>');
        $zip->addFromString('word/document.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body><w:p><w:r><w:t xml:space="preserve">'.htmlspecialchars($text, \ENT_XML1, 'UTF-8').'</w:t></w:r></w:p></w:body></w:document>');
        $zip->close();

        return $path;
    }

    /** Картинка с текстом (для распознавания): чёрный текст на белом, шрифт DejaVu. */
    private function textImage(string $text): ?string
    {
        $font = '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf';
        if (!\function_exists('imagettftext') || !is_file($font)) {
            return null;
        }
        $image = imagecreatetruecolor(1400, 300);
        self::assertNotFalse($image);
        imagefill($image, 0, 0, (int) imagecolorallocate($image, 255, 255, 255));
        imagettftext($image, 48, 0, 60, 120, (int) imagecolorallocate($image, 0, 0, 0), $font, $text);
        $path = sys_get_temp_dir().'/docportal-ocr-'.bin2hex(random_bytes(4)).'.png';
        imagepng($image, $path);
        unset($image);

        return $path;
    }

    public function testShellRunsProgramsAndReportsMissing(): void
    {
        $missing = Shell::run(['docportal-no-such-program-'.bin2hex(random_bytes(3))]);
        self::assertSame(127, $missing['code']);
        self::assertStringContainsString('не найдена', $missing['err']);
        self::assertNull(Shell::resolve('docportal-no-such-program'));
        self::assertNull(Shell::resolve(''));

        if (!Shell::exists('printf')) {
            self::markTestSkipped('нет printf');
        }
        // Аргументы передаются как есть: пробелы и кавычки в именах файлов не ломают команду.
        $run = Shell::run(['printf', '%s|%s', 'файл с пробелом', 'и "кавычкой"']);
        self::assertSame(0, $run['code']);
        self::assertSame('файл с пробелом|и "кавычкой"', $run['out']);
    }

    public function testOfficePreviewIsConvertedAndCached(): void
    {
        $previewer = $this->previewer();
        if (!$previewer->isAvailable()) {
            self::markTestSkipped('LibreOffice не установлен');
        }
        $document = (new Document($this->section('ИТ')))->setTitle('Регламент закупок')->setCode('ЗАК-001')->setType(Document::TYPE_FILE)->setPublic(true);
        $file = $this->docx('Порядок согласования закупок на 2026 год.');
        static::getContainer()->get(DocumentManager::class)->create($document, new UploadedFile($file, 'zakupki.docx', null, null, true), null, null, $this->user('petrova'), true);
        $version = $document->getCurrentVersion();
        self::assertNotNull($version);

        self::assertTrue($previewer->supports($version));
        self::assertFalse($previewer->isReady($version), 'до первого обращения предпросмотра нет');
        $pdf = $previewer->pdf($version);
        self::assertNotNull($pdf, 'LibreOffice должен перевести docx в PDF');
        self::assertStringStartsWith('%PDF', (string) file_get_contents($pdf, false, null, 0, 4));
        self::assertTrue($previewer->isReady($version));
        self::assertSame($pdf, $previewer->pdf($version), 'второй раз берётся из кэша');
        self::assertSame(1, $previewer->usage()['files']);

        // Маршрут отдаёт PDF встроенно, в карточке есть ссылка на предпросмотр.
        $id = (int) $document->getId();
        $this->client->request('GET', '/documents/'.$id.'/preview');
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/pdf');
        self::assertStringContainsString('inline', (string) $this->client->getResponse()->headers->get('Content-Disposition'));
        $crawler = $this->client->request('GET', '/documents/'.$id);
        self::assertSelectorExists('.card--preview iframe');
        self::assertStringContainsString('/documents/'.$id.'/preview', $crawler->filter('.card--preview iframe')->attr('src') ?? '');

        // Удаление документа убирает и готовые предпросмотры.
        static::getContainer()->get(DocumentManager::class)->delete($this->document('ЗАК-001'), $this->user('petrova'));
        self::assertSame(0, $previewer->usage()['files']);
    }

    public function testFailedConversionIsRememberedAndTempFoldersAreCleaned(): void
    {
        // Вместо LibreOffice — сценарий, который всегда завершается ошибкой и считает свои запуски.
        $bin = sys_get_temp_dir().'/docportal-fake-soffice-'.bin2hex(random_bytes(3));
        $counter = $bin.'.runs';
        file_put_contents($bin, "#!/bin/sh\necho run >> '".$counter."'\necho 'Error: source file could not be loaded' >&2\nexit 1\n");
        chmod($bin, 0755);
        $dir = sys_get_temp_dir().'/docportal-preview-'.bin2hex(random_bytes(3));
        $previewer = new DocumentPreviewer(static::getContainer()->get(FileStorage::class), new NullLogger(), $dir, $bin, 10);

        $document = (new Document($this->section('ИТ')))->setTitle('Повреждённый файл')->setCode('ПОВ-001')->setType(Document::TYPE_FILE)->setPublic(true);
        static::getContainer()->get(DocumentManager::class)->create($document, $this->upload('broken.docx', 'не docx'), null, null, $this->user('petrova'), true);
        $version = $document->getCurrentVersion();
        self::assertNotNull($version);

        self::assertFalse($previewer->hasFailed($version));
        self::assertNull($previewer->pdf($version));
        self::assertTrue($previewer->hasFailed($version), 'неудача запоминается');
        self::assertNull($previewer->pdf($version));
        self::assertCount(1, (array) file($counter), 'повторный просмотр не запускает конвертацию снова');
        $docDir = $dir.'/'.$document->getId();
        self::assertSame([], glob($docDir.'/tmp-*'), 'временный каталог конвертации удалён');

        // Каталог, оставшийся от прерванного процесса, удаляется при следующей конвертации.
        $previewer->clear();
        self::assertFalse($previewer->hasFailed($version), 'очистка кэша снимает отметку о неудаче');
        mkdir($docDir.'/tmp-stale/profile', 0700, true);
        touch($docDir.'/tmp-stale', time() - 7200);
        mkdir($docDir.'/tmp-fresh', 0700, true);
        self::assertNull($previewer->pdf($version));
        self::assertCount(2, (array) file($counter));
        self::assertDirectoryDoesNotExist($docDir.'/tmp-stale');
        self::assertDirectoryExists($docDir.'/tmp-fresh', 'каталог идущей сейчас конвертации не трогается');

        $previewer->clear();
        self::assertDirectoryDoesNotExist($docDir, 'очистка убирает и временные каталоги');
        @unlink($bin);
        @unlink($counter);
        @rmdir($dir);
    }

    public function testPreviewRefusesUnsupportedAndProtectsInternal(): void
    {
        $manager = static::getContainer()->get(DocumentManager::class);
        $previewer = $this->previewer();
        // Текстовый файл браузер показывает сам — предпросмотр перенаправляет на просмотр файла.
        $plain = (new Document($this->section('ИТ')))->setTitle('Памятка')->setCode('ПАМ-001')->setType(Document::TYPE_FILE)->setPublic(true);
        $manager->create($plain, $this->upload('pamyatka.txt', 'текст'), null, null, $this->user('petrova'), true);
        $plainVersion = $plain->getCurrentVersion();
        self::assertNotNull($plainVersion);
        self::assertFalse($previewer->supports($plainVersion));
        $this->client->request('GET', '/documents/'.$plain->getId().'/preview');
        if ($previewer->isAvailable()) {
            self::assertResponseRedirects('/documents/'.$plain->getId().'/versions/1/download?inline=1');
        } else {
            self::assertResponseStatusCodeSame(404);
        }

        // Внутренний документ гостю недоступен и в предпросмотре.
        $internal = (new Document($this->section('Общие документы')))->setTitle('Служебный отчёт')->setCode('СО-001')->setType(Document::TYPE_FILE)->setPublic(false);
        $manager->create($internal, $this->upload('otchet.txt', 'секрет'), null, null, $this->user('ivanov'), true);
        $this->client->request('GET', '/documents/'.$internal->getId().'/preview');
        self::assertResponseRedirects('/login');
    }

    public function testInlineFilesCannotRunScripts(): void
    {
        $manager = static::getContainer()->get(DocumentManager::class);
        // HTML под видом .txt: по содержимому это text/html, но в браузере файл открывается только как текст.
        $html = (new Document($this->section('ИТ')))->setTitle('Подложный текст')->setCode('XSS-001')->setType(Document::TYPE_FILE)->setPublic(true);
        $manager->create($html, $this->upload('note.txt', '<html><body><script>alert(document.cookie)</script></body></html>'), null, null, $this->user('petrova'), true);
        $this->client->request('GET', '/documents/'.$html->getId().'/download?inline=1');
        self::assertResponseIsSuccessful();
        self::assertStringStartsWith('text/plain', (string) $this->client->getResponse()->headers->get('Content-Type'));
        self::assertStringContainsString('sandbox', (string) $this->client->getResponse()->headers->get('Content-Security-Policy'));
        self::assertSame('nosniff', $this->client->getResponse()->headers->get('X-Content-Type-Options'));

        // SVG со скриптом: показывается картинкой, но при прямом открытии скрипты запрещены политикой.
        $svg = (new Document($this->section('ИТ')))->setTitle('Схема')->setCode('XSS-002')->setType(Document::TYPE_FILE)->setPublic(true);
        $manager->create($svg, $this->upload('scheme.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script><rect width="10" height="10"/></svg>'), null, null, $this->user('petrova'), true);
        $this->client->request('GET', '/documents/'.$svg->getId().'/download?inline=1');
        self::assertResponseIsSuccessful();
        self::assertSame('image/svg+xml', $this->client->getResponse()->headers->get('Content-Type'));
        $csp = (string) $this->client->getResponse()->headers->get('Content-Security-Policy');
        self::assertStringContainsString("default-src 'none'", $csp);
        self::assertStringContainsString('sandbox', $csp);

        // Файлы вне белого списка (например, .xml) в браузере не открываются — только скачиваются.
        $xml = (new Document($this->section('ИТ')))->setTitle('Выгрузка')->setCode('XSS-003')->setType(Document::TYPE_FILE)->setPublic(true);
        $manager->create($xml, $this->upload('data.xml', '<?xml version="1.0"?><x:script xmlns:x="http://www.w3.org/1999/xhtml">alert(1)</x:script>'), null, null, $this->user('petrova'), true);
        $this->client->request('GET', '/documents/'.$xml->getId().'/download?inline=1');
        self::assertResponseIsSuccessful();
        self::assertStringStartsWith('attachment', (string) $this->client->getResponse()->headers->get('Content-Disposition'));

        // На странице документа текстовый предпросмотр — в iframe-песочнице.
        $crawler = $this->client->request('GET', '/documents/'.$html->getId());
        self::assertResponseIsSuccessful();
        self::assertSame(1, $crawler->filter('iframe.preview-frame__iframe[sandbox]')->count());
    }

    public function testOcrRecognizesScans(): void
    {
        $ocr = static::getContainer()->get(OcrReader::class);
        if (!$ocr->isAvailable()) {
            self::markTestSkipped('tesseract или pdftoppm не установлены');
        }
        $image = $this->textImage('DOCUMENT CONTROL');
        if (null === $image) {
            self::markTestSkipped('нет GD с поддержкой TrueType или шрифта DejaVu');
        }
        // Пока распознавание выключено, картинки не поддерживаются вовсе.
        $extractor = static::getContainer()->get(TextExtractor::class);
        $this->settings()->setOcr(['enabled' => false]);
        self::assertFalse($ocr->isEnabled());
        self::assertFalse($extractor->supports('png'));
        self::assertSame('', $ocr->readImage($image));

        $this->settings()->setOcr(['enabled' => true, 'languages' => 'eng', 'max_pages' => 2, 'dpi' => 150]);
        self::assertTrue($ocr->isEnabled());
        self::assertTrue($extractor->supports('png'));
        self::assertContains('png', $extractor->supportedExtensions());
        self::assertStringContainsStringIgnoringCase('DOCUMENT', $ocr->readImage($image));

        // Загрузка через сайт: распознавание в веб-запросе не выполняется (оно может идти минутами) —
        // текст ждёт фонового задания, API отвечает статусом pending.
        $extractor->allowOcr(false);
        $document = (new Document($this->section('Общие документы')))->setTitle('Скан приказа')->setCode('СКАН-001')->setType(Document::TYPE_FILE)->setPublic(true);
        static::getContainer()->get(DocumentManager::class)->create($document, new UploadedFile($image, 'scan.png', null, null, true), null, null, $this->user('ivanov'), true);
        $texts = static::getContainer()->get(DocumentTextRepository::class);
        $text = $texts->findForDocument($document);
        self::assertNotNull($text);
        self::assertSame(TextExtractor::STATUS_PENDING, $text->getStatus());
        self::assertContains((int) $document->getId(), $texts->findOutdatedDocumentIds(1000), 'документ в очереди фоновой индексации');
        $id = (int) $document->getId();
        $this->em()->getConnection()->executeStatement('UPDATE document SET updated_at = ? WHERE id = ?', [(new \DateTimeImmutable('-1 hour'))->format('Y-m-d H:i:s'), $id]);
        $this->em()->clear();

        // Фоновое задание (cron) распознаёт скан; документ снова попадает в ленту изменений API.
        $extractor->allowOcr(true);
        $stats = static::getContainer()->get(TextIndexer::class)->reindex();
        self::assertGreaterThanOrEqual(1, $stats['indexed']);
        $this->em()->clear();
        $text = $texts->findForDocument($this->document('СКАН-001'));
        self::assertNotNull($text);
        self::assertSame(TextExtractor::STATUS_OK, $text->getStatus());
        self::assertStringContainsStringIgnoringCase('DOCUMENT', $text->getContent(), 'распознанный текст попадает в индекс');
        self::assertGreaterThan(new \DateTimeImmutable('-10 minutes'), $this->document('СКАН-001')->getUpdatedAt(), 'распознанный текст — изменение для внешних систем');

        // Веб-процесс со своим (пустым) файловым кэшем — другой контейнер Docker или кэш, очищенный
        // обновлением, — отдаёт распознанный текст из индекса, а не «ждёт распознавания» вечно.
        $extractor->allowOcr(false);
        foreach (glob(static::getContainer()->getParameter('kernel.cache_dir').'/doc-text/*') ?: [] as $cached) {
            @unlink($cached);
        }
        $version = $this->document('СКАН-001')->getCurrentVersion();
        self::assertNotNull($version);
        $result = $extractor->extract($version);
        self::assertSame(TextExtractor::STATUS_OK, $result['status']);
        self::assertStringContainsStringIgnoringCase('DOCUMENT', (string) $result['text']);
        $extractor->allowOcr(null);

        $crawler = $this->client->request('GET', '/search?q=DOCUMENT+CONTROL');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Скан приказа', $crawler->text());

        $this->settings()->setOcr(['enabled' => false]);
    }

    public function testOcrDecidesWhenPdfIsAScan(): void
    {
        $ocr = static::getContainer()->get(OcrReader::class);
        self::assertTrue($ocr->looksLikeScan('', 1), 'пустой текст — скан');
        self::assertTrue($ocr->looksLikeScan(str_repeat('a', 50), 1));
        self::assertFalse($ocr->looksLikeScan(str_repeat('a', 500), 1), 'текстовый слой есть — распознавать не нужно');
        self::assertTrue($ocr->looksLikeScan(str_repeat('a', 500), 20), 'на 20 страниц 500 символов — всё-таки скан');

        self::assertSame('rus+eng', PortalSettings::normalizeLanguages('rus, eng'));
        self::assertSame('rus+eng', PortalSettings::normalizeLanguages(' rus + eng '));
        self::assertSame('eng', PortalSettings::normalizeLanguages('eng+eng'));
        self::assertSame('', PortalSettings::normalizeLanguages('  ++  '));
    }
}
