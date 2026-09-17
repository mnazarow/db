<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Document;
use App\Entity\DocumentEvent;
use App\Repository\DocumentAcknowledgementRepository;
use App\Repository\DocumentEventRepository;
use App\Service\AcknowledgementService;
use App\Service\DocumentManager;
use App\Tests\PortalTestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Ознакомление с документами «под подпись»: назначение, подтверждение, привязка к редакции,
 * отчёт, лист для печати, CSV и напоминания.
 */
final class AcknowledgementTest extends PortalTestCase
{
    private function service(): AcknowledgementService
    {
        return static::getContainer()->get(AcknowledgementService::class);
    }

    private function acknowledgements(): DocumentAcknowledgementRepository
    {
        return static::getContainer()->get(DocumentAcknowledgementRepository::class);
    }

    private function upload(string $name, string $content): UploadedFile
    {
        $path = sys_get_temp_dir().'/'.bin2hex(random_bytes(4)).'-'.$name;
        file_put_contents($path, $content);

        return new UploadedFile($path, $name, null, null, true);
    }

    /** Новый опубликованный документ, чтобы тесты не мешали друг другу. */
    private function freshDocument(string $code, string $title = 'Положение об ознакомлении'): Document
    {
        $document = (new Document($this->section('Общие документы')))->setTitle($title)->setCode($code)->setType(Document::TYPE_FILE)->setPublic(false);
        static::getContainer()->get(DocumentManager::class)->create($document, $this->upload('doc.txt', 'Текст '.$code), null, null, $this->user('ivanov'), true);

        return $document;
    }

    public function testAssignAndConfirm(): void
    {
        // В демо-данных ознакомление уже назначено, поэтому считаем прирост, а не абсолютные значения.
        $wasSmirnov = $this->acknowledgements()->countPendingForUser($this->user('smirnov'));
        $wasPetrova = $this->acknowledgements()->countPendingForUser($this->user('petrova'));
        $document = $this->freshDocument('ОЗН-001');
        $result = $this->service()->assign($document, [$this->user('smirnov'), $this->user('petrova')], new \DateTimeImmutable('+7 days'), $this->user('ivanov'));
        self::assertSame(['created' => 2, 'existing' => 0, 'skipped' => 0, 'version' => 1], $result);

        // Повторное назначение той же редакции ничего не дублирует.
        $again = $this->service()->assign($document, [$this->user('smirnov')], null, $this->user('ivanov'));
        self::assertSame(['created' => 0, 'existing' => 1, 'skipped' => 0, 'version' => 1], $again);
        self::assertCount(2, $this->acknowledgements()->findForDocument($document));

        $pending = $this->acknowledgements()->findPending($document, $this->user('smirnov'));
        self::assertNotNull($pending);
        self::assertFalse($pending->isConfirmed());
        self::assertSame(1, $pending->getVersionNumber());
        self::assertSame('ivanov', $pending->getAssignedBy()?->getUsername());
        self::assertFalse($pending->isOverdue());
        self::assertSame(7, $pending->getDaysLeft());

        $confirmed = $this->service()->confirm($document, $this->user('smirnov'), '10.1.2.3');
        self::assertNotNull($confirmed);
        self::assertTrue($confirmed->isConfirmed());
        self::assertSame('10.1.2.3', $confirmed->getConfirmedIp());
        self::assertNull($this->acknowledgements()->findPending($document, $this->user('smirnov')), 'после подтверждения задача снимается');
        self::assertNull($this->service()->confirm($document, $this->user('smirnov')), 'повторное подтверждение ничего не меняет');
        self::assertNull($this->service()->confirm($document, $this->user('sidorov')), 'подтвердить можно только назначенное');

        self::assertSame(['assigned' => 2, 'confirmed' => 1, 'overdue' => 0], $this->acknowledgements()->summaryForDocument($document, 1));
        self::assertSame($wasPetrova + 1, $this->acknowledgements()->countPendingForUser($this->user('petrova')));
        self::assertSame($wasSmirnov, $this->acknowledgements()->countPendingForUser($this->user('smirnov')), 'подтверждённое из счётчика уходит');

        // Назначение и подтверждение попадают в историю документа.
        $events = static::getContainer()->get(DocumentEventRepository::class)->findBy(['document' => $document, 'type' => DocumentEvent::UPDATE], ['id' => 'DESC']);
        $details = array_map(static fn (DocumentEvent $e): array => $e->getDetails() ?? [], $events);
        self::assertContains('confirmed', array_column($details, 'acknowledgement'));
        self::assertContains('assigned', array_column($details, 'acknowledgement'));
    }

