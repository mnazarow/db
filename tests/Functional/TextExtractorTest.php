<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Service\Text\TextExtractor;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Извлечение текста из файлов разных форматов (для API и описаний через LLM).
 */
final class TextExtractorTest extends KernelTestCase
{
    private string $dir;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->dir = sys_get_temp_dir().'/docportal-text-'.bin2hex(random_bytes(4));
        mkdir($this->dir, 0700, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir.'/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
        parent::tearDown();
        restore_exception_handler();
    }

    private function extractor(): TextExtractor
    {
        return static::getContainer()->get(TextExtractor::class);
    }

    private function zip(string $name, array $entries): string
    {
        $path = $this->dir.'/'.$name;
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($path, \ZipArchive::CREATE));
        foreach ($entries as $entry => $content) {
            $zip->addFromString($entry, $content);
        }
        $zip->close();

        return $path;
    }

    public function testPlainHtmlAndEncodings(): void
    {
        $x = $this->extractor();
        file_put_contents($this->dir.'/a.txt', "\xEF\xBB\xBFПривет,  мир\r\n\r\n\r\n\r\nВторая строка");
        self::assertSame("Привет, мир\n\nВторая строка", $x->extractFile($this->dir.'/a.txt', 'txt'));

        file_put_contents($this->dir.'/cp1251.txt', mb_convert_encoding('Текст в кодировке Windows-1251', 'Windows-1251', 'UTF-8'));
        self::assertSame('Текст в кодировке Windows-1251', $x->extractFile($this->dir.'/cp1251.txt', 'txt'));

        file_put_contents($this->dir.'/p.html', '<html><head><style>p{color:red}</style><script>alert(1)</script></head><body><h1>Заголовок</h1><p>Абзац &amp; текст</p><table><tr><td>1</td><td>2</td></tr></table></body></html>');
        $html = $x->extractFile($this->dir.'/p.html', 'html');
        self::assertStringContainsString("Заголовок\nАбзац & текст", $html);
        self::assertStringNotContainsString('alert', $html);
        self::assertStringNotContainsString('color', $html);
        self::assertStringContainsString("1\t2", $html);

        self::assertNull($x->extractFile($this->dir.'/a.txt', 'dwg'));
        self::assertFalse($x->supports('doc'));
        self::assertTrue($x->supports('DOCX'));
    }

    public function testOfficeFormats(): void
    {
        $x = $this->extractor();
        $docx = $this->zip('doc.docx', [
            '[Content_Types].xml' => '<?xml version="1.0"?><Types/>',
            'word/document.xml' => '<?xml version="1.0"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body><w:p><w:r><w:t>Первый</w:t></w:r><w:r><w:t xml:space="preserve"> абзац</w:t></w:r></w:p><w:p><w:r><w:t>Второй</w:t><w:tab/><w:t>с табуляцией</w:t></w:r></w:p><w:tbl><w:tr><w:tc><w:p><w:r><w:t>Я1</w:t></w:r></w:p></w:tc><w:tc><w:p><w:r><w:t>Я2</w:t></w:r></w:p></w:tc></w:tr></w:tbl></w:body></w:document>',
            'word/footer1.xml' => '<w:ftr xmlns:w="x"><w:p><w:r><w:t>Нижний колонтитул</w:t></w:r></w:p></w:ftr>',
        ]);
        $text = (string) $x->extractFile($docx, 'docx');
        self::assertStringContainsString("Первый абзац\nВторой\tс табуляцией", $text);
        self::assertStringContainsString("Я1\tЯ2", $text, 'строка таблицы — в одну строку через табуляцию');
        self::assertStringContainsString('Нижний колонтитул', $text);
        self::assertStringNotContainsString('<w:', $text);

        $xlsx = $this->zip('table.xlsx', [
            'xl/workbook.xml' => '<workbook xmlns:r="r"><sheets><sheet name="Смета" sheetId="1" r:id="rId1"/></sheets></workbook>',
            'xl/_rels/workbook.xml.rels' => '<Relationships><Relationship Id="rId1" Type="t" Target="worksheets/sheet1.xml"/></Relationships>',
            'xl/sharedStrings.xml' => '<sst><si><t>Наименование</t></si><si><r><t>Насос </t></r><r><t>НС-40</t></r></si></sst>',
            'xl/worksheets/sheet1.xml' => '<worksheet><sheetData><row r="1"><c r="A1" t="s"><v>0</v></c><c r="B1" t="inlineStr"><is><t>Цена</t></is></c></row><row r="2"><c r="A2" t="s"><v>1</v></c><c r="B2"><v>1250.5</v></c></row><row r="3"/></sheetData></worksheet>',
        ]);
        $text = (string) $x->extractFile($xlsx, 'xlsx');
        self::assertSame("[Смета]\nНаименование\tЦена\nНасос НС-40\t1250.5", $text);

        $pptx = $this->zip('slides.pptx', [
            'ppt/slides/slide2.xml' => '<p:sld xmlns:a="a"><a:p><a:r><a:t>Второй слайд</a:t></a:r></a:p></p:sld>',
            'ppt/slides/slide1.xml' => '<p:sld xmlns:a="a"><a:p><a:r><a:t>Заголовок</a:t></a:r></a:p><a:p><a:r><a:t>Пункт </a:t></a:r><a:r><a:t>один</a:t></a:r></a:p></p:sld>',
            'ppt/notesSlides/notesSlide1.xml' => '<p:notes xmlns:a="a"><a:p><a:r><a:t>Заметка докладчика</a:t></a:r></a:p></p:notes>',
        ]);
        $text = (string) $x->extractFile($pptx, 'pptx');
        self::assertStringStartsWith("[Слайд 1]\nЗаголовок\nПункт один", $text);
        self::assertStringContainsString("[Слайд 2]\nВторой слайд", $text);
        self::assertStringContainsString("[Заметки]\nЗаметка докладчика", $text);
        self::assertLessThan(mb_strpos($text, 'Второй слайд'), mb_strpos($text, 'Заголовок'), 'слайды идут по порядку номеров');

        $odt = $this->zip('text.odt', [
            'content.xml' => '<office:document-content xmlns:text="t"><office:body><office:text><text:h>Раздел</text:h><text:p>Строка<text:tab/>с<text:s text:c="2"/>пробелами</text:p></office:text></office:body></office:document-content>',
        ]);
        self::assertSame("Раздел\nСтрока\tс пробелами", $x->extractFile($odt, 'odt'));

        file_put_contents($this->dir.'/broken.docx', 'это не zip');
        $this->expectException(\RuntimeException::class);
        $x->extractFile($this->dir.'/broken.docx', 'docx');
    }

    public function testPdf(): void
    {
        $x = $this->extractor();
        if (!$x->hasPdftotext()) {
            self::markTestSkipped('pdftotext не установлен');
        }
        $text = 'Hello PDF world';
        $stream = "BT /F1 18 Tf 50 750 Td ({$text}) Tj ET";
        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>',
            '<< /Length '.\strlen($stream)." >>\nstream\n{$stream}\nendstream",
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
        ];
        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $i => $obj) {
            $offsets[] = \strlen($pdf);
            $pdf .= ($i + 1)." 0 obj\n{$obj}\nendobj\n";
        }
        $xref = \strlen($pdf);
        $pdf .= "xref\n0 ".(\count($objects) + 1)."\n0000000000 65535 f \n";
        foreach ($offsets as $o) {
            $pdf .= \sprintf("%010d 00000 n \n", $o);
        }
        $pdf .= "trailer\n<< /Size ".(\count($objects) + 1)." /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF";
        file_put_contents($this->dir.'/t.pdf', $pdf);
        self::assertSame($text, $x->extractFile($this->dir.'/t.pdf', 'pdf'));
        self::assertContains('pdf', $x->supportedExtensions());
    }
}
