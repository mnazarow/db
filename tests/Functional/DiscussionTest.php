<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Document;
use App\Entity\DocumentComment;
use App\Repository\DocumentCommentRepository;
use App\Repository\DocumentSubscriptionRepository;
use App\Service\CommentService;
use App\Service\DocumentManager;
use App\Service\Http\HttpResponse;
use App\Service\Http\RecordingTransport;
use App\Service\Notification\SubscriptionNotifier;
use App\Service\Notification\TelegramNotifier;
use App\Service\PortalSettings;
use App\Service\SubscriptionService;
use App\Tests\PortalTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Обсуждение документов, подписка на изменения и уведомления в Telegram.
 */
final class DiscussionTest extends PortalTestCase
{
    private function comments(): CommentService
    {
        return static::getContainer()->get(CommentService::class);
    }

    private function subscriptions(): SubscriptionService
    {
        return static::getContainer()->get(SubscriptionService::class);
    }

    private function notifier(): SubscriptionNotifier
    {
        return static::getContainer()->get(SubscriptionNotifier::class);
    }

    private function telegram(): TelegramNotifier
    {
        return static::getContainer()->get(TelegramNotifier::class);
    }

    private function http(): RecordingTransport
    {
        return static::getContainer()->get(RecordingTransport::class);
    }

    private function settings(): PortalSettings
    {
        return static::getContainer()->get(PortalSettings::class);
    }

    /** Свежий опубликованный документ, чтобы тесты не мешали друг другу. */
    private function freshDocument(string $code, string $title = 'Регламент обсуждения'): Document
    {
        $document = (new Document($this->section('Общие документы')))->setTitle($title)->setCode($code)->setType(Document::TYPE_FILE)->setPublic(false);
        $path = sys_get_temp_dir().'/'.bin2hex(random_bytes(4)).'-doc.txt';
        file_put_contents($path, 'Текст документа '.$code);
        static::getContainer()->get(DocumentManager::class)->create($document, new UploadedFile($path, 'doc.txt', null, null, true), null, null, $this->user('ivanov'), true);

        return $document;
    }

    /** Читает CSRF-токен со страницы документа (в тестах менеджер токенов недоступен напрямую). */
    private function documentToken(int $id): string
    {
        $crawler = $this->client->request('GET', '/documents/'.$id);
        self::assertResponseIsSuccessful();
        $token = $crawler->filter('#comment-form input[name="_token"]')->attr('value');
        self::assertNotEmpty($token);

        return (string) $token;
    }

