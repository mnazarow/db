<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Document;
use App\Entity\Section;
use App\Repository\DocumentRepository;
use App\Repository\SectionRepository;
use App\Service\FileStorage;
use App\Service\Import\DirectoryImporter;
use App\Service\Import\DirectoryScanner;
use App\Service\Import\ImportJobStore;
use App\Service\Import\ImportOptions;
use App\Service\Import\ImportPlan;
use App\Tests\PortalTestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Импорт из каталога: папки → разделы, файлы → документы; повторный импорт, новые версии,
 * перенос с удалением исходников, ограничение глубины, консольная команда и страницы панели администратора.
 */
final class DirectoryImportTest extends PortalTestCase
{
    private string $importDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importDir = static::getContainer()->getParameter('app.import_dir');
        self::removeDir($this->importDir);
        mkdir($this->importDir, 0770, true);
    }

    /** Создаёт дерево папок и файлов; возвращает путь к корню. */
    private function makeTree(string $name = 'Архив ОТК'): string
    {
        $root = $this->importDir.'/'.$name;
        $files = [
            'README.txt' => 'Документы отдела технического контроля.',
            'Положение_об_ОТК.docx' => str_repeat('docx', 100),
            'Инструкции по контролю/описание.txt' => 'Инструкции по контролю качества.',
            'Инструкции по контролю/2024/ИК-01_Входной_контроль.pdf' => '%PDF-1.4 ik01',
            'Инструкции по контролю/2024/ИК-02 Сварка.pdf' => '%PDF-1.4 ik02',
            'Инструкции по контролю/2025/ИК-03.pdf' => '%PDF-1.4 ik03',
            'Протоколы/Протокол 10.pdf' => '%PDF-1.4 p10',
            'Протоколы/Протокол 2.pdf' => '%PDF-1.4 p2',
            'Протоколы/Протокол 1.pdf' => '%PDF-1.4 p1',
            'Протоколы/Thumbs.db' => 'x',
            'Протоколы/~$Протокол 1.pdf' => 'lock',
            'Черновики/пустой.pdf' => '',
            'Черновики/старый.exe' => 'MZ',
            '.svn/entries' => 'x',
        ];
        foreach ($files as $rel => $content) {
            $path = $root.'/'.$rel;
            if (!is_dir(\dirname($path))) {
                mkdir(\dirname($path), 0770, true);
            }
            file_put_contents($path, $content);
        }

        return $root;
    }

    private function importer(): DirectoryImporter
    {
        return static::getContainer()->get(DirectoryImporter::class);
    }

    private function sections(): SectionRepository
    {
        return static::getContainer()->get(SectionRepository::class);
    }

    private function documents(): DocumentRepository
    {
        return static::getContainer()->get(DocumentRepository::class);
    }

    public function testScanBuildsPlanWithSkips(): void
    {
        $root = $this->makeTree();
        $options = new ImportOptions(rootAsSection: true);
        $plan = $this->importer()->scan($root, $options);
        $summary = $plan->summary();

        self::assertSame('Архив ОТК', $plan->label);
        self::assertSame(6, $summary['dirs'], 'корень, Инструкции, 2024, 2025, Протоколы, Черновики (без .svn)');
        self::assertSame(7, $summary['files']);
        self::assertSame(2, $summary['skipped'][ImportPlan::SKIP_DESC]);
        self::assertSame(3, $summary['skipped'][ImportPlan::SKIP_SYSTEM], 'Thumbs.db, ~$..., .svn');
        self::assertSame(1, $summary['skipped'][ImportPlan::SKIP_EMPTY]);
        self::assertSame(1, $summary['skipped'][ImportPlan::SKIP_EXT]);

        $names = array_map(static fn (array $e) => $e['name'], array_values(array_filter($plan->entries, static fn (array $e) => 'file' === $e['type'] && str_starts_with($e['path'], 'Протоколы/') && null === $e['skip'])));
        self::assertSame(['Протокол 1.pdf', 'Протокол 2.pdf', 'Протокол 10.pdf'], $names, 'естественный порядок имён');

        $rootEntry = $plan->entries[0];
        self::assertSame('dir', $rootEntry['type']);
        self::assertSame('', $rootEntry['path']);
        self::assertSame('README.txt', $rootEntry['descFile']);
        self::assertSame('Положение об ОТК', DirectoryScanner::documentTitle('Положение_об_ОТК.docx'));
    }

    public function testImportCreatesTreeAndIsIdempotent(): void
    {
        $root = $this->makeTree();
        $admin = $this->user('admin');
        $target = $this->section('Производство');
        $options = new ImportOptions(targetSectionId: $target->getId(), rootAsSection: true, publish: true, validityMonths: 6);

        $state = $this->importer()->run($this->importer()->scan($root, $options), $options, $admin);
        self::assertSame(6, $state->counters['sectionsNew']);
        self::assertSame(7, $state->counters['docsNew']);
        self::assertSame(0, $state->counters['errors']);
        self::assertTrue($state->finished);

        $this->em()->clear();
        $archive = $this->sections()->findChildByName($this->section('Производство'), 'Архив ОТК');
        self::assertInstanceOf(Section::class, $archive);
        self::assertSame(1, $archive->getDepth());
        self::assertSame('Документы отдела технического контроля.', $archive->getDescription(), 'README.txt стал описанием раздела');
        $instr = $this->sections()->findChildByName($archive, 'Инструкции по контролю');
        self::assertNotNull($instr);
        self::assertSame('Инструкции по контролю качества.', $instr->getDescription());
        $y2024 = $this->sections()->findChildByName($instr, '2024');
        self::assertNotNull($y2024);
        self::assertSame(3, $y2024->getDepth());
        self::assertSame('Производство / Архив ОТК / Инструкции по контролю / 2024', $y2024->getFullName());
        self::assertNull($this->sections()->findChildByName($archive, '.svn'));

        $doc = $this->documents()->findFileByName($y2024, 'ИК-01_Входной_контроль.pdf');
        self::assertInstanceOf(Document::class, $doc);
        self::assertSame('ИК-01 Входной контроль', $doc->getTitle());
        self::assertTrue($doc->isPublished());
        self::assertSame('ИК-01_Входной_контроль.pdf', $doc->getCurrentVersion()?->getOriginalName());
        self::assertSame('%PDF-1.4 ik01', file_get_contents((string) static::getContainer()->get(FileStorage::class)->absolutePath($doc->getCurrentVersion())));
        self::assertNotNull($doc->getValidUntil());
        self::assertSame((new \DateTimeImmutable('today +6 months'))->format('Y-m-d'), $doc->getValidUntil()->format('Y-m-d'));
        self::assertFileExists($root.'/Инструкции по контролю/2024/ИК-01_Входной_контроль.pdf', 'без deleteSource исходники остаются');

        // Повторный импорт: ничего нового, один изменившийся файл → новая версия.
        file_put_contents($root.'/Инструкции по контролю/2024/ИК-01_Входной_контроль.pdf', '%PDF-1.4 ik01 v2');
        $state2 = $this->importer()->run($this->importer()->scan($root, $options), $options, $admin);
        self::assertSame(0, $state2->counters['sectionsNew']);
        self::assertSame(6, $state2->counters['sectionsExisting']);
        self::assertSame(0, $state2->counters['docsNew']);
        self::assertSame(1, $state2->counters['versionsNew']);
        self::assertSame(6, $state2->counters['unchanged']);
        $this->em()->clear();
        $doc = $this->documents()->find($doc->getId());
        self::assertSame(2, $doc?->getCurrentVersion()?->getNumber());
        self::assertSame(2, $doc?->getVersionCount());

        // Без обновления изменившийся файл пропускается.
        file_put_contents($root.'/Инструкции по контролю/2024/ИК-01_Входной_контроль.pdf', '%PDF-1.4 ik01 v3');
        $noUpdate = new ImportOptions(targetSectionId: $target->getId(), rootAsSection: true, updateExisting: false);
        $plan = $this->importer()->preview($this->importer()->scan($root, $noUpdate), $noUpdate);
        $results = array_count_values(array_map(static fn (array $e) => $e['result'], $plan->entries));
        self::assertSame(1, $results[ImportPlan::RESULT_EXISTS]);
        self::assertSame(6, $results[ImportPlan::RESULT_UNCHANGED]);
        self::assertSame(6, $results[ImportPlan::RESULT_SECTION_EXISTS]);
        $state3 = $this->importer()->run($plan, $noUpdate, $admin);
        self::assertSame(0, $state3->counters['versionsNew']);
        $this->em()->clear();
        self::assertSame(2, $this->documents()->find($doc->getId())?->getVersionCount());
    }

    public function testImportWithoutRootSectionIntoTopLevelAndDeleteSource(): void
    {
        $root = $this->makeTree('Перенос');
        $admin = $this->user('admin');
        $before = $this->sections()->countAll();
        $options = new ImportOptions(targetSectionId: null, rootAsSection: false, publish: false, validityMonths: 0, deleteSource: true, removeRootIfEmpty: true);
        $plan = $this->importer()->scan($root, $options);
        $rootFiles = array_values(array_filter($plan->entries, static fn (array $e) => 'file' === $e['type'] && !str_contains($e['path'], '/')));
        self::assertCount(2, $rootFiles);
        foreach ($rootFiles as $e) {
            self::assertSame(ImportPlan::SKIP_NO_SECTION, $e['skip'], 'файлы в корне без целевого раздела пропускаются: '.$e['name']);
        }

        $state = $this->importer()->run($plan, $options, $admin);
        self::assertSame(5, $state->counters['sectionsNew'], 'Инструкции, 2024, 2025, Протоколы, Черновики');
        self::assertSame(6, $state->counters['docsNew']);
        $this->em()->clear();
        self::assertSame($before + 5, $this->sections()->countAll());
        $instr = $this->sections()->findChildByName(null, 'Инструкции по контролю');
        self::assertNotNull($instr);
        self::assertSame(0, $instr->getDepth(), 'папки первого уровня стали разделами верхнего уровня');
        $doc = $this->documents()->findFileByName($this->sections()->findChildByName($instr, '2025'), 'ИК-03.pdf');
        self::assertNotNull($doc);
        self::assertTrue($doc->isDraft());
        self::assertNull($doc->getValidUntil());

        self::assertFileDoesNotExist($root.'/Инструкции по контролю/2025/ИК-03.pdf', 'исходник перенесён');
        self::assertFileDoesNotExist($root.'/Инструкции по контролю/описание.txt', 'использованное описание удалено');
        self::assertDirectoryDoesNotExist($root.'/Инструкции по контролю', 'опустевшие папки удалены');
        self::assertFileExists($root.'/Черновики/старый.exe', 'пропущенные файлы остаются');
        self::assertFileExists($root.'/README.txt', 'файлы корня без раздела остаются');
        self::assertDirectoryExists($root);
    }

    public function testTooDeepFoldersAreFlattened(): void
    {
        $root = $this->importDir.'/Глубина';
        $deep = $root.'/'.implode('/', range(1, 12));
        mkdir($deep, 0770, true);
        file_put_contents($deep.'/глубокий.pdf', '%PDF-1.4 deep');
        $admin = $this->user('admin');
        $options = new ImportOptions(rootAsSection: true);
        $plan = $this->importer()->scan($root, $options);
        $skippedDirs = array_values(array_filter($plan->entries, static fn (array $e) => 'dir' === $e['type'] && ImportPlan::SKIP_DEPTH === $e['skip']));
        self::assertCount(3, $skippedDirs, 'Глубина=0, 1..9 → глубина 9, папки 10, 11, 12 не помещаются');
        $file = $plan->entries[array_key_last($plan->entries)];
        self::assertSame('file', $file['type']);
        self::assertSame(implode('/', range(1, 9)), $file['dir'], 'файл попадает в самый глубокий допустимый раздел');

        $state = $this->importer()->run($plan, $options, $admin);
        self::assertSame(10, $state->counters['sectionsNew']);
        self::assertSame(1, $state->counters['docsNew']);
        $this->em()->clear();
        $section = $this->sections()->find($state->sections[implode('/', range(1, 9))]);
        self::assertSame(Section::MAX_DEPTH - 1, $section?->getDepth());
        self::assertNotNull($this->documents()->findFileByName($section, 'глубокий.pdf'));
    }

    public function testConsoleCommandDryRunAndRun(): void
    {
        $root = $this->makeTree('Консоль');
        $app = new Application(static::$kernel);
        $app->setAutoExit(false);

        $out = new BufferedOutput();
        $code = $app->run(new ArrayInput(['command' => 'app:import:directory', 'dir' => $root, '--dry-run' => true, '--root-as-section' => true, '--section' => 'kadry', '--verbose' => 1]), $out);
        $text = $out->fetch();
        self::assertSame(0, $code, $text);
        self::assertStringContainsString('Режим проверки', $text);
        self::assertStringContainsString('новых разделов 6', $text);
        self::assertNull($this->sections()->findChildByName($this->section('Кадры'), 'Консоль'), 'dry-run ничего не создаёт');

        $out = new BufferedOutput();
        $code = $app->run(new ArrayInput(['command' => 'app:import:directory', 'dir' => $root, '--root-as-section' => true, '--section' => 'kadry', '--user' => 'admin', '--yes' => true, '--draft' => true, '--verbose' => 1]), $out);
        $text = $out->fetch();
        self::assertSame(0, $code, $text);
        self::assertStringContainsString('Импорт завершён', $text);
        $this->em()->clear();
        $created = $this->sections()->findChildByName($this->section('Кадры'), 'Консоль');
        self::assertNotNull($created);
        self::assertSame(7, $this->documents()->countInSection($created, true));

        $out = new BufferedOutput();
        $code = $app->run(new ArrayInput(['command' => 'app:import:directory', 'dir' => $root.'/нет-такой-папки', '--verbose' => 1]), $out);
        self::assertSame(1, $code);
        self::assertStringContainsString('не найден', $out->fetch());
    }

    public function testAdminPagesAndBatchJob(): void
    {
        $this->makeTree('Через панель');
        $this->loginAs('sidorov');
        $this->client->request('GET', '/admin/import');
        self::assertResponseStatusCodeSame(403, 'модератор не имеет доступа к импорту');

        $this->loginAs('admin');
        $crawler = $this->client->request('GET', '/admin/import');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Через панель', $crawler->filter('#import-folder')->text());
        $token = $crawler->filter('input[name=_token]')->attr('value');
        $target = $this->section('Общие документы');

        $crawler = $this->client->request('POST', '/admin/import/preview', ['_token' => $token, 'folder' => 'Через панель', 'section' => $target->getId(), 'root_as_section' => '1', 'status' => 'published', 'validity' => '3', 'update' => '1']);
        self::assertResponseIsSuccessful();
        $body = $crawler->text();
        self::assertStringContainsString('План импорта', $body);
        self::assertStringContainsString('новый раздел', $body);
        self::assertStringContainsString('Положение_об_ОТК.docx', $body);
        self::assertStringContainsString('файл описания раздела', $body);

        $this->client->request('POST', '/admin/import/preview', ['_token' => $token, 'folder' => '../etc', 'section' => 0]);
        self::assertResponseRedirects();
        self::assertStringStartsWith('/admin/import?', (string) $this->client->getResponse()->headers->get('Location'));
        $this->client->request('POST', '/admin/import/preview', ['_token' => 'bad', 'folder' => 'Через панель']);
        self::assertResponseStatusCodeSame(403);

        $this->client->request('POST', '/admin/import/start', ['_token' => $token, 'folder' => 'Через панель', 'section' => $target->getId(), 'root_as_section' => '1', 'status' => 'published', 'validity' => '3', 'update' => '1']);
        self::assertResponseRedirects();
        $location = (string) $this->client->getResponse()->headers->get('Location');
        self::assertMatchesRegularExpression('#^/admin/import/jobs/[0-9]{8}-[0-9]{6}-[0-9a-f]{6}$#', $location);
        $jobId = basename($location);

        $crawler = $this->client->request('GET', $location);
        self::assertResponseIsSuccessful();
        self::assertSame('0', $crawler->filter('[data-job-position]')->text());

        $finished = false;
        for ($i = 0; $i < 20 && !$finished; ++$i) {
            $this->client->request('POST', $location.'/run', ['_token' => $token]);
            self::assertResponseIsSuccessful();
            $data = json_decode((string) $this->client->getResponse()->getContent(), true);
            self::assertIsArray($data);
            self::assertNull($data['error']);
            $finished = (bool) $data['finished'];
        }
        self::assertTrue($finished, 'задание должно завершиться за несколько порций');
        self::assertSame(6, $data['counters']['sectionsNew']);
        self::assertSame(7, $data['counters']['docsNew']);
        self::assertSame(100, $data['percent']);
        self::assertSame('Общие документы / Через панель', $data['root_section']['name']);

        $job = static::getContainer()->get(ImportJobStore::class)->load($jobId);
        self::assertNotNull($job);
        self::assertTrue($job->isFinished());
        self::assertSame(7, $job->state->counters['docsNew']);

        $crawler = $this->client->request('GET', $location);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Завершён', $crawler->filter('[data-job-status]')->text());

        // Повторный запуск уже завершённого задания ничего не меняет.
        $this->client->request('POST', $location.'/run', ['_token' => $token]);
        self::assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertTrue($data['finished']);

        $this->client->request('GET', '/admin/import/jobs/00000000-000000-000000');
        self::assertResponseStatusCodeSame(404);

        $this->em()->clear();
        $created = $this->sections()->findChildByName($this->section('Общие документы'), 'Через панель');
        self::assertNotNull($created);
        self::assertSame(7, $this->documents()->countInSection($created, true));
    }

    private static function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $name) {
            if ('.' === $name || '..' === $name) {
                continue;
            }
            $path = $dir.'/'.$name;
            if (is_dir($path) && !is_link($path)) {
                self::removeDir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
