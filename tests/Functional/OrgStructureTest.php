<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\User;
use App\Service\AcknowledgementService;
use App\Service\Org\OrgImporter;
use App\Tests\PortalTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Оргструктура: загрузка подразделений, должностей и руководителей из выгрузки 1С:ЗУП (CSV/Excel), пробный запуск,
 * блокировка уволенных, руководитель в карточке пользователя и страница «Мои сотрудники».
 */
final class OrgStructureTest extends PortalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Данные загружаются один раз на класс — оргструктуру каждый тест начинает с чистого листа.
        $em = $this->em();
        foreach (static::getContainer()->get('App\Repository\UserRepository')->findAll() as $user) {
            if ('nobody' === $user->getUsername()) {
                $em->remove($user);
                continue;
            }
            $user->setManager(null)->setJobTitle(null)->setEmployeeNumber(null)->setActive(true);
        }
        $em->flush();
    }

    private function importer(): OrgImporter
    {
        return static::getContainer()->get(OrgImporter::class);
    }

    /** Выгрузка «как из 1С»: заголовок отчёта над таблицей, Windows-1251, разделитель «;». */
    private static function staffCsv(): string
    {
        $rows = [
            'Кадровые данные сотрудников;;;;;;',
            'Дата отчёта: 10.10.2026;;;;;;',
            'Сотрудник;Табельный номер;Подразделение;Должность;Руководитель;Логин;Дата увольнения',
            'Сидоров Алексей Николаевич;0001;Производство;Начальник производства;;sidorov;',
            'Иванов Игорь Петрович;0002;Служба качества;Инженер по качеству;Сидоров А.Н.;;',
            'Смирнов Дмитрий Олегович;0003;"Отдел продаж; опт";Менеджер;0002;smirnov;',
            'Кузнецова Елена Викторовна;0004;Отдел кадров;Специалист;ivanov@example.ru;kuznetsova;01.01.2020',
            'Неизвестный Сотрудник;0099;Склад;Кладовщик;sidorov;nobody;',
            'Петрова Мария Сергеевна;0005;ИТ-отдел;Программист;Нет Такого;petrova;',
        ];

        return (string) mb_convert_encoding(implode("\r\n", $rows)."\r\n", 'Windows-1251', 'UTF-8');
    }

    private function staffFile(): string
    {
        $path = sys_get_temp_dir().'/org-'.bin2hex(random_bytes(4)).'.csv';
        file_put_contents($path, self::staffCsv());

        return $path;
    }

    public function testParsingHelpers(): void
    {
        $rows = OrgImporter::csvRows(self::staffCsv());
        $table = OrgImporter::table($rows);
        self::assertNotNull($table);
        self::assertSame(['name' => 0, 'number' => 1, 'department' => 2, 'title' => 3, 'manager' => 4, 'login' => 5, 'dismissed' => 6], $table['columns']);
        self::assertCount(6, $table['rows']);
        self::assertSame('Отдел продаж; опт', $table['rows'][2]['values']['department'], 'кавычки и разделитель внутри значения');
        self::assertSame(4, $table['rows'][0]['line'], 'номер строки файла — для отчёта');

        self::assertSame('иванов и п', OrgImporter::initials('Иванов Игорь Петрович'));
        self::assertSame('иванов и п', OrgImporter::initials('Иванов И.П.'));
        self::assertSame('иванов и п', OrgImporter::initials('Иванов ИП'));
        self::assertNull(OrgImporter::initials('Иванов'));

        self::assertTrue(OrgImporter::isDismissed(['dismissed' => '01.01.2020']));
        self::assertTrue(OrgImporter::isDismissed(['dismissed' => 'Уволен']));
        self::assertTrue(OrgImporter::isDismissed(['dismissed' => 'да']));
        self::assertFalse(OrgImporter::isDismissed(['dismissed' => '31.12.2999']), 'увольнение в будущем — ещё работает');
        self::assertFalse(OrgImporter::isDismissed(['dismissed' => 'Работает']));
        self::assertFalse(OrgImporter::isDismissed([]));

        self::assertNull(OrgImporter::table([1 => ['Что-то', 'Ещё']]), 'без узнаваемых столбцов — нет таблицы');

        // Excel: таблица на первом видимом листе, строка заголовков — вторая.
        $writer = new \App\Service\Xlsx\XlsxWriter();
        $sheet = $writer->addSheet('Сотрудники');
        foreach ([['Списочный состав'], ['Логин', 'ФИО', 'Подразделение', 'Должность', 'Руководитель'], ['ivanov', 'Иванов Игорь Петрович', 'Служба качества', 'Инженер', 'sidorov']] as $r => $cells) {
            foreach ($cells as $c => $value) {
                $sheet->set($r + 1, $c + 1, $value);
            }
        }
        $xlsx = sys_get_temp_dir().'/org-'.bin2hex(random_bytes(4)).'.xlsx';
        $writer->save($xlsx);
        $table = $this->importer()->read($xlsx, 'staff.xlsx');
        unlink($xlsx);
        self::assertSame(['login' => 0, 'name' => 1, 'department' => 2, 'title' => 3, 'manager' => 4], $table['columns']);
        self::assertSame(['login' => 'ivanov', 'name' => 'Иванов Игорь Петрович', 'department' => 'Служба качества', 'title' => 'Инженер', 'manager' => 'sidorov'], $table['rows'][0]['values']);
        self::assertSame(3, $table['rows'][0]['line']);

        $this->expectException(\RuntimeException::class);
        $this->importer()->read(__FILE__, 'staff.docx');
    }

    public function testDryRunChangesNothingThenImportApplies(): void
    {
        $path = $this->staffFile();
        $table = $this->importer()->read($path, 'staff.csv');
        $admin = $this->user('admin');

        $report = $this->importer()->import($table, ['create' => false, 'deactivate' => true], $admin, true);
        self::assertTrue($report['dry_run']);
        self::assertSame(6, $report['rows']);
        self::assertSame(5, $report['matched']);
        self::assertSame(1, $report['deactivated']);
        self::assertGreaterThanOrEqual(3, $report['managers']);
        self::assertSame(1, $report['skipped'], 'неизвестный сотрудник без --create пропущен');
        $messages = implode("\n", array_column($report['problems'], 'message'));
        self::assertStringContainsString('Неизвестный Сотрудник', $messages);
        self::assertStringContainsString('Руководитель «Нет Такого»', $messages);

        $this->em()->clear();
        $ivanov = $this->user('ivanov');
        self::assertNull($ivanov->getManager(), 'пробный запуск ничего не сохранил');
        self::assertNull($ivanov->getJobTitle());
        self::assertTrue($this->user('kuznetsova')->isActive());

        $report = $this->importer()->import($this->importer()->read($path, 'staff.csv'), ['create' => true, 'deactivate' => true], $this->user('admin'), false);
        self::assertFalse($report['dry_run']);
        self::assertSame(1, $report['created']);
        $this->em()->clear();

        $ivanov = $this->user('ivanov');
        self::assertSame('Инженер по качеству', $ivanov->getJobTitle());
        self::assertSame('0002', $ivanov->getEmployeeNumber());
        self::assertSame('sidorov', $ivanov->getManager()?->getUsername(), 'руководитель по «Фамилия И.О.»');
        $smirnov = $this->user('smirnov');
        self::assertSame('ivanov', $smirnov->getManager()?->getUsername(), 'руководитель по табельному номеру');
        self::assertSame('Отдел продаж; опт', $smirnov->getDepartment());
        self::assertFalse($this->user('kuznetsova')->isActive(), 'уволенная заблокирована');
        self::assertSame('ivanov', $this->user('kuznetsova')->getManager()?->getUsername(), 'руководитель по почте');
        $nobody = $this->user('nobody');
        self::assertSame('Неизвестный Сотрудник', $nobody->getDisplayName());
        self::assertSame('sidorov', $nobody->getManager()?->getUsername());
        self::assertTrue($nobody->isMustChangePassword());
        self::assertNull($this->user('petrova')->getManager(), 'неизвестный руководитель не назначен');

        // Повторная загрузка того же файла ничего не меняет.
        $report = $this->importer()->import($this->importer()->read($path, 'staff.csv'), ['deactivate' => true], $this->user('admin'), true);
        self::assertSame(0, $report['updated']);
        self::assertSame(0, $report['managers']);
        unlink($path);

        // «По кругу» назначить нельзя.
        $this->expectException(\DomainException::class);
        $this->user('sidorov')->setManager($this->user('smirnov'));
    }

    public function testTeamPageShowsSubordinatesWithPendingDocuments(): void
    {
        $sidorov = $this->user('sidorov');
        $ivanov = $this->user('ivanov')->setManager($sidorov);
        $smirnov = $this->user('smirnov')->setManager($ivanov);
        $this->em()->flush();
        $document = $this->document('ПОЛ-001');
        static::getContainer()->get(AcknowledgementService::class)->assign($document, [$smirnov], new \DateTimeImmutable('-3 days'), $this->user('admin'), false);

        $this->loginAs('sidorov');
        $crawler = $this->client->request('GET', '/profile/team');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('footer', 'Мои сотрудники');
        $text = $crawler->filter('.team-table')->text();
        self::assertStringContainsString('Иванов Игорь Петрович', $text);
        self::assertStringContainsString('Смирнов Дмитрий Олегович', $text, 'сотрудники подчинённого тоже видны');
        self::assertStringContainsString($document->getTitle(), $text);
        self::assertStringContainsString('просрочено', $text);

        $this->client->request('GET', '/profile/team.csv');
        self::assertResponseIsSuccessful();
        $csv = (string) $this->client->getInternalResponse()->getContent();
        self::assertStringContainsString('smirnov', $csv);
        self::assertStringContainsString('Руководитель', $csv);

        // У обычного сотрудника подчинённых нет; чужих сотрудников смотреть нельзя.
        $this->loginAs('smirnov');
        $this->client->request('GET', '/profile/team');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.table-card__empty', 'нет подчинённых');
        self::assertSelectorTextNotContains('footer', 'Мои сотрудники');
        $this->client->request('GET', '/profile/team?manager=sidorov');
        self::assertResponseStatusCodeSame(403);

        // Администратор — может.
        $this->loginAs('admin');
        $this->client->request('GET', '/profile/team?manager=sidorov');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.team-table', 'Смирнов');
    }

    public function testAdminUploadPreviewAndApply(): void
    {
        $this->loginAs('admin');
        $crawler = $this->client->request('GET', '/admin/users/org-import');
        self::assertResponseIsSuccessful();
        $token = (string) $crawler->filter('input[name=_token]')->attr('value');

        $path = $this->staffFile();
        $this->client->request('POST', '/admin/users/org-import', ['_token' => $token, 'deactivate' => '1'], ['file' => new UploadedFile($path, 'Кадровые данные.csv', 'text/csv', null, true)]);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.card', 'Что изменится');
        self::assertSelectorTextContains('body', 'пробная загрузка');
        $fileToken = (string) $this->client->getCrawler()->filter('input[name=file_token]')->attr('value');
        self::assertMatchesRegularExpression('/^[a-f0-9]{24}\.csv$/', $fileToken);
        $this->em()->clear();
        self::assertNull($this->user('ivanov')->getJobTitle());

        $this->client->request('POST', '/admin/users/org-import/apply', ['_token' => $token, 'file_token' => $fileToken, 'deactivate' => '1', 'file_name' => 'Кадровые данные.csv']);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Оргструктура загружена');
        $this->em()->clear();
        self::assertSame('Инженер по качеству', $this->user('ivanov')->getJobTitle());
        self::assertFileDoesNotExist(static::getContainer()->getParameter('kernel.project_dir').'/var/org-import/'.$fileToken, 'файл после загрузки удалён');

        // Повторно тот же файл пробной загрузки применить нельзя; подменить путь — тоже.
        $this->client->request('POST', '/admin/users/org-import/apply', ['_token' => $token, 'file_token' => $fileToken]);
        self::assertResponseRedirects('/admin/users/org-import');
        $this->client->request('POST', '/admin/users/org-import/apply', ['_token' => $token, 'file_token' => '../../.env']);
        self::assertResponseRedirects('/admin/users/org-import');

        // Не тот формат.
        $bad = sys_get_temp_dir().'/org-'.bin2hex(random_bytes(4)).'.pdf';
        file_put_contents($bad, '%PDF');
        $this->client->request('POST', '/admin/users/org-import', ['_token' => $token], ['file' => new UploadedFile($bad, 'x.pdf', 'application/pdf', null, true)]);
        self::assertSelectorTextContains('body', 'Нужен файл CSV или Excel');
        self::assertResponseIsSuccessful();

        // Руководитель в карточке пользователя: по логину, с проверкой «по кругу».
        $petrova = $this->user('petrova');
        $crawler = $this->client->request('GET', '/admin/users/'.$petrova->getId().'/edit');
        $form = $crawler->filter('form[data-user-form]')->form();
        $form['user[managerLogin]'] = 'sidorov';
        $form['user[jobTitle]'] = 'Ведущий программист';
        $this->client->submit($form);
        self::assertResponseRedirects('/admin/users');
        $this->em()->clear();
        self::assertSame('sidorov', $this->user('petrova')->getManager()?->getUsername());
        self::assertSame('Ведущий программист', $this->user('petrova')->getJobTitle());

        $crawler = $this->client->request('GET', '/admin/users/'.$this->user('sidorov')->getId().'/edit');
        $form = $crawler->filter('form[data-user-form]')->form();
        $form['user[managerLogin]'] = 'petrova';
        $this->client->submit($form);
        self::assertSelectorTextContains('body', 'не может быть руководителем');
        $this->em()->clear();
        self::assertNull($this->user('sidorov')->getManager());

        $crawler = $this->client->request('GET', '/admin/users/new');
        $form = $crawler->filter('form[data-user-form]')->form();
        $form['user[username]'] = 'novikov';
        $form['user[displayName]'] = 'Новиков Пётр';
        $form['user[managerLogin]'] = 'nosuchuser';
        $this->client->submit($form);
        self::assertSelectorTextContains('body', 'Руководитель не найден');
        self::assertNull(static::getContainer()->get('App\Repository\UserRepository')->findOneByUsername('novikov'), 'пользователь не создан');
    }

    public function testConsoleCommand(): void
    {
        $path = $this->staffFile();
        $application = new \Symfony\Bundle\FrameworkBundle\Console\Application(static::$kernel);
        $tester = new \Symfony\Component\Console\Tester\CommandTester($application->find('app:org:import'));
        $code = $tester->execute(['file' => $path, '--dry-run' => true]);
        $output = $tester->getDisplay();
        self::assertSame(2, $code, 'есть строки с проблемами — код 2');
        self::assertStringContainsString('Пробный запуск', $output);
        self::assertStringContainsString('Неизвестный Сотрудник', $output);
        self::assertSame(1, $tester->execute(['file' => '/nonexistent.csv']));
        unlink($path);
        $this->em()->clear();
        self::assertNull($this->user('ivanov')->getJobTitle());
        self::assertInstanceOf(User::class, $this->user('ivanov'));
    }
}
