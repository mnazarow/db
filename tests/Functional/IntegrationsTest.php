<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Document;
use App\Entity\DocumentEvent;
use App\Repository\ApiKeyRepository;
use App\Repository\DocumentEventRepository;
use App\Repository\DocumentRepository;
use App\Service\DocumentManager;
use App\Service\Http\HttpResponse;
use App\Service\Http\RecordingTransport;
use App\Service\Integration\WebhookNotifier;
use App\Service\Llm\DocumentDescriber;
use App\Service\Llm\LlmClient;
use App\Service\Llm\LlmException;
use App\Service\PortalSettings;
use App\Tests\PortalTestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Интеграции: описания документов через LLM (клиент, описатель, команда, карточка, панель администратора),
 * webhook об изменениях и управление ключами API в панели администратора.
 */
final class IntegrationsTest extends PortalTestCase
{
    private function settings(): PortalSettings
    {
        return static::getContainer()->get(PortalSettings::class);
    }

    private function http(): RecordingTransport
    {
        return static::getContainer()->get(RecordingTransport::class);
    }

    private function enableLlm(array $override = []): void
    {
        $this->settings()->setLlm(array_merge([
            'enabled' => true,
            'base_url' => 'http://llm.test/v1/',
            'api_key' => 'sk-test-secret-key-1234',
            'model' => 'test-model',
            'prompt' => '',
            'max_input_chars' => 3000,
            'timeout' => 30,
            'auto_describe' => false,
            'temperature' => '0.1',
        ], $override));
    }

    private static function completion(string $text): HttpResponse
    {
        return new HttpResponse(200, json_encode(['id' => 'x', 'choices' => [['message' => ['role' => 'assistant', 'content' => $text]]]], \JSON_UNESCAPED_UNICODE));
    }

    protected function tearDown(): void
    {
        $this->http()->reset();
        $this->settings()->setLlm(['enabled' => false, 'model' => PortalSettings::LLM_DEFAULT_MODEL, 'api_key' => '']);
        $this->settings()->setWebhook(false, '', '');
        parent::tearDown();
    }

