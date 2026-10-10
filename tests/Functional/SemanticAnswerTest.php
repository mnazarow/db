<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\AiQuestion;
use App\Entity\Document;
use App\Repository\AiQuestionRepository;
use App\Service\DocumentManager;
use App\Service\Http\HttpResponse;
use App\Service\Http\RecordingTransport;
use App\Service\Llm\AnswerService;
use App\Service\Llm\SemanticIndex;
use App\Service\PortalSettings;
use App\Tests\PortalTestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Поиск по смыслу для ответов ИИ (смысловой индекс на эмбеддингах), журнал вопросов к ИИ с оценками ответов и
 * страница «Вопросы к ИИ». Модель эмбеддингов в тесте — «понятийная»: у слов одной темы (пожар, возгорание,
 * огнетушитель…) общий признак, поэтому вопрос находит документ, в котором нет ни одного слова вопроса.
 */
final class SemanticAnswerTest extends PortalTestCase
{
    /** Тема → основы слов (вектор — сколько раз встретились слова темы, плюс общий признак). */
    private const CONCEPTS = [
        ['пожар', 'возгора', 'огнетуш', 'эвакуац', 'пламя', 'горит', 'дым'],
        ['отпуск', 'отдых', 'каникул'],
        ['пароль', 'учётн', 'учетн', 'вход'],
        ['манометр', 'давлен', 'калибров'],
        ['марс', 'ракет', 'космос', 'полёт', 'полет'],
    ];

