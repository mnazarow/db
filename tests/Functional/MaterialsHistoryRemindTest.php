<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\MaterialParameter;
use App\Entity\MaterialRow;
use App\Entity\MaterialRowChange;
use App\Repository\MaterialRowChangeRepository;
use App\Service\Material\MaterialImporter;
use App\Service\Material\MaterialReminder;
use App\Service\Material\MaterialService;
use App\Service\PortalSettings;
use App\Tests\PortalTestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Таблицы материалов (1.7.0): журнал изменений строк (правки, загрузка и отвязка файла, удаление, импорт
 * из Excel), диалог истории и журнал раздела, еженедельная сводка «Не соответствует / Отсутствует».
 */
final class MaterialsHistoryRemindTest extends PortalTestCase
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
        static::getContainer()->get(\App\Service\DocumentKindService::class)->reset();
        static::getContainer()->get(MaterialService::class)->forgetParameters();
        parent::tearDown();
    }

    private function materials(): MaterialService
    {
        return static::getContainer()->get(MaterialService::class);
    }

    private function changes(): MaterialRowChangeRepository
    {
        return static::getContainer()->get(MaterialRowChangeRepository::class);
    }

    private function row(string $section, string $title): MaterialRow
    {
        foreach ($this->materials()->rows($this->section($section)) as $row) {
            if ($row->getTitle() === $title) {
                return $row;
            }
        }
        self::fail(\sprintf('Строка «%s» не найдена в разделе «%s»', $title, $section));
    }

    private function tableToken(int $sectionId): string
    {
        $crawler = $this->client->request('GET', '/sections/'.$sectionId.'/materials');
        self::assertResponseIsSuccessful();

        return json_decode($crawler->filter('#mt-config')->text(), true, 512, \JSON_THROW_ON_ERROR)['token'];
    }

    /** @return array<string, mixed> */
    private function api(string $url, array $data, string $token, array $files = []): array
    {
        $this->client->request('POST', $url, $data, $files, ['HTTP_X_CSRF_TOKEN' => $token, 'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);

        return json_decode((string) $this->client->getResponse()->getContent(), true) ?? [];
    }

    /** @return list<array<string, mixed>> */
    private function history(int $sectionId, int $rowId): array
    {
        $this->client->request('GET', '/sections/'.$sectionId.'/materials/rows/'.$rowId.'/history');
        self::assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertTrue($data['ok']);

        return $data['changes'];
    }

    public function testRowHistoryRecordsEveryChange(): void
    {
        $pumps = $this->section('Насосные станции');
        $base = '/sections/'.$pumps->getId().'/materials';
        $this->loginAs('sidorov');
        $token = $this->tableToken($pumps->getId());
        $pm = $this->materials()->roleParameter($pumps, MaterialParameter::ROLE_PM);
        $updated = $this->materials()->roleParameter($pumps, MaterialParameter::ROLE_UPDATED);
        self::assertNotNull($pm);
        self::assertNotNull($updated);

        $created = $this->api($base.'/rows', ['title' => 'Буклет для дилеров'], $token);
        $rowId = (int) $created['row']['id'];
        $this->api($base.'/rows/'.$rowId, ['field' => $pm->key(), 'value' => 'Не соответствует'], $token);
        $this->api($base.'/rows/'.$rowId, ['field' => $updated->key(), 'value' => '05.03.2026'], $token);
        $this->api($base.'/rows/'.$rowId, ['field' => 'title', 'value' => 'Буклет для дилеров 2026'], $token);
        // Значение не изменилось — записи нет.
        $this->api($base.'/rows/'.$rowId, ['field' => 'title', 'value' => 'Буклет для дилеров 2026'], $token);
        // Ошибочное значение не сохраняется и в журнал не попадает.
        $this->api($base.'/rows/'.$rowId, ['field' => $pm->key(), 'value' => 'Хорошо'], $token);
        self::assertResponseStatusCodeSame(422);

        $path = sys_get_temp_dir().'/'.bin2hex(random_bytes(4)).'-buklet.pdf';
        file_put_contents($path, "%PDF-1.4\n% buklet\n%%EOF\n");
        $upload = $this->api($base.'/rows/'.$rowId.'/upload', [], $token, ['file' => new UploadedFile($path, 'buklet.pdf', 'application/pdf', null, true)]);
        self::assertTrue($upload['ok'], $upload['error'] ?? '');
        $this->api($base.'/rows/'.$rowId.'/detach', [], $token);

        $changes = $this->history($pumps->getId(), $rowId);
        $actions = array_column($changes, 'action');
        self::assertSame([MaterialRowChange::DETACH, MaterialRowChange::UPLOAD, MaterialRowChange::UPDATE, MaterialRowChange::UPDATE, MaterialRowChange::UPDATE, MaterialRowChange::CREATE], $actions, 'новые сверху');
        [$detach, $uploaded, $title, $date, $state, $create] = $changes;
        self::assertSame('Сидоров Алексей Николаевич', $create['user']);
        self::assertSame('Буклет для дилеров', $create['new']);
        self::assertSame([$pm->getName(), '', 'Не соответствует'], [$state['fieldLabel'], $state['old'], $state['new']]);
        self::assertSame('05.03.2026', $date['new'], 'дата — как в таблице');
        self::assertSame(['Материал', 'Буклет для дилеров', 'Буклет для дилеров 2026'], [$title['fieldLabel'], $title['old'], $title['new']]);
        self::assertStringContainsString('«buklet.pdf», версия 1', (string) $uploaded['new']);
        self::assertSame('Буклет для дилеров 2026', $detach['old']);
        self::assertMatchesRegularExpression('/^\d{2}\.\d{2}\.\d{4} \d{2}:\d{2}$/', $create['at']);

        // Удаление: запись остаётся в журнале раздела без ссылки на строку.
        $this->api($base.'/rows/'.$rowId.'/delete', [], $token);
        $this->em()->clear();
        $journal = $this->changes()->findForSection($this->section('Насосные станции'), 20);
        self::assertSame(MaterialRowChange::DELETE, $journal[0]->getAction());
        self::assertSame('Буклет для дилеров 2026', $journal[0]->getRowTitle());
        self::assertNull($journal[0]->getRow());
        self::assertSame(7, \count(array_filter($journal, static fn (MaterialRowChange $c): bool => 'Буклет для дилеров 2026' === $c->getRowTitle() || 'Буклет для дилеров' === $c->getRowTitle())), 'история удалённой строки сохраняется');
        self::assertNull($journal[1]->getRow(), 'ссылка на удалённую строку снята и у прежних записей');

        // Журнал раздела: страница, фильтр, CSV.
        $crawler = $this->client->request('GET', $base.'/history');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Строка удалена', $crawler->filter('table.mt-history')->text());
        self::assertStringContainsString('(строка удалена)', $crawler->filter('table.mt-history')->text());
        $crawler = $this->client->request('GET', $base.'/history?action=upload');
        $uploads = $crawler->filter('table.mt-history tbody tr');
        self::assertGreaterThanOrEqual(1, $uploads->count());
        self::assertStringContainsString('buklet.pdf', $uploads->first()->text());
        self::assertSame(0, $crawler->filter('table.mt-history tbody tr')->reduce(static fn ($tr): bool => str_contains($tr->text(), 'Строка удалена'))->count(), 'фильтр по действию');
        $crawler = $this->client->request('GET', $base.'/history?action=bogus');
        self::assertResponseIsSuccessful();
        $this->client->request('GET', $base.'/history.csv');
        self::assertResponseIsSuccessful();
        $csv = (string) $this->client->getInternalResponse()->getContent();
        self::assertStringStartsWith("\xEF\xBB\xBF".'Когда;Кто;Материал;Действие;Поле;Было;Стало', $csv);
        self::assertStringContainsString('Строка удалена', $csv);

        // Чужой раздел и сотрудник без прав — нет доступа.
        $this->loginAs('smirnov');
        $this->client->request('GET', $base.'/history');
        self::assertResponseStatusCodeSame(403);
        $this->client->request('GET', $base.'/rows/'.$this->materials()->rows($this->section('Насосные станции'))[0]->getId().'/history');
        self::assertResponseStatusCodeSame(403);
    }

    public function testImportIsRecordedPerChangedValue(): void
    {
        $valves = $this->section('Предохранительные клапаны');
        $row = $this->materials()->rows($valves)[0];
        $comment = $this->materials()->roleParameter($valves, MaterialParameter::ROLE_COMMENT);
        self::assertNotNull($comment);
        $parameters = $this->materials()->parameters($valves);
        $header = array_merge(['Материал', 'Тип'], array_map(static fn (MaterialParameter $p): string => $p->getName(), $parameters));
        $sheetRows = [1 => $header];
        $n = 1;
        foreach ($this->materials()->rows($valves) as $existing) {
            $cells = [$existing->getTitle(), (string) $existing->getKind()?->getName()];
            foreach ($parameters as $parameter) {
                $cells[] = $existing->getId() === $row->getId() && $parameter->getId() === $comment->getId() ? 'Обновить к сезону' : $parameter->display($existing->getValue($parameter));
            }
            $sheetRows[++$n] = $cells;
        }
        $sheetRows[++$n] = array_merge(['Новый каталог клапанов', ''], array_fill(0, \count($parameters), ''));
        $sheet = ['name' => $valves->getName(), 'hidden' => false, 'rows' => array_map(static fn (array $r): array => array_combine(range(0, \count($r) - 1), $r), $sheetRows), 'lists' => [], 'dates' => []];

        $report = static::getContainer()->get(MaterialImporter::class)->apply([$sheet], false, $this->user('admin'));
        self::assertSame(1, $report['totals']['created']);
        self::assertGreaterThanOrEqual(1, $report['totals']['updated']);

        $this->em()->clear();
        $imports = array_values(array_filter($this->changes()->findForSection($this->section('Предохранительные клапаны'), 50), static fn (MaterialRowChange $c): bool => MaterialRowChange::IMPORT === $c->getAction()));
        // Демо-данные тоже загружены импортом — считаем только записи этого импорта.
        $added = array_values(array_filter($imports, static fn (MaterialRowChange $c): bool => null === $c->getField() && 'Новый каталог клапанов' === $c->getRowTitle()));
        self::assertCount(1, $added);
        self::assertSame('Новый каталог клапанов', $added[0]->getNewValue());
        $commentChanges = array_values(array_filter($imports, static fn (MaterialRowChange $c): bool => $c->getField() === $comment->key() && 'Обновить к сезону' === $c->getNewValue()));
        self::assertCount(1, $commentChanges, 'по одной записи на изменённое значение');
        self::assertSame('Обновить к сезону', $commentChanges[0]->getNewValue());
        self::assertSame('Администратор портала', $commentChanges[0]->getUserName());
    }

    public function testWeeklyDigestSettingsAndSending(): void
    {
        $settings = static::getContainer()->get(PortalSettings::class);
        $settings->set(PortalSettings::MATERIALS_REMIND_ENABLED, false);
        $settings->set(PortalSettings::MATERIALS_REMIND_LAST_RUN, null);
        $reminder = static::getContainer()->get(MaterialReminder::class);

        // Выключено — ничего не отправляется (cron запускается, но молчит).
        $result = $reminder->run();
        self::assertSame('disabled', $result['status']);

        // Что попадёт в сводку: строки «Не соответствует»/«Отсутствует», получатели — модераторы раздела.
        $collected = $reminder->collect(['Не соответствует', 'Отсутствует']);
        self::assertNotEmpty($collected);
        foreach ($collected as $entry) {
            self::assertNotEmpty($entry['recipients']);
            foreach ($entry['rows'] as $item) {
                self::assertContains($item['state'], ['Не соответствует', 'Отсутствует']);
            }
        }
        $pumps = array_values(array_filter($collected, static fn (array $e): bool => 'Насосные станции' === $e['section']->getName()))[0] ?? null;
        self::assertNotNull($pumps);
        self::assertContains('sidorov', array_map(static fn ($u): string => $u->getUsername(), $pumps['recipients']));
        self::assertStringEndsWith('/sections/'.$pumps['section']->getId().'/materials', $pumps['url']);

        // Настройки в панели администратора.
        $this->loginAs('admin');
        $crawler = $this->client->request('GET', '/admin/materials');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Еженедельная сводка', $crawler->filter('#remind')->text());
        $token = $crawler->filter('#remind input[name=_token]')->first()->attr('value');
        $this->client->request('POST', '/admin/materials/remind', ['_token' => $token, 'enabled' => '1', 'states' => []]);
        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('Выберите хотя бы одно состояние', $crawler->text());
        self::assertFalse($settings->materialsRemind()['enabled']);
        $this->client->request('POST', '/admin/materials/remind', ['_token' => $token, 'enabled' => '1', 'states' => ['Не соответствует', 'Неизвестно', 'Чепуха']]);
        $this->client->followRedirect();
        $config = static::getContainer()->get(PortalSettings::class)->materialsRemind();
        self::assertTrue($config['enabled']);
        self::assertSame(['Не соответствует', 'Неизвестно'], $config['states'], 'неизвестные состояния отброшены');

        // Предпросмотр получателей из панели: ничего не отправляет.
        $this->client->request('POST', '/admin/materials/remind/send', ['_token' => $token, 'mode' => 'preview']);
        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('Пробный запуск', $crawler->text());
        self::assertNull(static::getContainer()->get(PortalSettings::class)->materialsRemind()['last_run']);

        // Команда cron: отправка, затем повтор в ту же неделю — пропускается.
        $app = new Application(static::$kernel);
        $app->setAutoExit(false);
        $out = new BufferedOutput();
        self::assertSame(0, $app->run(new ArrayInput(['command' => 'app:materials:remind', '--no-interaction' => true, '--verbose' => true]), $out));
        $text = $out->fetch();
        self::assertStringContainsString('Сводка отправлена', $text);
        self::assertMatchesRegularExpression('/писем [1-9]/u', $text);
        self::assertNotNull(static::getContainer()->get(PortalSettings::class)->materialsRemind()['last_run']);
        self::assertSame(0, $app->run(new ArrayInput(['command' => 'app:materials:remind', '--no-interaction' => true, '--verbose' => true]), $out));
        self::assertStringContainsString('уже отправлялась', $out->fetch());
        self::assertSame(0, $app->run(new ArrayInput(['command' => 'app:materials:remind', '--dry-run' => true, '--no-interaction' => true, '--verbose' => true]), $out));
        self::assertStringContainsString('Пробный запуск', $out->fetch());

        // Письмо: состояние, материал и ссылка на таблицу.
        $email = (new \ReflectionMethod(MaterialReminder::class, 'email'))->invoke($reminder, $this->user('sidorov'), [$pumps], ['Не соответствует', 'Отсутствует']);
        $html = static::getContainer()->get('twig')->render('emails/materials_remind.html.twig', $email->getContext());
        self::assertStringContainsString('Насосные станции', $html);
        self::assertStringContainsString($pumps['url'], $html);
        self::assertStringContainsString($pumps['rows'][0]['row']->getTitle(), $html);
        $text = static::getContainer()->get('twig')->render('emails/materials_remind.txt.twig', $email->getContext());
        self::assertStringContainsString('['.$pumps['rows'][0]['state'].']', $text);
    }
}