    public function testSettingsAndClient(): void
    {
        $settings = $this->settings();
        self::assertFalse($settings->isLlmEnabled());
        $llm = $settings->llm();
        self::assertSame(PortalSettings::LLM_DEFAULT_BASE_URL, $llm['base_url']);
        self::assertSame(PortalSettings::LLM_DEFAULT_PROMPT, $llm['prompt']);
        self::assertSame('sk-t…1234', PortalSettings::mask('sk-test-secret-key-1234'));
        self::assertSame('••••', PortalSettings::mask('abcd'));

        $this->enableLlm();
        $llm = $settings->llm();
        self::assertTrue($llm['enabled']);
        self::assertSame('http://llm.test/v1', $llm['base_url'], 'завершающий слэш убирается');
        self::assertSame(3000, $llm['max_input_chars']);
        self::assertEqualsWithDelta(0.1, $llm['temperature'], 0.0001);

        try {
            $settings->setLlm(['base_url' => 'ftp://x', 'model' => 'm']);
            self::fail('ожидалось исключение');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('http', $e->getMessage());
        }
        try {
            $settings->setLlm(['base_url' => 'http://ok', 'model' => '']);
            self::fail('ожидалось исключение');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('модели', $e->getMessage());
        }

        $client = static::getContainer()->get(LlmClient::class);
        $this->http()->enqueue(self::completion('  Готово. '));
        self::assertSame('Готово.', $client->complete('Система', 'Вопрос', 10));
        $request = $this->http()->last();
        self::assertSame('POST', $request['method']);
        self::assertSame('http://llm.test/v1/chat/completions', $request['url']);
        self::assertSame('Bearer sk-test-secret-key-1234', $request['headers']['Authorization']);
        self::assertSame(30, $request['timeout']);
        $body = json_decode((string) $request['body'], true);
        self::assertSame('test-model', $body['model']);
        self::assertSame('system', $body['messages'][0]['role']);
        self::assertSame('Вопрос', $body['messages'][1]['content']);
        self::assertSame(10, $body['max_tokens']);

        $this->http()->enqueue(new HttpResponse(401, '{"error":{"message":"Incorrect API key provided"}}'));
        try {
            $client->complete('s', 'u');
            self::fail('ожидалось исключение');
        } catch (LlmException $e) {
            self::assertStringContainsString('HTTP 401', $e->getMessage());
            self::assertStringContainsString('Incorrect API key', $e->getMessage());
        }
        $this->http()->enqueue(new HttpResponse(0, '', 'Could not resolve host'));
        try {
            $client->complete('s', 'u');
            self::fail('ожидалось исключение');
        } catch (LlmException $e) {
            self::assertStringContainsString('Could not resolve host', $e->getMessage());
        }
        $this->http()->enqueue(new HttpResponse(200, '{"choices":[]}'));
        try {
            $client->complete('s', 'u');
            self::fail('ожидалось исключение');
        } catch (LlmException $e) {
            self::assertStringContainsString('пустой ответ', $e->getMessage());
        }
        $this->http()->enqueue(self::completion('готово'));
        $test = $client->test();
        self::assertTrue($test['ok']);
        self::assertStringContainsString('test-model', $test['message']);

        // Модели, не принимающие max_tokens/temperature: запрос повторяется без спорных параметров.
        $this->http()->reset();
        $this->http()->enqueue(
            new HttpResponse(400, '{"error":{"message":"Unsupported parameter: \'max_tokens\' is not supported with this model. Use \'max_completion_tokens\' instead."}}'),
            self::completion('Ответ после повтора.'),
        );
        self::assertSame('Ответ после повтора.', $client->complete('s', 'u', 100));
        self::assertCount(2, $this->http()->requests);
        $retry = json_decode((string) $this->http()->last()['body'], true);
        self::assertArrayNotHasKey('max_tokens', $retry);
        self::assertSame(100, $retry['max_completion_tokens']);

        $this->http()->reset();
        $this->http()->enqueue(
            new HttpResponse(400, '{"error":{"message":"temperature does not support 0.1 with this model"}}'),
            self::completion('Без температуры.'),
        );
        self::assertSame('Без температуры.', $client->complete('s', 'u', 100));
        self::assertArrayNotHasKey('temperature', json_decode((string) $this->http()->last()['body'], true));

        // Ошибка 400 без понятной причины не повторяется.
        $this->http()->reset();
        $this->http()->enqueue(new HttpResponse(400, '{"error":{"message":"model not found"}}'));
        try {
            $client->complete('s', 'u');
            self::fail('ожидалось исключение');
        } catch (LlmException $e) {
            self::assertStringContainsString('model not found', $e->getMessage());
        }
        self::assertCount(1, $this->http()->requests, 'повтора быть не должно');
    }

