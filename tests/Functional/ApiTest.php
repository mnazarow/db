<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\ApiKey;
use App\Entity\Document;
use App\Repository\ApiKeyRepository;
use App\Repository\DocumentRepository;
use App\Repository\SectionRepository;
use App\Service\DocumentManager;
use App\Service\PortalSettings;
use App\Service\Text\TextExtractor;
use App\Tests\PortalTestCase;

/**
 * REST API для внешних систем (выгрузка в RAG): ключи, видимость документов, фильтры, текст, содержимое, изменения.
 */
final class ApiTest extends PortalTestCase
{
    private static ?string $publicToken = null;
    private static ?string $internalToken = null;

    private function tokens(): void
    {
        if (null !== self::$publicToken && null !== static::getContainer()->get(ApiKeyRepository::class)->findOneByToken(self::$publicToken)) {
            return;
        }
        $em = $this->em();
        self::$publicToken = ApiKey::generateToken();
        self::$internalToken = ApiKey::generateToken();
        $em->persist(new ApiKey('Индексатор (открытые)', self::$publicToken, $this->user('admin')));
        $em->persist((new ApiKey('Индексатор (все)', self::$internalToken))->setIncludeInternal(true));
        $em->flush();
    }

    /** @return array<string, mixed> */
    private function api(string $path, ?string $token, array $server = []): array
    {
        $headers = null !== $token ? ['HTTP_AUTHORIZATION' => 'Bearer '.$token] : [];
        $this->client->request('GET', '/api/v1'.$path, [], [], $headers + $server);
        $content = (string) $this->client->getResponse()->getContent();
        $data = json_decode($content, true);
        self::assertIsArray($data, 'Ответ API должен быть JSON: '.mb_substr($content, 0, 200));

        return $data;
    }

    public function testAuthentication(): void
    {
        $this->tokens();
        $data = $this->api('', null);
        self::assertResponseStatusCodeSame(401);
        self::assertSame('missing_token', $data['error']['code']);
        self::assertStringContainsString('Bearer', (string) $this->client->getResponse()->headers->get('WWW-Authenticate'));

        $data = $this->api('/documents', 'dp_'.str_repeat('0', 48));
        self::assertResponseStatusCodeSame(401);
        self::assertSame('invalid_token', $data['error']['code']);

        $data = $this->api('', self::$publicToken);
        self::assertResponseIsSuccessful();
        self::assertSame('1', $data['api_version']);
        self::assertSame('Индексатор (открытые)', $data['key']['name']);
        self::assertFalse($data['key']['include_internal']);
        self::assertArrayHasKey('documents', $data['endpoints']);

        // Ключ можно передать и заголовком X-Api-Key; счётчик обращений растёт.
        $this->client->request('GET', '/api/v1', [], [], ['HTTP_X_API_KEY' => self::$publicToken]);
        self::assertResponseIsSuccessful();
        $key = static::getContainer()->get(ApiKeyRepository::class)->findOneByToken(self::$publicToken);
        $this->em()->refresh($key);
        self::assertGreaterThanOrEqual(2, $key->getRequestCount());
        self::assertNotNull($key->getLastUsedAt());

        // Отключённый ключ не работает, включённый — снова работает.
        $key->setEnabled(false);
        $this->em()->flush();
        $data = $this->api('', self::$publicToken);
        self::assertResponseStatusCodeSame(401);
        self::assertStringContainsString('отключён', $data['error']['message']);
        // Между запросами EntityManager сбрасывается — сущность нужно получить заново.
        static::getContainer()->get(ApiKeyRepository::class)->findOneByToken(self::$publicToken)->setEnabled(true);
        $this->em()->flush();
        $this->api('', self::$publicToken);
        self::assertResponseIsSuccessful();

        // Сессионный вход на портале не даёт доступа к API, а гостевые ограничения на API не действуют.
        $this->loginAs('admin');
        $this->client->request('GET', '/api/v1/documents');
        self::assertResponseStatusCodeSame(401);
        $settings = static::getContainer()->get(PortalSettings::class);
        $settings->setGuestAccess(PortalSettings::GUEST_OFF, []);
        try {
            $this->api('/documents?per_page=1', self::$publicToken, ['REMOTE_ADDR' => '203.0.113.5']);
            self::assertResponseIsSuccessful();
        } finally {
            $settings->setGuestAccess(PortalSettings::GUEST_ALL, []);
        }
    }