    public function testCommentsAndReplies(): void
    {
        $document = $this->freshDocument('ОБС-001');
        $id = (int) $document->getId();

        // Сотрудник задаёт вопрос через форму в карточке.
        $this->loginAs('smirnov');
        $this->client->request('POST', '/documents/'.$id.'/comments', ['_token' => $this->documentToken($id), 'body' => "Вопрос по пункту 3.2:\n\n\nкогда вступает в силу?"]);
        self::assertResponseRedirects();
        $comments = static::getContainer()->get(DocumentCommentRepository::class);
        $first = $comments->findForDocument($this->document('ОБС-001'))[0];
        self::assertSame('Смирнов Дмитрий Олегович', $first->getAuthorName());
        self::assertSame("Вопрос по пункту 3.2:\n\nкогда вступает в силу?", $first->getBody(), 'лишние пустые строки убираются');
        self::assertSame(1, $first->getVersionNumber(), 'реплика привязана к текущей редакции');
        self::assertSame(1, $comments->countForDocument($this->document('ОБС-001')));

        // Ответ модератора крепится к исходной реплике.
        $this->loginAs('ivanov');
        $this->client->request('POST', '/documents/'.$id.'/comments', ['_token' => $this->documentToken($id), 'parent' => (string) $first->getId(), 'body' => 'С даты утверждения.']);
        self::assertResponseRedirects();
        $thread = $comments->findForDocument($this->document('ОБС-001'));
        self::assertCount(2, $thread);
        self::assertTrue($thread[1]->isReply());
        self::assertSame($thread[0]->getId(), $thread[1]->getParent()?->getId());

        // Ответ на ответ поднимается на верхний уровень ветки — глубже одного уровня не уходим.
        $this->client->request('POST', '/documents/'.$id.'/comments', ['_token' => $this->documentToken($id), 'parent' => (string) $thread[1]->getId(), 'body' => 'Уточнение к ответу.']);
        $thread = $comments->findForDocument($this->document('ОБС-001'));
        self::assertCount(3, $thread);
        self::assertSame($thread[0]->getId(), $thread[2]->getParent()?->getId());

        // Пустое сообщение не принимается.
        $this->client->request('POST', '/documents/'.$id.'/comments', ['_token' => $this->documentToken($id), 'body' => "   \n "]);
        self::assertSame(3, $comments->countForDocument($this->document('ОБС-001')));

        // Автор правит свою реплику, чужую — нет.
        $own = $comments->findForDocument($this->document('ОБС-001'))[1];
        $this->client->request('POST', '/documents/'.$id.'/comments/'.$own->getId().'/edit', ['_token' => $this->documentToken($id), 'body' => 'С даты утверждения, переходных положений нет.']);
        self::assertResponseRedirects();
        $this->em()->clear();
        self::assertStringContainsString('переходных положений', $comments->find($own->getId())?->getBody() ?? '');
        self::assertNotNull($comments->find($own->getId())?->getEditedAt());

        $this->loginAs('smirnov');
        $this->client->request('POST', '/documents/'.$id.'/comments/'.$own->getId().'/edit', ['_token' => $this->documentToken($id), 'body' => 'Подмена чужой реплики.']);
        self::assertResponseStatusCodeSame(403);

        // Модератор раздела удаляет любую реплику: она остаётся с отметкой «удалено».
        $alien = $comments->findForDocument($this->document('ОБС-001'))[0];
        $this->loginAs('ivanov');
        $this->client->request('POST', '/documents/'.$id.'/comments/'.$alien->getId().'/delete', ['_token' => $this->documentToken($id)]);
        self::assertResponseRedirects();
        $this->em()->clear();
        $deleted = $comments->find($alien->getId());
        self::assertNotNull($deleted);
        self::assertTrue($deleted->isDeleted());
        self::assertSame('', $deleted->getBody());
        self::assertSame('Иванов Игорь Петрович', $deleted->getDeletedByName());
        self::assertSame(2, $comments->countForDocument($this->document('ОБС-001')), 'удалённая реплика не считается');

        // Обсуждение можно выключить в настройках портала.
        $this->settings()->setDiscussion(false, true);
        $crawler = $this->client->request('GET', '/documents/'.$id);
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('Обсуждение', $crawler->filter('.doc-main')->text());
        $this->settings()->setDiscussion(true, true);
    }

