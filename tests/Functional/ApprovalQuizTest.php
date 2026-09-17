<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Document;
use App\Entity\DocumentApproval;
use App\Repository\DocumentAcknowledgementRepository;
use App\Repository\DocumentApprovalRepository;
use App\Repository\DocumentQuestionRepository;
use App\Service\AcknowledgementService;
use App\Service\ApprovalService;
use App\Service\DocumentManager;
use App\Service\PortalSettings;
use App\Service\QuizService;
use App\Tests\PortalTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Согласование документа перед публикацией и проверка знаний после ознакомления.
 */
final class ApprovalQuizTest extends PortalTestCase
{
    private function approvals(): DocumentApprovalRepository
    {
        return static::getContainer()->get(DocumentApprovalRepository::class);
    }

    private function service(): ApprovalService
    {
        return static::getContainer()->get(ApprovalService::class);
    }

    private function settings(): PortalSettings
    {
        return static::getContainer()->get(PortalSettings::class);
    }

    private function upload(string $name, string $content): UploadedFile
    {
        $path = sys_get_temp_dir().'/'.bin2hex(random_bytes(4)).'-'.$name;
        file_put_contents($path, $content);

        return new UploadedFile($path, $name, null, null, true);
    }

    private function draft(string $code, string $title = 'Проект регламента'): Document
    {
        $document = (new Document($this->section('Общие документы')))->setTitle($title)->setCode($code)->setType(Document::TYPE_FILE)->setPublic(true);
        static::getContainer()->get(DocumentManager::class)->create($document, $this->upload('doc.txt', 'Текст '.$code), null, null, $this->user('ivanov'), false);

        return $document;
    }

    protected function tearDown(): void
    {
        // Настройки портала кэшируются: следующий тест не должен получить включённое согласование.
        // EntityManager после запросов клиента может содержать отсоединённые сущности — очищаем.
        $this->em()->clear();
        $this->settings()->setApproval(false, true);
        parent::tearDown();
    }

    public function testRequestAndApprove(): void
    {
        $document = $this->draft('СОГЛ-001', 'Регламент обработки заявок');
        $approval = $this->service()->request($document, $this->user('kuznetsova'), 'Проверьте пункт 3', $this->user('ivanov'));

        self::assertTrue($approval->isPending());
        self::assertSame(1, $approval->getVersionNumber());
        self::assertSame('Проверьте пункт 3', $approval->getRequestNote());
        self::assertTrue($document->isOnReview(), 'документ переходит в статус «на согласовании»');
        self::assertSame('На согласовании', static::getContainer()->get(\App\Twig\AppExtension::class)::STATUS_LABELS[$document->getStatus()]);
        self::assertSame(1, $this->approvals()->countPendingForUser($this->user('kuznetsova')));
        self::assertTrue($this->approvals()->isPendingApprover($this->user('kuznetsova'), $document));
        self::assertFalse($this->approvals()->isPendingApprover($this->user('smirnov'), $document));

        // Повторный запрос отклоняется, себе отправить нельзя, заблокированному — тоже.
        try {
            $this->service()->request($document, $this->user('petrova'), null, $this->user('ivanov'));
            self::fail('второй запрос по тому же документу должен быть отклонён');
        } catch (\DomainException $e) {
            self::assertStringContainsString('уже на согласовании', $e->getMessage());
        }

        // Согласующий видит документ до публикации, посторонний сотрудник — нет.
        $this->loginAs('kuznetsova');
        $this->client->request('GET', '/documents/'.$document->getId());
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.ack-box__title', 'Требуется ваше согласование');
        $this->loginAs('smirnov');
        $this->client->request('GET', '/documents/'.$document->getId());
        self::assertResponseStatusCodeSame(403);

        // Решение согласующего с автопубликацией.
        $this->settings()->setApproval(true, true);
        $approval = $this->approvals()->findPending($this->document('СОГЛ-001'));
        self::assertNotNull($approval);
        $this->service()->decide($approval, true, 'Согласовано без замечаний', $this->user('kuznetsova'));
        self::assertTrue($approval->isApproved());
        self::assertSame('Согласовано без замечаний', $approval->getDecisionNote());
        self::assertNotNull($approval->getDecidedAt());
        self::assertTrue($this->document('СОГЛ-001')->isPublished(), 'при автопубликации документ выходит сразу');
        self::assertSame(0, $this->approvals()->countPendingForUser($this->user('kuznetsova')));
        self::assertTrue($this->approvals()->isApproved($this->document('СОГЛ-001'), 1));

        // Повторное решение ничего не меняет.
        try {
            $this->service()->decide($approval, false, null, $this->user('kuznetsova'));
            self::fail('решение принимается один раз');
        } catch (\DomainException $e) {
            self::assertStringContainsString('уже принято', $e->getMessage());
        }
    }

