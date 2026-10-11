<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Document;
use App\Service\DocumentManager;
use App\Service\Pdf\Bundle\BundleJobStore;
use App\Service\Pdf\Bundle\SectionBundleBuilder;
use App\Service\Pdf\HtmlPdfWriter;
use App\Service\Pdf\PdfFactory;
use App\Service\Process\Shell;
use App\Tests\PortalTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * PDF-сборник раздела (1.7.0): права, выбор документов, сборка порциями, содержание с номерами страниц,
 * документы, которые в PDF не переводятся (только реквизиты), скачивание и удаление; отрисовка страниц портала.
 */
final class SectionBundleTest extends PortalTestCase
{
    /** @var list<string> задания, созданные тестом (удаляются в конце) */
    private array $createdJobs = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->em()->getConnection()->beginTransaction();
    }

    protected function tearDown(): void
    {
        $store = static::getContainer()->get(BundleJobStore::class);
        foreach ($this->createdJobs as $id) {
            $job = $store->load($id);
            if (null !== $job) {
                $store->delete($job);
            }
        }
        $connection = $this->em()->getConnection();
        while ($connection->isTransactionActive()) {
            $connection->rollBack();
        }
        $this->em()->clear();
        static::getContainer()->get('cache.app')->clear();
        parent::tearDown();
    }

    private static function requireTools(): void
    {
        if (!Shell::exists('pdfunite') || !Shell::exists('pdfinfo') || !Shell::exists('pdftotext')) {
            self::markTestSkipped('Нужен пакет poppler-utils (pdfunite, pdfinfo, pdftotext).');
        }
    }

    private static function pdfText(string $pdf): string
    {
        $path = tempnam(sys_get_temp_dir(), 'bundle');
        file_put_contents($path, $pdf);
        $text = (string) shell_exec('pdftotext -layout '.escapeshellarg($path).' - 2>/dev/null');
        unlink($path);

        return (string) preg_replace('/[ \t]+/u', ' ', $text);
    }

    /** Документ-файл, созданный тестом (демо-документы могут быть изменены другими тестами процесса). */
    private function newFile(string $section, string $title, string $code, string $name, string $bytes): Document
    {
        $path = sys_get_temp_dir().'/'.bin2hex(random_bytes(4)).'-'.$name;
        file_put_contents($path, $bytes);
        $document = (new Document($this->section($section)))->setTitle($title)->setCode($code)->setType(Document::TYPE_FILE);
        static::getContainer()->get(DocumentManager::class)->create($document, new UploadedFile($path, $name, null, null, true), null, null, $this->user('admin'), true);

        return $document;
    }

    private function newPage(string $section, string $title, string $code, string $html): Document
    {
        $document = (new Document($this->section($section)))->setTitle($title)->setCode($code)->setType(Document::TYPE_PAGE);
        static::getContainer()->get(DocumentManager::class)->create($document, null, $html, null, $this->user('admin'), true);

        return $document;
    }

    private function samplePdf(string $text): string
    {
        $pdf = static::getContainer()->get(PdfFactory::class)->create();
        $pdf->addPage();
        $pdf->write($text, 12);

        return $pdf->output();
    }

    private static function samplePng(): string
    {
        $image = imagecreatetruecolor(320, 200);
        imagefill($image, 0, 0, (int) imagecolorallocate($image, 40, 110, 190));
        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }

    private static function sampleZip(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'zip');
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::OVERWRITE);
        $zip->addFromString('readme.txt', 'Схема');
        $zip->close();
        $bytes = (string) file_get_contents($path);
        unlink($path);

        return $bytes;
    }

    /** @return list<Document> PDF, страница, картинка и архив — по одному в разделах «Общих документов» */
    private function sampleDocuments(): array
    {
        return [
            $this->newFile('Регламенты и политики', 'Тестовое положение о сборниках', 'ТСТ-001', 'polozhenie.pdf', $this->samplePdf('Polozhenie o sbornikah: tekst PDF')),
            $this->newPage('Информационная безопасность', 'Тестовая политика паролей', 'ТСТ-002', '<h2>Требования к паролям сборника</h2><p>Пароль меняется раз в полгода.</p>'),
            $this->newFile('Рабочее место', 'Тестовая схема кабинета', 'ТСТ-003', 'scheme.png', self::samplePng()),
            $this->newFile('Охрана труда', 'Архив схем эвакуации', 'ОТ-900', 'scheme.zip', self::sampleZip()),
        ];
    }

    /** Запускает сборку через страницу задания до конца; возвращает последнее состояние. */
    private function runJob(string $jobUrl, string $token): array
    {
        $state = [];
        for ($i = 0; $i < 40; ++$i) {
            $this->client->request('POST', $jobUrl.'/run', ['_token' => $token]);
            self::assertResponseIsSuccessful();
            $state = json_decode((string) $this->client->getResponse()->getContent(), true);
            if ($state['finished']) {
                return $state;
            }
        }
        self::fail('Сборка не закончилась за 40 шагов.');
    }

    public function testOnlySectionManagersBuildBundles(): void
    {
        $id = $this->section('Общие документы')->getId();
        $this->loginAs('smirnov');
        $this->client->request('GET', '/sections/'.$id.'/bundle');
        self::assertResponseStatusCodeSame(403);

        $this->loginAs('ivanov');
        $crawler = $this->client->request('GET', '/sections/'.$id.'/bundle');
        self::assertResponseIsSuccessful();
        self::assertGreaterThan(5, $crawler->filter('input[name="documents[]"]')->count());
        self::assertSelectorExists('input[name="title_pages"][checked]');

        $this->client->request('GET', '/sections/'.$id);
        self::assertSelectorExists('a[href="/sections/'.$id.'/bundle"]');

        // Пустой выбор — ошибка формы, задание не создаётся.
        $token = $crawler->filter('form[data-bundle-form] input[name="_token"]')->attr('value');
        $this->client->request('POST', '/sections/'.$id.'/bundle', ['_token' => $token, 'title_pages' => '1']);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.alert--danger', 'Отметьте хотя бы один документ');
    }

    public function testBundleIsBuiltWithContentsAndNotes(): void
    {
        self::requireTools();
        $picked = $this->sampleDocuments();
        $section = $this->section('Общие документы');
        $sectionId = (int) $section->getId();

        $this->loginAs('ivanov');
        $crawler = $this->client->request('GET', '/sections/'.$sectionId.'/bundle');
        $token = $crawler->filter('form[data-bundle-form] input[name="_token"]')->attr('value');
        $this->client->request('POST', '/sections/'.$sectionId.'/bundle', [
            '_token' => $token,
            'title_pages' => '1',
            'documents' => array_map(static fn (Document $d): int => (int) $d->getId(), $picked),
        ]);
        self::assertResponseRedirects();
        $jobUrl = (string) $this->client->getResponse()->headers->get('Location');
        self::assertMatchesRegularExpression('~^/sections/'.$sectionId.'/bundle/\d{8}-\d{6}-[0-9a-f]{8}$~', $jobUrl);
        $this->createdJobs[] = basename($jobUrl);

        $this->client->request('GET', $jobUrl);
        self::assertResponseIsSuccessful();
        self::assertSelectorCount(4, '[data-job-items] tr');

        // Чужое задание не открывается: модератору другого раздела — 403 (нет прав на раздел).
        $this->loginAs('petrova');
        $this->client->request('GET', $jobUrl);
        self::assertResponseStatusCodeSame(403);
        $this->loginAs('ivanov');

        $state = $this->runJob($jobUrl, $token);
        self::assertNull($state['error']);
        self::assertSame(['ok' => 3, 'note' => 1, 'pending' => 0], $state['counters']);
        // Порядок — по дереву разделов: регламенты, ИБ, ИТ, охрана труда.
        self::assertSame(['ok', 'ok', 'ok', 'note'], array_column($state['items'], 'status'));
        self::assertStringContainsString('ZIP', (string) $state['items'][3]['note']);
        self::assertNotNull($state['download']);

        $this->client->request('GET', $state['download']['url']);
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/pdf');
        self::assertStringContainsString('attachment;', (string) $this->client->getResponse()->headers->get('Content-Disposition'));
        $pdf = (string) file_get_contents($this->client->getResponse()->getFile()->getPathname());
        self::assertStringStartsWith('%PDF-', $pdf);
        $text = self::pdfText($pdf);
        self::assertStringContainsString('Сборник документов', $text);
        self::assertStringContainsString('Тестовое положение о сборниках', $text);
        self::assertStringContainsString('Polozhenie o sbornikah: tekst PDF', $text, 'PDF включён как есть');
        self::assertStringContainsString('Требования к паролям сборника', $text, 'страница портала отрисована');
        self::assertStringContainsString('(только реквизиты)', $text);
        self::assertStringContainsString('Содержимое не включено в сборник', $text);

        // Номера страниц в содержании совпадают с фактическими: титульная страница документа N — на указанной странице.
        $pages = (int) $state['download']['pages'];
        self::assertGreaterThanOrEqual(1 + 4 + 3, $pages, 'обложка, 4 титульные страницы и содержимое трёх документов');
        self::assertSame(1, preg_match('/4\. Архив схем эвакуации — ОТ-900 \(только реквизиты\) \.+ (\d+)/u', $text, $m), $text);
        $path = tempnam(sys_get_temp_dir(), 'bundle');
        file_put_contents($path, $pdf);
        $page = (string) shell_exec('pdftotext -f '.(int) $m[1].' -l '.(int) $m[1].' '.escapeshellarg($path).' - 2>/dev/null');
        unlink($path);
        self::assertStringContainsString('Архив схем эвакуации', $page);
        self::assertStringContainsString('документ 4 из 4', $page);
        self::assertSame($pages, (int) $m[1], 'документ без содержимого — одна последняя страница');

        // Администратор открывает чужое задание; автор удаляет сборник.
        $this->loginAs('admin');
        $this->client->request('GET', $jobUrl);
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-job-download]:not([hidden])');
        $this->loginAs('ivanov');
        $this->client->request('POST', $jobUrl.'/delete', ['_token' => $token]);
        self::assertResponseRedirects('/sections/'.$sectionId.'/bundle');
        $this->client->request('GET', $jobUrl);
        self::assertResponseStatusCodeSame(404);
    }

    public function testBundleWithoutTitlePagesStartsWithHeadings(): void
    {
        self::requireTools();
        $builder = static::getContainer()->get(SectionBundleBuilder::class);
        $store = static::getContainer()->get(BundleJobStore::class);
        $section = $this->section('Информационная безопасность');
        $documents = [
            $this->newPage('Информационная безопасность', 'Тестовая политика паролей', 'ТСТ-011', '<p>Пароль меняется раз в полгода.</p>'),
            $this->newPage('Информационная безопасность', 'Тестовые правила доступа', 'ТСТ-012', '<p>Доступ выдаётся по заявке.</p>'),
        ];
        $job = $store->create($section, $this->user('petrova'), $builder->items($documents), false);
        $this->createdJobs[] = $job->id;
        $guard = 0;
        while (!$job->isFinished() && $guard++ < 10) {
            self::assertTrue($store->runBatch($job));
        }
        self::assertNull($job->error);
        self::assertTrue($job->isReady());
        $pdf = (string) file_get_contents((string) $store->result($job));
        $text = self::pdfText($pdf);
        self::assertStringContainsString('Тестовая политика паролей', $text);
        self::assertStringContainsString('ТСТ-011 · версия', $text, 'строка реквизитов под заголовком документа');
        self::assertStringNotContainsString('документ 1 из 2', $text, 'без отдельных титульных страниц');
        // Обложка + по странице на документ.
        self::assertSame(3, $job->pages);
    }

    public function testLongTitlePagesKeepTableOfContentsExact(): void
    {
        self::requireTools();
        $builder = static::getContainer()->get(SectionBundleBuilder::class);
        $store = static::getContainer()->get(BundleJobStore::class);
        $long = $this->newFile('Регламенты и политики', 'Очень длинное название '.str_repeat('положения о порядке согласования ', 7), 'ДЛН-'.str_repeat('7', 40), 'dlinnoe.pdf', $this->samplePdf('Dlinnyi dokument'));
        $long->setDescription(str_repeat('Подробное описание документа для титульной страницы. ', 14));
        $this->em()->flush();
        $second = $this->newFile('Регламенты и политики', 'Второй документ сборника', 'ДЛН-2', 'vtoroi.pdf', $this->samplePdf('Vtoroi dokument'));
        $job = $store->create($this->section('Регламенты и политики'), $this->user('ivanov'), $builder->items([$long, $second]), true);
        $this->createdJobs[] = $job->id;
        for ($i = 0; $i < 10 && !$job->isFinished(); ++$i) {
            $store->runBatch($job);
        }
        self::assertTrue($job->isReady(), (string) $job->error);
        $path = (string) $store->result($job);
        $toc = (string) shell_exec('pdftotext -layout -f 1 -l 1 '.escapeshellarg($path).' -');
        self::assertSame(1, preg_match('/2\. Второй документ сборника — ДЛН-2 \.+\s+(\d+)/u', (string) preg_replace('/[ \t]+/u', ' ', $toc), $m), $toc);
        $page = (string) shell_exec('pdftotext -f '.(int) $m[1].' -l '.(int) $m[1].' '.escapeshellarg($path).' -');
        self::assertStringContainsString('документ 2 из 2', $page, 'содержание указывает на титульную страницу второго документа');
        $front = (int) $m[1] - 1 - $job->items[0]['pages'];
        self::assertGreaterThanOrEqual(1, $front);
        self::assertSame($job->pages, $front + $job->items[0]['pages'] + $job->items[1]['pages'], 'страницы частей сходятся с итоговым файлом');
    }

    public function testInterruptedItemIsNotRetriedForever(): void
    {
        self::requireTools();
        $builder = static::getContainer()->get(SectionBundleBuilder::class);
        $store = static::getContainer()->get(BundleJobStore::class);
        $documents = [$this->newPage('Информационная безопасность', 'Тестовая страница А', 'ТСТ-021', '<p>А</p>'), $this->newPage('Информационная безопасность', 'Тестовая страница Б', 'ТСТ-022', '<p>Б</p>')];
        $job = $store->create($this->section('Информационная безопасность'), $this->user('admin'), $builder->items($documents), false);
        $this->createdJobs[] = $job->id;
        // Два прежних запроса прервались на первом документе (время, память) — третий его не повторяет.
        $job->attempts = 2;
        $store->save($job);
        $store->runBatch($job);
        self::assertSame('note', $job->items[0]['status']);
        self::assertStringContainsString('прерывалась', (string) $job->items[0]['note']);
        self::assertSame('ok', $job->items[1]['status']);
    }

    public function testCancelAndActiveLimit(): void
    {
        $builder = static::getContainer()->get(SectionBundleBuilder::class);
        $store = static::getContainer()->get(BundleJobStore::class);
        $section = $this->section('Общие документы');
        $documents = [$this->newPage('Информационная безопасность', 'Тестовая страница В', 'ТСТ-031', '<p>В</p>')];
        $first = $store->create($section, $this->user('ivanov'), $builder->items($documents), true);
        $second = $store->create($section, $this->user('ivanov'), $builder->items($documents), true);
        $this->createdJobs[] = $first->id;
        $this->createdJobs[] = $second->id;

        // Третий одновременный сборник не запускается.
        $this->loginAs('ivanov');
        $crawler = $this->client->request('GET', '/sections/'.$section->getId().'/bundle');
        $token = $crawler->filter('form[data-bundle-form] input[name="_token"]')->attr('value');
        $this->client->request('POST', '/sections/'.$section->getId().'/bundle', ['_token' => $token, 'documents' => [(int) $documents[0]->getId()]]);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.alert--danger', 'уже собираются');

        // Отмена выполняющегося задания: оно скрывается сразу, а файлы удаляет выполняющий запрос.
        $dir = $store->workDir($first->id);
        $lockPath = \dirname($dir).'/'.$first->id.'.json.lock';
        $lock = fopen($lockPath, 'c');
        flock($lock, \LOCK_EX);
        $this->client->request('POST', '/sections/'.$section->getId().'/bundle/'.$first->id.'/delete', ['_token' => $token]);
        self::assertResponseRedirects();
        self::assertNull($store->load($first->id));
        self::assertDirectoryExists($dir);
        flock($lock, \LOCK_UN);
        fclose($lock);
        self::assertTrue($store->runBatch($first));
        self::assertDirectoryDoesNotExist($dir);
        self::assertFileDoesNotExist(\dirname($dir).'/'.$first->id.'.json');
    }

    public function testPageHtmlIsRenderedReadably(): void
    {
        self::requireTools();
        $pdf = static::getContainer()->get(PdfFactory::class)->create();
        $pdf->addPage();
        $image = imagecreatetruecolor(60, 30);
        ob_start();
        imagepng($image);
        $png = base64_encode((string) ob_get_clean());
        (new HtmlPdfWriter($pdf, 'https://portal.example.ru'))->write(
            '<h2>Порядок действий</h2><p>Смотрите <a href="/documents/7">инструкцию</a> и <a href="https://example.ru/x">сайт</a>.</p>'
            .'<ol><li data-list="ordered">Первый шаг</li><li data-list="ordered">Второй шаг</li><li data-list="bullet" class="ql-indent-1">подробность</li><li data-list="ordered">Третий шаг</li></ol>'
            .'<table><tr><th>Служба</th><th>Телефон</th></tr><tr><td>Охрана</td><td>1111</td></tr></table>'
            .'<p><img src="data:image/png;base64,'.$png.'" alt="схема"></p><p><img src="https://example.ru/a.png" alt="внешняя"></p><pre>  отступ сохраняется</pre>'
        );
        $text = self::pdfText($pdf->output());
        self::assertStringContainsString('Порядок действий', $text);
        self::assertStringContainsString('инструкцию (https://portal.example.ru/documents/7)', $text);
        self::assertStringContainsString('сайт (https://example.ru/x)', $text);
        self::assertMatchesRegularExpression('/1\. Первый шаг\s+2\. Второй шаг\s+[•◦] подробность\s+3\. Третий шаг/u', $text);
        self::assertMatchesRegularExpression('/Служба\s+Телефон\s+Охрана\s+1111/u', $text);
        self::assertStringContainsString('[Изображение: внешняя — смотрите в портале]', $text);
        self::assertStringContainsString('отступ сохраняется', $text);
    }
}
