<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Document;
use App\Entity\DocumentAcknowledgement;
use App\Entity\DocumentQuestion;
use App\Entity\DocumentTemplate;
use App\Entity\User;
use App\Repository\DocumentRepository;
use App\Repository\SectionModeratorRepository;
use App\Repository\UserRepository;
use App\Service\DocumentManager;
use App\Service\DocumentTemplateService;
use App\Service\ExpiryNotifier;
use App\Service\PortalUrl;
use App\Service\UserManager;
use App\Service\Validity;
use App\Tests\PortalTestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\RawMessage;

/**
 * Исправления 1.6.1 в жизненном цикле документов: дата изменения, уведомления о сроках, удаление
 * пользователей, проверка знаний, поиск по тегам, ограничение по подразделениям, страницы, шаблоны.
 */
final class CoreFixesTest extends PortalTestCase
{
    private function manager(): DocumentManager
    {
        return static::getContainer()->get(DocumentManager::class);
    }

    private function upload(string $name, string $content): UploadedFile
    {
        $path = sys_get_temp_dir().'/'.bin2hex(random_bytes(4)).'-'.$name;
        file_put_contents($path, $content);

        return new UploadedFile($path, $name, null, null, true);
    }

    public function testViewsAndDownloadsDoNotChangeModificationDate(): void
    {
        $document = $this->document('ПОЛ-001');
        $id = (int) $document->getId();
        $stamp = new \DateTimeImmutable('2020-01-02 03:04:05');
        $document->setUpdatedAt($stamp);
        $views = $document->getViewCount();
        $this->em()->flush();

        $this->manager()->recordView($document, $this->user('smirnov'), '10.0.0.5');
        $this->manager()->recordDownload($document, $document->getCurrentVersion() ?? throw new \LogicException(), null, '10.0.0.6');
        // Служебное изменение (отметка об уведомлении) тоже не делает документ «изменённым».
        $document->setExpiryNoticeStage(1);
        $this->em()->flush();
        $this->em()->clear();

        $fresh = $this->em()->find(Document::class, $id);
        self::assertNotNull($fresh);
        self::assertSame($stamp->format('Y-m-d H:i:s'), $fresh->getUpdatedAt()->format('Y-m-d H:i:s'), 'просмотр — не изменение документа');
        self::assertSame($views + 1, $fresh->getViewCount(), 'счётчик просмотров увеличен в базе');
        $fresh->setExpiryNoticeStage(0);
        $this->em()->flush();
    }

    public function testExpiryNoticeIsRepeatedWhenMailFails(): void
    {
        $document = $this->document('ПОЛ-001');
        $document->setValidUntil(new \DateTimeImmutable('today +5 days'))->setExpiryNoticeStage(0);
        $this->em()->flush();
        $failing = new class implements MailerInterface {
            public int $attempts = 0;

            public function send(RawMessage $message, ?Envelope $envelope = null): void
            {
                ++$this->attempts;
                throw new TransportException('SMTP недоступен');
            }
        };
        $container = static::getContainer();
        $notifier = new ExpiryNotifier(
            $this->em(),
            $container->get(DocumentRepository::class),
            $container->get(SectionModeratorRepository::class),
            $container->get(UserRepository::class),
            $container->get(Validity::class),
            $failing,
            new NullLogger(),
            'Портал <noreply@example.ru>',
            true,
            'Портал',
            $container->get(PortalUrl::class),
        );
        try {
            $stats = $notifier->run();
            self::assertGreaterThan(0, $failing->attempts);
            self::assertGreaterThan(0, $stats['failed']);
            self::assertSame(0, $this->document('ПОЛ-001')->getExpiryNoticeStage(), 'письмо не ушло — уведомление повторится при следующей проверке');
        } finally {
            $this->em()->clear();
            $this->document('ПОЛ-001')->setValidUntil(new \DateTimeImmutable('today +1 year'))->setExpiryNoticeStage(0);
            $this->em()->flush();
        }
    }

    public function testUserWithAcknowledgementHistoryIsBlockedNotDeleted(): void
    {
        $user = (new User())->setUsername('temp.reader')->setDisplayName('Временный читатель')->setPassword('x');
        $this->em()->persist($user);
        $document = $this->document('ПОЛ-001');
        $this->em()->persist(new DocumentAcknowledgement($document, $user, (int) $document->getCurrentVersion()?->getNumber(), $this->user('admin'), null));
        $this->em()->flush();
        try {
            static::getContainer()->get(UserManager::class)->delete($user, $this->user('admin'));
            self::fail('сотрудника с историей ознакомлений удалять нельзя');
        } catch (\DomainException $e) {
            self::assertStringContainsString('Заблокируйте', $e->getMessage());
        }
        self::assertNotNull(static::getContainer()->get(UserRepository::class)->findOneByUsername('temp.reader'));
    }