    public function testRejectAndPublishGuard(): void
    {
        $this->settings()->setApproval(true, false);
        $document = $this->draft('СОГЛ-002');
        self::assertFalse($this->service()->canPublish($document), 'несогласованный документ публиковать нельзя');

        // Кнопка «Опубликовать» заблокирована, прямой POST отклоняется с понятным сообщением.
        $this->loginAs('ivanov');
        $crawler = $this->client->request('GET', '/documents/'.$document->getId());
        self::assertSelectorExists('.card--actions button[disabled]');
        self::assertStringContainsString('Отправить на согласование', $crawler->filter('.card--actions')->text());
        $this->client->request('POST', '/documents/'.$document->getId().'/publish', ['_token' => $this->csrf($crawler)]);
        self::assertResponseRedirects('/documents/'.$document->getId());
        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('только согласованные документы', $crawler->text());
        self::assertFalse($this->document('СОГЛ-002')->isPublished());

        // Отклонение возвращает документ в черновики с комментарием.
        $approval = $this->service()->request($this->document('СОГЛ-002'), $this->user('kuznetsova'), null, $this->user('ivanov'));
        $this->service()->decide($approval, false, 'Не указан ответственный', $this->user('kuznetsova'));
        self::assertTrue($approval->isRejected());
        self::assertTrue($this->document('СОГЛ-002')->isDraft());
        self::assertFalse($this->service()->canPublish($this->document('СОГЛ-002')));

        // После согласования публикация разрешена (автопубликация выключена).
        $approval = $this->service()->request($this->document('СОГЛ-002'), $this->user('kuznetsova'), null, $this->user('ivanov'));
        $this->service()->decide($approval, true, null, $this->user('kuznetsova'));
        self::assertTrue($this->document('СОГЛ-002')->isDraft(), 'без автопубликации документ остаётся черновиком');
        self::assertTrue($this->service()->canPublish($this->document('СОГЛ-002')));

        $this->loginAs('ivanov');
        $crawler = $this->client->request('GET', '/documents/'.$document->getId());
        self::assertSelectorNotExists('.card--actions button[disabled]');
        $this->client->request('POST', '/documents/'.$document->getId().'/publish', ['_token' => $this->csrf($crawler)]);
        self::assertResponseRedirects('/documents/'.$document->getId());
        self::assertTrue($this->document('СОГЛ-002')->isPublished());
    }

    public function testApprovalWebFlow(): void
    {
        $document = $this->draft('СОГЛ-003', 'Инструкция по приёмке');
        $id = (int) $document->getId();

        // Модератор отправляет на согласование через форму.
        $this->loginAs('ivanov');
        $crawler = $this->client->request('GET', '/documents/'.$id.'/approval');
        self::assertResponseIsSuccessful();
        $this->client->request('POST', '/documents/'.$id.'/approval', [
            '_token' => $this->csrf($crawler),
            'approver' => (string) $this->user('kuznetsova')->getId(),
            'note' => 'Посмотрите раздел 2',
        ]);
        self::assertResponseRedirects('/documents/'.$id);
        self::assertSame('На согласовании', trim($this->client->followRedirect()->filter('.badge')->first()->text()));

        // Согласующий решает из списка «Мне на согласование».
        $this->loginAs('kuznetsova');
        $crawler = $this->client->request('GET', '/profile/approvals');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Инструкция по приёмке', $crawler->text());
        self::assertStringContainsString('Посмотрите раздел 2', $crawler->text());
        $approval = $this->approvals()->findPending($this->document('СОГЛ-003'));
        self::assertNotNull($approval);
        $token = (string) $crawler->filter('form[action="/approvals/'.$approval->getId().'/decide"] input[name="_token"]')->attr('value');
        $this->client->request('POST', '/approvals/'.$approval->getId().'/decide', [
            '_token' => $token,
            'from_list' => '1',
            'decision' => 'approve',
            'note' => 'Ок',
        ]);
        self::assertResponseRedirects('/profile/approvals');
        self::assertTrue($this->approvals()->isApproved($this->document('СОГЛ-003'), 1));

        // Посторонний сотрудник решение принять не может.
        $document = $this->draft('СОГЛ-004');
        $approval = $this->service()->request($document, $this->user('kuznetsova'), null, $this->user('ivanov'));
        try {
            $this->service()->decide($approval, true, null, $this->user('smirnov'));
            self::fail('решение принимает только назначенный согласующий');
        } catch (\DomainException $e) {
            self::assertStringContainsString('назначенный согласующий', $e->getMessage());
        }
        $this->loginAs('smirnov');
        $this->client->request('POST', '/approvals/'.$approval->getId().'/decide', ['_token' => 'подделка', 'decision' => 'approve']);
        self::assertResponseStatusCodeSame(403);
        self::assertTrue($this->approvals()->findPending($this->document('СОГЛ-004'))?->isPending());

        // Автор отзывает запрос — документ снова черновик.
        $this->loginAs('ivanov');
        $cancelToken = $this->tokenFrom('/documents/'.$document->getId(), 'form[action="/approvals/'.$approval->getId().'/cancel"] input[name="_token"]');
        $this->client->request('POST', '/approvals/'.$approval->getId().'/cancel', ['_token' => $cancelToken]);
        self::assertResponseRedirects('/documents/'.$document->getId());
        self::assertTrue($this->document('СОГЛ-004')->isDraft());
        self::assertNull($this->approvals()->findPending($this->document('СОГЛ-004')));

        // Сводка администратора.
        $this->loginAs('admin');
        $crawler = $this->client->request('GET', '/admin/approvals');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Незакрытых согласований нет', $crawler->text());
    }

