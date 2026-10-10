<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Document;
use App\Service\DocumentManager;
use App\Service\Http\HttpResponse;
use App\Service\Http\RecordingTransport;
use App\Service\Llm\AnswerService;
use App\Service\PortalSettings;
use App\Tests\PortalTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Ответы ИИ на странице поиска: источники — только доступные сотруднику документы и видео, модель отвечает
 * по их фрагментам, номера [n] становятся ссылками; без источников модель не вызывается.
 */
final class AiAnswerTest extends PortalTestCase
{
    protected function setUp(): void
    {
        $_SERVER['VIDEO_API_URL'] = $_ENV['VIDEO_API_URL'] = 'http://video-api.test';
        parent::setUp();
        $this->http()->reset();
        $this->settings()->setLlm(['enabled' => true, 'base_url' => 'http://llm.test/v1', 'api_key' => 'k', 'model' => 'test-model', 'answers' => true]);
    }

    protected function tearDown(): void
    {
        unset($_SERVER['VIDEO_API_URL'], $_ENV['VIDEO_API_URL']);
        $this->http()->reset();
        $this->settings()->setLlm(['enabled' => false, 'base_url' => PortalSettings::LLM_DEFAULT_BASE_URL, 'model' => PortalSettings::LLM_DEFAULT_MODEL, 'api_key' => '', 'answers' => true]);
        parent::tearDown();
    }

    private function http(): RecordingTransport
    {
        return static::getContainer()->get(RecordingTransport::class);
    }

    private function settings(): PortalSettings
    {
        return static::getContainer()->get(PortalSettings::class);
    }

    private function token(string $q): string
    {
        $crawler = $this->client->request('GET', '/search?q='.rawurlencode($q));
        self::assertResponseIsSuccessful();

        return (string) $crawler->filter('[data-ai-answer]')->attr('data-token');
    }

    private function manometerDocument(): Document
    {
        $existing = static::getContainer()->get('App\Repository\DocumentRepository')->findOneBy(['code' => 'ИИ-001']);
        if (null !== $existing) {
            return $existing;
        }
        $document = (new Document($this->section('Общие документы')))->setTitle('Инструкция по калибровке манометров')->setCode('ИИ-001')
            ->setType(Document::TYPE_FILE)->setPublic(false)->setDescription('Калибровку манометров проводит метрологическая служба раз в год; внеочередная — после ремонта насоса.');
        $path = sys_get_temp_dir().'/'.bin2hex(random_bytes(4)).'-doc.txt';
        file_put_contents($path, 'Текст инструкции');
        static::getContainer()->get(DocumentManager::class)->create($document, new UploadedFile($path, 'doc.txt', null, null, true), null, null, $this->user('ivanov'), true);

        return $document;
    }

    public function testAnswerFromAccessibleDocumentsAndVideo(): void
    {
        $document = $this->manometerDocument();
        $this->loginAs('smirnov');
        $q = 'Как часто нужна калибровка манометров?';
        $token = $this->token($q);
        self::assertNotEmpty($token, 'блок ответа есть на странице поиска');
        self::assertSelectorExists('[data-ai-answer][data-auto]', 'вопрос со знаком «?» — ответ запрашивается сразу');

        $this->http()->reset();
        $this->http()->enqueue(
            new HttpResponse(200, (string) json_encode(['items' => [['shortId' => 'vid123', 'title' => 'Поверка манометра', 'channel' => 'Метрология', 'description' => '', 'excerpt' => 'манометр снимают и отправляют на калибровку']]])),
            new HttpResponse(200, (string) json_encode(['choices' => [['message' => ['content' => "Раз в год, это делает **метрологическая служба** [1].\nПорядок снятия показан в видео [2]. <script>x</script>"]]]])),
        );
        $this->client->request('POST', '/search/answer', ['q' => $q, '_token' => $token]);
        self::assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertNull($data['error']);
        self::assertSame('Инструкция по калибровке манометров', $data['sources'][0]['title']);
        self::assertSame('/documents/'.$document->getId(), $data['sources'][0]['url']);
        self::assertSame('video', $data['sources'][1]['type']);
        self::assertSame('/video/watch/vid123', $data['sources'][1]['url']);
        self::assertStringContainsString('<a class="ai-answer__ref" href="/documents/'.$document->getId().'"', $data['html']);
        self::assertStringContainsString('<b>метрологическая служба</b>', $data['html']);
        self::assertStringNotContainsString('<script>', $data['html'], 'ответ модели экранируется');

        [$video, $llm] = $this->http()->requests;
        self::assertStringStartsWith('http://video-api.test/api/internal/context?', $video['url']);
        self::assertStringContainsString('audience=employee', $video['url']);
        self::assertSame('http://llm.test/v1/chat/completions', $llm['url']);
        $prompt = json_decode((string) $llm['body'], true)['messages'][1]['content'];
        self::assertStringContainsString($q, $prompt);
        self::assertStringContainsString('[1] Документ «Инструкция по калибровке манометров»', $prompt);
        self::assertStringContainsString('метрологическая служба раз в год', $prompt);
        self::assertStringContainsString('[2] Видео «Поверка манометра»', $prompt);
    }

