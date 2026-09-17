<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\AuditEvent;
use App\Entity\Document;
use App\Repository\AuditEventRepository;
use App\Repository\DocumentRepository;
use App\Security\Access;
use App\Service\Audit\AuditExporter;
use App\Service\DocumentManager;
use App\Tests\PortalTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Ограниченный доступ к отдельным документам и журнал аудита.
 */
final class AccessAuditTest extends PortalTestCase
{
    private function documents(): DocumentRepository
    {
        return static::getContainer()->get(DocumentRepository::class);
    }

    private function access(): Access
    {
        return static::getContainer()->get(Access::class);
    }

    private function restricted(string $code, array $departments = [], array $usernames = []): Document
    {
        $document = (new Document($this->section('Приказы')))->setTitle('Приказ о премировании '.$code)->setCode($code)->setType(Document::TYPE_FILE)->setPublic(false);
        $path = sys_get_temp_dir().'/'.bin2hex(random_bytes(4)).'-order.txt';
        file_put_contents($path, 'Текст приказа '.$code);
        static::getContainer()->get(DocumentManager::class)->create($document, new UploadedFile($path, 'order.txt', null, null, true), null, null, $this->user('kuznetsova'), true);
        $document->setRestricted(true)
            ->setAllowedDepartments($departments)
            ->setAllowedUsers(array_map(fn (string $name) => $this->user($name), $usernames));
        $this->em()->flush();

        return $document;
    }

    public function testRestrictedDocumentIsVisibleOnlyToAllowed(): void
    {
        $document = $this->restricted('ПРЕМ-001', ['Отдел кадров'], ['smirnov']);
        self::assertTrue($document->isRestricted());
        self::assertSame(['Отдел кадров'], $document->getAllowedDepartments());
        self::assertCount(1, $document->getAllowedUsers());

        // Проверка на уровне правил доступа.
        self::assertTrue($this->access()->canViewDocument($this->user('kuznetsova'), $document), 'подразделение из списка');
        self::assertTrue($this->access()->canViewDocument($this->user('smirnov'), $document), 'сотрудник из списка');
        self::assertTrue($this->access()->canViewDocument($this->user('admin'), $document), 'администратор видит всё');
        self::assertFalse($this->access()->canViewDocument($this->user('petrova'), $document), 'посторонний сотрудник');
        self::assertFalse($this->access()->canViewDocument(null, $document), 'гость');

        // Карточка документа.
        $id = (int) $document->getId();
        $this->loginAs('petrova');
        $this->client->request('GET', '/documents/'.$id);
        self::assertResponseStatusCodeSame(403);
        $this->client->request('GET', '/documents/'.$id.'/download');
        self::assertResponseStatusCodeSame(403);
        $this->loginAs('smirnov');
        $this->client->request('GET', '/documents/'.$id);
        self::assertResponseIsSuccessful();

        // Списки и поиск: документа нет у тех, кому он не открыт.
        $section = $this->section('Приказы');
        $viewerAllowed = $this->access()->viewer($this->user('kuznetsova'));
        $viewerDenied = $this->access()->viewer($this->user('petrova'));
        $titles = static fn (array $documents): array => array_map(static fn (Document $d): string => (string) $d->getCode(), $documents);
        self::assertContains('ПРЕМ-001', $titles($this->documents()->findBySection($section, [Document::STATUS_PUBLISHED], 'title', false, $viewerAllowed)));
        self::assertNotContains('ПРЕМ-001', $titles($this->documents()->findBySection($section, [Document::STATUS_PUBLISHED], 'title', false, $viewerDenied)));
        self::assertNotContains('ПРЕМ-001', $titles($this->documents()->findBySection($section, [Document::STATUS_PUBLISHED], 'title', true, $this->access()->viewer(null))), 'гостю тоже не видно');
        self::assertContains('ПРЕМ-001', $titles($this->documents()->search('премировании', [Document::STATUS_PUBLISHED], null, 50, false, $viewerAllowed)));
        self::assertNotContains('ПРЕМ-001', $titles($this->documents()->search('премировании', [Document::STATUS_PUBLISHED], null, 50, false, $viewerDenied)));

        // Счётчики раздела тоже учитывают ограничение.
        $allowedCount = $this->documents()->countByStatus([(int) $section->getId()], false, $viewerAllowed)[Document::STATUS_PUBLISHED];
        $deniedCount = $this->documents()->countByStatus([(int) $section->getId()], false, $viewerDenied)[Document::STATUS_PUBLISHED];
        self::assertSame($allowedCount - 1, $deniedCount);

        // Модератор раздела видит документ, даже если не входит в списки.
        self::assertTrue($this->access()->canViewDocument($this->user('kuznetsova'), $document));

        // Снятие ограничения открывает документ всем сотрудникам.
        $document = $this->document('ПРЕМ-001');
        $document->setRestricted(false);
        $this->em()->flush();
        self::assertSame([], $document->getAllowedDepartments(), 'при снятии ограничения списки очищаются');
        self::assertTrue($this->access()->canViewDocument($this->user('petrova'), $this->document('ПРЕМ-001')));
    }

