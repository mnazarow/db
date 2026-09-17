<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Document;
use App\Entity\DocumentLink;
use App\Entity\DocumentTemplate;
use App\Repository\DocumentLinkRepository;
use App\Repository\DocumentTemplateRepository;
use App\Service\BulkDocumentService;
use App\Service\DocumentLinkService;
use App\Service\DocumentManager;
use App\Service\DocumentTemplateService;
use App\Tests\PortalTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Связи между документами, шаблоны с автонумерацией и массовые операции в реестре.
 */
final class LinksTemplatesBulkTest extends PortalTestCase
{
    private function links(): DocumentLinkService
    {
        return static::getContainer()->get(DocumentLinkService::class);
    }

    private function templates(): DocumentTemplateService
    {
        return static::getContainer()->get(DocumentTemplateService::class);
    }

    private function bulk(): BulkDocumentService
    {
        return static::getContainer()->get(BulkDocumentService::class);
    }

    private function freshDocument(string $code, string $title, string $section = 'Общие документы'): Document
    {
        $document = (new Document($this->section($section)))->setTitle($title)->setCode($code)->setType(Document::TYPE_FILE)->setPublic(false);
        $path = sys_get_temp_dir().'/'.bin2hex(random_bytes(4)).'-doc.txt';
        file_put_contents($path, 'Текст '.$code);
        static::getContainer()->get(DocumentManager::class)->create($document, new UploadedFile($path, 'doc.txt', null, null, true), null, null, $this->user('ivanov'), true);

        return $document;
    }

    public function testLinksAreVisibleFromBothSides(): void
    {
        $old = $this->freshDocument('СВЗ-001', 'Порядок выдачи пропусков (2024)');
        $new = $this->freshDocument('СВЗ-002', 'Порядок выдачи пропусков (2026)');
        $ivanov = $this->user('ivanov');

        $link = $this->links()->link($new, $old, DocumentLink::REPLACES, $ivanov, 'Пересмотр по итогам аудита');
        self::assertSame('заменяет', $link->labelFor($new));
        self::assertSame('заменён документом', $link->labelFor($old));
        self::assertSame($old->getId(), $link->other($new)->getId());

        $rows = $this->links()->forDocument($this->document('СВЗ-001'));
        self::assertCount(1, $rows);
        self::assertSame('заменён документом', $rows[0]['label']);
        self::assertSame('СВЗ-002', $rows[0]['document']->getCode());

        // Повторная и встречная связь того же вида не заводятся.
        try {
            $this->links()->link($new, $old, DocumentLink::REPLACES, $ivanov);
            self::fail('повторная связь должна отклоняться');
        } catch (\DomainException $e) {
            self::assertStringContainsString('уже есть', $e->getMessage());
        }
        try {
            $this->links()->link($old, $new, DocumentLink::REPLACES, $ivanov);
            self::fail('встречная связь должна отклоняться');
        } catch (\DomainException $e) {
            self::assertStringContainsString('обратная связь', mb_strtolower($e->getMessage()));
        }
        // «См. также» читается одинаково с обеих сторон, поэтому встречную заводить можно.
        $this->links()->link($old, $new, DocumentLink::RELATED, $ivanov);
        self::assertCount(2, $this->links()->forDocument($this->document('СВЗ-001')));

        // Документ находится по обозначению, идентификатору и точному названию.
        self::assertSame($old->getId(), $this->links()->resolve('СВЗ-001')?->getId());
        self::assertSame($old->getId(), $this->links()->resolve((string) $old->getId())?->getId());
        self::assertSame($old->getId(), $this->links()->resolve('Порядок выдачи пропусков (2024)')?->getId());
        self::assertNull($this->links()->resolve('несуществующий документ'));

        // Заменённый документ можно сразу перенести в архив.
        self::assertFalse($this->document('СВЗ-001')->isArchived());
        $archived = $this->links()->archiveSuperseded($this->document('СВЗ-002'), $ivanov);
        self::assertSame(['Порядок выдачи пропусков (2024)'], $archived);
        self::assertTrue($this->document('СВЗ-001')->isArchived());

        // Карточка показывает связь, страница второго документа — обратную подпись.
        $this->loginAs('ivanov');
        $crawler = $this->client->request('GET', '/documents/'.$new->getId());
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('заменяет', $crawler->filter('#links')->text());
        $crawler = $this->client->request('GET', '/documents/'.$old->getId());
        self::assertStringContainsString('заменён документом', $crawler->filter('#links')->text());

        // Удаление связи через карточку.
        $token = (string) $crawler->filter('#links form input[name="_token"]')->first()->attr('value');
        $repository = static::getContainer()->get(DocumentLinkRepository::class);
        $this->client->request('POST', '/documents/'.$old->getId().'/links/'.$link->getId().'/delete', ['_token' => $token]);
        self::assertResponseRedirects();
        self::assertNull($repository->find($link->getId()));

        // Документ нельзя связать сам с собой.
        $this->expectException(\DomainException::class);
        $this->links()->link($this->document('СВЗ-002'), $this->document('СВЗ-002'), DocumentLink::RELATED, $ivanov);
    }