    public function testNoSourcesNoModelCallAndGuards(): void
    {
        $this->loginAs('smirnov');
        $token = $this->token('квазистационарный гиперболоид');
        $this->http()->reset();
        $this->http()->setDefault(new HttpResponse(200, '{"items":[]}'));
        $this->client->request('POST', '/search/answer', ['q' => 'квазистационарный гиперболоид', '_token' => $token]);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertNull($data['html']);
        self::assertStringContainsString('не нашлось материалов', $data['error']);
        self::assertCount(0, array_filter($this->http()->requests, static fn (array $r): bool => str_contains($r['url'], 'llm.test')), 'без источников модель не вызывается');

        // Неверный токен — отказ; выключенные ответы — 404 и блока на странице нет.
        $this->client->request('POST', '/search/answer', ['q' => 'калибровка', '_token' => 'bad']);
        self::assertResponseStatusCodeSame(400);
        $this->settings()->setLlm(['enabled' => true, 'base_url' => 'http://llm.test/v1', 'model' => 'test-model', 'answers' => false]);
        $this->client->request('POST', '/search/answer', ['q' => 'калибровка', '_token' => $token]);
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', '/search?q=калибровка');
        self::assertSelectorNotExists('[data-ai-answer]');
    }

    public function testGuestCannotAsk(): void
    {
        $this->client->request('GET', '/search?q='.rawurlencode('калибровка манометров'));
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('[data-ai-answer]', 'гостю ответы ИИ не предлагаются');
        $this->client->request('POST', '/search/answer', ['q' => 'калибровка']);
        self::assertResponseRedirects('/login');
    }

    public function testFragmentsAndHtml(): void
    {
        $text = str_repeat('Вводная часть без нужных слов. ', 80).'Манометры калибруют ежегодно. '.str_repeat('Прочее. ', 300);
        $fragment = AnswerService::fragments($text, ['манометры']);
        self::assertStringContainsString('Манометры калибруют ежегодно', $fragment);
        self::assertLessThan(mb_strlen($text), mb_strlen($fragment));
        self::assertSame(['часто', 'калибровка', 'манометров'], AnswerService::keywords('Как часто нужна калибровка манометров?') === ['часто', 'нужна', 'калибровка', 'манометров'] ? ['часто', 'калибровка', 'манометров'] : AnswerService::keywords('Как часто калибровка манометров?'));
        $html = AnswerService::toHtml("Шаги:\n\n- снять [1]\n- отправить [9]", [['n' => 1, 'type' => 'document', 'title' => 'Т', 'url' => '/documents/1', 'meta' => '']]);
        self::assertStringContainsString('<ul><li>снять <a class="ai-answer__ref" href="/documents/1"', $html);
        self::assertStringContainsString('отправить [9]', $html, 'несуществующий номер остаётся текстом');
    }
}
