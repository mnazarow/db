<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Document;
use App\Entity\MaterialParameter;
use App\Entity\MaterialRow;
use App\Entity\MaterialRowChange;
use App\Entity\Section;
use App\Repository\MaterialRowChangeRepository;
use App\Service\DocumentKindService;
use App\Service\DocumentManager;
use App\Service\Material\MaterialLinker;
use App\Service\Material\MaterialService;
use App\Service\Material\MaterialSummary;
use App\Service\PortalSettings;
use App\Service\SectionManager;
use App\Tests\PortalTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * «Документы → строки»: документы раздела с таблицей материалов и его подразделов сами становятся
 * строками таблицы, а «С файлами», «Отсутствует» и состояние считаются по ним; счётчики на карточках
 * разделов и в сводной — живые.
 */
final class MaterialsWithDocumentsTest extends PortalTestCase
{
    private function materials(): MaterialService
    {
        return static::getContainer()->get(MaterialService::class);
    }

    private function summary(): MaterialSummary
    {
        return static::getContainer()->get(MaterialSummary::class);
    }

    private function manager(): DocumentManager
    {
        return static::getContainer()->get(DocumentManager::class);
    }

    private function sections(): SectionManager
    {
        return static::getContainer()->get(SectionManager::class);
    }

    private function pdf(string $name): UploadedFile
    {
        $path = sys_get_temp_dir().'/'.bin2hex(random_bytes(4)).'-'.$name;
        file_put_contents($path, "%PDF-1.4\n% ".$name."\n%%EOF\n");

        return new UploadedFile($path, $name, 'application/pdf', null, true);
    }

    /** Новая категория с таблицей материалов: три строки, как после импорта листа Excel. */
    private function category(string $name): Section
    {
        $admin = $this->user('admin');
        $section = $this->sections()->create($name, null, null, $admin);
        $this->materials()->enable($section, $admin);
        $pm = $this->materials()->roleParameter($section, MaterialParameter::ROLE_PM);
        $kinds = static::getContainer()->get(DocumentKindService::class);
        foreach ([
            ['Каталог '.$name, 'Каталоги и брошюры', 'Соответствует'],
            ['Паспорт '.$name, 'Паспорта и руководства', 'Отсутствует'],
            ['Опросный лист', 'Опросные листы и ТКП', ''],
        ] as [$title, $kind, $check]) {
            $row = $this->materials()->addRow($section, $admin, $title);
            $row->setKind($kinds->match($kind));
            if ('' !== $check) {
                $row->setValue($pm, $check);
            }
        }
        $this->em()->flush();

        return $section;
    }

    private function upload(Section $section, string $title, ?string $kind = null): Document
    {
        $document = (new Document($section))->setTitle($title)->setType(Document::TYPE_FILE);
        if (null !== $kind) {
            $document->setKind(static::getContainer()->get(DocumentKindService::class)->match($kind));
        }
        $this->manager()->create($document, $this->pdf('file.pdf'), null, null, $this->user('admin'), true);

        return $document;
    }

    private function rowOf(Document $document): ?MaterialRow
    {
        $rows = $this->materials()->rowsForDocument($document);

        return $rows[0] ?? null;
    }

