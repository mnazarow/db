<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Document;
use App\Service\DocumentManager;
use App\Service\Pdf\PdfFactory;
use App\Service\Pdf\PdfWatermarker;
use App\Service\PortalSettings;
use App\Service\Process\Shell;
use App\Tests\PortalTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Водяной знак: ФИО и время на PDF при скачивании и просмотре (qpdf накладывает страницу со знаком под размер
 * каждой страницы), исключение для тех, кто правит документ, режимы и знак поверх страниц портала.
 */
final class WatermarkTest extends PortalTestCase
{
    protected function tearDown(): void
    {
        $this->settings()->setWatermark(PdfWatermarker::MODE_OFF, '', true);
        parent::tearDown();
    }

    private function settings(): PortalSettings
    {
        return static::getContainer()->get(PortalSettings::class);
    }

    private function watermarker(): PdfWatermarker
    {
        return static::getContainer()->get(PdfWatermarker::class);
    }

    private static ?string $sample = null;

    /** PDF из трёх страниц: две A4 и одна альбомная (один и тот же файл на весь класс). */
    private function samplePdf(): string
    {
        if (null !== self::$sample) {
            return self::$sample;
        }
        $pdf = static::getContainer()->get(PdfFactory::class)->create();
        $pdf->addPage();
        $pdf->write('Инструкция по охране труда: страница первая.');
        $pdf->addPage();
        $pdf->write('Страница вторая.');
        $pdf->addPage(841.89, 595.28);
        $pdf->rotatedText(420, 300, 'Альбомная страница', 14, 0);

        return self::$sample = $pdf->output();
    }

    private function pdfDocument(string $code, bool $restricted = false, bool $public = false): Document
    {
        $existing = static::getContainer()->get('App\Repository\DocumentRepository')->findOneBy(['code' => $code]);
        if (null !== $existing) {
            return $existing;
        }
        $document = (new Document($this->section('Общие документы')))->setTitle('Инструкция '.$code)->setCode($code)
            ->setType(Document::TYPE_FILE)->setPublic($public);
        $path = sys_get_temp_dir().'/'.bin2hex(random_bytes(4)).'-wm.pdf';
        file_put_contents($path, $this->samplePdf());
        static::getContainer()->get(DocumentManager::class)->create($document, new UploadedFile($path, 'instruction.pdf', 'application/pdf', null, true), null, null, $this->user('ivanov'), true);
        if ($restricted) {
            $document->setRestricted(true);
            $document->setAllowedUsers([$this->user('smirnov')]);
            $this->em()->flush();
        }

        return $document;
    }

    private static function requireQpdf(): void
    {
        if (!Shell::exists('qpdf')) {
            self::markTestSkipped('Нет qpdf: водяной знак не накладывается.');
        }
    }

    public function testRangesAndMarkPdf(): void
    {
        self::assertSame('1-3,5,7-8', PdfWatermarker::ranges([8, 1, 2, 3, 5, 7]));
        self::assertSame('4', PdfWatermarker::ranges([4]));
        $texts = $this->watermarker()->texts($this->user('smirnov'), '10.1.2.3', new \DateTimeImmutable('2026-10-10 09:05'), 'Инструкция');
        self::assertStringContainsString('(smirnov) · 10.10.2026 09:05', $texts['main']);
        self::assertStringContainsString('Выдано: ', $texts['footer']);
        self::assertStringContainsString('10.1.2.3', $texts['footer']);
        self::assertSame('Гость · 10.1.2.3 · 10.10.2026 09:05', $this->watermarker()->texts(null, '10.1.2.3', new \DateTimeImmutable('2026-10-10 09:05'))['main']);

        $pdf = $this->watermarker()->markPdf([[595.3, 841.9], [841.9, 595.3]], $texts);
        self::assertStringStartsWith('%PDF-', $pdf);
        self::assertStringContainsString('/ExtGState', $pdf, 'надпись полупрозрачная');
        self::assertStringContainsString('/MediaBox [0 0 841.9 595.3]', $pdf, 'страница знака под размер альбомной страницы');

        $svg = rawurldecode(substr($this->watermarker()->svgDataUri('<Иванов> & "Ко"'), \strlen('data:image/svg+xml;charset=utf-8,')));
        self::assertStringContainsString('&lt;Иванов&gt; &amp; &quot;Ко&quot;', $svg, 'текст в SVG экранируется');
    }