    public function testSubscriptionNotifiesByMailAndTelegram(): void
    {
        $document = $this->freshDocument('ОБС-002', 'Порядок уведомлений');
        $id = (int) $document->getId();
        $subscriptions = static::getContainer()->get(DocumentSubscriptionRepository::class);

        // Подписка на документ из карточки.
        $this->loginAs('smirnov');
        $this->client->request('POST', '/documents/'.$id.'/subscribe', ['_token' => $this->documentToken($id)]);
        self::assertResponseRedirects();
        self::assertTrue($this->subscriptions()->isSubscribedToDocument($this->user('smirnov'), $this->document('ОБС-002')));

        // Подписка на раздел покрывает и подразделы.
        $this->subscriptions()->toggleSection($this->user('petrova'), $this->section('Общие документы'));
        $pathIds = $this->document('ОБС-002')->getSection()->getPathIds();
        $names = array_map(static fn ($u) => $u->getUsername(), $subscriptions->subscribers($this->document('ОБС-002'), $pathIds));
        self::assertContains('smirnov', $names);
        self::assertContains('petrova', $names);

        // Telegram: бот настроен, чат сотрудника привязан.
        $this->settings()->setTelegram(['enabled' => true, 'token' => '123:TEST', 'bot_name' => 'docportal_bot', 'api_url' => 'http://telegram.test', 'admin_chat' => '']);
        $smirnov = $this->user('smirnov');
        $smirnov->linkTelegram('1001', 'Алексей');
        $this->em()->flush();
        $this->http()->reset();
        $this->http()->setDefault(new HttpResponse(200, '{"ok":true,"result":{"message_id":1}}'));

        // Новая реплика в обсуждении от другого сотрудника.
        $this->comments()->add($this->document('ОБС-002'), $this->user('ivanov'), 'Вышла новая редакция, посмотрите изменения в пункте 4.');
        $stats = $this->notifier()->flush();
        self::assertSame(1, $stats['documents']);
        self::assertGreaterThanOrEqual(2, $stats['recipients'], 'подписчик документа и подписчик раздела');
        self::assertGreaterThanOrEqual(1, $stats['telegram']);
        $request = $this->http()->last();
        self::assertNotNull($request);
        self::assertStringStartsWith('http://telegram.test/bot123:TEST/sendMessage', $request['url']);
        $payload = json_decode((string) $request['body'], true);
        self::assertSame('1001', $payload['chat_id']);
        self::assertStringContainsString('Порядок уведомлений', $payload['text']);
        self::assertStringContainsString('пункте 4', $payload['text']);

        // Автор изменения себе уведомление не получает.
        $this->http()->reset();
        $ivanov = $this->user('ivanov');
        $ivanov->linkTelegram('2002', 'Игорь');
        $this->em()->flush();
        $this->comments()->add($this->document('ОБС-002'), $ivanov, 'Дополнение от автора.');
        $this->notifier()->flush();
        foreach ($this->http()->requests as $sent) {
            $payload = json_decode((string) $sent['body'], true);
            self::assertNotSame('2002', $payload['chat_id'] ?? null, 'себе уведомление не уходит');
        }

        // Документ с ограниченным доступом не раскрывается подписчику раздела.
        $restricted = $this->freshDocument('ОБС-003', 'Приказ с ограничением');
        $restricted->setRestricted(true)->setAllowedDepartments(['Отдел кадров']);
        $this->em()->flush();
        $this->http()->reset();
        $this->comments()->add($this->document('ОБС-003'), $this->user('kuznetsova'), 'Реплика по закрытому документу.');
        $stats = $this->notifier()->flush();
        self::assertSame(0, $stats['telegram'], 'посторонним подписчикам уведомление не уходит');

        // Отписка через профиль.
        $this->loginAs('smirnov');
        $crawler = $this->client->request('GET', '/profile/subscriptions');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Порядок уведомлений', $crawler->text());
        $subscription = $subscriptions->findForDocument($this->user('smirnov'), $this->document('ОБС-002'));
        self::assertNotNull($subscription);
        $token = (string) $crawler->filter('form[action$="/remove"] input[name="_token"]')->first()->attr('value');
        $this->client->request('POST', '/profile/subscriptions/'.$subscription->getId().'/remove', ['_token' => $token]);
        self::assertResponseRedirects();
        self::assertFalse($this->subscriptions()->isSubscribedToDocument($this->user('smirnov'), $this->document('ОБС-002')));

        $this->settings()->setTelegram(['enabled' => false, 'token' => '', 'bot_name' => '', 'api_url' => '', 'admin_chat' => '']);
    }

