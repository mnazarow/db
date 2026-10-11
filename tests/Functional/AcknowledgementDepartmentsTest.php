<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Document;
use App\Entity\User;
use App\Service\AcknowledgementService;
use App\Service\DocumentManager;
use App\Tests\PortalTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Сводка ознакомлений по подразделениям (1.7.0): «подразделения × документы» в панели администратора,
 * объединение подразделений без учёта регистра и «ё», перекрытие прежних редакций, фильтр по разделу, CSV.
 */
final class AcknowledgementDepartmentsTest extends PortalTestCase
{
    /**
     * Демо-данные общие для всех тестов процесса: каждый тест идёт в транзакции, которая в конце откатывается
     * (кэш настроек портала сбрасывается вместе с ней).
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
        static::getContainer()->get('cache.app')->clear();
        parent::tearDown();
    }

    private function service(): AcknowledgementService
    {
        return static::getContainer()->get(AcknowledgementService::class);
    }

    private function newDocument(string $code, string $section = 'Общие документы'): Document
    {
        $path = sys_get_temp_dir().'/'.bin2hex(random_bytes(4)).'-doc.txt';
        file_put_contents($path, 'Текст '.$code);
        $document = (new Document($this->section($section)))->setTitle('Документ '.$code)->setCode($code)->setType(Document::TYPE_FILE)->setPublic(false);
        static::getContainer()->get(DocumentManager::class)->create($document, new UploadedFile($path, 'doc.txt', null, null, true), null, null, $this->user('admin'), true);

        return $document;
    }

    private function staff(string $username, string $department): User
    {
        $user = (new User())->setUsername($username)->setDisplayName('Сотрудник '.$username)->setEmail($username.'@example.ru')->setDepartment($department);
        $user->setPassword('x');
        $this->em()->persist($user);
        $this->em()->flush();

        return $user;
    }

    /** @return array<string, mixed> строка подразделения из сводки */
    private static function department(array $matrix, string $name): array
    {
        foreach ($matrix['departments'] as $department) {
            if ($department['name'] === $name) {
                return $department;
            }
        }
        self::fail('Подразделение не найдено в сводке: '.$name.' (есть: '.implode(', ', array_column($matrix['departments'], 'name')).')');
    }

    public function testMatrixCountsAndMergesDepartments(): void
    {
        $a = $this->staff('logist1', 'Логистика');
        $b = $this->staff('logist2', '  логистика ');
        $c = $this->staff('logist3', 'ЛОГИСТИКА');
        $d = $this->staff('nodept', '');
        $first = $this->newDocument('СВ-001');
        $second = $this->newDocument('СВ-002');
        $admin = $this->user('admin');
        $this->service()->assign($first, [$a, $b, $c, $d], new \DateTimeImmutable('-2 days'), $admin, false);
        $this->service()->assign($second, [$a, $b], new \DateTimeImmutable('+10 days'), $admin, false);
        $this->service()->confirm($first, $a, '127.0.0.1');
        $this->service()->confirm($first, $b, '127.0.0.1');
        $this->service()->confirm($second, $a, '127.0.0.1');

        $matrix = $this->service()->departmentMatrix();
        $logistics = self::department($matrix, 'Логистика');
        self::assertSame(['assigned' => 3, 'confirmed' => 2, 'overdue' => 1, 'pct' => 66], array_intersect_key($logistics['cells'][$first->getId()], array_flip(['assigned', 'confirmed', 'overdue', 'pct'])), 'три написания — одно подразделение');
        self::assertSame(['assigned' => 2, 'confirmed' => 1, 'overdue' => 0, 'pct' => 50], array_intersect_key($logistics['cells'][$second->getId()], array_flip(['assigned', 'confirmed', 'overdue', 'pct'])));
        self::assertSame(5, $logistics['assigned']);
        self::assertSame(3, $logistics['confirmed']);
        self::assertSame(60, $logistics['pct']);
        $none = self::department($matrix, AcknowledgementService::NO_DEPARTMENT);
        self::assertSame(1, $none['cells'][$first->getId()]['overdue']);
        self::assertSame(AcknowledgementService::NO_DEPARTMENT, end($matrix['departments'])['name'], '«Без подразделения» — в конце');
        $columns = array_map(static fn (array $c): int => (int) $c['document']->getId(), $matrix['documents']);
        self::assertContains($first->getId(), $columns);
        self::assertSame(4, array_values(array_filter($matrix['documents'], static fn (array $c): bool => $c['document']->getId() === $first->getId()))[0]['assigned']);

        // Новая редакция перекрывает прежнее назначение: в сводке — только последнее назначение сотрудника.
        $path = sys_get_temp_dir().'/'.bin2hex(random_bytes(4)).'-v2.txt';
        file_put_contents($path, 'Вторая редакция');
        static::getContainer()->get(DocumentManager::class)->addFileVersion($first, new UploadedFile($path, 'v2.txt', null, null, true), 'v2', $admin);
        $this->service()->assign($first, [$a], null, $admin, false);
        $logistics = self::department($this->service()->departmentMatrix(), 'Логистика');
        self::assertSame(3, $logistics['cells'][$first->getId()]['assigned'], 'одна запись на сотрудника');
        self::assertSame(1, $logistics['cells'][$first->getId()]['confirmed'], 'подтверждение прежней редакции у logist1 больше не считается');

        // Уволенные (неактивные) сотрудники не учитываются.
        $b->setActive(false);
        $this->em()->flush();
        $logistics = self::department($this->service()->departmentMatrix(), 'Логистика');
        self::assertSame(2, $logistics['cells'][$first->getId()]['assigned']);

        // Сортировка «сначала отстающие» и ограничение числа столбцов.
        $byPct = $this->service()->departmentMatrix(null, 1, 'pct');
        self::assertCount(1, $byPct['documents']);
        self::assertGreaterThanOrEqual(1, $byPct['hidden_documents']);
        $named = array_values(array_filter($byPct['departments'], static fn (array $d): bool => !$d['no_department']));
        for ($i = 1; $i < \count($named); ++$i) {
            self::assertLessThanOrEqual($named[$i]['pct'], $named[$i - 1]['pct']);
        }
    }