    public function testSectionsAndDocumentsVisibility(): void
    {
        $this->tokens();
        $sections = static::getContainer()->get(SectionRepository::class);
        $documents = static::getContainer()->get(DocumentRepository::class);

        $data = $this->api('/sections', self::$publicToken);
        self::assertResponseIsSuccessful();
        self::assertSame($sections->countAll(), $data['total']);
        $byName = array_column($data['items'], null, 'name');
        self::assertNull($byName['Общие документы']['parent_id']);
        self::assertSame('Общие документы / Регламенты и политики', $byName['Регламенты и политики']['full_name']);
        self::assertSame(3, $byName['Регламенты и политики']['document_count']);
        self::assertSame(1, $byName['Информационная безопасность']['document_count'], 'внутренний документ ИБ-002 ключу «только открытые» не считается, черновик ИБ-003 — никому');

        $internal = $this->api('/sections', self::$internalToken);
        self::assertSame(2, array_column($internal['items'], null, 'name')['Информационная безопасность']['document_count']);

        $section = $this->section('Информационная безопасность');
        $data = $this->api('/sections/'.$section->getId(), self::$publicToken);
        self::assertResponseIsSuccessful();
        self::assertSame($section->getName(), $data['name']);
        self::assertIsArray($data['children']);

        // Списки документов: открытые / все, статусы, пагинация.
        $publicCount = $documents->countByStatus(null, true)[Document::STATUS_PUBLISHED];
        $allCount = $documents->countByStatus()[Document::STATUS_PUBLISHED];
        self::assertGreaterThan($publicCount, $allCount, 'в демо-данных есть внутренние документы');

        $data = $this->api('/documents', self::$publicToken);
        self::assertResponseIsSuccessful();
        self::assertSame($publicCount, $data['total']);
        self::assertSame(1, $data['page']);
        self::assertSame(50, $data['per_page']);
        foreach ($data['items'] as $item) {
            self::assertTrue($item['is_public'], $item['title']);
            self::assertSame(Document::STATUS_PUBLISHED, $item['status']);
        }
        $first = $data['items'][0];
        foreach (['id', 'title', 'code', 'description', 'type', 'tags', 'section', 'valid_until', 'validity', 'updated_at', 'version', 'links'] as $field) {
            self::assertArrayHasKey($field, $first);
        }
        self::assertStringEndsWith('/api/v1/documents/'.$first['id'], $first['links']['self']);
        self::assertStringEndsWith('/documents/'.$first['id'], $first['links']['web']);

        $data = $this->api('/documents', self::$internalToken);
        self::assertSame($allCount, $data['total']);
        $data = $this->api('/documents?status=all', self::$internalToken);
        self::assertSame($allCount + $documents->countByStatus()[Document::STATUS_ARCHIVED], $data['total']);
        $data = $this->api('/documents?status=archived', self::$internalToken);
        self::assertSame($documents->countByStatus()[Document::STATUS_ARCHIVED], $data['total']);
        $this->api('/documents?status=draft', self::$internalToken);
        self::assertResponseStatusCodeSame(400);

        $data = $this->api('/documents?per_page=10&page=2', self::$publicToken);
        self::assertSame(10, $data['per_page']);
        self::assertSame(2, $data['page']);
        self::assertSame((int) ceil($publicCount / 10), $data['pages']);
        self::assertCount(min(10, max(0, $publicCount - 10)), $data['items']);
        $data = $this->api('/documents?per_page=1000', self::$publicToken);
        self::assertSame(200, $data['per_page'], 'размер страницы ограничен');

        // Фильтры: раздел (с подразделами и без), тип, тег, дата изменения.
        $production = $this->section('Производство');
        $data = $this->api('/documents?section='.$production->getId(), self::$publicToken);
        self::assertSame(\count($documents->findInSubtree($production, [Document::STATUS_PUBLISHED], 'title', 0, true)), $data['total']);
        $data = $this->api('/documents?section='.$production->getId().'&subtree=0', self::$publicToken);
        self::assertSame(\count($documents->findBySection($production, [Document::STATUS_PUBLISHED], 'title', true)), $data['total']);
        $this->api('/documents?section=999999', self::$publicToken);
        self::assertResponseStatusCodeSame(404);
        $data = $this->api('/documents?type=page', self::$publicToken);
        foreach ($data['items'] as $item) {
            self::assertSame('page', $item['type']);
        }
        self::assertGreaterThan(0, $data['total']);
        self::assertSame($data['total'], $this->api('/documents?type=PAGE', self::$publicToken)['total'], 'значения фильтров не зависят от регистра');
        $this->api('/documents?type=файл', self::$publicToken);
        self::assertResponseStatusCodeSame(400);
        // Нечисловой раздел — явная ошибка, а не молчаливая выдача всех документов.
        $this->api('/documents?section=abc', self::$publicToken);
        self::assertResponseStatusCodeSame(400);
        self::assertSame($publicCount, $this->api('/documents?section=', self::$publicToken)['total'], 'пустое значение фильтра игнорируется');
        $data = $this->api('/documents?tag='.rawurlencode('регламент'), self::$publicToken);
        self::assertSame(1, $data['total']);
        self::assertSame('ПОЛ-001', $data['items'][0]['code']);
        $data = $this->api('/documents?updated_since='.rawurlencode((new \DateTimeImmutable('+1 day'))->format(\DATE_ATOM)), self::$publicToken);
        self::assertSame(0, $data['total']);
        $data = $this->api('/documents?updated_since='.rawurlencode('2000-01-01T00:00:00+00:00'), self::$publicToken);
        self::assertSame($publicCount, $data['total']);
        $this->api('/documents?updated_since=не-дата', self::$publicToken);
        self::assertResponseStatusCodeSame(400);

        $data = $this->api('/search?q='.rawurlencode('пожарной'), self::$publicToken);
        self::assertSame(1, $data['total']);
        self::assertSame('Инструкция по пожарной безопасности', $data['items'][0]['title']);
        $this->api('/search?q=a', self::$publicToken);
        self::assertResponseStatusCodeSame(400);
    }

