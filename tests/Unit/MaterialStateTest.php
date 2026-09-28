<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entity\MaterialParameter;
use App\Entity\Section;
use App\Service\Material\MaterialState;
use App\Service\Material\MaterialSummary;
use App\Service\Xlsx\XlsxReader;
use App\Service\Xlsx\XlsxWriter;
use PHPUnit\Framework\TestCase;

/**
 * Формула «Состояния» из книги «Продуктовые материалы», проверка значений столбцов и чтение/запись .xlsx.
 */
final class MaterialStateTest extends TestCase
{
    public function testStateFormulaMatchesExcel(): void
    {
        // =ЕСЛИ(PM="";"";ЕСЛИ(PM="Не соответствует";"Не соответствует";ЕСЛИ(PM="Не выбрано";"Неизвестно";
        //  ЕСЛИ(PM="Отсутствует";"Отсутствует";ЕСЛИ(И(PM="Соответствует";Д="Соответствует");"Готово";
        //  ЕСЛИ(И(PM="Соответствует";Д="Не соответствует");"Допустимо";""))))))
        $cases = [
            ['', 'Соответствует', ''],
            ['Не соответствует', 'Соответствует', 'Не соответствует'],
            ['Не соответствует', '', 'Не соответствует'],
            ['Не выбрано', 'Соответствует', 'Неизвестно'],
            ['Отсутствует', 'Не выбрано', 'Отсутствует'],
            ['Соответствует', 'Соответствует', 'Готово'],
            ['Соответствует', 'Не соответствует', 'Допустимо'],
            ['Соответствует', 'Отсутствует', ''],
            ['Соответствует', 'Не выбрано', ''],
            ['Соответствует', '', ''],
            [' Соответствует ', 'Соответствует', 'Готово'],
        ];
        foreach ($cases as [$pm, $design, $expected]) {
            self::assertSame($expected, MaterialState::compute($pm, $design), \sprintf('PM «%s», дизайн «%s»', $pm, $design));
        }
        self::assertTrue(MaterialState::isProblem(MaterialState::MISMATCH));
        self::assertTrue(MaterialState::isProblem(MaterialState::ABSENT));
        self::assertFalse(MaterialState::isProblem(MaterialState::UNKNOWN));
        self::assertSame('ready', MaterialState::css(MaterialState::READY));
        self::assertSame('empty', MaterialState::css(''));
    }

    public function testParameterValuesAreNormalizedByKind(): void
    {
        $section = new Section();
        $number = new MaterialParameter($section, 'Кол-во страниц', MaterialParameter::KIND_NUMBER);
        self::assertSame('12.5', $number->normalize(' 12,5 '));
        self::assertSame('1500', $number->normalize('1 500'));
        self::assertSame('12,5', $number->display('12.5'));
        $this->assertRejected($number, 'двенадцать', 'нужно число');

        $date = new MaterialParameter($section, 'Дата обнов.', MaterialParameter::KIND_DATE);
        self::assertSame('2026-08-02', $date->normalize('02.08.2026'));
        self::assertSame('2026-08-02', $date->normalize('2.8.26'));
        self::assertSame('2026-08-02', $date->normalize('2026-08-02'));
        self::assertSame('02.08.2026', $date->display('2026-08-02'));
        $this->assertRejected($date, '31.02.2026', 'нужна дата');

        $list = new MaterialParameter($section, 'Требования PM', MaterialParameter::KIND_LIST, ['Не выбрано', 'Соответствует', 'Не соответствует', 'Отсутствует'], MaterialParameter::ROLE_PM);
        self::assertSame('Соответствует', $list->normalize('соответствует'));
        self::assertTrue($list->hasLockedOptions());
        $this->assertRejected($list, 'Почти', 'из списка');

        $link = new MaterialParameter($section, 'Ссылка', MaterialParameter::KIND_LINK);
        self::assertSame('https://www.example.ru/a', $link->normalize('www.example.ru/a'));
        self::assertSame('/documents/5', $link->normalize('/documents/5'));
        $this->assertRejected($link, 'не ссылка', 'http');

        $text = new MaterialParameter($section, 'Комментарий');
        self::assertSame("строка 1\nстрока 2", $text->normalize("строка 1\r\nстрока 2\u{0007}"));
        self::assertSame('', $text->normalize('   '));

        self::assertSame(MaterialParameter::ROLE_UPDATED, MaterialParameter::roleForName('Дата обновления'));
        self::assertSame(MaterialParameter::ROLE_DESIGN, MaterialParameter::roleForName('Дизайн-требования'));
    }