    public function testPageSizesFollowRotation(): void
    {
        self::requireQpdf();
        $dir = sys_get_temp_dir().'/wm-'.bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents($dir.'/src.pdf', $this->samplePdf());
        Shell::run(['qpdf', $dir.'/src.pdf', '--rotate=+90:1', '--', $dir.'/rotated.pdf']);
        $sizes = $this->watermarker()->pageSizes($dir.'/rotated.pdf');
        self::assertSame([1 => [841.9, 595.3], 2 => [595.3, 841.9], 3 => [841.9, 595.3]], $sizes, 'повёрнутая страница — альбомная');
        self::assertNull($this->watermarker()->pageSizes(__FILE__), 'не PDF — null, а не исключение');
        array_map('unlink', glob($dir.'/*') ?: []);
        rmdir($dir);
    }

    public function testDownloadAndPreviewCarryWatermark(): void
    {
        self::requireQpdf();
        $document = $this->pdfDocument('ВЗ-001');
        $original = $this->samplePdf();
        $this->settings()->setWatermark(PdfWatermarker::MODE_ALL, 'ДСП', true);

        $this->loginAs('smirnov');
        $this->client->request('GET', '/documents/'.$document->getId().'/download');
        self::assertResponseIsSuccessful();
        $response = $this->client->getResponse();
        $content = (string) $this->client->getInternalResponse()->getContent();
        self::assertStringStartsWith('%PDF-', $content);
        self::assertNotSame(md5($original), md5($content), 'отдана копия со знаком');
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        self::assertSame('none', $response->headers->get('Accept-Ranges'), 'копия не отдаётся частями');
        if (Shell::exists('pdftotext')) {
            $file = sys_get_temp_dir().'/wm-check-'.bin2hex(random_bytes(4)).'.pdf';
            file_put_contents($file, $content);
            $text = Shell::run(['pdftotext', '-enc', 'UTF-8', $file, '-'])['out'];
            unlink($file);
            self::assertStringContainsString('smirnov', $text);
            self::assertStringContainsString('Выдано: Смирнов', $text, 'строка внизу страницы');
            self::assertStringContainsString('Инструкция по охране труда', $text, 'текст документа на месте');
            self::assertSame(3, substr_count($text, "\f"), 'три страницы, как в исходнике');
        }
        self::assertSame([], glob(sys_get_temp_dir().'/docportal-wm-*') ?: [], 'временный файл знака удалён');

        // Просмотр в браузере (inline) — тоже со знаком.
        $this->client->request('GET', '/documents/'.$document->getId().'/download?inline=1');
        self::assertResponseIsSuccessful();
        self::assertNotSame(md5($original), md5((string) $this->client->getInternalResponse()->getContent()));

        // Модератор раздела получает чистый файл (настройка «не ставить тем, кто правит документ»).
        $this->loginAs('ivanov');
        $this->client->request('GET', '/documents/'.$document->getId().'/download');
        self::assertSame(md5($original), md5((string) $this->client->getInternalResponse()->getContent()));

        // …а без этой настройки — тоже со знаком.
        $this->settings()->setWatermark(PdfWatermarker::MODE_ALL, '', false);
        $this->client->request('GET', '/documents/'.$document->getId().'/download');
        self::assertNotSame(md5($original), md5((string) $this->client->getInternalResponse()->getContent()));
    }