    public function testTemplateFillsCardAndNumbersDocuments(): void
    {
        $template = (new DocumentTemplate('Приказ (тестовый шаблон)'))
            ->setSection($this->section('Приказы'))
            ->setType(Document::TYPE_FILE)
            ->setTitlePattern('Приказ от {ДАТА} № ')
            ->setCodePattern('ПРК-{ГОД}-{NNN}')
            ->setTagsString('приказ, основная деятельность')
            ->setValidityMonths(12)
            ->setPublic(false);
        $this->templates()->save($template, $this->user('admin'));

        $today = new \DateTimeImmutable('2026-03-05');
        self::assertSame('ПРК-2026-001', $this->templates()->preview($template, $today));

        $document = new Document($this->section('Общие документы'));
        $body = $this->templates()->prepare($template, $document, $today);
        self::assertNull($body, 'у шаблона вида «файл» текста страницы нет');
        self::assertSame('Приказы', $document->getSection()->getName());
        self::assertSame('ПРК-2026-001', $document->getCode());
        self::assertSame('Приказ от 05.03.2026 №', $document->getTitle());
        self::assertSame(['приказ', 'основная деятельность'], $document->getTags());
        self::assertSame('2027-03-05', $document->getValidUntil()?->format('Y-m-d'));

        // Номера выдаются подряд и не повторяются.
        self::assertSame('ПРК-2026-002', $this->templates()->nextCode($template, $today));
        self::assertSame('ПРК-2026-003', $this->templates()->nextCode($template, $today));
        self::assertSame(3, $template->getCounter());
        // В новом году нумерация начинается заново.
        self::assertSame('ПРК-2027-001', $this->templates()->nextCode($template, new \DateTimeImmutable('2027-01-09')));

        // Занятое обозначение пропускается.
        $this->freshDocument('ПРК-2027-002', 'Ранее заведённый приказ');
        self::assertSame('ПРК-2027-003', $this->templates()->nextCode($template, new \DateTimeImmutable('2027-02-01')));

        // Шаблон вида «страница» отдаёт заготовку текста.
        $page = (new DocumentTemplate('Регламент процесса (тестовый)'))->setType(Document::TYPE_PAGE)->setBody('<h2>Назначение</h2><p>Текст.</p>');
        $this->templates()->save($page, $this->user('admin'));
        self::assertSame('<h2>Назначение</h2><p>Текст.</p>', $this->templates()->prepare($page, new Document($this->section('Общие документы'))));

        // Форма нового документа заполняется по шаблону, счётчик использования растёт.
        $this->loginAs('admin');
        $crawler = $this->client->request('GET', '/documents/new?template='.$template->getId());
        self::assertResponseIsSuccessful();
        self::assertStringStartsWith('ПРК-', (string) $crawler->filter('#document_code')->attr('value'));
        $this->em()->clear();
        $stored = static::getContainer()->get(DocumentTemplateRepository::class)->find($template->getId());
        self::assertSame(1, $stored?->getUsageCount());

        // Выключенный шаблон в списке не предлагается.
        $stored->setActive(false);
        $this->em()->flush();
        $names = array_map(static fn (DocumentTemplate $t): string => $t->getName(), static::getContainer()->get(DocumentTemplateRepository::class)->findForSection($this->section('Приказы')));
        self::assertNotContains('Приказ (тестовый шаблон)', $names);
        self::assertContains('Регламент процесса (тестовый)', $names, 'общие шаблоны предлагаются в любом разделе');
    }