    /** Сколько раз модель эмбеддингов вызывалась (тексты в запросах). */
    private int $embedded = 0;
    private bool $embedFails = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->settings()->setLlm(['enabled' => true, 'base_url' => 'http://llm.test/v1', 'api_key' => 'k', 'model' => 'test-model', 'answers' => true]);
        $this->settings()->setEmbeddings(['enabled' => true, 'model' => 'test-embed', 'base_url' => '', 'dimensions' => 0, 'log_days' => 180]);
        $this->http()->reset();
        $this->http()->setDefault(new HttpResponse(200, '{"items":[]}'));
        $this->http()->setHandler(function (string $method, string $url, ?string $body): ?HttpResponse {
            $payload = json_decode((string) $body, true) ?: [];
            if (str_ends_with($url, '/embeddings')) {
                if ($this->embedFails) {
                    return new HttpResponse(503, '{"error":"model is loading"}');
                }
                $data = [];
                foreach ($payload['input'] as $i => $text) {
                    ++$this->embedded;
                    $data[] = ['index' => $i, 'embedding' => self::vector((string) $text)];
                }

                return new HttpResponse(200, (string) json_encode(['data' => array_reverse($data)]));
            }
            if (str_ends_with($url, '/chat/completions')) {
                $prompt = (string) ($payload['messages'][1]['content'] ?? '');
                $answer = str_contains($prompt, 'огнетушитель') ? 'Сообщите по телефону 112 и возьмите огнетушитель [1].' : 'В документах портала ответа не нашлось — уточните у ответственного.';

                return new HttpResponse(200, (string) json_encode(['choices' => [['message' => ['content' => $answer]]]], \JSON_UNESCAPED_UNICODE));
            }

            return null;
        });
    }

    protected function tearDown(): void
    {
        $this->http()->reset();
        $this->settings()->setEmbeddings(['enabled' => false, 'model' => PortalSettings::EMBED_DEFAULT_MODEL, 'log_days' => 180]);
        $this->settings()->setLlm(['enabled' => false, 'base_url' => PortalSettings::LLM_DEFAULT_BASE_URL, 'model' => PortalSettings::LLM_DEFAULT_MODEL, 'api_key' => '', 'answers' => true]);
        parent::tearDown();
    }

    /** @return list<float> */
    private static function vector(string $text): array
    {
        $text = mb_strtolower($text);
        $vector = [];
        foreach (self::CONCEPTS as $words) {
            $n = 0;
            foreach ($words as $w) {
                $n += mb_substr_count($text, $w);
            }
            $vector[] = (float) $n;
        }
        $vector[] = 0.3;

        return $vector;
    }

    private function http(): RecordingTransport
    {
        return static::getContainer()->get(RecordingTransport::class);
    }

    private function settings(): PortalSettings
    {
        return static::getContainer()->get(PortalSettings::class);
    }

    private function index(): SemanticIndex
    {
        return static::getContainer()->get(SemanticIndex::class);
    }

    private function page(string $code, string $title, string $html, bool $restricted = false): Document
    {
        $existing = static::getContainer()->get('App\Repository\DocumentRepository')->findOneBy(['code' => $code]);
        if (null !== $existing) {
            return $existing;
        }
        $document = (new Document($this->section('Общие документы')))->setTitle($title)->setCode($code)->setType(Document::TYPE_PAGE)->setPublic(false);
        static::getContainer()->get(DocumentManager::class)->create($document, null, $html, null, $this->user('ivanov'), true);
        if ($restricted) {
            $document->setRestricted(true);
            $document->setAllowedUsers([$this->user('sidorov')]);
            $this->em()->flush();
        }

        return $document;
    }

    private function firePage(): Document
    {
        return $this->page('ПБ-777', 'Порядок действий при пожаре в офисе', '<p>При пожаре сообщите по телефону 112, возьмите огнетушитель и покиньте здание по плану эвакуации. Лифтом не пользоваться.</p>');
    }

    private function command(array $input): CommandTester
    {
        $tester = new CommandTester((new Application(static::$kernel))->find('app:ai:index'));
        $tester->execute($input);

        return $tester;
    }

    private function token(string $q): string
    {
        $crawler = $this->client->request('GET', '/search?q='.rawurlencode($q));
        self::assertResponseIsSuccessful();

        return (string) $crawler->filter('[data-ai-answer]')->attr('data-token');
    }

    public function testChunksPackingAndTopics(): void
    {
        $sentence = 'Работник обязан соблюдать требования охраны труда и правила внутреннего распорядка. ';
        $chunks = SemanticIndex::chunks(str_repeat($sentence, 60));
        self::assertGreaterThan(4, \count($chunks));
        foreach ($chunks as $chunk) {
            self::assertLessThanOrEqual(SemanticIndex::CHUNK_CHARS, mb_strlen($chunk));
            self::assertStringEndsWith('.', $chunk, 'фрагмент режется по концу предложения');
        }
        self::assertSame([], SemanticIndex::chunks("  \n "));
        self::assertCount(3, SemanticIndex::chunks(str_repeat($sentence, 200), 3), 'не больше заданного числа фрагментов');

        $vector = [0.25, -0.5, 1.0];
        self::assertSame($vector, SemanticIndex::unpack(SemanticIndex::pack($vector)));
        self::assertSame([], SemanticIndex::unpack('abc'));
        self::assertEqualsWithDelta(1.0, SemanticIndex::dot([0.6, 0.8], [0.6, 0.8]), 1e-9);

        self::assertSame(AnswerService::topic('Сколько дней отпуска положено?'), AnswerService::topic('отпуск — сколько дней положено'));
        self::assertSame('дней отпус полож', AnswerService::topic('Сколько дней отпуска положено?'));
        self::assertTrue(AiQuestionRepository::similar(['дней', 'отпус'], ['дней', 'отпус', 'полож']));
        self::assertFalse(AiQuestionRepository::similar(['отпус'], ['отпус', 'декре', 'уход']), 'одно общее слово из трёх — разные темы');
        self::assertTrue(AiQuestionRepository::similar(['марс'], ['марс']));
        self::assertFalse(AiQuestionRepository::similar([], ['марс']));
    }

    public function testIndexIsBuiltAndKeptUpToDate(): void
    {
        $fire = $this->firePage();
        $tester = $this->command(['--limit' => 500]);
        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        $status = $this->index()->status();
        self::assertSame(0, $status['outdated'], 'все опубликованные документы в индексе');
        self::assertGreaterThan(0, $status['chunks']);
        self::assertSame('test-embed|0', $status['model']);
        $connection = $this->em()->getConnection();
        self::assertSame(1, (int) $connection->fetchOne('SELECT chunks FROM document_embedding WHERE document_id = ?', [$fire->getId()]));
        self::assertStringContainsString('огнетушитель', (string) $connection->fetchOne('SELECT content FROM document_chunk WHERE document_id = ?', [$fire->getId()]));

        // Повторный запуск ничего не пересчитывает.
        $calls = $this->embedded;
        $this->command([]);
        self::assertSame($calls, $this->embedded);

        // Изменился документ — пересчитывается только он; снят с публикации — уходит из индекса.
        $connection->executeStatement('UPDATE document_embedding SET embedded_at = ? WHERE document_id = ?', [(new \DateTimeImmutable('-1 hour'))->format('Y-m-d H:i:s'), $fire->getId()]);
        $connection->executeStatement('UPDATE document SET updated_at = ? WHERE id = ?', [(new \DateTimeImmutable('-1 minute'))->format('Y-m-d H:i:s'), $fire->getId()]);
        self::assertSame([$fire->getId()], $this->index()->outdatedIds());
        $calls = $this->embedded;
        $tester = $this->command([]);
        self::assertSame([], $this->index()->outdatedIds());
        self::assertSame($calls, $this->embedded, 'текст не изменился — модель не вызывается');
        self::assertStringContainsString('Обработано документов: 1', $tester->getDisplay());
        // Текст изменился — векторы считаются заново.
        $connection->executeStatement('UPDATE document SET description = ?, updated_at = ? WHERE id = ?', ['Памятка: огнетушитель и эвакуация', (new \DateTimeImmutable('-30 seconds'))->format('Y-m-d H:i:s'), $fire->getId()]);
        $connection->executeStatement('UPDATE document_embedding SET embedded_at = ? WHERE document_id = ?', [(new \DateTimeImmutable('-1 hour'))->format('Y-m-d H:i:s'), $fire->getId()]);
        $this->em()->clear();
        $this->command([]);
        self::assertGreaterThan($calls, $this->embedded);
        $connection->executeStatement("UPDATE document SET status = 'archived' WHERE id = ?", [$fire->getId()]);
        $this->command([]);
        self::assertFalse($connection->fetchOne('SELECT 1 FROM document_embedding WHERE document_id = ?', [$fire->getId()]));
        self::assertFalse($connection->fetchOne('SELECT 1 FROM document_chunk WHERE document_id = ?', [$fire->getId()]));
        $connection->executeStatement("UPDATE document SET status = 'published' WHERE id = ?", [$fire->getId()]);

        // Другая модель — индекс строится заново.
        $this->command([]);
        $this->settings()->setEmbeddings(['enabled' => true, 'model' => 'test-embed', 'dimensions' => 64]);
        self::assertGreaterThan(1, \count($this->index()->outdatedIds()), 'векторы другой модели несравнимы');
        $this->settings()->setEmbeddings(['enabled' => true, 'model' => 'test-embed', 'dimensions' => 0]);

        // Модель недоступна — ошибка в задании (для «Мониторинга»); прежние векторы остаются в поиске, документ
        // повторится в следующий раз (в конце очереди).
        $this->embedFails = true;
        $connection->executeStatement('UPDATE document SET description = ?, updated_at = ? WHERE id = ?', ['Памятка при пожаре', (new \DateTimeImmutable('-20 seconds'))->format('Y-m-d H:i:s'), $fire->getId()]);
        $connection->executeStatement('UPDATE document_embedding SET embedded_at = ? WHERE document_id = ?', [(new \DateTimeImmutable('-1 hour'))->format('Y-m-d H:i:s'), $fire->getId()]);
        $this->em()->clear();
        $tester = $this->command([]);
        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('не отвечает', $tester->getDisplay());
        self::assertSame('ok', $connection->fetchOne('SELECT status FROM document_embedding WHERE document_id = ?', [$fire->getId()]), 'прежний вектор документа сохранён');
        self::assertNotFalse($connection->fetchOne('SELECT error FROM document_embedding WHERE document_id = ? AND error IS NOT NULL', [$fire->getId()]));
        self::assertGreaterThan(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM document_chunk WHERE document_id = ?', [$fire->getId()]), 'фрагменты не удалены');
        self::assertContains($fire->getId(), $this->index()->outdatedIds());
        self::assertSame(1, $this->index()->status()['errors']);
        $this->embedFails = false;
        $this->command([]);
        self::assertFalse($connection->fetchOne('SELECT error FROM document_embedding WHERE document_id = ? AND error IS NOT NULL', [$fire->getId()]));
        self::assertSame([], $this->index()->outdatedIds());
    }

    public function testAnswerFindsDocumentByMeaningAndCanBeRated(): void
    {
        $fire = $this->firePage();
        $this->command(['--limit' => 500]);
        $this->loginAs('smirnov');
        $q = 'Что делать при возгорании?';
        $token = $this->token($q);
        self::assertSelectorExists('[data-semantic]', 'блок «Похоже по смыслу» на странице поиска');

        $this->http()->requests = [];
        $this->client->request('POST', '/search/answer', ['q' => $q, '_token' => $token]);
        self::assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertNull($data['error']);
        self::assertTrue($data['semantic']);
        self::assertSame('answered', $data['status']);
        // В документах нет слова «возгорание» — они найдены по смыслу (и демонстрационная инструкция по пожарной безопасности).
        $titles = array_column($data['sources'], 'title');
        self::assertContains('Порядок действий при пожаре в офисе', $titles);
        self::assertContains('Инструкция по пожарной безопасности', $titles);
        self::assertNotContains('Политика информационной безопасности', $titles, 'далёкие по смыслу документы отброшены');
        self::assertStringContainsString('class="ai-answer__ref"', $data['html']);
        self::assertNotNull($data['id']);
        $chat = array_values(array_filter($this->http()->requests, static fn (array $r): bool => str_ends_with($r['url'], '/chat/completions')))[0];
        self::assertStringContainsString('покиньте здание по плану эвакуации', json_decode((string) $chat['body'], true)['messages'][1]['content'], 'источник — найденный фрагмент');

        $question = $this->em()->getRepository(AiQuestion::class)->find($data['id']);
        self::assertSame(AiQuestion::STATUS_ANSWERED, $question->getStatus());
        self::assertTrue($question->isSemantic());
        self::assertSame('возго', $question->getTopic());

        // Оценка: автор — да; чужой — нет; неверная оценка — 400.
        $this->client->request('POST', $data['rate_url'], ['rating' => '-1', 'comment' => '  Нет порядка для склада ', '_token' => $token]);
        self::assertResponseIsSuccessful();
        $this->client->request('POST', $data['rate_url'], ['rating' => '5', '_token' => $token]);
        self::assertResponseStatusCodeSame(400);
        $this->em()->clear();
        $question = $this->em()->getRepository(AiQuestion::class)->find($data['id']);
        self::assertSame(-1, $question->getRating());
        self::assertSame('Нет порядка для склада', $question->getComment());
        $this->loginAs('sidorov');
        $this->client->request('POST', $data['rate_url'], ['rating' => '1', '_token' => $this->token('x y z')]);
        self::assertResponseStatusCodeSame(404);

        // Через неделю оценить нельзя.
        $this->em()->getConnection()->executeStatement('UPDATE ai_question SET created_at = ? WHERE id = ?', [(new \DateTimeImmutable('-8 days'))->format('Y-m-d H:i:s'), $data['id']]);
        $this->loginAs('smirnov');
        $this->client->request('POST', $data['rate_url'], ['rating' => '1', '_token' => $this->token('a b c')]);
        self::assertResponseStatusCodeSame(404);
        self::assertStringContainsString('в течение недели', json_decode((string) $this->client->getResponse()->getContent(), true)['error']);
    }

    public function testSemanticBlockRespectsAccessAndFallsBack(): void
    {
        $this->firePage();
        $secret = $this->page('ПБ-778', 'Секретный план эвакуации склада', '<p>Эвакуация склада при пожаре: огнетушитель у ворот.</p>', true);
        $this->command(['--limit' => 500]);

        $this->loginAs('smirnov');
        $token = $this->token('возгорание в офисе');
        $this->client->request('POST', '/search/semantic', ['q' => 'возгорание в офисе', '_token' => $token]);
        self::assertResponseIsSuccessful();
        $items = json_decode((string) $this->client->getResponse()->getContent(), true)['items'];
        $titles = array_column($items, 'title');
        self::assertContains('Порядок действий при пожаре в офисе', $titles);
        self::assertNotContains($secret->getTitle(), $titles, 'документ с ограниченным доступом чужим не показывается');
        self::assertStringContainsString('огнетушитель', $items[array_search('Порядок действий при пожаре в офисе', $titles, true)]['snippet']);

        $this->loginAs('sidorov');
        $this->client->request('POST', '/search/semantic', ['q' => 'возгорание в офисе', '_token' => $this->token('возгорание в офисе')]);
        self::assertContains($secret->getTitle(), array_column(json_decode((string) $this->client->getResponse()->getContent(), true)['items'], 'title'));

        // Модель эмбеддингов не отвечает — ответ ищется по словам, без ошибки для сотрудника.
        $this->embedFails = true;
        $this->client->request('POST', '/search/answer', ['q' => 'Порядок действий при пожаре?', '_token' => $this->token('пожар')]);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertFalse($data['semantic']);
        self::assertContains('Порядок действий при пожаре в офисе', array_column($data['sources'], 'title'));

        // Гостю — нельзя; выключен поиск по смыслу — пусто и блока нет.
        $this->client->request('POST', '/search/semantic', ['q' => 'пожар', '_token' => 'x']);
        self::assertResponseStatusCodeSame(400);
        $this->settings()->setEmbeddings(['enabled' => false, 'model' => 'test-embed']);
        $this->client->request('GET', '/search?q=пожар');
        self::assertSelectorNotExists('[data-semantic]');
    }

    public function testUnansweredQuestionsReportAndRetention(): void
    {
        $this->firePage();
        $this->command(['--limit' => 500]);
        $this->loginAs('smirnov');
        foreach (['Когда будет полёт на Марс?', 'полёт на Марс когда', 'сколько дней отпуска?', 'Сколько дней отпуска положено?'] as $q) {
            $this->client->request('POST', '/search/answer', ['q' => $q, '_token' => $this->token($q)]);
            // Далёкие по смыслу документы (ниже минимального сходства) источниками не становятся.
            self::assertContains(json_decode((string) $this->client->getResponse()->getContent(), true)['status'], ['no_sources', 'not_found']);
        }
        $this->client->request('POST', '/search/semantic', ['q' => 'полёт на Марс', '_token' => $this->token('марс')]);
        self::assertSame([], json_decode((string) $this->client->getResponse()->getContent(), true)['items'], 'ничего похожего по смыслу — блок пуст');
        $this->loginAs('sidorov');
        $this->client->request('POST', '/search/answer', ['q' => 'Сколько стоит ракета для полёта на Марс?', '_token' => $this->token('ракета')]);

        $this->loginAs('admin');
        $crawler = $this->client->request('GET', '/admin/ai-questions');
        self::assertResponseIsSuccessful();
        $table = $crawler->filter('.ai-topics')->text();
        self::assertStringContainsString('полёт на Марс когда', $table, 'тема — последняя формулировка');
        self::assertSame(1, substr_count($table, 'Марс когда'), 'похожие формулировки — одна тема');
        self::assertSame(1, substr_count($table, 'дней отпуска'), '«дней отпуска» и «дней отпуска положено» — одна тема');
        $link = $crawler->filter('.ai-topics a:contains("Создать документ")')->first()->attr('href');
        self::assertStringContainsString('title=', (string) $link);
        $this->client->request('GET', (string) $link);
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('input[value="полёт на Марс когда"]', 'название нового документа — вопрос');

        $this->client->request('GET', '/admin/ai-questions.csv?view=unanswered&period=7');
        self::assertResponseIsSuccessful();
        $csv = (string) $this->client->getInternalResponse()->getContent();
        self::assertStringContainsString('Сколько раз спрашивали', $csv);
        self::assertStringContainsString('полёт на Марс когда', $csv);
        $this->client->request('GET', '/admin/ai-questions?view=all&period=all');
        self::assertSelectorTextContains('.ai-questions', 'в документах нет ответа');
        $this->client->request('GET', '/admin/ai-questions?view=unhelpful');
        self::assertResponseIsSuccessful();
        $this->loginAs('smirnov');
        $this->client->request('GET', '/admin/ai-questions');
        self::assertResponseStatusCodeSame(403);

        // Срок хранения: старше — удаляются задачей обслуживания (и при выключенном поиске по смыслу).
        $connection = $this->em()->getConnection();
        $total = (int) $connection->fetchOne('SELECT COUNT(*) FROM ai_question');
        $connection->executeStatement('UPDATE ai_question SET created_at = ? WHERE question LIKE ?', [(new \DateTimeImmutable('-200 days'))->format('Y-m-d H:i:s'), '%ракета%']);
        $this->settings()->setEmbeddings(['enabled' => false, 'model' => 'test-embed', 'log_days' => 180]);
        $this->command(['--quiet-if-disabled' => true]);
        self::assertSame($total - 1, (int) $connection->fetchOne('SELECT COUNT(*) FROM ai_question'));

        // 0 дней — вопросы не сохраняются, оценивать нечего.
        $this->settings()->setEmbeddings(['enabled' => false, 'model' => 'test-embed', 'log_days' => 0]);
        $this->client->request('POST', '/search/answer', ['q' => 'Порядок действий при пожаре?', '_token' => $this->token('пожар')]);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertNull($data['id']);
        self::assertNull($data['rate_url']);
        self::assertSame($total - 1, (int) $connection->fetchOne('SELECT COUNT(*) FROM ai_question'));
    }

    public function testAdminSettings(): void
    {
        $this->loginAs('admin');
        $crawler = $this->client->request('GET', '/admin/integrations');
        self::assertSelectorTextContains('#semantic', 'Поиск по смыслу');
        $token = (string) $crawler->filter('#semantic input[name=_token]')->first()->attr('value');

        $this->client->request('POST', '/admin/integrations/semantic', ['_token' => $token, 'enabled' => '1', 'model' => 'bge-m3', 'base_url' => 'ftp://x', 'dimensions' => '0', 'log_days' => '90']);
        self::assertSame('test-embed', $this->settings()->embeddings()['model'], 'неверный адрес — не сохранено');
        $this->client->request('POST', '/admin/integrations/semantic', ['_token' => $token, 'enabled' => '1', 'model' => 'bge-m3', 'base_url' => 'http://ollama:11434/v1', 'api_key' => '', 'dimensions' => '0', 'log_days' => '90']);
        $cfg = $this->settings()->embeddings();
        self::assertSame('bge-m3', $cfg['model']);
        self::assertSame('http://ollama:11434/v1', $cfg['base_url']);
        self::assertSame('', $cfg['api_key'], 'ключ модели чата не уходит на другой сервер');
        self::assertSame(90, $cfg['log_days']);
        $this->client->followRedirect();
        self::assertSelectorTextContains('body', 'Модель изменилась');

        $this->settings()->setEmbeddings(['enabled' => true, 'model' => 'test-embed', 'base_url' => '', 'dimensions' => 0]);
        self::assertSame('k', $this->settings()->embeddings()['api_key'], 'без своего адреса — ключ модели чата');
        $this->client->request('POST', '/admin/integrations/semantic/test', ['_token' => $token]);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert', 'длина вектора — 6');

        $this->firePage();
        $this->client->request('POST', '/admin/integrations/semantic/build', ['_token' => $token]);
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('.alert', 'Обработано документов');
        self::assertSame(0, $this->index()->status()['outdated']);
        self::assertStringContainsString('test-embed', $crawler->filter('#semantic')->text());
    }
}