    public function testUploadedDocumentsBecomeRowsAndDriveTheNumbers(): void
    {
        $section = $this->category('Тепловые насосы');
        $pm = $this->materials()->roleParameter($section, MaterialParameter::ROLE_PM);
        $sub = $this->sections()->create('Воздух — вода', $section, null, $this->user('admin'));

        $summary = $this->summary()->forSection($section);
        self::assertSame([3, 0, 3], [$summary['total'], $summary['files'], $summary['states']['Отсутствует']], 'без документов все материалы отсутствуют');

        // То же название (без учёта регистра и знаков), документ — в подразделе: привязка к строке.
        $passport = $this->upload($sub, 'ПАСПОРТ «Тепловые насосы»');
        $row = $this->rowOf($passport);
        self::assertNotNull($row);
        self::assertSame('Паспорт Тепловые насосы', $row->getTitle());
        self::assertSame($section->getId(), $row->getSection()->getId());
        self::assertSame('Не выбрано', $row->getValue($pm), '«Отсутствует» в проверке PM снято: файл есть');
        self::assertSame('Неизвестно', $this->materials()->state($row));
        $link = $this->materials()->roleParameter($section, MaterialParameter::ROLE_LINK);
        self::assertStringEndsWith('/documents/'.$passport->getId(), $row->getValue($link));
        $actions = array_map(static fn (MaterialRowChange $c): string => $c->getAction(), static::getContainer()->get(MaterialRowChangeRepository::class)->findForRow($row, 20));
        self::assertContains(MaterialRowChange::LINK, $actions);

        // Другое название, но единственная свободная строка того же типа.
        $catalog = $this->upload($section, 'Буклет 2026', 'Каталоги и брошюры');
        self::assertSame('Каталог Тепловые насосы', $this->rowOf($catalog)?->getTitle());
        self::assertSame('Неизвестно', $this->materials()->state($this->rowOf($catalog)), 'PM «Соответствует», дизайн не оценён: файл есть, состояние неизвестно');

        // Совпадений нет — новая строка с названием и типом документа.
        $photo = $this->upload($sub, 'Фото монтажа', 'Фотографии');
        $new = $this->rowOf($photo);
        self::assertNotNull($new);
        self::assertSame(['Фото монтажа', 'Фотографии'], [$new->getTitle(), $new->getKind()?->getName()]);

        $summary = $this->summary()->forSection($section);
        self::assertSame([4, 3, 1], [$summary['total'], $summary['files'], $summary['states']['Отсутствует']]);
        self::assertSame([$sub->getId() => 2], $this->summary()->filesBySubsection($section, [$sub]));

        // В архиве — не файл: материал снова «Отсутствует».
        $this->manager()->archive($photo, $this->user('admin'));
        $summary = $this->summary()->forSection($section);
        self::assertSame([4, 2, 2], [$summary['total'], $summary['files'], $summary['states']['Отсутствует']]);
        // Возврат из архива — снова с файлом.
        $this->manager()->publish($photo, $this->user('admin'));
        self::assertSame(3, $this->summary()->forSection($section)['files']);
        self::assertCount(1, $this->materials()->rowsForDocument($photo), 'повторной строки нет');
    }

    public function testMovedDocumentsFollowTheirTable(): void
    {
        $heat = $this->category('Котлы');
        $other = $this->category('Бойлеры');
        $doc = $this->upload($heat, 'Монтажная схема котла', 'Прочее');
        self::assertSame($heat->getId(), $this->rowOf($doc)?->getSection()->getId());

        // Документ перенесён в раздел другой категории: строка прежней таблицы освобождается.
        $previous = $doc->getSection();
        $doc->setSection($other);
        $this->manager()->updateMetadata($doc, $previous, $this->user('admin'));
        $rows = $this->materials()->rowsForDocument($doc);
        self::assertCount(1, $rows);
        self::assertSame($other->getId(), $rows[0]->getSection()->getId());
        $old = array_values(array_filter($this->materials()->rows($heat), static fn (MaterialRow $r): bool => 'Монтажная схема котла' === $r->getTitle()));
        self::assertCount(1, $old);
        self::assertNull($old[0]->getDocument());
        self::assertSame('Отсутствует', $this->materials()->state($old[0]));

        // Подраздел с документом перенесён в другую категорию — документ уходит вместе с ним.
        $sub = $this->sections()->create('Настенные', $heat, null, $this->user('admin'));
        $wall = $this->upload($sub, 'Паспорт настенного котла', 'Паспорта и руководства');
        self::assertSame($heat->getId(), $this->rowOf($wall)?->getSection()->getId());
        $this->sections()->update($sub, $other, $this->user('admin'));
        $rows = $this->materials()->rowsForDocument($wall);
        self::assertCount(1, $rows);
        self::assertSame($other->getId(), $rows[0]->getSection()->getId());
    }

    public function testDetachedAndDeletedRowsDoNotComeBack(): void
    {
        $section = $this->category('Счётчики');
        $doc = $this->upload($section, 'Сертификат на счётчики', 'Прочее');
        $row = $this->rowOf($doc);
        self::assertNotNull($row);

        $this->materials()->detach($row, $this->user('admin'));
        self::assertSame(['linked' => 0, 'created' => 0, 'unlinked' => 0], $this->materials()->linkDocuments($section), 'отвязанный вручную документ сам не возвращается');
        self::assertNull($row->getDocument());

        // Загрузка файла в строку снова связывает её с документом и снимает отметку.
        $other = $this->upload($section, 'Декларация на счётчики', 'Прочее');
        $otherRow = $this->rowOf($other);
        $this->materials()->deleteRow($otherRow, $this->user('admin'));
        self::assertSame(0, array_sum($this->materials()->linkDocuments($section)), 'документ удалённой строки не становится строкой снова');
        self::assertTrue(static::getContainer()->get(MaterialLinker::class)->isExcluded($other));
    }

