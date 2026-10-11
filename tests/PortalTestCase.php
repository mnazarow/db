<?php

declare(strict_types=1);

namespace App\Tests;

use App\Entity\Document;
use App\Entity\Section;
use App\Entity\User;
use App\Repository\DocumentRepository;
use App\Repository\SectionRepository;
use App\Repository\UserRepository;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Общая база функциональных тестов: пересоздаёт схему тестовой БД и загружает демо-данные один раз на класс.
 */
abstract class PortalTestCase extends WebTestCase
{
    protected static bool $schemaReady = false;
    protected KernelBrowser $client;

    /**
     * Тесты, которые проверяют возможности MySQL/MariaDB (на них работает портал и CI). При локальном запуске
     * на SQLite (DATABASE_URL=sqlite://…) они пропускаются с причиной — а не падают, скрывая настоящие ошибки.
     */
    private const MYSQL_ONLY = [
        'AccessAuditTest::testAuditLogRecordsSecurityEvents' => 'поиск по журналу без учёта регистра кириллицы',
        'AccessTest::testGuestSeesOpenDocumentsOnly' => 'поиск без учёта регистра кириллицы',
        'AccessTest::testAdminHasFullAccess' => 'функции дат в статистике',
        'AcknowledgementDepartmentsTest::testMatrixCountsAndMergesDepartments' => 'сравнение utf8mb4_bin',
        'AcknowledgementDepartmentsTest::testSectionFilterPageAndCsv' => 'сравнение utf8mb4_bin',
        'AcknowledgementTest::testPendingListsFollowDocumentAccess' => 'LIKE с экранированием по подразделениям',
        'ApiTest::testSectionsAndDocumentsVisibility' => 'поиск по тегам без учёта регистра кириллицы',
        'ApprovalQuizTest::testQuestionsPageIsForModeratorsOnly' => 'каскадное удаление по внешним ключам',
        'CoreFixesTest::testSearchFindsCyrillicTags' => 'поиск без учёта регистра кириллицы',
        'DirectoryImportTest::testNonUtf8NamesFromWindowsArchivesAreImported' => 'поиск разделов без учёта регистра кириллицы',
        'DirectoryImportTest::testImportCreatesTreeAndIsIdempotent' => 'поиск разделов без учёта регистра кириллицы',
        'DirectoryImportTest::testImportWithoutRootSectionIntoTopLevelAndDeleteSource' => 'поиск разделов без учёта регистра кириллицы',
        'DirectoryImportTest::testMovedFileIsReturnedAfterDatabaseError' => 'триггер с SIGNAL',
        'DirectoryImportTest::testPreviewForTopLevelImportRecognisesExistingSections' => 'поиск разделов без учёта регистра кириллицы',
        'DirectoryImportTest::testConsoleCommandDryRunAndRun' => 'поиск разделов без учёта регистра кириллицы',
        'DirectoryImportTest::testAdminPagesAndBatchJob' => 'поиск разделов без учёта регистра кириллицы',
        'DiscussionTest::testTelegramLinkingByCode' => 'блокировка GET_LOCK',
        'LinksTemplatesBulkTest::testLinksAreVisibleFromBothSides' => 'поиск документа по названию без учёта регистра кириллицы',
        'LinksTemplatesBulkTest::testBulkActionsRespectRights' => 'каскадное удаление по внешним ключам',
        'LinksTemplatesBulkTest::testBulkFromRegistryPage' => 'поиск без учёта регистра кириллицы',
        'MaterialsHistoryRemindTest::testRowHistoryRecordsEveryChange' => 'ON DELETE SET NULL по внешним ключам',
        'MonitoringTest::testJobsRecordedByCommandsAndScripts' => 'блокировка GET_LOCK',
        'PreviewOcrTest::testOcrRecognizesScans' => 'поиск по содержимому без учёта регистра кириллицы',
        'SearchContentTest::testContentIsIndexedAndFound' => 'поиск по содержимому',
        'SearchContentTest::testPhrasesInQuotesAreMatchedExactly' => 'поиск по содержимому',
        'SearchContentTest::testIndexFollowsVersionsStatusAndDeletion' => 'поиск по содержимому',
        'SearchContentTest::testInternalDocumentIsNotFoundByGuest' => 'поиск по содержимому',
        'SearchContentTest::testReindexCommandAndStatistics' => 'поиск по содержимому',
        'SearchContentTest::testWebReindexAllMarksIndexOutdatedAndRebuildsFirstPortion' => 'поиск по содержимому',
    ];

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $key = substr((string) strrchr(static::class, '\\'), 1).'::'.$this->name();
        if (isset(self::MYSQL_ONLY[$key]) && $this->em()->getConnection()->getDatabasePlatform() instanceof SQLitePlatform) {
            self::markTestSkipped('Проверяется только на MySQL/MariaDB (на SQLite нет: '.self::MYSQL_ONLY[$key].').');
        }
        if (!static::$schemaReady) {
            $this->resetDatabase();
            static::$schemaReady = true;
        }
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        // FrameworkBundle регистрирует обработчик исключений при каждой загрузке ядра; PHPUnit 11 считает
        // «лишний» обработчик признаком рискованного теста — снимаем его вручную.
        while (true) {
            $handler = set_exception_handler(static fn () => null);
            restore_exception_handler();
            if (\is_array($handler) && $handler[0] instanceof \Symfony\Component\ErrorHandler\ErrorHandler) {
                restore_exception_handler();
                continue;
            }
            break;
        }
    }

    protected function resetDatabase(): void
    {
        $em = $this->em();
        $tool = new SchemaTool($em);
        $metadata = $em->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);
        $storage = static::getContainer()->get('App\Service\FileStorage')->getStorageDir();
        if (is_dir($storage)) {
            foreach (scandir($storage) ?: [] as $entry) {
                if (ctype_digit($entry)) {
                    static::getContainer()->get('App\Service\FileStorage')->deleteDocumentDir((int) $entry);
                }
            }
        }
        $app = new Application(static::$kernel);
        $app->setAutoExit(false);
        $out = new BufferedOutput();
        $code = $app->run(new ArrayInput(['command' => 'app:demo:load', '--no-events' => true, '--force' => true]), $out);
        self::assertSame(0, $code, $out->fetch());
        $em->clear();
        // Настройки портала кэшируются (cache.app) — после пересоздания схемы кэш должен быть пустым.
        static::getContainer()->get('cache.app')->clear();
        // Счётчик неудачных входов живёт в отдельном пуле и переживает перезапуск тестов:
        // без очистки повторный прогон упирается в защиту от подбора пароля.
        if (static::getContainer()->has('app.login_limiter_pool')) {
            static::getContainer()->get('app.login_limiter_pool')->clear();
        }
    }

    protected function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    protected function user(string $username): User
    {
        $user = static::getContainer()->get(UserRepository::class)->findOneByUsername($username);
        self::assertNotNull($user, 'Пользователь не найден: '.$username);

        return $user;
    }

    protected function section(string $name): Section
    {
        $section = static::getContainer()->get(SectionRepository::class)->findOneBy(['name' => $name]);
        self::assertNotNull($section, 'Раздел не найден: '.$name);

        return $section;
    }

    protected function document(string $code): Document
    {
        $doc = static::getContainer()->get(DocumentRepository::class)->findOneBy(['code' => $code]);
        self::assertNotNull($doc, 'Документ не найден: '.$code);

        return $doc;
    }

    protected function loginAs(string $username): void
    {
        $this->client->loginUser($this->user($username));
    }
}
