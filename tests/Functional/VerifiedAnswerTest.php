<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\AiQuestion;
use App\Entity\AiVerifiedAnswer;
use App\Entity\Document;
use App\Service\DocumentManager;
use App\Service\Http\HttpResponse;
use App\Service\Http\RecordingTransport;
use App\Service\Llm\VerifiedAnswers;
use App\Service\PortalSettings;
use App\Tests\PortalTestCase;

/**
 * Проверенные ответы ИИ: администратор пишет или утверждает ответ на частый вопрос, сотрудник получает его
 * сразу (без языковой модели), с источниками, которые ему открыты; выключенный ответ не выдаётся.
 */
final class VerifiedAnswerTest extends PortalTestCase
{
    private int $chatCalls = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->settings()->setLlm(['enabled' => true, 'base_url' => 'http://llm.test/v1', 'api_key' => 'k', 'model' => 'test-model', 'answers' => true]);
        $this->settings()->setEmbeddings(['enabled' => false, 'model' => 'test-embed', 'log_days' => 180]);
        $this->http()->reset();
        $this->http()->setDefault(new HttpResponse(200, '{"items":[]}'));
        $this->http()->setHandler(function (string $method, string $url): ?HttpResponse {
            if (str_ends_with($url, '/chat/completions')) {
                ++$this->chatCalls;

                return new HttpResponse(200, (string) json_encode(['choices' => [['message' => ['content' => 'Ответ модели по документам [1].']]]], \JSON_UNESCAPED_UNICODE));
            }

            return null;
        });
    }

    protected function tearDown(): void
    {
        $this->http()->reset();
        $this->settings()->setLlm(['enabled' => false, 'base_url' => PortalSettings::LLM_DEFAULT_BASE_URL, 'model' => PortalSettings::LLM_DEFAULT_MODEL, 'api_key' => '', 'answers' => true]);
        $this->em()->getConnection()->executeStatement('DELETE FROM ai_verified_answer');
        parent::tearDown();
    }

    public function testMatchingRules(): void
    {
        $phrasings = ['Сколько дней отпуска положено?'];
        self::assertSame(2.0, VerifiedAnswers::score('сколько дней ОТПУСКА положено', $phrasings), 'дословно, без регистра и знаков');
        self::assertSame(1.0, VerifiedAnswers::score('А сколько положено дней отпуска', $phrasings), 'порядок слов и окончания не важны');
        self::assertSame(0.0, VerifiedAnswers::score('сколько дней отпуска?', $phrasings), 'вопрос шире проверенного — только через «другие формулировки»');
        self::assertGreaterThan(0, VerifiedAnswers::score('сколько дней отпуска положено сотруднику', $phrasings), 'одно лишнее слово при длинном вопросе');
        self::assertSame(0.0, VerifiedAnswers::score('как оформить учебный отпуск', ['Как оформить отпуск?']), 'частный случай — модель');
        self::assertSame(0.0, VerifiedAnswers::score('как оформить отпуск без сохранения зарплаты', ['Как оформить отпуск?']), 'вопрос уже проверенного — отвечает модель');
        self::assertSame(0.0, VerifiedAnswers::score('отпуск', ['Как оформить отпуск?']), 'одно слово к ответу из нескольких — не подходит');
        self::assertSame(0.0, VerifiedAnswers::score('как сменить пароль от почты', ['Как сменить пароль?']), 'другой пароль — модель');
        self::assertSame(0.0, VerifiedAnswers::score('как сменить пароль учётной записи', ['Как сменить пароль?']), 'два лишних слова — для этого есть «другие формулировки»');
        self::assertGreaterThan(0, VerifiedAnswers::score('как сменить пароль учётной записи', ['Как сменить пароль?', 'сменить пароль учётной записи']));
        self::assertSame(0.0, VerifiedAnswers::score('где столовая', $phrasings));
        // Отрицания меняют смысл: такие вопросы проверенным ответом не закрываются.
        self::assertSame(0.0, VerifiedAnswers::score('как оформить отпуск с сохранением зарплаты', ['Как оформить отпуск без сохранения зарплаты?']));
        self::assertSame(0.0, VerifiedAnswers::score('когда не выплачивают премию', ['Когда выплачивают премию?']));
        self::assertSame(0.0, VerifiedAnswers::score('можно ли работать удаленно', ['Нельзя ли работать удалённо?']));
        self::assertSame(0.0, VerifiedAnswers::score('как оформить отпуск', ['Как оформить отпуск без сохранения зарплаты?']), 'общий вопрос — не к ответу про частный случай');
        self::assertGreaterThan(0, VerifiedAnswers::score('почему не выплачивают премию?', ['Когда не выплачивают премию']), 'то же отрицание — подходит');
    }

    public function testAdminWritesAnswerAndEmployeeGetsItWithoutModel(): void
    {
        $open = $this->page('ОТП-01', 'Положение об отпусках', '<p>Ежегодный отпуск — 28 календарных дней.</p>');
        $restricted = $this->page('ОТП-02', 'Отпуска руководителей', '<p>Порядок для руководителей.</p>', true);

        // Сотрудник спросил — ответила модель; администратор утверждает ответ из журнала.
        $this->loginAs('smirnov');
        $first = $this->ask('Сколько дней отпуска положено?');
        self::assertSame(AiQuestion::STATUS_ANSWERED, $first['status']);
        self::assertSame(1, $this->chatCalls);

        $this->loginAs('admin');
        $crawler = $this->client->request('GET', '/admin/ai-questions?view=all&period=all');
        self::assertResponseIsSuccessful();
        $link = $crawler->filter('a:contains("Утвердить ответ")')->first()->attr('href');
        $crawler = $this->client->request('GET', (string) $link);
        self::assertResponseIsSuccessful();
        self::assertSame('Сколько дней отпуска положено?', $crawler->filter('#v-question')->attr('value'));
        self::assertStringContainsString('Ответ модели', $crawler->filter('#v-answer')->text());

        // Неизвестный источник — ошибка, ничего не сохраняется.
        $form = $crawler->filter('form.form')->first()->form([
            'answer' => "Ежегодный отпуск — **28 календарных дней** [1].\n- Дополнительные дни — по [2].",
            'alternatives' => "сколько длится отпуск\n",
            'sources' => "/documents/{$open->getId()}\nНЕТ-ТАКОГО",
        ]);
        $this->client->submit($form);
        self::assertSelectorTextContains('.alert--error', 'НЕТ-ТАКОГО');
        // Ссылка [2] при одном источнике — ошибка.
        $this->client->submit($crawler->filter('form.form')->first()->form(), ['answer' => 'Отпуск — 28 дней [1][2].', 'sources' => "/documents/{$open->getId()}"]);
        self::assertSelectorTextContains('.alert--error', 'ссылка [2]');
        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM ai_verified_answer'));

        $crawler = $this->client->submit($crawler->filter('form.form')->first()->form(), [
            'answer' => "Ежегодный отпуск — **28 календарных дней** [1].\n- Дополнительные дни — по [2].",
            'alternatives' => 'сколько длится отпуск',
            'sources' => "/documents/{$open->getId()}\nОТП-02",
        ]);
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('.alert--success', 'Проверенный ответ сохранён');
        self::assertStringContainsString('<b>28 календарных дней</b>', $crawler->filter('.ai-answer__text')->html());
        $saved = $this->em()->getRepository(AiVerifiedAnswer::class)->findOneBy([]);
        self::assertSame([$open->getId(), $restricted->getId()], $saved->getDocumentIds());
        self::assertSame(['сколько длится отпуск'], $saved->getAlternativeList());

        // Среди источников — документ с ограниченным доступом: администратор предупреждён.
        self::assertSelectorTextContains('.alert--warning', 'ограниченным доступом');

        // Сотруднику без доступа к одному из источников проверенный ответ не выдаётся — отвечает модель.
        $this->loginAs('smirnov');
        $calls = $this->chatCalls;
        self::assertSame(AiQuestion::STATUS_ANSWERED, $this->ask('А сколько положено дней отпуска?')['status']);
        self::assertSame($calls + 1, $this->chatCalls, 'ответила модель');
        // У кого есть доступ ко всем источникам — получает проверенный ответ с обоими.
        $this->loginAs('sidorov');
        $calls = $this->chatCalls;
        $answer = $this->ask('Сколько дней отпуска положено');
        self::assertSame(AiQuestion::STATUS_VERIFIED, $answer['status']);
        self::assertCount(2, $answer['sources']);
        self::assertSame(AiQuestion::STATUS_VERIFIED, $this->ask('Сколько длится отпуск')['status'], 'другая формулировка');
        self::assertSame($calls, $this->chatCalls, 'модель не вызывалась');
        // Более узкий вопрос — ответ модели.
        self::assertSame(AiQuestion::STATUS_ANSWERED, $this->ask('Сколько дней отпуска положено сотрудникам с вредными условиями труда?')['status']);

        // Источник с ограниченным доступом убран — ответ получают все.
        $this->loginAs('admin');
        $crawler = $this->client->request('GET', '/admin/ai-questions/answers/'.$saved->getId());
        $this->client->submit($crawler->filter('form.form')->first()->form(), ['answer' => 'Ежегодный отпуск — **28 календарных дней** [1].', 'sources' => "/documents/{$open->getId()}"]);
        $this->client->followRedirect();
        self::assertSelectorNotExists('.alert--warning');
        $this->loginAs('smirnov');
        $calls = $this->chatCalls;
        $answer = $this->ask('А сколько положено дней отпуска?');
        self::assertSame($calls, $this->chatCalls, 'модель не вызывалась');
        self::assertSame(AiQuestion::STATUS_VERIFIED, $answer['status']);
        self::assertNotNull($answer['verified']);
        self::assertStringContainsString('28 календарных дней', $answer['html']);
        self::assertSame(['Положение об отпусках'], array_column($answer['sources'], 'title'));
        self::assertNotNull($answer['rate_url'], 'проверенный ответ тоже можно оценить');

        // Учёт выдач и статистика.
        $this->em()->clear();
        $saved = $this->em()->getRepository(AiVerifiedAnswer::class)->find($saved->getId());
        self::assertSame(3, $saved->getUses());
        $this->loginAs('admin');
        $crawler = $this->client->request('GET', '/admin/ai-questions?view=verified');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.ai-verified', 'Сколько дней отпуска положено?');
        self::assertSelectorTextContains('.ai-verified', 'выдаётся');
        $crawler = $this->client->request('GET', '/admin/ai-questions?view=verified&check='.rawurlencode('отпуск сколько дней положено'));
        self::assertSelectorTextContains('.ai-verified-check', 'Получит ответ');
        $this->client->request('GET', '/admin/ai-questions?view=verified&check='.rawurlencode('где столовая'));
        self::assertSelectorTextContains('.ai-verified-check', 'ответит языковая модель');
        $this->client->request('GET', '/admin/ai-questions.csv?view=verified');
        self::assertStringContainsString('28 календарных дней', (string) $this->client->getInternalResponse()->getContent());
        $crawler = $this->client->request('GET', '/admin/ai-questions?period=all');
        self::assertSelectorTextContains('.grid--ai-stats', 'проверенных ответов — 3');

        // Выключенный ответ не выдаётся.
        $crawler = $this->client->request('GET', '/admin/ai-questions/answers/'.$saved->getId());
        $form = $crawler->filter('form.form')->first()->form();
        $form['active']->untick();
        $this->client->submit($form);
        $this->loginAs('smirnov');
        self::assertSame(AiQuestion::STATUS_ANSWERED, $this->ask('А сколько положено дней отпуска?')['status']);

        // Удаление — только с токеном; обычному сотруднику страница закрыта.
        $this->client->request('GET', '/admin/ai-questions/answers/new');
        self::assertResponseStatusCodeSame(403);
        $this->loginAs('admin');
        $crawler = $this->client->request('GET', '/admin/ai-questions/answers/'.$saved->getId());
        $this->client->submit($crawler->filter('form[action$="/delete"]')->form());
        self::assertResponseRedirects('/admin/ai-questions?view=verified');
        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM ai_verified_answer'));
    }

    public function testVerifiedAnswersWorkWithoutModel(): void
    {
        $answer = (new AiVerifiedAnswer())->setQuestion('Как сменить пароль?')->setAnswer('Откройте «Профиль → Сменить пароль».');
        $this->em()->persist($answer);
        $this->em()->flush();
        $this->settings()->setLlm(['enabled' => false, 'base_url' => PortalSettings::LLM_DEFAULT_BASE_URL, 'model' => PortalSettings::LLM_DEFAULT_MODEL, 'api_key' => '', 'answers' => true]);

        $this->loginAs('smirnov');
        $crawler = $this->client->request('GET', '/search?q='.rawurlencode('как сменить пароль?'));
        self::assertSelectorTextContains('[data-ai-ask]', 'Найти готовый ответ');
        self::assertSame(AiQuestion::STATUS_VERIFIED, $this->ask('как сменить пароль?')['status']);
        $other = $this->ask('где столовая?');
        self::assertSame(AiQuestion::STATUS_NO_SOURCES, $other['status']);
        self::assertStringContainsString('Готового ответа', (string) $other['error']);
        self::assertSame(0, $this->chatCalls);

        // Нет ни модели, ни проверенных ответов — блока ответа нет.
        $this->em()->getConnection()->executeStatement('UPDATE ai_verified_answer SET active = 0');
        $this->client->request('GET', '/search?q='.rawurlencode('как сменить пароль?'));
        self::assertSelectorNotExists('[data-ai-answer]');
    }

    /** @return array<string, mixed> */
    private function ask(string $question): array
    {
        $crawler = $this->client->request('GET', '/search?q='.rawurlencode($question));
        self::assertResponseIsSuccessful();
        $this->client->request('POST', '/search/answer', ['q' => $question, '_token' => (string) $crawler->filter('[data-ai-answer]')->attr('data-token')]);
        self::assertResponseIsSuccessful();

        return json_decode((string) $this->client->getResponse()->getContent(), true);
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

    private function http(): RecordingTransport
    {
        return static::getContainer()->get(RecordingTransport::class);
    }

    private function settings(): PortalSettings
    {
        return static::getContainer()->get(PortalSettings::class);
    }
}