    /** «Привязать документ раздела…»: вручную — к пустой строке, из другой строки, с заменой документа строки. */
    public function testManualLinkFromRowMenu(): void
    {
        $section = $this->category('Ручная привязка');
        $catalog = $this->upload($section, 'Каталог Ручная привязка', 'Каталоги и брошюры');
        $passport = $this->upload($section, 'Паспорт насоса XL', 'Паспорта и руководства');
        $rows = [];
        foreach ($this->materials()->rows($section) as $row) {
            $rows[$row->getTitle()] = $row;
        }
        self::assertSame($catalog->getId(), $rows['Каталог Ручная привязка']->getDocument()?->getId());
        self::assertSame($passport->getId(), $rows['Паспорт Ручная привязка']->getDocument()?->getId());
        $this->materials()->detach($rows['Паспорт Ручная привязка'], $this->user('admin'));
        $other = $this->upload($this->category('Чужая категория'), 'Чужой каталог', 'Каталоги и брошюры');

        $this->loginAs('markova');
        $crawler = $this->client->request('GET', '/sections/'.$section->getId().'/materials');
        $config = json_decode($crawler->filter('#mt-config')->text(), true);
        $base = '/sections/'.$section->getId().'/materials';
        $this->client->request('GET', $base.'/documents');
        $list = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertTrue($list['ok']);
        self::assertSame([$passport->getId()], array_column($list['documents'], 'id'), 'в списке — документы раздела без строки, и отвязанные вручную');
        self::assertTrue($list['documents'][0]['excluded']);

        $post = function (string $title, Document $document, ?string $user = null) use ($base, $rows, $config): array {
            $this->client->request('POST', $base.'/rows/'.$rows[$title]->getId().'/link', ['document' => $document->getId()], [], ['HTTP_X_CSRF_TOKEN' => $config['token'], 'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);

            return ['status' => $this->client->getResponse()->getStatusCode()] + (json_decode((string) $this->client->getResponse()->getContent(), true) ?? []);
        };
        $this->client->request('POST', $base.'/rows/'.$rows['Опросный лист']->getId().'/link', ['document' => $passport->getId()], [], ['HTTP_X_CSRF_TOKEN' => 'wrong']);
        self::assertResponseStatusCodeSame(403);

        // К пустой строке: документ снова в таблице и снова может привязываться сам.
        $res = $post('Опросный лист', $passport);
        self::assertTrue($res['ok'], json_encode($res, \JSON_UNESCAPED_UNICODE));
        self::assertStringContainsString('привязан к строке', $res['message']);
        $this->em()->clear();
        $linker = static::getContainer()->get(MaterialLinker::class);
        self::assertSame($passport->getId(), $this->em()->find(MaterialRow::class, $rows['Опросный лист']->getId())->getDocument()?->getId());
        self::assertFalse($linker->isExcluded($passport));
        self::assertSame([], $linker->candidates($this->em()->find(Section::class, $section->getId())));

        // Из другой строки: документ переходит, прежняя строка — без файла (с записью в журнале).
        $res = $post('Паспорт Ручная привязка', $passport);
        self::assertTrue($res['ok']);
        $this->em()->clear();
        self::assertNull($this->em()->find(MaterialRow::class, $rows['Опросный лист']->getId())->getDocument());
        self::assertSame($passport->getId(), $this->em()->find(MaterialRow::class, $rows['Паспорт Ручная привязка']->getId())->getDocument()?->getId());
        $changes = static::getContainer()->get(MaterialRowChangeRepository::class)->findBy(['row' => $rows['Опросный лист']->getId(), 'action' => MaterialRowChange::DETACH]);
        self::assertNotEmpty($changes);
        self::assertStringContainsString('привязан вручную к строке «Паспорт Ручная привязка»', $changes[0]->getFieldLabel());

        // Чужой документ и документ в архиве не привязываются.
        $res = $post('Опросный лист', $other);
        self::assertSame(422, $res['status']);
        self::assertStringContainsString('не из раздела', $res['error']);

        // Замена документа строки: только тем, кто меняет устройство таблицы; прежний документ сам не вернётся.
        $this->loginAs('belov');
        $crawler = $this->client->request('GET', $base);
        $config = json_decode($crawler->filter('#mt-config')->text(), true);
        $res = $post('Каталог Ручная привязка', $passport);
        self::assertSame(403, $res['status']);
        self::assertStringContainsString('маркетолог', $res['error']);
        $this->loginAs('markova');
        $crawler = $this->client->request('GET', $base);
        $config = json_decode($crawler->filter('#mt-config')->text(), true);
        $res = $post('Каталог Ручная привязка', $passport);
        self::assertTrue($res['ok']);
        $this->em()->clear();
        self::assertSame($passport->getId(), $this->em()->find(MaterialRow::class, $rows['Каталог Ручная привязка']->getId())->getDocument()?->getId());
        self::assertTrue($linker->isExcluded($catalog), 'заменённый документ сам не привязывается');
        self::assertSame([$catalog->getId()], array_column($linker->candidates($this->em()->find(Section::class, $section->getId())), 'id'));
        self::assertSame(0, array_sum($this->materials()->linkDocuments($this->em()->find(Section::class, $section->getId()))));
    }

    public function testFirstViewLinksDocumentsOfOlderTablesAndButtonLinksTheRest(): void
    {
        // Таблица, которую вели до связки «Документы → строки»: документы лежат в разделе, строк для них нет.
        $section = $this->sections()->create('Задвижки', null, null, $this->user('admin'));
        $this->materials()->enable($section, $this->user('admin'));
        $linker = static::getContainer()->get(MaterialLinker::class);
        $old = $linker->withoutAutoLink(fn (): Document => $this->upload($section, 'Каталог задвижек', 'Каталоги и брошюры'));
        self::assertSame([], $this->materials()->rowsForDocument($old));
        $settings = static::getContainer()->get(PortalSettings::class);
        $settings->set(MaterialLinker::SETTING_SYNCED, array_values(array_diff((array) $settings->get(MaterialLinker::SETTING_SYNCED, []), [$section->getId()])));
        self::assertFalse($linker->isSynced($section));

        $this->loginAs('markova');
        $crawler = $this->client->request('GET', '/sections/'.$section->getId().'/materials');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $this->materials()->rowsForDocument($old), 'первое открытие таблицы связало документы раздела');
        self::assertStringContainsString('с файлами 1', $crawler->filter('[data-mt-summary]')->text());
        self::assertTrue($linker->isSynced($section));

        // Кнопка «Связать документы раздела» — для документов, загруженных в обход портала.
        $later = $linker->withoutAutoLink(fn (): Document => $this->upload($section, 'Паспорт задвижки', 'Паспорта и руководства'));
        $token = $crawler->filter('form[action$="/materials/link"] input[name=_token]')->attr('value');
        $this->client->request('POST', '/sections/'.$section->getId().'/materials/link', ['_token' => $token]);
        self::assertResponseRedirects('/sections/'.$section->getId().'/materials');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flashes', 'добавлено строк — 1');
        self::assertCount(1, $this->materials()->rowsForDocument($later));
    }

    public function testCountersOnSectionCardsAndSectionPage(): void
    {
        $section = $this->category('Радиаторы отопления');
        $this->upload($section, 'Каталог Радиаторы отопления');

        // Маркетолог видит счётчики материалов на карточке раздела и на странице раздела.
        $this->loginAs('markova');
        $crawler = $this->client->request('GET', '/');
        $card = $crawler->filter('a.section-card[href="/sections/'.$section->getId().'"]');
        self::assertStringContainsString('Материалы: 1 из 3 с файлами', $card->filter('.section-card__materials')->text());
        self::assertStringContainsString('отсутствует 2', $card->filter('.section-card__materials')->text());
        $crawler = $this->client->request('GET', '/sections/'.$section->getId());
        self::assertStringContainsString('с файлами 1', $crawler->filter('.mt-section-card')->text());

        // Сводная по категориям — те же цифры.
        $this->loginAs('admin');
        $crawler = $this->client->request('GET', '/admin/materials');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Радиаторы отопления', $crawler->filter('table.ms-pivot')->first()->text());

        // Сотрудник без доступа к таблицам материалов счётчиков не видит.
        $this->loginAs('petrova');
        $crawler = $this->client->request('GET', '/');
        self::assertCount(0, $crawler->filter('.section-card__materials'));
        $crawler = $this->client->request('GET', '/sections/'.$section->getId());
        self::assertCount(0, $crawler->filter('.mt-section-card'));
    }
}