    public function testDescriberAndCommand(): void
    {
        $describer = static::getContainer()->get(DocumentDescriber::class);
        $documents = static::getContainer()->get(DocumentRepository::class);
        $doc = $this->document('ПОЛ-003'); // без описания, PDF
        self::assertNull($doc->getDescription());
        self::assertSame(DocumentDescriber::SKIP_DISABLED, $describer->skipReason($doc));

        $this->enableLlm();
        self::assertNull($describer->skipReason($doc));
        $prompt = $describer->buildPrompt($doc, 3000);
        self::assertStringContainsString('Название: Правила внутреннего трудового распорядка', $prompt);
        self::assertStringContainsString('Обозначение/номер: ПОЛ-003', $prompt);
        self::assertStringContainsString('Раздел: Общие документы / Регламенты и политики', $prompt);
        self::assertStringContainsString('Файл: ', $prompt);

        $this->http()->enqueue(self::completion("Описание: «Документ устанавливает правила внутреннего трудового распорядка компании.\n\nОн предназначен для всех сотрудников.»"));
        $text = $describer->describe($doc, $this->user('admin'), '10.0.0.1');
        self::assertSame("Документ устанавливает правила внутреннего трудового распорядка компании.\n\nОн предназначен для всех сотрудников.", $text);
        self::assertSame($text, $doc->getDescription());
        self::assertTrue($doc->isDescriptionGenerated());
        self::assertSame(Document::DESCRIPTION_LLM, $doc->getDescriptionSource());
        self::assertNotNull($doc->getDescriptionGeneratedAt());
        $events = static::getContainer()->get(DocumentEventRepository::class)->findForDocument($doc, 5, DocumentEvent::UPDATE);
        self::assertSame('llm', $events[0]->getDetails()['description'] ?? null);
        self::assertSame('test-model', $events[0]->getDetails()['model'] ?? null);
        $body = json_decode((string) $this->http()->last()['body'], true);
        self::assertStringContainsString('ПОЛ-003', $body['messages'][1]['content']);
        self::assertSame(PortalSettings::LLM_DEFAULT_PROMPT, $body['messages'][0]['content']);

        // Сгенерированное описание: повторно — только в режиме regenerate; ручная правка снимает пометку.
        self::assertSame(DocumentDescriber::SKIP_EXISTS, $describer->skipReason($doc));
        self::assertNull($describer->skipReason($doc, true));
        $doc->setDescription($doc->getDescription());
        self::assertTrue($doc->isDescriptionGenerated(), 'то же значение — пометка остаётся');
        $doc->setDescription('Отредактировано вручную.');
        self::assertFalse($doc->isDescriptionGenerated());
        self::assertSame(Document::DESCRIPTION_MANUAL, $doc->getDescriptionSource());
        self::assertNull($doc->getDescriptionGeneratedAt());
        $this->em()->flush();
        self::assertSame(DocumentDescriber::SKIP_MANUAL, $describer->skipReason($doc, true));
        self::assertNull($describer->skipReason($doc, true, true));

        // Пустой/ошибочный ответ модели — исключение, описание не меняется.
        $this->http()->enqueue(self::completion('   '));
        try {
            $describer->describe($doc);
            self::fail('ожидалось исключение');
        } catch (LlmException) {
            self::assertSame('Отредактировано вручную.', $doc->getDescription());
        }

        // Команда: dry-run и обработка порции документов без описания.
        $before = $documents->countDescriptions();
        self::assertGreaterThan(2, $before['without']);
        $app = new Application(static::$kernel);
        $app->setAutoExit(false);
        $out = new BufferedOutput();
        self::assertSame(0, $app->run(new ArrayInput(['command' => 'app:documents:describe', '--dry-run' => true, '--limit' => '3', '--verbose' => 1]), $out));
        self::assertStringContainsString('изменения не внесены', $out->fetch());
        self::assertSame($before, $documents->countDescriptions());

        $this->http()->enqueue(self::completion('Первое описание.'), self::completion('Второе описание.'));
        $out = new BufferedOutput();
        self::assertSame(0, $app->run(new ArrayInput(['command' => 'app:documents:describe', '--limit' => '2', '--verbose' => 1]), $out));
        $output = $out->fetch();
        self::assertStringContainsString('Сформировано описаний: 2', $output);
        $after = $documents->countDescriptions();
        self::assertSame($before['without'] - 2, $after['without']);
        self::assertSame($before['llm'] + 2, $after['llm']);

        // Пять ошибок подряд — остановка с кодом ошибки.
        for ($i = 0; $i < 5; ++$i) {
            $this->http()->enqueue(new HttpResponse(500, 'boom'));
        }
        $out = new BufferedOutput();
        self::assertSame(1, $app->run(new ArrayInput(['command' => 'app:documents:describe', '--limit' => '10', '--verbose' => 1]), $out));
        self::assertStringContainsString('Пять ошибок подряд', $out->fetch());
    }

