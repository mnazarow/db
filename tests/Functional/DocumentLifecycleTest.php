<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Document;
use App\Entity\DocumentEvent;
use App\Repository\DocumentEventRepository;
use App\Repository\DocumentRepository;
use App\Service\DocumentManager;
use App\Service\ExpiryNotifier;
use App\Tests\PortalTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Жизненный цикл документа: версии, восстановление, публикация, удаление, статистика, сроки.
 */
final class DocumentLifecycleTest extends PortalTestCase
{
    private function upload(string $name, string $content): UploadedFile
    {
        $path = sys_get_temp_dir().'/'.bin2hex(random_bytes(4)).'-'.$name;
        file_put_contents($path, $content);

        return new UploadedFile($path, $name, null, null, true);
    }

    public function testFileVersionsAndRestore(): void
    {
        $manager = static::getContainer()->get(DocumentManager::class);
        $petrova = $this->user('petrova');
        $doc = (new Document($this->section('Рабочее место')))->setTitle('Тестовый регламент')->setCode('ТЕСТ-001')->setType(Document::TYPE_FILE)->setTags(['тест', 'Тест', '']);
        $manager->create($doc, $this->upload('reglament.txt', 'версия 1'), null, null, $petrova, true);
        self::assertTrue($doc->isPublished());
        self::assertSame(['тест'], $doc->getTags(), 'теги нормализуются и не дублируются');
        self::assertSame(1, $doc->getCurrentVersion()?->getNumber());
        self::assertTrue($manager->getStorage()->exists($doc->getCurrentVersion()));

        $v2 = $manager->addFileVersion($doc, $this->upload('reglament.txt', 'версия 2'), 'Правка', $petrova);
        self::assertSame(2, $v2->getNumber());
        self::assertSame($v2->getId(), $doc->getCurrentVersion()?->getId());

        $v3 = $manager->restoreVersion($doc, $doc->findVersion(1), $petrova);
        self::assertSame(3, $v3->getNumber());
        self::assertSame($doc->findVersion(1)->getChecksum(), $v3->getChecksum(), 'восстановленная версия — копия первой');
        self::assertSame('версия 1', file_get_contents($manager->getStorage()->absolutePath($v3)));

        $types = array_map(static fn (DocumentEvent $e) => $e->getType(), static::getContainer()->get(DocumentEventRepository::class)->findForDocument($doc));
        self::assertContains(DocumentEvent::CREATE, $types);
        self::assertContains(DocumentEvent::PUBLISH, $types);
        self::assertContains(DocumentEvent::NEW_VERSION, $types);
        self::assertContains(DocumentEvent::RESTORE, $types);

        $manager->unpublish($doc, $petrova);
        self::assertTrue($doc->isDraft());
        $manager->archive($doc, $petrova);
        self::assertTrue($doc->isArchived());

        $id = $doc->getId();
        $dir = $manager->getStorage()->getStorageDir().'/'.$id;
        self::assertDirectoryExists($dir);
        $manager->delete($doc, $petrova);
        self::assertNull(static::getContainer()->get(DocumentRepository::class)->find($id));
        self::assertDirectoryDoesNotExist($dir);
    }

    public function testRejectsForbiddenExtensionAndEmptyPage(): void
    {
        $manager = static::getContainer()->get(DocumentManager::class);
        $doc = (new Document($this->section('Рабочее место')))->setTitle('Плохой файл')->setType(Document::TYPE_FILE);
        try {
            $manager->create($doc, $this->upload('virus.exe', 'x'), null, null, $this->user('petrova'), true);
            self::fail('Недопустимое расширение должно отклоняться');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('не принимаются', $e->getMessage());
        }
        $page = (new Document($this->section('Рабочее место')))->setTitle('Пустая страница')->setType(Document::TYPE_PAGE);
        $this->expectException(\InvalidArgumentException::class);
        $manager->create($page, null, '<p>   </p>', null, $this->user('petrova'), true);
    }

