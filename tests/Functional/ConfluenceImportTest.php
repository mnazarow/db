<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\ConfluencePage;
use App\Entity\Document;
use App\Entity\Section;
use App\Repository\ConfluencePageRepository;
use App\Service\DocumentManager;
use App\Service\Http\HttpResponse;
use App\Service\Http\RecordingTransport;
use App\Service\Import\Confluence\StorageConverter;
use App\Service\PortalSettings;
use App\Tests\PortalTestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Импорт из Confluence: Server/Data Center (API v1, личный токен) и Cloud (API v2, почта и API-токен, курсорные
 * страницы ответа); страницы — документами, ветки — подразделами, вложения — файлами, изображения встроены,
 * ссылки ведут на импортированные документы; повторный импорт обновляет только изменившееся.
 */
final class ConfluenceImportTest extends PortalTestCase
{
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

    /** @var array<string, array{title: string, parent: ?string, version: int, body: string}> */
    private array $pages = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->pages = [
            '100' => ['title' => 'Главная', 'parent' => null, 'version' => 3, 'body' => '<p>Добро пожаловать.</p><ac:structured-macro ac:name="children"/>'],
            '101' => ['title' => 'Руководства', 'parent' => '100', 'version' => 1, 'body' => '<ac:structured-macro ac:name="children"/>'],
            '102' => ['title' => 'Монтаж насоса', 'parent' => '101', 'version' => 2, 'body' => self::richBody()],
            '103' => ['title' => 'Обслуживание', 'parent' => '101', 'version' => 1, 'body' => '<p>Раз в год&nbsp;&mdash; проверка.</p>'],
            '104' => ['title' => 'Пустая страница', 'parent' => '100', 'version' => 1, 'body' => ''],
        ];
    }

    protected function tearDown(): void
    {
        $this->http()->reset();
        static::getContainer()->get(PortalSettings::class)->setConfluence(['url' => '', 'token' => '']);
        parent::tearDown();
    }

    public function testServerSpaceImportsAsSectionsPagesAndAttachments(): void
    {
        $this->settings()->setConfluence(['url' => 'https://wiki.test/confluence/', 'auth' => 'token', 'token' => 'pat-secret']);
        $this->http()->setHandler($this->server(...));

        $this->loginAs('admin');
        $crawler = $this->client->request('GET', '/admin/import/confluence');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Документация', $crawler->filter('#cf-spaces')->html());
        self::assertSame('Bearer pat-secret', $this->http()->requests[0]['headers']['Authorization']);
        self::assertStringNotContainsString('pat-secret', $crawler->html(), 'токен на странице не показывается');

        $job = $this->runImport($crawler, ['space' => 'DOCS', 'section' => '0']);
        self::assertSame(5, $job['position']);
        self::assertSame(['sectionsNew' => 2, 'docsNew' => 4, 'attachments' => 1, 'errors' => 0], array_intersect_key($job['counters'], ['sectionsNew' => 0, 'docsNew' => 0, 'attachments' => 0, 'errors' => 0]));
        self::assertNull($job['error']);

        // Дерево: «Документация» / «Главная» (её документ и «Пустая страница») / «Руководства» (две страницы и вложение).
        $root = $this->em()->getRepository(Section::class)->findOneBy(['name' => 'Документация', 'parent' => null]);
        self::assertNotNull($root);
        $home = $this->em()->getRepository(Section::class)->findOneBy(['name' => 'Главная', 'parent' => $root]);
        $guides = $this->em()->getRepository(Section::class)->findOneBy(['name' => 'Руководства', 'parent' => $home]);
        self::assertNotNull($guides);
        $docs = static function (Section $s): array {
            $titles = array_map(static fn (Document $d): string => $d->getTitle(), static::getContainer()->get('doctrine')->getRepository(Document::class)->findBy(['section' => $s]));
            sort($titles);

            return $titles;
        };
        self::assertSame(['Главная', 'Пустая страница'], $docs($home));
        self::assertSame(['passport', 'Монтаж насоса', 'Обслуживание'], $docs($guides), 'страница «Руководства» без текста — только раздел; вложение .exe пропущено');

        $mount = $this->doc('Монтаж насоса');
        self::assertTrue($mount->isPage());
        self::assertTrue($mount->isPublished());
        $html = (string) $mount->getCurrentVersion()?->getContent();
        self::assertStringContainsString('<h2>Подготовка</h2>', $html);
        self::assertStringContainsString('<li>Проверить комплект</li>', $html);
        self::assertStringContainsString('<td colspan="2">', $html);
        self::assertStringContainsString('<pre><code>pump --start &amp;&amp; echo ok</code></pre>', $html);
        self::assertStringContainsString('<strong>Внимание</strong>', $html);
        self::assertStringContainsString('src="data:image/png;base64,', $html);
        $decoded = html_entity_decode($html, \ENT_QUOTES | \ENT_HTML5);
        self::assertStringContainsString('href="/confluence/page?space=DOCS&title='.rawurlencode('Обслуживание').'"', $decoded);
        self::assertStringContainsString('href="/confluence/attachment?page=102&file=passport.pdf"', $decoded);
        self::assertStringContainsString('☑ Залить воду', $html);
        self::assertStringContainsString('<strong>[ГОТОВО]</strong>', $html);
        self::assertStringContainsString('страница в Confluence', $html);
        self::assertStringNotContainsString('ac:', $html);
        self::assertStringContainsString("Раз в год\u{00A0}— проверка", (string) $this->doc('Обслуживание')->getCurrentVersion()?->getContent(), 'именованные сущности HTML (&nbsp;, &mdash;) — символами');
        self::assertStringContainsString('пустая', (string) $this->doc('Пустая страница')->getCurrentVersion()?->getContent());

        // Ссылки между страницами и на вложения ведут на импортированные документы.
        $this->client->request('GET', '/confluence/page?space=DOCS&title='.rawurlencode('Обслуживание'));
        self::assertResponseRedirects('/documents/'.$this->doc('Обслуживание')->getId());
        $this->client->request('GET', '/confluence/attachment?page=102&file=passport.pdf');
        self::assertResponseRedirects('/documents/'.$this->doc('passport')->getId());
        $this->client->request('GET', '/confluence/attachment?page=102&file=schema.png');
        self::assertResponseRedirects('/documents/'.$mount->getId(), null, 'изображение не импортировано отдельно — ведёт на страницу');
        $this->client->request('GET', '/confluence/page?space=DOCS&title='.rawurlencode('Ещё не импортирована'));
        self::assertResponseRedirects('https://wiki.test/confluence/display/DOCS/'.str_replace('%20', '+', rawurlencode('Ещё не импортирована')));

        // Повторный импорт: без изменений — ничего нового; новая версия страницы — новая версия документа.
        $this->pages['103']['version'] = 2;
        $this->pages['103']['body'] = '<p>Раз в полгода.</p>';
        $crawler = $this->client->request('GET', '/admin/import/confluence');
        $job = $this->runImport($crawler, ['space' => 'DOCS', 'section' => '0']);
        self::assertSame(0, $job['counters']['docsNew']);
        self::assertSame(1, $job['counters']['versionsNew']);
        self::assertSame(0, $job['counters']['sectionsNew']);
        $service = $this->doc('Обслуживание');
        self::assertSame(2, $service->getCurrentVersion()?->getNumber());
        self::assertStringContainsString('Раз в полгода', (string) $service->getCurrentVersion()?->getContent());

        // Удалённый в портале документ при повторном импорте не возвращается.
        static::getContainer()->get(DocumentManager::class)->delete($this->doc('Пустая страница'), $this->user('admin'));
        $this->pages['104']['version'] = 2;
        $crawler = $this->client->request('GET', '/admin/import/confluence');
        $this->runImport($crawler, ['space' => 'DOCS', 'section' => (string) $root->getId()]);
        self::assertNull($this->em()->getRepository(Document::class)->findOneBy(['title' => 'Пустая страница']));
        self::assertCount(5, static::getContainer()->get(ConfluencePageRepository::class)->findBy(['kind' => ConfluencePage::KIND_PAGE]));
    }

    public function testCloudSpaceFlatFromConsoleAndErrors(): void
    {
        $this->settings()->setConfluence(['url' => 'https://acme.atlassian.net', 'auth' => 'cloud', 'user' => 'it@acme.example', 'token' => 'api-token']);
        $this->http()->setHandler($this->cloud(...));
        $app = new Application(static::$kernel);
        $app->setAutoExit(false);
        $out = new BufferedOutput();
        $target = static::getContainer()->get(\App\Service\SectionManager::class)->create('Вики Cloud', null, null, $this->user('admin'));
        $code = $app->run(new ArrayInput(['command' => 'app:import:confluence', 'space' => 'DOCS', '--section' => (string) $target->getId(), '--flat' => true, '--no-attachments' => true, '--draft' => true, '--no-interaction' => true, '--verbose' => true]), $out);
        $text = $out->fetch();
        self::assertSame(0, $code, $text);
        self::assertSame('Basic '.base64_encode('it@acme.example:api-token'), $this->http()->requests[0]['headers']['Authorization']);
        self::assertStringStartsWith('https://acme.atlassian.net/wiki/api/v2/spaces?keys=DOCS', $this->http()->requests[0]['url']);
        self::assertTrue(\in_array(true, array_map(static fn (array $r): bool => str_contains($r['url'], 'cursor=next1'), $this->http()->requests), true), 'вторая страница выдачи по курсору');
        $this->em()->clear();
        $root = $this->em()->getRepository(Section::class)->find($target->getId());
        self::assertCount(0, $this->em()->getRepository(Section::class)->findBy(['parent' => $root]), 'без подразделов');
        $docs = [];
        foreach ($this->em()->getRepository(Document::class)->findBy(['section' => $root]) as $d) {
            $docs[$d->getTitle()] = $d;
        }
        ksort($docs);
        self::assertSame(['Главная', 'Монтаж насоса', 'Обслуживание', 'Пустая страница', 'Руководства'], array_keys($docs), 'в плоском режиме и страница без текста — документ; без вложений');
        self::assertFalse($docs['Монтаж насоса']->isPublished(), 'черновики');
        self::assertStringContainsString('src="data:image/png;base64,', (string) $docs['Монтаж насоса']->getLatestVersion()?->getContent(), 'изображения встраиваются и без вложений');
        self::assertStringContainsString('https://acme.atlassian.net/wiki/spaces/DOCS/pages/102', (string) $docs['Монтаж насоса']->getLatestVersion()?->getContent());

        // Неверный токен — понятная ошибка, задание не создаётся.
        $this->http()->setHandler(static fn (): HttpResponse => new HttpResponse(401, '{"message":"Unauthorized"}'));
        $code = $app->run(new ArrayInput(['command' => 'app:import:confluence', 'space' => 'DOCS', '--no-interaction' => true, '--verbose' => true]), $out);
        self::assertSame(1, $code);
        self::assertStringContainsString('не принял логин или токен', $out->fetch());
    }

    public function testStorageConverterKeepsTextOfBrokenMarkup(): void
    {
        $converter = new StorageConverter();
        $none = static fn (): ?string => null;
        $link = static fn (string $s, string $t): string => '/p/'.$t;
        self::assertSame('<p>Текст</p><p>вторая строка</p>', $converter->convert('<p>Текст<br>вторая строка</p>', 'X', $none, $link, $link(...)), 'не XML (br без закрытия) — переносится текст');
        self::assertSame('<p><a href="https://example.ru">https://example.ru</a></p>', $converter->convert('<p><a href="https://example.ru"></a></p>', 'X', $none, $link, $link(...)));
        self::assertSame('<p>x</p>', $converter->convert('<p onclick="alert(1)">x<script>alert(1)</script></p>', 'X', $none, $link, $link(...)));
    }

    /** Задание импорта через форму и страницу задания (порции до конца). @param array<string, string> $fields @return array<string, mixed> */
    private function runImport(\Symfony\Component\DomCrawler\Crawler $crawler, array $fields): array
    {
        $form = $crawler->filter('form[action$="/admin/import/confluence/start"]')->form($fields);
        $this->client->submit($form);
        self::assertResponseRedirects();
        $page = $this->client->followRedirect();
        $url = (string) $page->filter('[data-import-job]')->attr('data-import-job');
        $token = (string) $page->filter('[data-import-job]')->attr('data-token');
        for ($i = 0; $i < 20; ++$i) {
            $this->client->request('POST', $url, ['_token' => $token]);
            $data = json_decode((string) $this->client->getResponse()->getContent(), true);
            if ($data['finished'] ?? false) {
                $this->em()->clear();

                return $data;
            }
        }
        self::fail('Импорт не завершился');
    }

    private function doc(string $title): Document
    {
        $this->em()->clear();
        $doc = $this->em()->getRepository(Document::class)->findOneBy(['title' => $title]);
        self::assertNotNull($doc, $title);

        return $doc;
    }

    /** Confluence Server / Data Center, API v1. */
    private function server(string $method, string $url): HttpResponse
    {
        $p = parse_url($url);
        parse_str($p['query'] ?? '', $q);
        $path = (string) $p['path'];
        $base = '/confluence';
        if ($path === $base.'/rest/api/space') {
            return self::json(['results' => [['key' => 'DOCS', 'name' => 'Документация'], ['key' => 'HR', 'name' => 'Кадры']], '_links' => []]);
        }
        if ($path === $base.'/rest/api/space/DOCS') {
            return self::json(['key' => 'DOCS', 'name' => 'Документация']);
        }
        if ($path === $base.'/rest/api/content' && 'DOCS' === ($q['spaceKey'] ?? '')) {
            $results = [];
            foreach ($this->pages as $id => $page) {
                $results[] = ['id' => (string) $id, 'title' => $page['title'], 'version' => ['number' => $page['version']], 'ancestors' => $this->ancestors((string) $id), '_links' => ['webui' => '/pages/viewpage.action?pageId='.$id]];
            }
            // Две страницы выдачи (start/limit, _links.next от базы Confluence).
            $start = (int) ($q['start'] ?? 0);
            $chunk = \array_slice($results, $start, 3);

            return self::json(['results' => $chunk, '_links' => $start + 3 < \count($results) ? ['next' => '/rest/api/content?spaceKey=DOCS&start='.($start + 3)] : []]);
        }
        if (preg_match('~^'.$base.'/rest/api/content/(\d+)$~', $path, $m)) {
            $page = $this->pages[$m[1]];

            return self::json(['id' => $m[1], 'title' => $page['title'], 'version' => ['number' => $page['version']], 'body' => ['storage' => ['value' => $page['body']]]]);
        }
        if (preg_match('~^'.$base.'/rest/api/content/(\d+)/child/attachment$~', $path, $m)) {
            return self::json(['results' => '102' === $m[1] ? [
                ['id' => 'att1', 'title' => 'schema.png', 'metadata' => ['mediaType' => 'image/png'], 'extensions' => ['fileSize' => 68], 'version' => ['number' => 1], '_links' => ['download' => '/download/attachments/102/schema.png?version=1']],
                ['id' => 'att2', 'title' => 'passport.pdf', 'metadata' => ['mediaType' => 'application/pdf'], 'extensions' => ['fileSize' => 30], 'version' => ['number' => 1], '_links' => ['download' => '/download/attachments/102/passport.pdf?version=1']],
                ['id' => 'att3', 'title' => 'virus.exe', 'metadata' => ['mediaType' => 'application/octet-stream'], 'extensions' => ['fileSize' => 10], 'version' => ['number' => 1], '_links' => ['download' => '/download/attachments/102/virus.exe?version=1']],
            ] : [], '_links' => []]);
        }
        if (str_starts_with($path, $base.'/download/attachments/102/')) {
            return str_ends_with($path, '.png') ? new HttpResponse(200, (string) base64_decode(self::PNG)) : new HttpResponse(200, "%PDF-1.4\n% паспорт\n%%EOF\n");
        }

        return new HttpResponse(404, '{"message":"not found '.$path.'"}');
    }

    /** Confluence Cloud, API v2 (курсорные страницы ответа, ссылки от корня сайта /wiki/…). */
    private function cloud(string $method, string $url): HttpResponse
    {
        $p = parse_url($url);
        parse_str($p['query'] ?? '', $q);
        $path = (string) $p['path'];
        if ('/wiki/api/v2/spaces' === $path) {
            return self::json(['results' => [['id' => '9001', 'key' => 'DOCS', 'name' => 'Документация']], '_links' => []]);
        }
        if ('/wiki/api/v2/spaces/9001/pages' === $path) {
            $all = [];
            foreach ($this->pages as $id => $page) {
                $all[] = ['id' => (string) $id, 'title' => $page['title'], 'parentId' => $page['parent'], 'parentType' => null !== $page['parent'] ? 'page' : null, 'position' => (int) $id, 'version' => ['number' => $page['version']], '_links' => ['webui' => '/spaces/DOCS/pages/'.$id]];
            }
            $second = 'next1' === ($q['cursor'] ?? '');

            return self::json(['results' => $second ? \array_slice($all, 2) : \array_slice($all, 0, 2), '_links' => $second ? [] : ['next' => '/wiki/api/v2/spaces/9001/pages?limit=250&cursor=next1']]);
        }
        if (preg_match('~^/wiki/api/v2/pages/(\d+)$~', $path, $m)) {
            $page = $this->pages[$m[1]];

            return self::json(['id' => $m[1], 'title' => $page['title'], 'version' => ['number' => $page['version']], 'body' => ['storage' => ['value' => $page['body'], 'representation' => 'storage']]]);
        }
        if (preg_match('~^/wiki/api/v2/pages/(\d+)/attachments$~', $path, $m)) {
            return self::json(['results' => '102' === $m[1] ? [['id' => 'att1', 'title' => 'schema.png', 'mediaType' => 'image/png', 'fileSize' => 68, 'version' => ['number' => 1], 'downloadLink' => '/download/attachments/102/schema.png?version=1']] : [], '_links' => []]);
        }
        if ('/wiki/download/attachments/102/schema.png' === $path) {
            return new HttpResponse(200, (string) base64_decode(self::PNG));
        }

        return new HttpResponse(404, '{"message":"not found '.$path.'"}');
    }

    /** @return list<array{id: string}> */
    private function ancestors(string $id): array
    {
        $out = [];
        for ($parent = $this->pages[$id]['parent']; null !== $parent; $parent = $this->pages[$parent]['parent']) {
            array_unshift($out, ['id' => $parent, 'title' => $this->pages[$parent]['title']]);
        }

        return $out;
    }

    private static function richBody(): string
    {
        return '<h2>Подготовка</h2><ul><li>Проверить комплект</li><li>Отключить питание</li></ul>'
            .'<table><tbody><tr><th>Модель</th><th>Напор</th></tr><tr><td colspan="2" style="color:red">НС-40</td></tr></tbody></table>'
            .'<ac:structured-macro ac:name="code" ac:schema-version="1"><ac:parameter ac:name="language">bash</ac:parameter><ac:plain-text-body><![CDATA[pump --start && echo ok]]></ac:plain-text-body></ac:structured-macro>'
            .'<ac:structured-macro ac:name="warning"><ac:rich-text-body><p>Не включать без воды.</p></ac:rich-text-body></ac:structured-macro>'
            .'<p><ac:image ac:width="300"><ri:attachment ri:filename="schema.png"/></ac:image></p>'
            .'<p>См. <ac:link><ri:page ri:content-title="Обслуживание"/><ac:plain-text-link-body><![CDATA[обслуживание]]></ac:plain-text-link-body></ac:link> и <ac:link><ri:attachment ri:filename="passport.pdf"/></ac:link>.</p>'
            .'<ac:task-list><ac:task><ac:task-id>1</ac:task-id><ac:task-status>complete</ac:task-status><ac:task-body>Залить воду</ac:task-body></ac:task></ac:task-list>'
            .'<p><ac:structured-macro ac:name="status"><ac:parameter ac:name="title">ГОТОВО</ac:parameter></ac:structured-macro> <ac:emoticon ac:name="smile"/></p>'
            .'<ac:structured-macro ac:name="toc"/>';
    }

    private static function json(array $data): HttpResponse
    {
        return new HttpResponse(200, (string) json_encode($data, \JSON_UNESCAPED_UNICODE));
    }

    private function http(): RecordingTransport
    {
        return static::getContainer()->get(RecordingTransport::class);
    }

    private function settings(): PortalSettings
    {
        return static::getContainer()->get(PortalSettings::class);
    }
}