    public function testRestrictionSurvivesTrickyDepartmentNames(): void
    {
        // Подразделения хранятся строкой с разделителем: похожие названия не должны пересекаться.
        $document = $this->restricted('ПРЕМ-002', ['Отдел кадров и делопроизводства']);
        $user = $this->user('kuznetsova');
        self::assertSame('Отдел кадров', $user->getDepartment());
        self::assertFalse($document->isAllowedFor($user), '«Отдел кадров» не равен «Отдел кадров и делопроизводства»');

        $document->setAllowedDepartments(['Отдел кадров и делопроизводства', 'Отдел кадров']);
        self::assertTrue($document->isAllowedFor($user));
        self::assertCount(2, $document->getAllowedDepartments());

        // Разделитель в названии подразделения не ломает хранение.
        $document->setAllowedDepartments(['Отдел|кадров', 'Отдел кадров']);
        self::assertSame(['Отдел кадров'], $document->getAllowedDepartments(), 'вертикальная черта заменяется пробелом, повтор убирается');
        self::assertSame('|Отдел кадров|', Document::departmentNeedle('Отдел кадров'));
    }

    public function testRestrictedDocumentIsHiddenFromApi(): void
    {
        $document = $this->restricted('ПРЕМ-003', ['Отдел кадров']);
        $documents = $this->documents();
        $codes = static fn (array $items): array => array_map(static fn (Document $d): string => (string) $d->getCode(), $items);

        $filters = ['statuses' => [Document::STATUS_PUBLISHED], 'public_only' => false, 'section' => null, 'type' => null, 'updated_since' => null, 'tag' => null, 'q' => null];
        self::assertNotContains('ПРЕМ-003', $codes($documents->findForApi($filters, 1, 200)['items']), 'ключу API документ с ограниченным доступом не отдаётся');

        // В ленте изменений он приходит как «скрытый», чтобы индексатор убрал его из индекса.
        $hidden = $documents->findHiddenSinceForApi(new \DateTimeImmutable('-1 hour'), false, false);
        self::assertContains('ПРЕМ-003', $codes($hidden));
    }

    public function testAuditLogRecordsSecurityEvents(): void
    {
        $audit = static::getContainer()->get(AuditEventRepository::class);
        $before = $audit->countFiltered([]);

        // Вход в систему пишется в журнал (обработчик сохраняет записи в конце запроса).
        $crawler = $this->client->request('GET', '/login');
        $this->client->submit($crawler->selectButton('Войти')->form(['_username' => 'ivanov', '_password' => 'Demo12345']));
        self::assertResponseRedirects();
        self::assertGreaterThan($before, $audit->countFiltered([]), 'запись о входе появилась');
        $login = $audit->findFiltered(['q' => 'Вход в систему', 'actor' => 'ivanov'], 5);
        self::assertNotEmpty($login);
        self::assertSame('Вход в систему', $login[0]->getAction());
        self::assertSame('ivanov', $login[0]->getActorName());
        self::assertSame(AuditEvent::LEVEL_INFO, $login[0]->getLevel());

        // Неудачный вход пишется как предупреждение.
        $this->client->getCookieJar()->clear();
        $crawler = $this->client->request('GET', '/login');
        $this->client->submit($crawler->selectButton('Войти')->form(['_username' => 'ivanov', '_password' => 'неверный']));
        $failures = $audit->findFiltered(['level' => AuditEvent::LEVEL_WARNING], 5);
        self::assertNotEmpty($failures, 'неудачный вход записан с уровнем «предупреждение»');

        // Фильтры и выгрузка.
        self::assertGreaterThan(0, $audit->countFiltered(['actor' => 'ivanov']));
        self::assertSame(0, $audit->countFiltered(['actor' => 'нет-такого-пользователя']));
        self::assertContains('ivanov', $audit->actors());
        $summary = $audit->summary();
        self::assertGreaterThan(0, $summary['total']);
        self::assertNotNull($summary['last']);

        $exporter = static::getContainer()->get(AuditExporter::class);
        $event = $audit->findFiltered([], 1)[0];
        $json = json_decode($exporter->line($event, AuditExporter::FORMAT_JSONL), true);
        self::assertIsArray($json);
        self::assertSame($event->getAction(), $json['action']);
        self::assertArrayHasKey('time', $json);
        $cef = $exporter->line($event, AuditExporter::FORMAT_CEF);
        self::assertStringStartsWith('CEF:0|Docportal|', $cef);
        self::assertStringContainsString('rt=', $cef);

        // Страница журнала доступна только администратору.
        $this->loginAs('ivanov');
        $this->client->request('GET', '/admin/audit');
        self::assertResponseStatusCodeSame(403);
        $this->loginAs('admin');
        $crawler = $this->client->request('GET', '/admin/audit');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Вход в систему', $crawler->text());
        $this->client->request('GET', '/admin/audit.csv');
        self::assertResponseHeaderSame('Content-Type', 'text/csv; charset=UTF-8');
        $csv = (string) $this->client->getInternalResponse()->getContent();
        self::assertStringContainsString('Уровень;Действие;Сотрудник;Адрес;Подробности', $csv);

        // Очистка по сроку хранения.
        $removed = $audit->purgeOlderThan(new \DateTimeImmutable('+1 day'));
        self::assertGreaterThan(0, $removed);
        self::assertSame(0, $audit->countFiltered([]));
    }
}