    public function testDescribeFromDocumentCardAndAutoDescribe(): void
    {
        $doc = $this->document('ОТ-003');
        $this->loginAs('sidorov'); // не модератор раздела «Охрана труда»
        $this->client->request('POST', '/documents/'.$doc->getId().'/describe', ['_token' => 'x']);
        self::assertResponseStatusCodeSame(403);

        $this->loginAs('ivanov');
        $crawler = $this->client->request('GET', '/documents/'.$doc->getId());
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('Описать через ИИ', $crawler->text(), 'кнопка скрыта, пока LLM выключена');

        $this->enableLlm();
        $crawler = $this->client->request('GET', '/documents/'.$doc->getId());
        self::assertStringContainsString('Описать через ИИ', $crawler->text());
        $token = $crawler->filter('form[action$="/describe"] input[name=_token]')->attr('value');
        $this->http()->enqueue(self::completion('План эвакуации второго этажа офиса.'));
        $this->client->request('POST', '/documents/'.$doc->getId().'/describe', ['_token' => $token]);
        self::assertResponseRedirects('/documents/'.$doc->getId());
        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('План эвакуации второго этажа офиса.', $crawler->filter('.doc-description')->text());
        self::assertSame(1, $crawler->filter('.ai-badge')->count(), 'пометка «ИИ» у сгенерированного описания');
        self::assertStringContainsString('Описать через ИИ заново', $crawler->text());

        // Автоописание при создании документа через форму.
        $this->enableLlm(['auto_describe' => true]);
        $section = $this->section('Охрана труда');
        $crawler = $this->client->request('GET', '/documents/new?section='.$section->getId().'&type=page');
        self::assertResponseIsSuccessful();
        $form = $crawler->filter('form[name=document]')->form();
        $values = $form->getPhpValues();
        $values['document']['title'] = 'Инструкция по работе на высоте';
        $values['document']['content'] = '<p>При работе на высоте более 1,8 м обязательна страховочная привязь.</p>';
        $values['document']['publish'] = '1';
        $this->http()->enqueue(self::completion('Инструкция определяет требования безопасности при работе на высоте.'));
        $this->client->request($form->getMethod(), $form->getUri(), $values, $form->getPhpFiles());
        self::assertResponseRedirects();
        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('сформировано языковой моделью', $crawler->text());
        self::assertStringContainsString('требования безопасности при работе на высоте', $crawler->filter('.doc-description')->text());
        $body = json_decode((string) $this->http()->last()['body'], true);
        self::assertStringContainsString('страховочная привязь', $body['messages'][1]['content'], 'текст страницы передаётся модели');

        // Ошибка модели при автоописании не мешает созданию документа.
        $crawler = $this->client->request('GET', '/documents/new?section='.$section->getId().'&type=page');
        $form = $crawler->filter('form[name=document]')->form();
        $values = $form->getPhpValues();
        $values['document']['title'] = 'Инструкция без описания';
        $values['document']['content'] = '<p>Текст.</p>';
        $this->http()->enqueue(new HttpResponse(503, 'overloaded'));
        $this->client->request($form->getMethod(), $form->getUri(), $values, $form->getPhpFiles());
        self::assertResponseRedirects();
        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('Описание не сформировано', $crawler->text());
        self::assertStringContainsString('Инструкция без описания', $crawler->text());
    }