    public function testDocumentDetailTextAndContent(): void
    {
        $this->tokens();
        $page = $this->document('ИБ-001');
        $data = $this->api('/documents/'.$page->getId(), self::$publicToken);
        self::assertResponseIsSuccessful();
        self::assertSame('Политика паролей', $data['title']);
        self::assertCount(3, $data['versions']);
        self::assertSame(3, $data['version']['number']);
        self::assertSame(TextExtractor::STATUS_OK, $data['text_status']);
        self::assertStringContainsString('не менее 12 символов', $data['text']);
        self::assertStringNotContainsString('<p>', $data['text']);
        $data = $this->api('/documents/'.$page->getId().'?text=0', self::$publicToken);
        self::assertArrayNotHasKey('text', $data);

        $this->api('/documents/'.$page->getId().'/text?version=1', self::$publicToken);
        self::assertResponseIsSuccessful();
        $data = $this->api('/documents/'.$page->getId().'/text?version=99', self::$publicToken);
        self::assertResponseStatusCodeSame(404);
        self::assertSame('not_found', $data['error']['code']);

        // Страница как HTML.
        $this->client->request('GET', '/api/v1/documents/'.$page->getId().'/content', [], [], ['HTTP_AUTHORIZATION' => 'Bearer '.self::$publicToken]);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('text/html', (string) $this->client->getResponse()->headers->get('Content-Type'));
        self::assertStringContainsString('<h2>', (string) $this->client->getResponse()->getContent());

        // Файл: PDF с несколькими версиями.
        $file = $this->document('ПОЛ-001');
        $this->client->request('GET', '/api/v1/documents/'.$file->getId().'/content?version=1', [], [], ['HTTP_AUTHORIZATION' => 'Bearer '.self::$publicToken]);
        self::assertResponseIsSuccessful();
        self::assertSame('application/pdf', $this->client->getResponse()->headers->get('Content-Type'));
        self::assertSame('1', $this->client->getResponse()->headers->get('X-Document-Version'));
        self::assertStringContainsString('attachment', (string) $this->client->getResponse()->headers->get('Content-Disposition'));
        $data = $this->api('/documents/'.$file->getId().'/text', self::$publicToken);
        self::assertResponseIsSuccessful();
        $extractor = static::getContainer()->get(TextExtractor::class);
        if ($extractor->hasPdftotext()) {
            self::assertSame(TextExtractor::STATUS_OK, $data['status']);
            self::assertStringContainsString('POL-001', $data['text']);
        } else {
            self::assertSame(TextExtractor::STATUS_UNSUPPORTED, $data['status']);
        }
        // Обращения через API не учитываются как просмотры/скачивания.
        self::assertSame(0, $this->document('ПОЛ-001')->getDownloadCount());

        // Внутренний документ и черновик: ключу «только открытые» — 404, ключу с правом — карточка.
        $internalDoc = $this->document('ИБ-002');
        $this->api('/documents/'.$internalDoc->getId(), self::$publicToken);
        self::assertResponseStatusCodeSame(404);
        $data = $this->api('/documents/'.$internalDoc->getId(), self::$internalToken);
        self::assertResponseIsSuccessful();
        self::assertFalse($data['is_public']);
        $draft = static::getContainer()->get(DocumentRepository::class)->findOneBy(['status' => Document::STATUS_DRAFT]);
        self::assertNotNull($draft);
        $this->api('/documents/'.$draft->getId(), self::$internalToken);
        self::assertResponseStatusCodeSame(404);
        $this->api('/documents/999999', self::$internalToken);
        self::assertResponseStatusCodeSame(404);
        $this->api('/nothing-here', self::$internalToken);
        self::assertResponseStatusCodeSame(404);
    }