    public function testQuizBlocksConfirmationUntilAllAnswersAreCorrect(): void
    {
        $quiz = static::getContainer()->get(QuizService::class);
        $document = $this->document('ОТ-001');
        self::assertFalse($quiz->hasQuiz($document));

        $first = $quiz->save($document, null, 'Как часто проводится повторный инструктаж?', ['Раз в полгода', 'Раз в пять лет', 'Никогда'], 0);
        $second = $quiz->save($document, null, 'Что делать при неисправной розетке?', ['Починить самому', 'Сообщить руководителю'], 1);
        self::assertTrue($quiz->hasQuiz($document));
        self::assertSame(2, $quiz->count($document));
        self::assertSame(['Раз в полгода', 'Раз в пять лет', 'Никогда'], $first->getOptions());

        // Вопрос без текста или с одним вариантом не сохраняется.
        try {
            $quiz->save($document, null, '   ', ['а', 'б'], 0);
            self::fail('пустой вопрос');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('не может быть пустым', $e->getMessage());
        }
        try {
            $quiz->save($document, null, 'Вопрос', ['только один'], 0);
            self::fail('один вариант');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('двух вариантов', $e->getMessage());
        }
        try {
            $quiz->save($document, null, 'Вопрос', ['а', 'б'], 5);
            self::fail('верный вариант вне списка');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('верный вариант', $e->getMessage());
        }

        // Проверка ответов.
        $wrong = $quiz->check($document, [$first->getId() => 1, $second->getId() => 1]);
        self::assertFalse($wrong['passed']);
        self::assertSame(1, $wrong['correct']);
        self::assertSame([$first->getId()], $wrong['wrong']);
        $right = $quiz->check($document, [$first->getId() => 0, $second->getId() => 1]);
        self::assertTrue($right['passed']);
        self::assertSame(2, $right['correct']);
        self::assertFalse($quiz->check($document, [])['passed'], 'без ответов проверка не пройдена');

        // Веб-поток сотрудника: кнопка «Ознакомлен» ведёт на проверку знаний.
        $acknowledgements = static::getContainer()->get(DocumentAcknowledgementRepository::class);
        $id = (int) $document->getId();
        $this->loginAs('smirnov');
        self::assertNotNull($acknowledgements->findPending($this->document('ОТ-001'), $this->user('smirnov')), 'в демо-данных ознакомление назначено');
        $crawler = $this->client->request('GET', '/documents/'.$id);
        $this->client->submitForm('Ознакомлен');
        self::assertResponseRedirects('/documents/'.$id.'/acknowledge/quiz');
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('.h-section', 'Проверка знаний');
        self::assertSame(2, $crawler->filter('.quiz-question')->count());

        // Неверные ответы: ознакомление не подтверждается, попытка засчитывается.
        $quizToken = (string) $crawler->filter('form input[name="_token"]')->first()->attr('value');
        $this->client->request('POST', '/documents/'.$id.'/acknowledge/quiz', [
            '_token' => $quizToken,
            'answers' => [(string) $first->getId() => '1', (string) $second->getId() => '0'],
        ]);
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.quiz-question.is-wrong');
        $row = $acknowledgements->findPending($this->document('ОТ-001'), $this->user('smirnov'));
        self::assertNotNull($row, 'ознакомление осталось неподтверждённым');
        self::assertSame(1, $row->getQuizAttempts());
        self::assertSame(0, $row->getQuizScore());

        // Верные ответы: ознакомление подтверждено, результат сохранён.
        $this->client->request('POST', '/documents/'.$id.'/acknowledge/quiz', [
            '_token' => $quizToken,
            'answers' => [(string) $first->getId() => '0', (string) $second->getId() => '1'],
        ]);
        self::assertResponseRedirects('/documents/'.$id);
        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('Проверка знаний пройдена (2 из 2)', $crawler->text());
        $confirmed = $acknowledgements->findOneFor($this->document('ОТ-001'), $this->user('smirnov'), 1);
        self::assertNotNull($confirmed);
        self::assertTrue($confirmed->isConfirmed());
        self::assertSame(2, $confirmed->getQuizAttempts());
        self::assertSame(2, $confirmed->getQuizScore());
        self::assertSame(2, $confirmed->getQuizTotal());
        self::assertTrue($confirmed->hasQuizResult());

        // Результат виден модератору в отчёте и в выгрузке.
        $this->loginAs('ivanov');
        $crawler = $this->client->request('GET', '/documents/'.$id.'/acknowledgements');
        self::assertStringContainsString('2 из 2', $crawler->text());
        self::assertStringContainsString('попыток: 2', $crawler->text());
        $this->client->request('GET', '/documents/'.$id.'/acknowledgements.csv');
        $csv = (string) $this->client->getInternalResponse()->getContent();
        self::assertStringContainsString('Проверка знаний', $csv);
        self::assertStringContainsString('2 из 2 (попыток: 2)', $csv);

        // Удаление вопроса выключает проверку (сущности после HTTP-запросов берём заново).
        foreach (static::getContainer()->get(DocumentQuestionRepository::class)->findForDocument($this->document('ОТ-001')) as $question) {
            $quiz->delete($question);
        }
        self::assertFalse($quiz->hasQuiz($this->document('ОТ-001')));
        self::assertSame(0, static::getContainer()->get(DocumentQuestionRepository::class)->countForDocument($this->document('ОТ-001')));
    }