    public function testAdminIntegrationsPage(): void
    {
        $this->loginAs('ivanov');
        $this->client->request('GET', '/admin/integrations');
        self::assertResponseStatusCodeSame(403);

        $this->loginAs('admin');
        $crawler = $this->client->request('GET', '/admin/integrations');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Ключи REST API', $crawler->text());
        self::assertStringContainsString('Описания документов через LLM', $crawler->text());
        $token = $crawler->filter('input[name=_token]')->first()->attr('value');

        // Ключ API: создание (токен показывается один раз), отключение, удаление.
        $this->client->request('POST', '/admin/integrations/keys', ['_token' => $token, 'name' => 'Индексатор RAG', 'include_internal' => '1']);
        self::assertResponseRedirects();
        $crawler = $this->client->followRedirect();
        $shown = $crawler->filter('#new-token-value')->attr('value');
        self::assertStringStartsWith('dp_', (string) $shown);
        self::assertStringContainsString('Индексатор RAG', $crawler->text());
        self::assertStringContainsString('все, включая внутренние', $crawler->text());
        $key = static::getContainer()->get(ApiKeyRepository::class)->findOneByToken((string) $shown);
        self::assertNotNull($key);
        self::assertTrue($key->isIncludeInternal());
        self::assertSame('admin', $key->getCreatedBy()?->getUsername());
        $crawler = $this->client->request('GET', '/admin/integrations');
        self::assertSame(0, $crawler->filter('#new-token-value')->count(), 'повторно токен не показывается');
        $this->client->request('GET', '/api/v1', [], [], ['HTTP_AUTHORIZATION' => 'Bearer '.$shown]);
        self::assertResponseIsSuccessful();

        $this->client->request('POST', '/admin/integrations/keys/'.$key->getId().'/toggle', ['_token' => $token]);
        self::assertResponseRedirects();
        $this->client->request('GET', '/api/v1', [], [], ['HTTP_AUTHORIZATION' => 'Bearer '.$shown]);
        self::assertResponseStatusCodeSame(401);
        $this->client->request('POST', '/admin/integrations/keys/'.$key->getId().'/delete', ['_token' => $token]);
        self::assertResponseRedirects();
        self::assertNull(static::getContainer()->get(ApiKeyRepository::class)->findOneByToken((string) $shown));
        $this->client->request('POST', '/admin/integrations/keys', ['_token' => 'wrong', 'name' => 'x']);
        self::assertResponseStatusCodeSame(403);

        // Настройки LLM: сохранение (ключ скрыт), проверка подключения, описание порции документов.
        $this->client->request('POST', '/admin/integrations/llm', ['_token' => $token, 'enabled' => '1', 'base_url' => 'http://ollama.local:11434/v1', 'model' => 'qwen2.5:7b', 'api_key' => 'sk-abcdef123456', 'prompt' => PortalSettings::LLM_DEFAULT_PROMPT, 'max_input_chars' => '8000', 'timeout' => '90', 'temperature' => '0,3', 'auto_describe' => '1']);
        self::assertResponseRedirects();
        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('Настройки LLM сохранены', $crawler->text());
        $llm = $this->settings()->llm();
        self::assertTrue($llm['enabled']);
        self::assertTrue($llm['auto_describe']);
        self::assertSame('http://ollama.local:11434/v1', $llm['base_url']);
        self::assertSame('qwen2.5:7b', $llm['model']);
        self::assertSame('sk-abcdef123456', $llm['api_key']);
        self::assertSame(8000, $llm['max_input_chars']);
        self::assertSame(90, $llm['timeout']);
        self::assertEqualsWithDelta(0.3, $llm['temperature'], 0.0001);
        self::assertSame(PortalSettings::LLM_DEFAULT_PROMPT, $llm['prompt']);
        self::assertStringNotContainsString('sk-abcdef123456', $crawler->html(), 'ключ LLM на странице не показывается');
        self::assertStringContainsString('sk-a…3456', $crawler->html());
        // Пустое поле ключа — прежний ключ сохраняется.
        $this->client->request('POST', '/admin/integrations/llm', ['_token' => $token, 'enabled' => '1', 'base_url' => 'http://ollama.local:11434/v1', 'model' => 'qwen2.5:7b', 'api_key' => '', 'max_input_chars' => '8000', 'timeout' => '90', 'temperature' => '0.3']);
        self::assertSame('sk-abcdef123456', $this->settings()->llm()['api_key']);
        $this->client->request('POST', '/admin/integrations/llm', ['_token' => $token, 'enabled' => '1', 'base_url' => 'not a url', 'model' => 'm']);
        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('должен начинаться с http', $crawler->text());

        $this->http()->enqueue(self::completion('готово'));
        $this->client->request('POST', '/admin/integrations/llm/test', ['_token' => $token]);
        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('ответила за', $crawler->text());
        self::assertSame('http://ollama.local:11434/v1/chat/completions', $this->http()->last()['url']);

        $without = static::getContainer()->get(DocumentRepository::class)->countDescriptions()['without'];
        self::assertGreaterThan(0, $without);
        $this->http()->setDefault(self::completion('Описание, сформированное моделью.'));
        $this->client->request('POST', '/admin/integrations/llm/describe', ['_token' => $token]);
        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('Сформировано описаний: '.min(10, $without), $crawler->text());
        self::assertSame(max(0, $without - 10), static::getContainer()->get(DocumentRepository::class)->countDescriptions()['without']);
        $this->http()->setDefault(null);

        // Webhook: сохранение, проверка адреса, пробный запрос с подписью.
        $this->client->request('POST', '/admin/integrations/webhook', ['_token' => $token, 'enabled' => '1', 'url' => 'https://rag.local/hooks/docportal', 'secret' => 'hook-secret']);
        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('Настройки webhook сохранены', $crawler->text());
        self::assertTrue($this->settings()->webhook()['enabled']);
        $this->client->request('POST', '/admin/integrations/webhook', ['_token' => $token, 'enabled' => '1', 'url' => 'javascript:alert(1)']);
        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('http:// или https://', $crawler->text());
        self::assertSame('https://rag.local/hooks/docportal', $this->settings()->webhook()['url'], 'ошибочный адрес не сохранён');

        $this->http()->reset();
        $this->http()->enqueue(new HttpResponse(204, ''));
        $this->client->request('POST', '/admin/integrations/webhook/test', ['_token' => $token]);
        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('Webhook принят: HTTP 204', $crawler->text());
        $request = $this->http()->requests[0];
        self::assertSame('https://rag.local/hooks/docportal', $request['url']);
        self::assertSame('ping', $request['headers']['X-Docportal-Event']);
        self::assertSame('sha256='.hash_hmac('sha256', (string) $request['body'], 'hook-secret'), $request['headers']['X-Docportal-Signature']);
        self::assertSame('ping', json_decode((string) $request['body'], true)['event']);
    }

