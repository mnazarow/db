<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Document;
use App\Service\AcknowledgementService;
use App\Service\DocumentManager;
use App\Service\Org\TeamDigest;
use App\Repository\UserRepository;
use App\Service\Notification\TelegramNotifier;
use App\Service\PortalSettings;
use App\Service\PortalUrl;
use App\Service\ProcessLock;
use App\Tests\PortalTestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\RawMessage;

/**
 * Еженедельная сводка руководителям: кому уходит (по оргструктуре, на всех уровнях), порядок сотрудников
 * (сначала с просроченным), скрытие названий документов, закрытых для руководителя, «только с невыполненным»,
 * выключенная сводка, защита от повторной рассылки, команда, письмо и настройки в панели администратора.
 */
final class TeamDigestTest extends PortalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->cleanUp();
    }

    protected function tearDown(): void
    {
        // Оргструктура, ознакомления и включённая сводка этого теста не должны влиять на другие тесты.
        $this->cleanUp();
        parent::tearDown();
    }

    private function cleanUp(): void
    {
        $em = $this->em();
        $em->clear();
        foreach (static::getContainer()->get('App\Repository\UserRepository')->findAll() as $user) {
            $user->setManager(null);
        }
        $em->flush();
        $em->getConnection()->executeStatement('DELETE FROM document_acknowledgement');
        $this->settings()->setTeamDigest(false, true);
        $em->getConnection()->executeStatement('DELETE FROM setting WHERE name = ?', [PortalSettings::TEAM_DIGEST_LAST_RUN]);
    }

    public function testDigestForManagers(): void
    {
        self::assertStringContainsString('некому', $this->digest()->run(true, true)['message'], 'руководителей в оргструктуре нет');

        $sidorov = $this->user('sidorov');
        $ivanov = $this->user('ivanov')->setManager($sidorov);
        $smirnov = $this->user('smirnov')->setManager($ivanov);
        $this->em()->flush();
        $acks = static::getContainer()->get(AcknowledgementService::class);
        $admin = $this->user('admin');
        $acks->assign($this->document('ПОЛ-001'), [$smirnov], new \DateTimeImmutable('-3 days'), $admin, false);
        $acks->assign($this->document('ПОЛ-001'), [$ivanov], new \DateTimeImmutable('+5 days'), $admin, false);
        // Документ с ограниченным доступом, закрытый для руководителя Сидорова: в его сводке — без названия.
        $secret = $this->restricted('ДИГ-001', ['smirnov']);
        $acks->assign($secret, [$smirnov], new \DateTimeImmutable('+5 days'), $admin, false);

        $digests = $this->digest()->collect();
        $byManager = [];
        foreach ($digests as $d) {
            $byManager[$d['manager']->getUsername()] = $d;
        }
        $managers = array_keys($byManager);
        sort($managers);
        self::assertSame(['ivanov', 'sidorov'], $managers, 'сводка — каждому руководителю цепочки');
        $top = $byManager['sidorov'];
        self::assertSame(2, $top['totals']['people'], 'сотрудники на всех уровнях');
        self::assertSame(3, $top['totals']['pending']);
        self::assertSame(1, $top['totals']['overdue']);
        self::assertSame('smirnov', $top['people'][0]['user']->getUsername(), 'сначала — с просроченным');
        $titles = array_column($top['people'][0]['shown'], 'title');
        self::assertContains($this->document('ПОЛ-001')->getTitle(), $titles);
        self::assertNotContains($secret->getTitle(), $titles, 'закрытый для руководителя документ — без названия');
        self::assertSame(1, $top['people'][0]['hidden']);
        self::assertSame(1, $byManager['ivanov']['totals']['people']);

        // Письмо: имена, документы, просрочка, ссылка на «Мои сотрудники»; закрытого названия нет.
        $email = (new \ReflectionMethod(TeamDigest::class, 'email'))->invoke($this->digest(), $top);
        self::assertStringContainsString('не выполнено 3, просрочено 1', $email->getSubject());
        $html = static::getContainer()->get('twig')->render('emails/team_digest.html.twig', $email->getContext());
        self::assertStringContainsString('Смирнов', $html);
        self::assertStringContainsString('/profile/team', $html);
        self::assertStringContainsString('просрочено 1', $html);
        self::assertStringNotContainsString($secret->getTitle(), $html);
        $text = static::getContainer()->get('twig')->render('emails/team_digest.txt.twig', $email->getContext());
        self::assertStringContainsString('Мои сотрудники: http', $text);
        $telegram = (new \ReflectionMethod(TeamDigest::class, 'telegramText'))->invoke($this->digest(), $top);
        self::assertStringContainsString('Мои сотрудники: не выполнено 3, просрочено 1', $telegram);

        // Выключена — не отправляется; включена — уходит раз в неделю.
        self::assertSame('disabled', $this->digest()->run()['status']);
        $this->settings()->setTeamDigest(true, true);
        $result = $this->digest()->run();
        self::assertSame(2, $result['managers']);
        self::assertSame(2, $result['emails']);
        self::assertSame('skipped', $this->digest()->run()['status'], 'второй раз за неделю — нет');
        self::assertStringContainsString('только что', $this->digest()->run(false, true)['message']);

        // Почта не работает: ни одно письмо не ушло — ошибка, а неделя не считается отправленной.
        $weekAgo = date('Y-m-d H:i:s', strtotime('-8 days'));
        $this->settings()->set(PortalSettings::TEAM_DIGEST_LAST_RUN, $weekAgo);
        $mailer = new class implements MailerInterface {
            public function send(RawMessage $message, ?Envelope $envelope = null): void
            {
                throw new TransportException('SMTP недоступен');
            }
        };
        $c = static::getContainer();
        $broken = new TeamDigest($c->get(UserRepository::class), $c->get(AcknowledgementService::class), $this->settings(), $c->get(PortalUrl::class),
            $mailer, $c->get(TelegramNotifier::class), $c->get('monolog.logger.app_audit'), 'portal@example.com', 'Портал', $c->get(ProcessLock::class));
        $failed = $broken->run();
        self::assertSame('error', $failed['status']);
        self::assertStringContainsString('ни одно из 2 писем не ушло', $failed['message']);
        self::assertSame($weekAgo, $this->settings()->fresh(PortalSettings::TEAM_DIGEST_LAST_RUN), 'после исправления почты сводка уйдёт');
        $tester = new CommandTester((new Application(static::$kernel))->find('app:team:digest'));
        self::assertSame(0, $tester->execute([]), 'почта снова работает');
        self::assertStringContainsString('писем 2', $tester->getDisplay());

        // Всё выполнено — руководителю без невыполненного сводка не уходит («только если есть невыполненное»).
        $this->em()->getConnection()->executeStatement('UPDATE document_acknowledgement SET confirmed_at = ? WHERE user_id = ?', [date('Y-m-d H:i:s'), $smirnov->getId()]);
        $this->em()->clear();
        $managers = array_map(static fn (array $d): string => $d['manager']->getUsername(), $this->digest()->collect());
        self::assertSame(['sidorov'], $managers, 'у Иванова всё выполнено');
        self::assertCount(2, $this->digest()->collect(null, false), 'без ограничения — всем руководителям');

        // Команда: пробный запуск и письмо одному руководителю.
        $tester = new CommandTester((new Application(static::$kernel))->find('app:team:digest'));
        $tester->execute(['--dry-run' => true, '--force' => true]);
        self::assertStringContainsString('Пробный запуск', $tester->getDisplay());
        self::assertStringContainsString('Сидоров', $tester->getDisplay());
        $tester->execute(['--manager' => 'sidorov']);
        self::assertStringContainsString('руководителей 1', $tester->getDisplay());
        $tester->execute(['--manager' => 'nobody-here']);
        self::assertSame(1, $tester->getStatusCode());
    }

    public function testAdminSettings(): void
    {
        $this->user('ivanov')->setManager($this->user('sidorov'));
        $this->em()->flush();
        static::getContainer()->get(AcknowledgementService::class)->assign($this->document('ПОЛ-001'), [$this->user('ivanov')], new \DateTimeImmutable('-1 day'), $this->user('admin'), false);

        $this->loginAs('admin');
        $crawler = $this->client->request('GET', '/admin/acknowledgements');
        self::assertSelectorTextContains('#team-digest', 'Руководителей в оргструктуре: 1');
        $form = $crawler->filter('form[action$="/admin/acknowledgements/team-digest"]')->form(['enabled' => '1']);
        $form['only_pending']->untick();
        $this->client->submit($form);
        self::assertResponseRedirects();
        self::assertTrue($this->settings()->teamDigest()['enabled']);
        self::assertFalse($this->settings()->teamDigest()['only_pending'], 'флажок снят');
        $crawler = $this->client->request('GET', '/admin/acknowledgements');
        $this->client->submit($crawler->filter('#team-digest form[action$="/send"]')->first()->form());
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert', 'Сидоров');
        self::assertNull($this->settings()->teamDigest()['last_run'], 'пробный запуск ничего не отправляет');

        $this->loginAs('smirnov');
        $this->client->request('POST', '/admin/acknowledgements/team-digest/send', ['_token' => 'x', 'mode' => 'send']);
        self::assertResponseStatusCodeSame(403);
    }

    private function digest(): TeamDigest
    {
        return static::getContainer()->get(TeamDigest::class);
    }

    private function settings(): PortalSettings
    {
        return static::getContainer()->get(PortalSettings::class);
    }

    /** @param list<string> $usernames */
    private function restricted(string $code, array $usernames): Document
    {
        $existing = static::getContainer()->get('App\Repository\DocumentRepository')->findOneBy(['code' => $code]);
        if (null !== $existing) {
            return $existing;
        }
        $document = (new Document($this->section('Общие документы')))->setTitle('Положение о премировании '.$code)->setCode($code)->setType(Document::TYPE_PAGE);
        static::getContainer()->get(DocumentManager::class)->create($document, null, '<p>Текст</p>', null, $this->user('admin'), true);
        $document->setRestricted(true)->setAllowedUsers(array_map(fn (string $name) => $this->user($name), $usernames));
        $this->em()->flush();

        return $document;
    }
}
