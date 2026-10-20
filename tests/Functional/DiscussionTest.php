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
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;
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
        self::assertSame(['updates' => 1, 'linked' => 1, 'answered' => 0, 'errors' => 0, 'skipped' => null, 'messages' => []], $stats);
        $this->em()->clear();
        self::assertSame('4004', $this->user('petrova')->getTelegramChatId());
        self::assertNull($this->user('petrova')->getTelegramCode(), 'код гасится после привязки');
        self::assertSame('123:11', $this->settings()->get(PortalSettings::TELEGRAM_OFFSET), 'смещение сдвигается, сообщения не разбираются дважды');

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

        // Следующий разбор продолжает с сохранённого места.
        $this->http()->reset();
        $this->http()->setDefault(new HttpResponse(200, '{"ok":true,"result":[]}'));
        $this->telegram()->poll();
        self::assertSame(31, json_decode((string) $this->http()->last()['body'], true)['offset']);

        // Токен заменили на другого бота: его номера сообщений свои, прежнее смещение не используется
        // (иначе Telegram считал бы новые сообщения уже прочитанными).
        $this->settings()->setTelegram(['enabled' => true, 'token' => '456:OTHER', 'bot_name' => 'docportal_bot', 'api_url' => 'http://telegram.test', 'admin_chat' => '']);
        $this->http()->reset();
        $this->http()->setDefault(new HttpResponse(200, '{"ok":true,"result":[]}'));
        $this->telegram()->poll();
        self::assertArrayNotHasKey('offset', json_decode((string) $this->http()->last()['body'], true), 'для нового бота разбор начинается с начала очереди');
        $this->settings()->setTelegram(['enabled' => true, 'token' => '123:TEST', 'bot_name' => 'docportal_bot', 'api_url' => 'http://telegram.test', 'admin_chat' => '']);

        // Пока разбор идёт в другом процессе (своё соединение с базой — например, контейнер cron), второй
        // запуск ничего не делает.
        $other = \Doctrine\DBAL\DriverManager::getConnection($this->em()->getConnection()->getParams());
        self::assertSame(1, (int) $other->fetchOne('SELECT GET_LOCK(?, 0)', [$this->telegram()->lockName()]));
        $this->http()->reset();
        try {
            $stats = $this->telegram()->poll();
        } finally {
            $other->fetchOne('SELECT RELEASE_LOCK(?)', [$this->telegram()->lockName()]);
            $other->close();
        }
        self::assertSame('busy', $stats['skipped']);
        self::assertSame([], $this->http()->requests, 'к Bot API не обращались');

        // Проверка связи из панели администратора подставляет имя бота.
        $this->http()->reset();
        $this->http()->setDefault(new HttpResponse(200, '{"ok":true,"result":{"username":"portal_bot"}}'));
        $result = $this->telegram()->check();
        self::assertTrue($result['ok']);
        self::assertSame('portal_bot', $this->settings()->telegram()['bot_name']);

        $this->settings()->setTelegram(['enabled' => false, 'token' => '', 'bot_name' => '', 'api_url' => '', 'admin_chat' => '']);
    }

    public function testTelegramMessagesSurviveLengthAndMarkup(): void
    {
        $this->settings()->setTelegram(['enabled' => true, 'token' => '123:TEST', 'bot_name' => 'docportal_bot', 'api_url' => 'http://telegram.test', 'admin_chat' => '']);

        // Короткое сообщение уходит с разметкой HTML.
        $this->http()->reset();
        $this->http()->setDefault(new HttpResponse(200, '{"ok":true,"result":{"message_id":1}}'));
        self::assertTrue($this->telegram()->send('1001', '<b>Заголовок</b> &amp; текст')['ok']);
        $payload = json_decode((string) $this->http()->last()['body'], true);
        self::assertSame('HTML', $payload['parse_mode']);
        self::assertSame('<b>Заголовок</b> &amp; текст', $payload['text']);

        // Длинное сообщение нельзя обрезать посередине разметки: оно уходит простым текстом, в пределах
        // ограничения Telegram (длина в символах UTF-16 — эмодзи занимают по два).
        $this->http()->reset();
        $this->http()->setDefault(new HttpResponse(200, '{"ok":true,"result":{"message_id":2}}'));
        $long = '<b>Документ</b> '.str_repeat('💬 текст &amp; ещё ', 400);
        self::assertTrue($this->telegram()->send('1001', $long)['ok']);
        $payload = json_decode((string) $this->http()->last()['body'], true);
        self::assertArrayNotHasKey('parse_mode', $payload);
        self::assertStringStartsWith('Документ 💬 текст & ещё', $payload['text']);
        self::assertStringEndsWith('…', $payload['text']);
        self::assertLessThanOrEqual(4000, \strlen(mb_convert_encoding($payload['text'], 'UTF-16LE', 'UTF-8')) / 2);

        // Разметку, которую Bot API не разобрал, отправка повторяет простым текстом.
        $this->http()->reset();
        $this->http()->enqueue(
            new HttpResponse(400, '{"ok":false,"error_code":400,"description":"Bad Request: can\'t parse entities: Unsupported start tag \"x\" at byte offset 0"}'),
            new HttpResponse(200, '{"ok":true,"result":{"message_id":3}}'),
        );
        self::assertTrue($this->telegram()->send('1001', '<x>Раздел</x> «Охрана труда»')['ok']);
        self::assertCount(2, $this->http()->requests);
        $payload = json_decode((string) $this->http()->last()['body'], true);
        self::assertArrayNotHasKey('parse_mode', $payload);
        self::assertSame('Раздел «Охрана труда»', $payload['text']);

        // Произвольный текст из консоли уходит без разметки: «<» — обычный символ.
        $this->http()->reset();
        $this->http()->setDefault(new HttpResponse(200, '{"ok":true,"result":{"message_id":4}}'));
        $tester = new CommandTester((new Application(self::$kernel))->find('app:telegram:poll'));
        self::assertSame(0, $tester->execute(['--send' => 'Давление < 6 бар & выше', '--chat' => '1001']));
        $payload = json_decode((string) $this->http()->last()['body'], true);
        self::assertArrayNotHasKey('parse_mode', $payload);
        self::assertSame('Давление < 6 бар & выше', $payload['text']);

        // Разбор по расписанию молчит, когда сообщений нет, — журнал cron не засоряется.
        $this->http()->reset();
        $this->http()->setDefault(new HttpResponse(200, '{"ok":true,"result":[]}'));
        self::assertSame(0, $tester->execute([]));
        self::assertSame('', trim($tester->getDisplay()));
        $this->settings()->setTelegram(['enabled' => false, 'token' => '', 'bot_name' => '', 'api_url' => '', 'admin_chat' => '']);
        self::assertSame(0, $tester->execute([]));
        self::assertSame('', trim($tester->getDisplay()), 'выключенные уведомления — тоже без вывода');
        self::assertSame(0, $tester->execute([], ['verbosity' => OutputInterface::VERBOSITY_VERBOSE]));
        self::assertStringContainsString('выключены', $tester->getDisplay());
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