    public function testDonutSegmentsFollowSummary(): void
    {
        $donut = MaterialSummary::donut(['states' => ['Готово' => 3, 'Допустимо' => 1, 'Не соответствует' => 0, 'Отсутствует' => 0, 'Неизвестно' => 0]]);
        self::assertSame(4, $donut['total']);
        self::assertCount(2, $donut['segments']);
        self::assertSame(['Готово', 75.0], [$donut['segments'][0]['state'], $donut['segments'][0]['share']]);
        self::assertStringStartsWith('M ', $donut['segments'][0]['path']);

        $single = MaterialSummary::donut(['states' => ['Готово' => 2]]);
        self::assertCount(1, $single['segments']);
        self::assertSame(0, MaterialSummary::donut(['states' => []])['total']);
    }

    public function testXlsxRoundTripKeepsTextDatesListsAndCharts(): void
    {
        $writer = new XlsxWriter();
        $summary = $writer->addSheet('Сводная');
        $sheet = $writer->addSheet('Труба PE/Xa: фитинги');
        self::assertSame('Труба PE Xa фитинги', $sheet->getName());
        $sheet->set(1, 1, 'Материал', XlsxWriter::STYLE_HEADER)->set(1, 2, 'Дата обнов.', XlsxWriter::STYLE_HEADER)->set(1, 3, 'Требования PM', XlsxWriter::STYLE_HEADER);
        $sheet->set(2, 1, 'Каталог <фитингов> & «труб»')->set(2, 2, new \DateTimeImmutable('2026-08-14'), XlsxWriter::STYLE_DATE)->set(2, 3, 'Соответствует');
        $sheet->set(3, 1, 'Паспорт')->set(3, 3, 'Нет данных', XlsxWriter::STYLE_DEFAULT, 'IF(C2="","",C2)');
        $sheet->listValidation('C2:C50', ['Соответствует', 'Не соответствует']);
        $summary->set(1, 1, 'Готово')->set(1, 2, 1)->set(2, 1, 'Допустимо')->set(2, 2, 0);
        $summary->doughnut(4, 1, '', "'Сводная'!\$A\$1:\$A\$2", "'Сводная'!\$B\$1:\$B\$2", ['Готово', 'Допустимо'], [1, 0], ['B6D7A8', 'FFE599']);

        $path = tempnam(sys_get_temp_dir(), 'xlsx-test-');
        self::assertIsString($path);
        try {
            $writer->save($path);
            $zip = new \ZipArchive();
            self::assertTrue($zip->open($path));
            self::assertNotFalse($zip->locateName('xl/charts/chart1.xml'));
            self::assertStringContainsString('drawingml.chart+xml', (string) $zip->getFromName('[Content_Types].xml'));
            self::assertStringContainsString('<drawing r:id=', (string) $zip->getFromName('xl/worksheets/sheet1.xml'));
            $zip->close();

            $sheets = (new XlsxReader())->read($path);
            self::assertSame(['Сводная', 'Труба PE Xa фитинги'], array_column($sheets, 'name'));
            // Строки и столбцы нумеруются с 1, как в Excel.
            $rows = $sheets[1]['rows'];
            self::assertSame('Каталог <фитингов> & «труб»', $rows[2][1]);
            self::assertSame('2026-08-14', $rows[2][2], 'дата читается как ГГГГ-ММ-ДД');
            self::assertTrue($sheets[1]['dates'][2] ?? false);
            self::assertSame('Нет данных', $rows[3][3], 'для формулы берётся закэшированное значение');
            self::assertSame(['Соответствует', 'Не соответствует'], $sheets[1]['lists'][3]);
        } finally {
            @unlink($path);
        }
    }

    private function assertRejected(MaterialParameter $parameter, string $value, string $message): void
    {
        try {
            $parameter->normalize($value);
            self::fail(\sprintf('Значение «%s» должно отклоняться', $value));
        } catch (\DomainException $e) {
            self::assertStringContainsString($message, $e->getMessage());
        }
    }
}