    public function testPageVersionsSanitizedAndDeduplicated(): void
    {
        $manager = static::getContainer()->get(DocumentManager::class);
        $petrova = $this->user('petrova');
        $doc = (new Document($this->section('Рабочее место')))->setTitle('Страница')->setType(Document::TYPE_PAGE);
        $manager->create($doc, null, '<h2>Заголовок</h2><p onclick="x()">Текст <script>alert(1)</script><a href="javascript:evil()">ссылка</a></p>', null, $petrova, false);
        $html = (string) $doc->getCurrentVersion()?->getContent();
        self::assertStringContainsString('<h2>Заголовок</h2>', $html);
        self::assertStringNotContainsString('<script', $html);
        self::assertStringNotContainsString('onclick', $html);
        self::assertStringNotContainsString('javascript:', $html);

        self::assertNull($manager->addPageVersion($doc, $html, 'без изменений', $petrova), 'одинаковый текст не создаёт версию');
        $v2 = $manager->addPageVersion($doc, $html.'<p>Дополнение</p>', 'дополнено', $petrova);
        self::assertSame(2, $v2?->getNumber());
        self::assertStringContainsString('Дополнение', $doc->getCurrentVersion()?->getPlainText() ?? '');

        $manager->publish($doc, $petrova);
        self::assertTrue($doc->isPublished());
        self::assertNotNull($doc->getPublishedAt());
    }

    public function testViewAndDownloadAreCounted(): void
    {
        $this->loginAs('smirnov');
        $doc = $this->document('ИТ-РМ-002');
        $views = $doc->getViewCount();
        $downloads = $doc->getDownloadCount();
        $this->client->request('GET', '/documents/'.$doc->getId());
        self::assertResponseIsSuccessful();
        $this->client->request('GET', '/documents/'.$doc->getId());
        self::assertResponseIsSuccessful();
        $this->client->request('GET', '/documents/'.$doc->getId().'/download');
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/pdf');
        self::assertStringContainsString('attachment', (string) $this->client->getResponse()->headers->get('Content-Disposition'));
        $this->client->request('GET', '/documents/'.$doc->getId().'/versions/1/download');
        self::assertResponseIsSuccessful();
        $this->client->request('GET', '/documents/'.$doc->getId().'/versions/99/download');
        self::assertResponseStatusCodeSame(404);

        $this->em()->clear();
        $fresh = $this->document('ИТ-РМ-002');
        self::assertSame($views + 1, $fresh->getViewCount(), 'повторный просмотр в течение 10 минут не учитывается');
        self::assertSame($downloads + 2, $fresh->getDownloadCount());
        self::assertSame(1, $fresh->findVersion(1)?->getDownloadCount());
    }

    public function testExpiryNotifierSendsOncePerStage(): void
    {
        $notifier = static::getContainer()->get(ExpiryNotifier::class);
        $stats = $notifier->run();
        self::assertGreaterThanOrEqual(3, $stats['expired'], 'в демо-данных есть просроченные документы');
        self::assertGreaterThanOrEqual(3, $stats['soon']);
        self::assertSame($stats['checked'], $stats['notified'] + $stats['skipped']);
        self::assertGreaterThan(0, $stats['notified']);
        self::assertContains('ivanov@example.ru', $stats['recipients'], 'владелец получает уведомление');
        self::assertContains('admin@example.ru', $stats['recipients'], 'администратор получает сводку');

        $again = $notifier->run();
        self::assertSame(0, $again['notified'], 'повторный запуск ничего не отправляет');
        self::assertSame($stats['checked'], $again['skipped']);

        $this->em()->clear();
        $expired = $this->document('ОТ-002');
        self::assertSame(2, $expired->getExpiryNoticeStage());
        $expired->setValidUntil(new \DateTimeImmutable('+1 year'));
        self::assertSame(0, $expired->getExpiryNoticeStage(), 'продление срока сбрасывает стадию');

        $forced = $notifier->run(force: true, dryRun: true);
        self::assertGreaterThan(0, $forced['notified']);
        self::assertSame(0, $forced['emails'], 'в режиме проверки письма не отправляются');
    }

    public function testSearchRespectsVisibility(): void
    {
        $repo = static::getContainer()->get(DocumentRepository::class);
        $published = $repo->search('резервного', [Document::STATUS_PUBLISHED]);
        self::assertCount(0, $published, 'ИБ-003 — черновик и не виден читателям');
        $all = $repo->search('резервного', Document::STATUSES);
        self::assertCount(1, $all);
        self::assertSame('ИБ-003', $all[0]->getCode());
        $byContent = $repo->search('менеджер паролей', [Document::STATUS_PUBLISHED]);
        self::assertNotEmpty($byContent, 'поиск по тексту страницы');
        self::assertSame('ИБ-001', $byContent[0]->getCode());
    }
}