    public function testSectionFilterPageAndCsv(): void
    {
        $sales = $this->staff('seller', 'Продажи');
        $general = $this->newDocument('СВ-010');
        $hr = $this->newDocument('СВ-011', 'Кадры');
        $admin = $this->user('admin');
        $this->service()->assign($general, [$sales], null, $admin, false);
        $this->service()->assign($hr, [$sales], null, $admin, false);
        $this->service()->confirm($hr, $sales, '127.0.0.1');

        $hrSection = $this->section('Кадры');
        $ids = static::getContainer()->get(\App\Repository\SectionRepository::class)->findSubtreeIds($hrSection);
        $filtered = $this->service()->departmentMatrix($ids);
        $documents = array_map(static fn (array $c): int => (int) $c['document']->getId(), $filtered['all_documents']);
        self::assertContains($hr->getId(), $documents);
        self::assertNotContains($general->getId(), $documents, 'фильтр по разделу');

        $this->loginAs('ivanov');
        $this->client->request('GET', '/admin/acknowledgements?view=departments');
        self::assertResponseStatusCodeSame(403);

        $this->loginAs('admin');
        $crawler = $this->client->request('GET', '/admin/acknowledgements?view=departments');
        self::assertResponseIsSuccessful();
        self::assertSame('По подразделениям', trim($crawler->filter('.tabs .tab.is-active')->text()));
        self::assertGreaterThan(0, $crawler->filter('table.heatmap tbody tr')->count());
        self::assertStringContainsString('Продажи', $crawler->filter('table.heatmap')->text());
        self::assertGreaterThan(0, $crawler->filter('.heat--full')->count(), 'ячейка 100% окрашена');

        $crawler = $this->client->request('GET', '/admin/acknowledgements?view=departments&section='.$hrSection->getId().'&sort=pct');
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('СВ-010', $crawler->filter('table.heatmap thead')->text());
        self::assertStringContainsString('СВ-011', $crawler->filter('table.heatmap thead')->text());

        // По документам — прежний вид.
        $crawler = $this->client->request('GET', '/admin/acknowledgements');
        self::assertSame('По документам', trim($crawler->filter('.tabs .tab.is-active')->text()));

        $this->client->request('GET', '/admin/acknowledgements/departments.csv');
        // StreamedResponse: содержимое уже перехвачено BrowserKit при отправке.
        $csv = (string) $this->client->getInternalResponse()->getContent();
        self::assertResponseIsSuccessful();
        self::assertStringStartsWith('text/csv', (string) $this->client->getResponse()->headers->get('Content-Type'));
        self::assertStringStartsWith("\xEF\xBB\xBF", $csv);
        self::assertStringContainsString('Подразделение;Документ;Обозначение;Раздел;Назначено;Ознакомились;Просрочено', $csv);
        self::assertMatchesRegularExpression('/Продажи;"?Документ СВ-011"?;СВ-011;Кадры;1;1;0;100/u', $csv);
        self::assertMatchesRegularExpression('/Продажи;"?Документ СВ-010"?;СВ-010;"?Общие документы"?;1;0;0;0/u', $csv);
    }
}