    public function testQuestionsPageIsForModeratorsOnly(): void
    {
        $document = $this->document('ОТ-001');
        $id = (int) $document->getId();

        $this->loginAs('smirnov');
        $this->client->request('GET', '/documents/'.$id.'/questions');
        self::assertResponseStatusCodeSame(403);

        $this->loginAs('ivanov');
        $crawler = $this->client->request('GET', '/documents/'.$id.'/questions');
        self::assertResponseIsSuccessful();
        $before = static::getContainer()->get(QuizService::class)->count($this->document('ОТ-001'));
        $this->client->request('POST', '/documents/'.$id.'/questions', [
            '_token' => (string) $crawler->filter('form input[name="_token"]')->first()->attr('value'),
            'text' => 'Кто отвечает за инструктаж?',
            'options' => ['Руководитель подразделения', 'Любой сотрудник', ''],
            'correct' => '0',
        ]);
        self::assertResponseRedirects('/documents/'.$id.'/questions');
        $crawler = $this->client->followRedirect();
        // Текст вопроса редактируется в поле ввода, поэтому ищем его в значении, а не в тексте страницы.
        self::assertContains('Кто отвечает за инструктаж?', $crawler->filter('input[name="text"]')->each(static fn ($n): string => (string) $n->attr('value')));
        self::assertSame($before + 1, static::getContainer()->get(QuizService::class)->count($this->document('ОТ-001')));
        // Пустой вариант отброшен: остаются два.
        $saved = static::getContainer()->get(DocumentQuestionRepository::class)->findForDocument($this->document('ОТ-001'));
        self::assertSame(['Руководитель подразделения', 'Любой сотрудник'], end($saved)->getOptions());

        // Уже подтверждённое ознакомление появление вопросов не отменяет.
        $service = static::getContainer()->get(AcknowledgementService::class);
        self::assertNull($service->confirm($this->document('ОТ-001'), $this->user('sidorov')), 'подтверждённое ранее ознакомление не меняется');

        // Удаление документа уносит и вопросы (внешний ключ с CASCADE).
        $questions = static::getContainer()->get(DocumentQuestionRepository::class);
        $fresh = $this->draft('ВОПР-001');
        $freshId = (int) $fresh->getId();
        static::getContainer()->get(QuizService::class)->save($fresh, null, 'Вопрос?', ['да', 'нет'], 0);
        self::assertSame(1, $questions->countForDocument($fresh));
        static::getContainer()->get(DocumentManager::class)->delete($this->document('ВОПР-001'), $this->user('ivanov'));
        $this->em()->clear();
        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM document_question WHERE document_id = ?', [$freshId]));
    }

    private function csrf(\Symfony\Component\DomCrawler\Crawler $crawler): string
    {
        return (string) $crawler->filter('input[name="_token"]')->first()->attr('value');
    }

    /** Токен берём из страницы: вне запроса сессии (и хранилища токенов) нет. */
    private function tokenFrom(string $url, string $selector = 'form input[name="_token"]'): string
    {
        $crawler = $this->client->request('GET', $url);
        self::assertResponseIsSuccessful($url);

        return (string) $crawler->filter($selector)->first()->attr('value');
    }
}