    public function testTelegramLinkingByCode(): void
    {
        $this->settings()->setTelegram(['enabled' => true, 'token' => '123:TEST', 'bot_name' => 'docportal_bot', 'api_url' => 'http://telegram.test', 'admin_chat' => '']);
        $user = $this->user('petrova');
        $user->unlinkTelegram();
        $this->em()->flush();

        $code = $this->telegram()->issueCode($user);
        self::assertMatchesRegularExpression('/^[0-9A-F]{8}$/', $code);
        self::assertSame($code, $this->user('petrova')->getTelegramCode());
        self::assertSame('https://t.me/docportal_bot?start='.$code, $this->telegram()->linkUrl($code));

        // Бот отдаёт сообщение с кодом — портал привязывает чат и отвечает подтверждением.
        $this->http()->reset();
        $this->http()->enqueue(
            new HttpResponse(200, json_encode(['ok' => true, 'result' => [[
                'update_id' => 10,
                'message' => ['message_id' => 1, 'chat' => ['id' => 4004], 'from' => ['first_name' => 'Мария'], 'text' => '/start '.$code],
            ]]], \JSON_UNESCAPED_UNICODE)),
            new HttpResponse(200, '{"ok":true,"result":{"message_id":2}}'),
        );
        $stats = $this->telegram()->poll();
        self::assertSame(['updates' => 1, 'linked' => 1, 'errors' => 0, 'messages' => []], $stats);
        $this->em()->clear();
        self::assertSame('4004', $this->user('petrova')->getTelegramChatId());
        self::assertNull($this->user('petrova')->getTelegramCode(), 'код гасится после привязки');
        self::assertSame(11, (int) $this->settings()->get(PortalSettings::TELEGRAM_OFFSET), 'смещение сдвигается, сообщения не разбираются дважды');

        // Устаревший код не срабатывает.
        $this->http()->reset();
        $this->http()->enqueue(
            new HttpResponse(200, json_encode(['ok' => true, 'result' => [[
                'update_id' => 20,
                'message' => ['message_id' => 3, 'chat' => ['id' => 5005], 'text' => 'ZZZZZZZZ'],
            ]]], \JSON_UNESCAPED_UNICODE)),
            new HttpResponse(200, '{"ok":true}'),
        );
        $stats = $this->telegram()->poll();
        self::assertSame(1, $stats['errors']);

        // Команда /stop отвязывает чат.
        $this->http()->reset();
        $this->http()->enqueue(
            new HttpResponse(200, json_encode(['ok' => true, 'result' => [[
                'update_id' => 30,
                'message' => ['message_id' => 4, 'chat' => ['id' => 4004], 'text' => '/stop'],
            ]]], \JSON_UNESCAPED_UNICODE)),
            new HttpResponse(200, '{"ok":true}'),
        );
        $this->telegram()->poll();
        $this->em()->clear();
        self::assertFalse($this->user('petrova')->hasTelegram());

        // Проверка связи из панели администратора подставляет имя бота.
        $this->http()->reset();
        $this->http()->setDefault(new HttpResponse(200, '{"ok":true,"result":{"username":"portal_bot"}}'));
        $result = $this->telegram()->check();
        self::assertTrue($result['ok']);
        self::assertSame('portal_bot', $this->settings()->telegram()['bot_name']);

        $this->settings()->setTelegram(['enabled' => false, 'token' => '', 'bot_name' => '', 'api_url' => '', 'admin_chat' => '']);
    }

    public function testCommentLengthAndCleanup(): void
    {
        self::assertSame('', DocumentComment::clean(null));
        self::assertSame("a\n\nb", DocumentComment::clean("a\r\n\r\n\r\n\r\nb"));
        self::assertSame(DocumentComment::MAX_LENGTH, mb_strlen(DocumentComment::clean(str_repeat('я', DocumentComment::MAX_LENGTH + 500))));
        self::assertSame('текст', DocumentComment::clean("\u{0000}текст\u{0007}"), 'управляющие символы удаляются');

        $document = $this->freshDocument('ОБС-004');
        $comment = $this->comments()->add($document, $this->user('ivanov'), str_repeat('слово ', 80));
        self::assertSame(200, mb_strlen($comment->excerpt()));
        self::assertStringEndsWith('…', $comment->excerpt());

        $this->expectException(\DomainException::class);
        $this->comments()->add($document, $this->user('ivanov'), '   ');
    }
}
