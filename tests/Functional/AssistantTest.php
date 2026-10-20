<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\ApiKey;
use App\Entity\ChatConversation;
use App\Entity\PriceList;
use App\Repository\ChatConversationRepository;
use App\Service\Assistant\ChatAssistant;
use App\Service\Http\HttpResponse;
use App\Service\Http\RecordingTransport;
use App\Service\Llm\AnswerService;
use App\Service\Notification\TelegramNotifier;
use App\Service\PortalSettings;
use App\Service\Price\PriceListService;
use App\Service\Xlsx\XlsxWriter;
use App\Tests\PortalTestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Помощник: чат на портале (разговор с уточнениями, источники — документы и прайс-листы, оценка, история)
 * и тот же помощник в Telegram для сотрудников, привязавших чат.
 */
final class AssistantTest extends PortalTestCase
{
    /** @var list<string> тексты запросов к модели */
    private array $prompts = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->http()->reset();
        $this->prompts = [];
        $this->settings()->setLlm(['enabled' => true, 'base_url' => 'http://llm.test/v1', 'api_key' => 'k', 'model' => 'test-model', 'answers' => true]);
        // Модель отвечает ссылкой на первый источник; запросы к ней запоминаются.
        $this->http()->setHandler(function (string $method, string $url, ?string $body): ?HttpResponse {
            if (!str_starts_with($url, 'http://llm.test/')) {
                return null;
            }
            $payload = json_decode((string) $body, true);
            $user = (string) ($payload['messages'][1]['content'] ?? '');
            $this->prompts[] = $user;
            preg_match('/^- (.+)$/mu', $user, $line);
            $answer = 'По прайс-листу: '.($line[1] ?? 'нет позиций').' [1].';

            return new HttpResponse(200, json_encode(['choices' => [['message' => ['role' => 'assistant', 'content' => $answer]]]], \JSON_UNESCAPED_UNICODE));
        });
        $this->ensurePriceList();
    }

    protected function tearDown(): void
    {
        $this->http()->reset();
        $this->settings()->setAssistant(['enabled' => true, 'own' => false]);
        $this->settings()->setLlm(['enabled' => false, 'base_url' => PortalSettings::LLM_DEFAULT_BASE_URL, 'model' => PortalSettings::LLM_DEFAULT_MODEL, 'api_key' => '', 'answers' => true]);
        $this->settings()->setTelegram(['enabled' => false, 'token' => '', 'bot_name' => '', 'api_url' => '', 'admin_chat' => '']);
        parent::tearDown();
    }

    public function testChatWithFollowUpsSourcesRatingAndHistory(): void
    {
        $this->loginAs('smirnov');
        $crawler = $this->client->request('GET', '/');
        self::assertSame(1, $crawler->filter('[data-assistant][data-mode=panel]')->count(), 'кнопка «Помощник» на страницах портала');
        $config = json_decode((string) $crawler->filter('[data-assistant-config]')->text(), true);
        self::assertSame('/assistant/ask', $config['ask']);

        $first = $this->ask($config['token'], 'Сколько стоит гидроаккумулятор 50 л?');
        self::assertSame('price', $first['reply']['sources'][0]['type']);
        self::assertSame('Прайс для помощника', $first['reply']['sources'][0]['title']);
        self::assertStringContainsString('Гидроаккумулятор 50 л', $first['reply']['html']);
        self::assertStringContainsString('class="ai-answer__ref"', $first['reply']['html']);
        self::assertNotNull($first['reply']['rate_url']);
        self::assertStringContainsString('Прайс-лист «Прайс для помощника»', $this->prompts[0]);
        self::assertStringContainsString("5\u{00A0}200\u{00A0}₽", $this->prompts[0], 'цены — как в прайс-листе');
        self::assertStringNotContainsString('Предыдущий разговор', $this->prompts[0]);
        $id = $first['conversation']['id'];

        // Уточнение: ищется вместе с прошлым вопросом, модель видит разговор, вперёд — позиция на 24 л.
        $second = $this->ask($config['token'], 'а на 24 литра?', $id);
        self::assertSame($id, $second['conversation']['id']);
        self::assertStringContainsString('Предыдущий разговор:', $this->prompts[1]);
        self::assertStringContainsString('Сотрудник: Сколько стоит гидроаккумулятор 50 л?', $this->prompts[1]);
        self::assertStringContainsString('Гидроаккумулятор 24 л', $second['reply']['html']);
        self::assertTrue(AnswerService::isFollowUp('а на 24 литра?'));
        self::assertFalse(AnswerService::isFollowUp('Какой порядок оформления командировки сотрудника?'));

        // История и оценка.
        $this->client->request('GET', '/assistant/conversations');
        $list = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame($id, $list['conversations'][0]['id']);
        self::assertSame(4, $list['conversations'][0]['messages']);
        $this->client->request('GET', '/assistant/conversations/'.$id);
        $messages = json_decode((string) $this->client->getResponse()->getContent(), true)['messages'];
        self::assertSame(['user', 'assistant', 'user', 'assistant'], array_column($messages, 'role'));
        $this->client->request('POST', $second['reply']['rate_url'], ['_token' => $config['rateToken'], 'rating' => '1']);
        self::assertResponseIsSuccessful();
        $this->client->request('GET', '/assistant/conversations/'.$id);
        self::assertSame(1, json_decode((string) $this->client->getResponse()->getContent(), true)['messages'][3]['rating']);
        $crawler = $this->client->request('GET', '/assistant?c='.$id);
        self::assertResponseIsSuccessful();
        self::assertSame((string) $id, $crawler->filter('[data-assistant][data-mode=page]')->attr('data-current'));
        self::assertSame(0, $crawler->filter('[data-assistant][data-mode=panel]')->count(), 'на странице помощника окна нет');

        // Чужой разговор недоступен.
        $this->loginAs('petrova');
        $crawler = $this->client->request('GET', '/');
        $token = json_decode((string) $crawler->filter('[data-assistant-config]')->text(), true)['token'];
        $this->client->request('GET', '/assistant/conversations/'.$id);
        self::assertResponseStatusCodeSame(404);
        $this->client->request('POST', '/assistant/ask', ['_token' => $token, 'message' => 'а сколько стоит?', 'conversation' => (string) $id]);
        self::assertResponseStatusCodeSame(404);

        // Удаление.
        $this->loginAs('smirnov');
        $this->client->request('POST', '/assistant/conversations/'.$id.'/delete', ['_token' => $config['token']]);
        self::assertResponseIsSuccessful();
        $this->em()->clear();
        self::assertNull(static::getContainer()->get(ChatConversationRepository::class)->find($id));

        // Пустой вопрос, старый ключ формы.
        $this->client->request('POST', '/assistant/ask', ['_token' => $config['token'], 'message' => ' ']);
        self::assertResponseStatusCodeSame(422);
        $this->client->request('POST', '/assistant/ask', ['_token' => 'x', 'message' => 'Вопрос']);
        self::assertResponseStatusCodeSame(400);
    }

    public function testGuestsAndDisabledModel(): void
    {
        $crawler = $this->client->request('GET', '/');
        self::assertSame(0, $crawler->filter('[data-assistant]')->count(), 'гостю помощник не показывается');
        $this->client->request('POST', '/assistant/ask', ['message' => 'Вопрос']);
        self::assertResponseRedirects();

        // Модель выключена, проверенных ответов нет — помощника нет.
        $this->settings()->setLlm(['enabled' => false, 'base_url' => 'http://llm.test/v1', 'model' => 'test-model', 'answers' => true]);
        $this->loginAs('smirnov');
        $crawler = $this->client->request('GET', '/');
        self::assertFalse(static::getContainer()->get(ChatAssistant::class)->isAvailable());
        self::assertSame(0, $crawler->filter('[data-assistant]')->count(), 'сотруднику — без кнопки, пока помощнику нечем отвечать');
        $this->client->request('GET', '/assistant');
        self::assertResponseStatusCodeSame(404);

        // Администратор видит кнопку с подсказкой, как подключить модель.
        $this->loginAs('admin');
        $crawler = $this->client->request('GET', '/');
        self::assertSame(1, $crawler->filter('[data-assistant] .assistant__setup')->count());
        self::assertSame(0, $crawler->filter('[data-assistant-form]')->count());
        self::assertStringContainsString('docportal ai enable', $crawler->filter('.assistant__setup')->text());
        $crawler = $this->client->request('GET', '/assistant');
        self::assertResponseIsSuccessful();
        self::assertSame(1, $crawler->filter('.assistant__setup')->count());
        $tester = new CommandTester((new Application(self::$kernel))->find('app:ai:configure'));
        self::assertSame(1, $tester->execute(['--ready' => true]), 'модель не подключена');
        $check = new CommandTester((new Application(self::$kernel))->find('app:check'));
        $check->execute([]);
        self::assertStringContainsString('модель не подключена', $check->getDisplay());
        $this->settings()->setLlm(['enabled' => true, 'base_url' => 'http://llm.test/v1', 'model' => 'test-model', 'answers' => true]);
        self::assertSame(0, $tester->execute(['--ready' => true]));
        $crawler = $this->client->request('GET', '/');
        self::assertSame(0, $crawler->filter('.assistant__setup')->count());
        self::assertSame(1, $crawler->filter('[data-assistant-form]')->count());
    }

    public function testTelegramAssistantForLinkedEmployees(): void
    {
        $this->settings()->setTelegram(['enabled' => true, 'token' => '777:BOT', 'bot_name' => 'docportal_bot', 'api_url' => 'http://telegram.test', 'admin_chat' => '']);
        $user = $this->user('kuznetsova');
        $user->linkTelegram('9009', 'Елена');
        $this->em()->flush();

        $sent = $this->pollWith([
            ['chat' => 9009, 'text' => 'Сколько стоит гидроаккумулятор 50 л?'],
            ['chat' => 9009, 'text' => 'а на 24 литра?'],
            ['chat' => 9009, 'text' => '/new'],
            ['chat' => 9009, 'text' => 'Сколько стоит гидроаккумулятор 24 л?'],
            ['chat' => 3003, 'text' => 'Здравствуйте, где найти прайс?'],
            ['chat' => 9009, 'text' => '/help'],
        ], $stats);
        self::assertSame(3, $stats['answered']);
        self::assertSame(0, $stats['errors']);
        self::assertCount(6, $sent);
        self::assertStringContainsString('Гидроаккумулятор 50 л', $sent[0]['text']);
        self::assertStringContainsString('<b>Источники</b>', $sent[0]['text']);
        self::assertStringContainsString('Прайс для помощника', $sent[0]['text']);
        self::assertStringContainsString('Гидроаккумулятор 24 л', $sent[1]['text']);
        self::assertSame('Начат новый разговор — задайте вопрос.', $sent[2]['text']);
        self::assertStringContainsString('привяжите чат', $sent[4]['text'], 'непривязанному чату — как привязать');
        self::assertSame('3003', $sent[4]['chat_id']);
        self::assertStringContainsString('/new', $sent[5]['text']);
        self::assertStringContainsString('Предыдущий разговор:', $this->prompts[1]);
        self::assertStringNotContainsString('Предыдущий разговор:', $this->prompts[2], 'после /new — новый разговор');
        $conversations = static::getContainer()->get(ChatConversationRepository::class)->findRecent($this->user('kuznetsova'));
        self::assertCount(2, $conversations);
        self::assertSame(ChatConversation::CHANNEL_TELEGRAM, $conversations[0]->getChannel());

        // Разговоры из Telegram видны и на портале.
        $this->loginAs('kuznetsova');
        $this->client->request('GET', '/assistant/conversations');
        self::assertSame('telegram', json_decode((string) $this->client->getResponse()->getContent(), true)['conversations'][0]['channel']);
    }

    public function testAssistantOnSeparateServerAndApiForExternalBots(): void
    {
        // Описания — выключены; помощник — своя модель на другом сервере со своим ключом.
        $this->settings()->setLlm(['enabled' => false, 'base_url' => 'http://llm.test/v1', 'model' => 'test-model', 'answers' => true]);
        $this->loginAs('admin');
        $crawler = $this->client->request('GET', '/admin/integrations');
        self::assertSelectorExists('#assistant');
        $form = $crawler->filter('#assistant form[action$="/assistant"]')->form();
        $form['enabled']->tick();
        $form['source']->select('own');
        $form['base_url'] = 'http://gpu.test:11435/v1/';
        $form['model'] = 'qwen2.5:14b';
        $form['api_key'] = 'gpu-secret-key';
        $form['timeout'] = '300';
        $this->client->submit($form);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flashes', 'Настройки помощника сохранены');
        $cfg = $this->settings()->assistant();
        self::assertTrue($cfg['ready'] && $cfg['own']);
        self::assertSame('http://gpu.test:11435/v1', $cfg['base_url']);
        self::assertSame(300, $cfg['timeout']);
        $this->http()->setHandler(function (string $method, string $url, ?string $body): ?HttpResponse {
            if (!str_starts_with($url, 'http://gpu.test:11435/v1/')) {
                return null;
            }
            $this->prompts[] = (string) (json_decode((string) $body, true)['messages'][1]['content'] ?? '');

            return new HttpResponse(200, json_encode(['choices' => [['message' => ['role' => 'assistant', 'content' => '<think>сверяю цены</think>Гидроаккумулятор 50 л — 5 200 ₽ [1].']]]], \JSON_UNESCAPED_UNICODE));
        });
        $crawler = $this->client->request('GET', '/admin/integrations');
        $this->client->submit($crawler->filter('#assistant form[action$="/assistant/test"]')->form());
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flashes', 'qwen2.5:14b');
        $request = $this->http()->last();
        self::assertSame('Bearer gpu-secret-key', $request['headers']['Authorization']);
        self::assertSame(300, $request['timeout']);

        // Внешний чат-бот: ключ с доступом к внутренним документам, ответ от имени сотрудника.
        $public = ApiKey::generateToken();
        $internal = ApiKey::generateToken();
        $this->em()->persist(new ApiKey('Бот без права', $public, $this->user('admin')));
        $this->em()->persist((new ApiKey('Чат-бот мессенджера', $internal))->setIncludeInternal(true));
        $this->em()->flush();
        $call = function (?string $token, array $body): array {
            $this->client->request('POST', '/api/v1/assistant', [], [], array_filter(['HTTP_AUTHORIZATION' => null !== $token ? 'Bearer '.$token : null, 'CONTENT_TYPE' => 'application/json']), json_encode($body, \JSON_UNESCAPED_UNICODE));

            return [$this->client->getResponse()->getStatusCode(), json_decode((string) $this->client->getResponse()->getContent(), true)];
        };
        self::assertSame(401, $call(null, ['user' => 'smirnov', 'question' => 'Вопрос'])[0]);
        self::assertSame(403, $call($public, ['user' => 'smirnov', 'question' => 'Вопрос'])[0], 'нужен ключ с доступом к внутренним документам');
        self::assertSame(404, $call($internal, ['user' => 'nobody', 'question' => 'Вопрос'])[0]);
        self::assertSame(400, $call($internal, ['user' => 'smirnov'])[0]);
        [$status, $data] = $call($internal, ['user' => 'smirnov@example.ru', 'question' => 'Сколько стоит гидроаккумулятор 50 л?']);
        self::assertSame(200, $status, json_encode($data, \JSON_UNESCAPED_UNICODE));
        self::assertSame('Гидроаккумулятор 50 л — 5 200 ₽ [1].', $data['answer'], 'рассуждения модели <think> не показываются');
        self::assertSame('price', $data['sources'][0]['type']);
        self::assertMatchesRegularExpression('#^https?://[^/]+/prices/\d+#', $data['sources'][0]['url'], 'ссылки — абсолютные');
        self::assertStringContainsString('<b>Источники</b>', $data['telegram_html']);
        $conversation = $data['conversation'];
        [$status, $data] = $call($internal, ['user' => 'smirnov', 'question' => 'а на 24 литра?', 'conversation' => $conversation]);
        self::assertSame(200, $status);
        self::assertSame($conversation, $data['conversation']);
        self::assertStringContainsString('Предыдущий разговор:', end($this->prompts));
        self::assertSame(404, $call($internal, ['user' => 'petrova', 'question' => 'а ещё?', 'conversation' => $conversation])[0], 'чужой разговор');
        $saved = static::getContainer()->get(ChatConversationRepository::class)->find($conversation);
        self::assertSame(ChatConversation::CHANNEL_API, $saved->getChannel());

        // Консоль: та же модель для помощника и описаний.
        $tester = new CommandTester((new Application(self::$kernel))->find('app:ai:configure'));
        self::assertSame(0, $tester->execute(['--assistant-shared' => true]));
        self::assertFalse($this->settings()->assistant()['own']);
        self::assertFalse($this->settings()->assistant()['ready'], 'модель описаний выключена — помощнику отвечать нечем');
        self::assertSame(0, $tester->execute(['--assistant-url' => 'http://gpu2.test/v1', '--assistant-model' => 'qwen2.5:32b', '--enable' => true]));
        self::assertTrue($this->settings()->assistant()['ready']);
        self::assertFalse($this->settings()->llm()['enabled'], '--enable с настройками помощника не включает описания');
        self::assertStringContainsString('отдельный сервер: qwen2.5:32b', $tester->getDisplay());
    }

    /**
     * Разбор сообщений бота: входящие — из списка, ответы Bot API — «ок». Возвращает отправленные сообщения.
     *
     * @param list<array{chat: int, text: string}> $incoming
     *
     * @return list<array<string, mixed>>
     */
    private function pollWith(array $incoming, ?array &$stats): array
    {
        $updates = [];
        foreach ($incoming as $i => $m) {
            $updates[] = ['update_id' => 500 + $i, 'message' => ['message_id' => $i + 1, 'chat' => ['id' => $m['chat'], 'type' => 'private'], 'from' => ['first_name' => 'Тест'], 'text' => $m['text']]];
        }
        $this->http()->enqueue(new HttpResponse(200, json_encode(['ok' => true, 'result' => $updates], \JSON_UNESCAPED_UNICODE)));
        $this->http()->setDefault(new HttpResponse(200, '{"ok":true,"result":{"message_id":99}}'));
        $stats = static::getContainer()->get(TelegramNotifier::class)->poll();
        $sent = [];
        foreach ($this->http()->requests as $request) {
            if (str_ends_with($request['url'], '/sendMessage')) {
                $sent[] = json_decode((string) $request['body'], true);
            }
        }

        return $sent;
    }

    /** @return array<string, mixed> */
    private function ask(string $token, string $message, ?int $conversation = null): array
    {
        $this->client->request('POST', '/assistant/ask', array_filter(['_token' => $token, 'message' => $message, 'conversation' => null !== $conversation ? (string) $conversation : null]));
        self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());

        return json_decode((string) $this->client->getResponse()->getContent(), true);
    }

    private function ensurePriceList(): void
    {
        $em = $this->em();
        if (null !== $em->getRepository(PriceList::class)->findOneBy(['title' => 'Прайс для помощника'])) {
            return;
        }
        $writer = new XlsxWriter();
        $sheet = $writer->addSheet('Прайс');
        foreach (['Артикул', 'Наименование', 'Ед.', 'Цена, руб.'] as $i => $label) {
            $sheet->set(1, $i + 1, $label);
        }
        foreach ([['GA-24', 'Гидроаккумулятор 24 л, вертикальный', 'шт', 3400], ['GA-50', 'Гидроаккумулятор 50 л, вертикальный', 'шт', 5200], ['RD-5', 'Реле давления механическое', 'шт', 1350]] as $r => $row) {
            foreach ($row as $c => $value) {
                $sheet->set($r + 2, $c + 1, $value);
            }
        }
        $path = sys_get_temp_dir().'/assistant-price-'.bin2hex(random_bytes(4)).'.xlsx';
        $writer->save($path);
        $service = static::getContainer()->get(PriceListService::class);
        $marketer = $this->user('markova');
        $list = $service->create($marketer, 'Прайс для помощника', null, 'RUB', false);
        $revision = $service->store($list, $path, 'price.xlsx', $marketer);
        $preview = $service->preview($revision, null, null, null);
        $service->import($revision, $preview['sheet'], $preview['mapping'], null, null, $marketer);
        @unlink($path);
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