    public function testAssignRefusesDraftsAndInactiveUsers(): void
    {
        $document = (new Document($this->section('Общие документы')))->setTitle('Черновик распоряжения')->setCode('ОЗН-002')->setType(Document::TYPE_FILE);
        $manager = static::getContainer()->get(DocumentManager::class);
        $manager->create($document, $this->upload('draft.txt', 'черновик'), null, null, $this->user('ivanov'), false);

        try {
            $this->service()->assign($document, [$this->user('smirnov')], null, $this->user('ivanov'));
            self::fail('черновик не должен допускать назначение');
        } catch (\DomainException $e) {
            self::assertStringContainsString('опубликованных', $e->getMessage());
        }

        $manager->publish($this->document('ОЗН-002'), $this->user('ivanov'));
        $blocked = $this->user('smirnov');
        $blocked->setActive(false);
        $this->em()->flush();
        $result = $this->service()->assign($this->document('ОЗН-002'), [$blocked], null, $this->user('ivanov'));
        self::assertSame(['created' => 0, 'existing' => 0, 'skipped' => 1, 'version' => 1], $result, 'заблокированным сотрудникам не назначаем');
        $blocked->setActive(true);
        $this->em()->flush();
    }

    public function testNewVersionRequiresNewAcknowledgement(): void
    {
        $wasSmirnov = $this->acknowledgements()->countPendingForUser($this->user('smirnov'));
        $wasSidorov = $this->acknowledgements()->countPendingForUser($this->user('sidorov'));
        $document = $this->freshDocument('ОЗН-003');
        $this->service()->assign($document, [$this->user('smirnov')], null, $this->user('ivanov'));
        $this->service()->confirm($document, $this->user('smirnov'));
        self::assertSame($wasSmirnov, $this->acknowledgements()->countPendingForUser($this->user('smirnov')));

        // Новая редакция: прежнее подтверждение остаётся в истории, но требуется новое.
        static::getContainer()->get(DocumentManager::class)->addFileVersion($document, $this->upload('doc2.txt', 'новая редакция'), 'Правка', $this->user('ivanov'));
        self::assertNull($this->acknowledgements()->findPending($document, $this->user('smirnov')), 'само по себе обновление ознакомление не назначает');
        $result = $this->service()->assign($document, [$this->user('smirnov')], null, $this->user('ivanov'));
        self::assertSame(2, $result['version']);
        self::assertSame(1, $result['created']);

        $pending = $this->acknowledgements()->findPending($document, $this->user('smirnov'));
        self::assertSame(2, $pending?->getVersionNumber());
        self::assertSame($wasSmirnov + 1, $this->acknowledgements()->countPendingForUser($this->user('smirnov')));
        self::assertCount(2, $this->acknowledgements()->findForDocument($document), 'запись по прежней редакции сохраняется как доказательство');
        self::assertSame(['assigned' => 1, 'confirmed' => 1, 'overdue' => 0], $this->acknowledgements()->summaryForDocument($document, 1));
        self::assertSame(['assigned' => 1, 'confirmed' => 0, 'overdue' => 0], $this->acknowledgements()->summaryForDocument($document, 2));

        // Незакрытое назначение по старой редакции не показывается второй задачей.
        $this->service()->assign($document, [$this->user('sidorov')], null, $this->user('ivanov'));
        $document = $this->document('ОЗН-003');
        static::getContainer()->get(DocumentManager::class)->addFileVersion($document, $this->upload('doc3.txt', 'третья редакция'), 'Правка', $this->user('ivanov'));
        $this->service()->assign($document, [$this->user('sidorov')], null, $this->user('ivanov'));
        self::assertSame($wasSidorov + 1, $this->acknowledgements()->countPendingForUser($this->user('sidorov')), 'перекрытое назначение по прежней редакции не считается');
        self::assertCount($wasSidorov + 1, $this->acknowledgements()->findPendingForUser($this->user('sidorov')));
        self::assertCount(4, $this->acknowledgements()->findForDocument($document), 'все назначения остаются в истории');
        self::assertSame(3, $this->acknowledgements()->findPending($document, $this->user('sidorov'))?->getVersionNumber());
    }

    public function testOverdueAndReminders(): void
    {
        $document = $this->freshDocument('ОЗН-004');
        $this->service()->assign($document, [$this->user('smirnov'), $this->user('petrova')], new \DateTimeImmutable('-1 day'), $this->user('ivanov'), false);
        $overdue = $this->acknowledgements()->findPending($document, $this->user('smirnov'));
        self::assertTrue($overdue?->isOverdue());
        self::assertSame(-1, $overdue?->getDaysLeft());
        self::assertSame(2, $this->acknowledgements()->summaryForDocument($document, 1)['overdue']);
        self::assertGreaterThanOrEqual(2, $this->acknowledgements()->totals()['overdue']);

        $dry = $this->service()->remind(3, true);
        self::assertGreaterThanOrEqual(2, $dry['items']);
        self::assertSame(0, $dry['emails'], 'в режиме проверки письма не уходят');
        self::assertNull($this->acknowledgements()->findPending($document, $this->user('smirnov'))?->getRemindedAt());

        $sent = $this->service()->remind(3);
        self::assertGreaterThanOrEqual(2, $sent['emails']);
        self::assertNotNull($this->acknowledgements()->findPending($document, $this->user('smirnov'))?->getRemindedAt());
        self::assertSame(0, $this->service()->remind(3)['emails'], 'повторно напоминаем не раньше чем через '.AcknowledgementService::REMINDER_INTERVAL_DAYS.' дня');

        $application = new Application(static::$kernel);
        $application->setAutoExit(false);
        $output = new BufferedOutput();
        self::assertSame(0, $application->run(new ArrayInput(['command' => 'app:documents:acknowledge-remind', '--dry-run' => true, '--verbose' => true]), $output));
        self::assertStringContainsString('Ожидают ознакомления', $output->fetch());
    }