    public function testChangesFeed(): void
    {
        $this->tokens();
        $data = $this->api('/changes', self::$publicToken);
        self::assertResponseStatusCodeSame(400);
        self::assertSame('bad_request', $data['error']['code']);

        $since = (new \DateTimeImmutable('-1 hour'))->format(\DATE_ATOM);
        $data = $this->api('/changes?since='.rawurlencode($since), self::$publicToken);
        self::assertResponseIsSuccessful();
        $publicCount = static::getContainer()->get(DocumentRepository::class)->countByStatus(null, true)[Document::STATUS_PUBLISHED];
        self::assertSame($publicCount, $data['changed_total']);
        self::assertNotEmpty($data['removed'], 'внутренние, архивные и черновики попадают в removed для ключа «только открытые»');
        self::assertSame([], $data['deleted']);
        self::assertFalse($data['truncated']);

        // Скрытые документы отдаются без названий: ключ «только открытые» не должен узнать содержимое
        // внутренних документов и черновиков — только идентификатор и причину.
        $internalTitle = $this->document('ИБ-002')->getTitle();
        $draftTitle = static::getContainer()->get(DocumentRepository::class)->findOneBy(['status' => Document::STATUS_DRAFT])?->getTitle();
        self::assertStringNotContainsString($internalTitle, (string) $this->client->getResponse()->getContent());
        self::assertStringNotContainsString((string) $draftTitle, (string) $this->client->getResponse()->getContent());
        foreach ($data['removed'] as $item) {
            self::assertSame(['id', 'reason', 'updated_at'], array_keys($item));
            self::assertContains($item['reason'], ['draft', 'archived', 'internal']);
        }
        $reasons = array_column($data['removed'], 'reason', 'id');
        self::assertSame('internal', $reasons[$this->document('ИБ-002')->getId()]);
        self::assertSame('archived', $reasons[$this->document('ПР-2025-01')->getId()]);

        // next_since — с небольшим перекрытием назад, иначе изменения в ту же секунду потерялись бы.
        self::assertArrayHasKey('next_since', $data);
        self::assertLessThan(strtotime($data['until']), strtotime($data['next_since']));
        self::assertGreaterThanOrEqual(strtotime($data['until']) - 60, strtotime($data['next_since']));

        $future = (new \DateTimeImmutable('+1 hour'))->format(\DATE_ATOM);
        $data = $this->api('/changes?since='.rawurlencode($future), self::$internalToken);
        self::assertSame([], $data['changed']);
        self::assertSame([], $data['removed']);

        // Снятие с публикации → removed; удаление → deleted.
        $manager = static::getContainer()->get(DocumentManager::class);
        $admin = $this->user('admin');
        $mark = (new \DateTimeImmutable('-1 second'))->format(\DATE_ATOM);
        $toHide = $this->document('ШБ-002');
        $manager->unpublish($toHide, $admin);
        $toDelete = $this->document('ИТ-РМ-003');
        $deletedId = $toDelete->getId();
        $manager->delete($toDelete, $admin);

        $data = $this->api('/changes?since='.rawurlencode($mark), self::$internalToken);
        self::assertResponseIsSuccessful();
        self::assertContains($toHide->getId(), array_column($data['removed'], 'id'));
        self::assertNotContains($toHide->getId(), array_column($data['changed'], 'id'));
        self::assertSame([$deletedId], array_column($data['deleted'], 'id'));
        self::assertSame('Схема сетевых розеток офиса', $data['deleted'][0]['title'], 'ключу «все документы» отдаём и название');
        self::assertStringContainsString('Рабочее место', (string) $data['deleted'][0]['section_path']);
        // Ключу «только открытые» — лишь идентификатор: удалить могли и внутренний документ.
        $publicView = $this->api('/changes?since='.rawurlencode($mark), self::$publicToken);
        self::assertSame([$deletedId], array_column($publicView['deleted'], 'id'));
        self::assertSame(['id', 'deleted_at'], array_keys($publicView['deleted'][0]));
        $this->api('/documents/'.$deletedId, self::$internalToken);
        self::assertResponseStatusCodeSame(404);

        // Повторная публикация → снова в changed (сущности после запросов получаем заново — EntityManager сброшен).
        $toHide = $this->document('ШБ-002');
        $manager->publish($toHide, $this->user('admin'));
        $data = $this->api('/changes?since='.rawurlencode($mark), self::$publicToken);
        self::assertContains($toHide->getId(), array_column($data['changed'], 'id'));
    }
}