    public function testWebhookOnDocumentChanges(): void
    {
        $this->settings()->setWebhook(true, 'https://rag.local/hook', 's3cret');
        $notifier = static::getContainer()->get(WebhookNotifier::class);
        $manager = static::getContainer()->get(DocumentManager::class);
        $this->http()->reset();
        $this->http()->setDefault(new HttpResponse(200, 'ok'));

        $doc = $this->document('ШБ-001');
        $manager->unpublish($doc, $this->user('admin'));
        $manager->publish($doc, $this->user('admin'));
        self::assertTrue($notifier->hasPending());
        self::assertSame([], $this->http()->requests, 'до конца запроса ничего не отправляется');
        $notifier->flush();
        self::assertFalse($notifier->hasPending());
        self::assertCount(1, $this->http()->requests, 'все изменения — одним запросом');
        $request = $this->http()->requests[0];
        $payload = json_decode((string) $request['body'], true);
        self::assertSame(WebhookNotifier::EVENT_CHANGED, $payload['event']);
        self::assertSame([['document_id' => $doc->getId(), 'type' => 'unpublish'], ['document_id' => $doc->getId(), 'type' => 'publish']], array_map(static fn (array $c) => ['document_id' => $c['document_id'], 'type' => $c['type']], $payload['changes']));
        // Названий документов в webhook нет: получатель без ключа API не должен узнавать содержимое.
        foreach ($payload['changes'] as $change) {
            self::assertSame(['document_id', 'type', 'at'], array_keys($change));
        }
        self::assertStringNotContainsString($doc->getTitle(), (string) $request['body']);
        self::assertSame([], $payload['deleted']);
        self::assertStringEndsWith('/api/v1/documents/'.$doc->getId(), $payload['documents'][0]['url']);
        self::assertSame('sha256='.hash_hmac('sha256', (string) $request['body'], 's3cret'), $request['headers']['X-Docportal-Signature']);

        // Просмотры не порождают webhook; удаление попадает в deleted; webhook уходит по завершении HTTP-запроса.
        $manager->recordView($doc, null, '10.0.0.1');
        self::assertFalse($notifier->hasPending());
        $victim = $this->document('ШБ-002');
        $victimId = $victim->getId();
        $manager->delete($victim, $this->user('admin'));
        $notifier->flush();
        $payload = json_decode((string) $this->http()->last()['body'], true);
        self::assertSame([$victimId], array_column($payload['deleted'], 'document_id'));
        self::assertSame(['document_id', 'deleted_at'], array_keys($payload['deleted'][0]));

        $this->http()->reset();
        $this->http()->setDefault(new HttpResponse(200, 'ok'));
        $this->loginAs('admin');
        $target = $this->document('ТК-041');
        $crawler = $this->client->request('GET', '/documents/'.$target->getId());
        $token = $crawler->filter('form[action$="/archive"] input[name=_token]')->attr('value');
        $this->client->request('POST', '/documents/'.$target->getId().'/archive', ['_token' => $token]);
        self::assertResponseRedirects();
        self::assertCount(1, $this->http()->requests, 'webhook отправлен по завершении запроса (kernel.terminate)');
        $payload = json_decode((string) $this->http()->requests[0]['body'], true);
        self::assertSame('archive', $payload['changes'][0]['type']);

        // Выключенный webhook — ничего не отправляется, очередь очищается.
        $this->settings()->setWebhook(false, 'https://rag.local/hook', null);
        $this->http()->reset();
        $manager->publish($this->document('ТК-041'), $this->user('admin'));
        $notifier->flush();
        self::assertSame([], $this->http()->requests);
        self::assertFalse($notifier->hasPending());
    }
}