    public function testWebFlowForEmployeeAndModerator(): void
    {
        $document = $this->freshDocument('ОЗН-005', 'Инструкция о пропускном режиме');
        $this->service()->assign($document, [$this->user('smirnov')], new \DateTimeImmutable('+5 days'), $this->user('ivanov'), false);
        $id = (int) $document->getId();
        $pending = $this->acknowledgements()->countPendingForUser($this->user('smirnov'));

        // Сотрудник: карточка документа, счётчик в шапке, страница «Мои ознакомления».
        $this->loginAs('smirnov');
        $crawler = $this->client->request('GET', '/documents/'.$id);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.ack-box__title', 'Требуется ознакомление');
        self::assertSelectorTextContains('.ack-chip__count', (string) $pending);
        $crawler = $this->client->request('GET', '/profile/acknowledgements');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Инструкция о пропускном режиме', $crawler->text());

        // Подтверждение: только POST и только с правильным токеном.
        $this->client->request('POST', '/documents/'.$id.'/acknowledge', ['_token' => 'подделка']);
        self::assertResponseStatusCodeSame(403);
        $crawler = $this->client->request('GET', '/documents/'.$id);
        $this->client->submitForm('Ознакомлен');
        self::assertResponseRedirects('/documents/'.$id);
        $crawler = $this->client->followRedirect();
        self::assertSelectorExists('.ack-box--done');
        self::assertStringContainsString('Ознакомление подтверждено', $crawler->text());
        self::assertSelectorTextContains('.ack-chip__count', (string) ($pending - 1), 'счётчик в шапке уменьшился');
        self::assertTrue($this->acknowledgements()->findOneFor($this->document('ОЗН-005'), $this->user('smirnov'), 1)?->isConfirmed());

        // Читателю недоступны назначение и отчёты.
        foreach (['/documents/'.$id.'/acknowledgements', '/documents/'.$id.'/acknowledgements/assign', '/documents/'.$id.'/acknowledgements.csv', '/documents/'.$id.'/acknowledgements/sheet'] as $url) {
            $this->client->request('GET', $url);
            self::assertResponseStatusCodeSame(403, $url);
        }
        $this->client->request('GET', '/admin/acknowledgements');
        self::assertResponseStatusCodeSame(403);

        // Модератор раздела: назначение через форму, отчёт, лист для печати и CSV.
        $this->loginAs('ivanov');
        $crawler = $this->client->request('GET', '/documents/'.$id.'/acknowledgements/assign');
        self::assertResponseIsSuccessful();
        self::assertSame(1, $crawler->filter('form[data-ack-form] input[name="users[]"][disabled]')->count(), 'уже назначенных повторно не отмечают');
        $token = (string) $crawler->filter('form[data-ack-form] input[name="_token"]')->attr('value');
        $this->client->request('POST', '/documents/'.$id.'/acknowledgements/assign', [
            '_token' => $token,
            'users' => [(string) $this->user('sidorov')->getId(), (string) $this->user('kuznetsova')->getId()],
            'due' => '2030-01-31',
            'silent' => '1',
        ]);
        self::assertResponseRedirects('/documents/'.$id.'/acknowledgements');
        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('назначено: 2', $crawler->text());
        self::assertSame('3', trim($crawler->filter('.stat__value')->eq(0)->text()), 'в сводке — трое назначенных');
        self::assertSame(3, $this->acknowledgements()->summaryForDocument($this->document('ОЗН-005'), 1)['assigned']);

        $crawler = $this->client->request('GET', '/documents/'.$id.'/acknowledgements/sheet');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.print-sheet__title', 'Лист ознакомления');
        self::assertSame(3, $crawler->filter('.print-sheet__table tbody tr')->count());
        self::assertStringContainsString('подтверждено в портале', $crawler->text());

        $this->client->request('GET', '/documents/'.$id.'/acknowledgements.csv');
        $csv = (string) $this->client->getInternalResponse()->getContent();
        self::assertResponseHeaderSame('Content-Type', 'text/csv; charset=UTF-8');
        self::assertStringContainsString('attachment; filename="acknowledgements-'.$id.'-v1.csv"', (string) $this->client->getResponse()->headers->get('Content-Disposition'));
        self::assertStringStartsWith("\xEF\xBB\xBF", $csv);
        self::assertStringContainsString('Сотрудник;Подразделение;Назначено;Срок;Ознакомлен;IP', $csv);
        self::assertStringContainsString('не ознакомлен', $csv);
        self::assertSame(2, substr_count($csv, 'не ознакомлен'));

        // Администратор видит сводку по всем документам.
        $this->loginAs('admin');
        $crawler = $this->client->request('GET', '/admin/acknowledgements');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Инструкция о пропускном режиме', $crawler->text());
        $crawler = $this->client->request('GET', '/admin/acknowledgements?pending=1');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Инструкция о пропускном режиме', $crawler->text());
    }
}