    public function testModesSelectDocuments(): void
    {
        $open = $this->pdfDocument('ВЗ-002', false, true);
        $internal = $this->pdfDocument('ВЗ-003');
        $restricted = $this->pdfDocument('ВЗ-004', true);
        $smirnov = $this->user('smirnov');
        $w = $this->watermarker();

        $this->settings()->setWatermark(PdfWatermarker::MODE_OFF, '', true);
        self::assertFalse($w->needsMark($restricted, $smirnov));

        $this->settings()->setWatermark(PdfWatermarker::MODE_RESTRICTED, '', true);
        self::assertTrue($w->needsMark($restricted, $smirnov));
        self::assertFalse($w->needsMark($internal, $smirnov));
        self::assertFalse($w->needsMark($open, $smirnov));
        self::assertFalse($w->needsMark($restricted, $this->user('ivanov')), 'модератор раздела — без знака');
        self::assertFalse($w->marksBundles());

        $this->settings()->setWatermark(PdfWatermarker::MODE_INTERNAL, '', true);
        self::assertTrue($w->needsMark($internal, $smirnov));
        self::assertTrue($w->needsMark($restricted, $smirnov));
        self::assertFalse($w->needsMark($open, $smirnov));
        self::assertFalse($w->marksBundles(), 'сборники собирают модераторы — им знак не ставится');

        $this->settings()->setWatermark(PdfWatermarker::MODE_ALL, '', false);
        self::assertTrue($w->needsMark($open, null), 'гостю тоже');
        self::assertTrue($w->marksBundles());

        // Режим «только с ограниченным доступом»: обычный внутренний документ скачивается как есть.
        self::requireQpdf();
        $this->settings()->setWatermark(PdfWatermarker::MODE_RESTRICTED, '', true);
        $this->loginAs('smirnov');
        $this->client->request('GET', '/documents/'.$internal->getId().'/download');
        self::assertSame(md5($this->samplePdf()), md5((string) $this->client->getInternalResponse()->getContent()), 'без знака');
    }

    public function testBrokenPdfIsServedAsIs(): void
    {
        self::requireQpdf();
        $document = (new Document($this->section('Общие документы')))->setTitle('Битый PDF')->setCode('ВЗ-005')->setType(Document::TYPE_FILE)->setPublic(false);
        $path = sys_get_temp_dir().'/'.bin2hex(random_bytes(4)).'-broken.pdf';
        file_put_contents($path, "%PDF-1.4\nэто не настоящий PDF");
        static::getContainer()->get(DocumentManager::class)->create($document, new UploadedFile($path, 'broken.pdf', 'application/pdf', null, true), null, null, $this->user('ivanov'), true);
        $this->settings()->setWatermark(PdfWatermarker::MODE_ALL, '', true);

        $this->loginAs('smirnov');
        $this->client->request('GET', '/documents/'.$document->getId().'/download');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('это не настоящий PDF', (string) $this->client->getInternalResponse()->getContent(), 'файл не потерян: отдан как есть');
    }

    public function testPageWatermarkAndAdminSettings(): void
    {
        $document = (new Document($this->section('Общие документы')))->setTitle('Страница со знаком')->setCode('ВЗ-006')->setType(Document::TYPE_PAGE)->setPublic(false);
        static::getContainer()->get(DocumentManager::class)->create($document, null, '<p>Текст страницы</p>', null, $this->user('ivanov'), true);

        $this->loginAs('smirnov');
        $this->client->request('GET', '/documents/'.$document->getId());
        self::assertSelectorNotExists('.page-content--watermark');

        $this->loginAs('admin');
        $crawler = $this->client->request('GET', '/admin/security');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('#watermark', 'Водяной знак на PDF');
        $token = (string) $crawler->filter('#watermark input[name=_token]')->attr('value');
        $this->client->request('POST', '/admin/security/watermark', ['_token' => $token, 'mode' => 'internal', 'text' => "Для служебного\nпользования", 'skip_editors' => '1']);
        self::assertResponseRedirects('/admin/security');
        self::assertSame(['mode' => 'internal', 'text' => 'Для служебного пользования', 'skip_editors' => true], $this->settings()->watermark());
        $this->client->request('POST', '/admin/security/watermark', ['_token' => $token, 'mode' => 'всё подряд']);
        self::assertSame('off', $this->settings()->watermark()['mode'], 'неизвестный режим — выключено');
        self::assertFalse($this->settings()->watermark()['skip_editors']);
        $this->client->request('POST', '/admin/security/watermark', ['_token' => 'bad', 'mode' => 'all']);
        self::assertResponseStatusCodeSame(403);

        $this->settings()->setWatermark(PdfWatermarker::MODE_INTERNAL, 'ДСП', true);
        $this->loginAs('smirnov');
        $this->client->request('GET', '/documents/'.$document->getId());
        self::assertSelectorExists('.page-content--watermark');
        self::assertStringContainsString('smirnov', rawurldecode((string) $this->client->getCrawler()->filter('.page-content--watermark')->attr('style')));
        $this->loginAs('ivanov');
        $this->client->request('GET', '/documents/'.$document->getId());
        self::assertSelectorNotExists('.page-content--watermark', 'модератору раздела — без знака');
    }
}