    public function testQuizOptionsKeepTheMarkedAnswerWhenFieldsAreBlank(): void
    {
        $question = new DocumentQuestion($this->document('ПОЛ-001'));
        // Отмечен третий вариант, а второе поле пустое: верным должен остаться «Каска», а не «Перчатки».
        $question->setOptions(['Очки', '', 'Каска', 'Перчатки'], 2);
        self::assertSame(['Очки', 'Каска', 'Перчатки'], $question->getOptions());
        self::assertSame(1, $question->getCorrectOption());
        self::assertTrue($question->isCorrect(1));
        try {
            $question->setOptions(['Очки', '', 'Каска'], 1);
            self::fail('отмечен пустой вариант — ошибка');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('Отметьте верный вариант', $e->getMessage());
        }
    }

    public function testSearchFindsCyrillicTags(): void
    {
        $results = static::getContainer()->get(DocumentRepository::class)->search('регламент', [Document::STATUS_PUBLISHED], null, 50);
        $codes = array_map(static fn (Document $d): ?string => $d->getCode(), $results);
        self::assertContains('ПОЛ-001', $codes, 'документ находится по тегу «регламент»');
    }

    public function testDepartmentRestrictionIgnoresCaseAndSpaces(): void
    {
        $document = (new Document($this->section('Общие документы')))->setTitle('Премирование')->setType(Document::TYPE_FILE);
        $document->setRestricted(true)->setAllowedDepartments(['Отдел  кадров']);
        $reader = (new User())->setUsername('dept.reader')->setDisplayName('Читатель')->setDepartment('отдел кадров');
        self::assertTrue($document->isAllowedFor($reader), 'подразделение из AD в другом регистре и с одним пробелом');
        self::assertSame(['Отдел кадров'], $document->getAllowedDepartments());
        $reader->setDepartment('Отдел кадров и учёта');
        self::assertFalse($document->isAllowedFor($reader));
    }

    public function testImageOnlyPageIsAcceptedAndHugePageIsRejected(): void
    {
        $page = (new Document($this->section('Общие документы')))->setTitle('Схема проезда')->setType(Document::TYPE_PAGE);
        $this->manager()->create($page, null, '<p><img src="data:image/png;base64,iVBORw0KGgo=" alt="схема"></p>', null, $this->user('ivanov'), false);
        self::assertNotNull($page->getId(), 'страница из одной картинки сохраняется');
        self::assertStringContainsString('<img', (string) $page->getCurrentVersion()?->getContent());

        $huge = (new Document($this->section('Общие документы')))->setTitle('Огромная страница')->setType(Document::TYPE_PAGE);
        try {
            $this->manager()->create($huge, null, '<p>'.str_repeat('а', DocumentManager::MAX_PAGE_BYTES).'</p>', null, $this->user('ivanov'), false);
            self::fail('слишком большая страница должна отклоняться, а не обрезаться');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('слишком большая', $e->getMessage());
        }
        self::assertNull($huge->getId());
    }

    public function testFailedUploadLeavesNoEmptyDocument(): void
    {
        $count = \count(static::getContainer()->get(DocumentRepository::class)->findAll());
        $document = (new Document($this->section('Общие документы')))->setTitle('Сбой загрузки')->setType(Document::TYPE_FILE);
        try {
            $this->manager()->createFromPath($document, '/nonexistent/'.bin2hex(random_bytes(4)).'.pdf', 'сбой.pdf', null, $this->user('ivanov'), false);
            self::fail('несуществующий файл');
        } catch (\Throwable) {
        }
        $this->em()->clear();
        self::assertCount($count, static::getContainer()->get(DocumentRepository::class)->findAll(), 'черновик без версий не остаётся');
    }

    public function testNumberingWithoutYearContinuesIntoNewYear(): void
    {
        $service = static::getContainer()->get(DocumentTemplateService::class);
        $template = (new DocumentTemplate('Распоряжение (без года)'))->setType(Document::TYPE_FILE)->setCodePattern('РСП-{NNN}');
        $service->save($template, $this->user('admin'));
        self::assertSame('РСП-001', $service->nextCode($template, new \DateTimeImmutable('2026-12-30')));
        self::assertSame('РСП-002', $service->nextCode($template, new \DateTimeImmutable('2027-01-02')), 'без года в образце нумерация не сбрасывается');
        self::assertSame('РСП-003', $service->preview($template, new \DateTimeImmutable('2028-01-05')), 'пример на странице шаблонов совпадает со следующим номером');
        self::assertSame('РСП-003', $service->nextCode($template, new \DateTimeImmutable('2028-01-05')));
    }
}