    public function testBulkActionsRespectRights(): void
    {
        $first = $this->freshDocument('МАС-001', 'Массовая операция 1');
        $second = $this->freshDocument('МАС-002', 'Массовая операция 2');
        $alien = $this->freshDocument('МАС-003', 'Чужой раздел', 'Приказы');
        $ids = [(int) $first->getId(), (int) $second->getId(), (int) $alien->getId()];

        // Модератор «Общих документов» не трогает документы чужого раздела.
        $result = $this->bulk()->run('archive', $ids, [], $this->user('ivanov'));
        self::assertSame(2, $result['done']);
        self::assertSame(1, $result['denied']);
        $this->em()->clear();
        self::assertTrue($this->document('МАС-001')->isArchived());
        self::assertFalse($this->document('МАС-003')->isArchived());

        // Повторная операция ничего не меняет.
        $result = $this->bulk()->run('archive', $ids, [], $this->user('ivanov'));
        self::assertSame(0, $result['done']);
        self::assertSame(2, $result['skipped']);

        // Публикация возвращает документы в строй.
        $this->bulk()->run('publish', $ids, [], $this->user('admin'));
        $this->em()->clear();
        self::assertTrue($this->document('МАС-001')->isPublished());
        self::assertTrue($this->document('МАС-003')->isPublished());

        // Перенос в раздел: администратору можно, в отчёте — число перенесённых.
        $target = $this->section('Приказы');
        $result = $this->bulk()->run('move', [(int) $this->document('МАС-001')->getId()], ['section' => $target], $this->user('admin'));
        self::assertSame(1, $result['done']);
        $this->em()->clear();
        self::assertSame('Приказы', $this->document('МАС-001')->getSection()->getName());

        // Без раздела перенос не выполняется.
        $result = $this->bulk()->run('move', $ids, ['section' => null], $this->user('admin'));
        self::assertSame(0, $result['done']);
        self::assertNotEmpty($result['messages']);

        // Срок актуальности и доступ.
        $this->bulk()->run('validity', $ids, ['valid_until' => new \DateTimeImmutable('2027-12-31')], $this->user('admin'));
        $this->em()->clear();
        self::assertSame('2027-12-31', $this->document('МАС-002')->getValidUntil()?->format('Y-m-d'));
        $this->bulk()->run('access', $ids, ['public' => true], $this->user('admin'));
        $this->em()->clear();
        self::assertTrue($this->document('МАС-002')->isPublic());

        // Удаление убирает документы вместе со связями.
        $this->links()->link($this->document('МАС-002'), $this->document('МАС-003'), DocumentLink::RELATED, $this->user('admin'));
        $result = $this->bulk()->run('delete', [(int) $this->document('МАС-002')->getId()], [], $this->user('admin'));
        self::assertSame(1, $result['done']);
        $this->em()->clear();
        self::assertNull(static::getContainer()->get('App\Repository\DocumentRepository')->findOneBy(['code' => 'МАС-002']));
        self::assertSame([], $this->links()->forDocument($this->document('МАС-003')));

        // Неизвестное действие и пустой список отклоняются.
        self::assertSame(0, $this->bulk()->run('burn', $ids, [], $this->user('admin'))['done']);
        self::assertNotEmpty($this->bulk()->run('archive', [], [], $this->user('admin'))['messages']);
    }

    public function testBulkFromRegistryPage(): void
    {
        $document = $this->freshDocument('МАС-010', 'Реестр: массовая операция');
        $this->loginAs('admin');
        $crawler = $this->client->request('GET', '/admin/documents?q=МАС-010');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Реестр: массовая операция', $crawler->text());
        $token = (string) $crawler->filter('#bulk-form input[name="_token"]')->attr('value');

        $this->client->request('POST', '/admin/documents/bulk', [
            '_token' => $token,
            'action' => 'archive',
            'ids' => [(string) $document->getId()],
        ]);
        self::assertResponseRedirects();
        $this->em()->clear();
        self::assertTrue($this->document('МАС-010')->isArchived());

        // Без токена операция не выполняется.
        $this->client->request('POST', '/admin/documents/bulk', ['action' => 'publish', 'ids' => [(string) $document->getId()]]);
        self::assertResponseStatusCodeSame(403);
    }
}
