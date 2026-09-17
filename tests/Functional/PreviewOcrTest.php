<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Document;
use App\Repository\DocumentTextRepository;
use App\Service\DocumentManager;
use App\Service\PortalSettings;
use App\Service\Preview\DocumentPreviewer;
use App\Service\Process\Shell;
use App\Service\Text\OcrReader;
use App\Service\Text\TextExtractor;
use App\Tests\PortalTestCase;
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
        imagedestroy($image);

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

        // Скан (PDF без текстового слоя) распознаётся при индексации и находится поиском.
        $document = (new Document($this->section('Общие документы')))->setTitle('Скан приказа')->setCode('СКАН-001')->setType(Document::TYPE_FILE)->setPublic(true);
        static::getContainer()->get(DocumentManager::class)->create($document, new UploadedFile($image, 'scan.png', null, null, true), null, null, $this->user('ivanov'), true);
        $text = static::getContainer()->get(DocumentTextRepository::class)->findForDocument($document);
        self::assertNotNull($text);
        self::assertStringContainsStringIgnoringCase('DOCUMENT', $text->getContent(), 'распознанный текст попадает в индекс');

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
