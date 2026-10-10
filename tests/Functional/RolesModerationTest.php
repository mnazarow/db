<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Document;
use App\Entity\DocumentApproval;
use App\Entity\User;
use App\Repository\DocumentApprovalRepository;
use App\Repository\DocumentRepository;
use App\Security\Access;
use App\Service\ApprovalService;
use App\Service\DocumentManager;
use App\Service\UserManager;
use App\Tests\PortalTestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Роли 1.8: маркетолог (модератор всех разделов), помощник маркетолога (публикует через модерацию)
 * и пользователь (видит только одобренное); общая очередь модерации.
 */
final class RolesModerationTest extends PortalTestCase
{
    private function approvals(): DocumentApprovalRepository
    {
        return static::getContainer()->get(DocumentApprovalRepository::class);
    }

    private function access(): Access
    {
        return static::getContainer()->get(Access::class);
    }

    private function upload(string $name, string $content): UploadedFile
    {
        $path = sys_get_temp_dir().'/'.bin2hex(random_bytes(4)).'-'.$name;
        file_put_contents($path, $content);

        return new UploadedFile($path, $name, null, null, true);
    }

    private function byTitle(string $title): Document
    {
        $this->em()->clear();
        $document = static::getContainer()->get(DocumentRepository::class)->findOneBy(['title' => $title]);
        self::assertNotNull($document, 'Документ не найден: '.$title);

        return $document;
    }

    /** Создаёт документ через форму от имени текущего пользователя. */
    private function createViaForm(string $sectionName, string $title, string $content, bool $publishOrSubmit = true): void
    {
        $section = $this->section($sectionName);
        $crawler = $this->client->request('GET', '/documents/new?section='.$section->getId().'&type=page');
        self::assertResponseIsSuccessful();
        $form = $crawler->filter('form[name=document]')->form();
        $values = $form->getPhpValues();
        $values['document']['title'] = $title;
        $values['document']['content'] = $content;
        if ($publishOrSubmit) {
            $values['document']['publish'] = '1';
        } else {
            unset($values['document']['publish']);
        }
        $this->client->request($form->getMethod(), $form->getUri(), $values, $form->getPhpFiles());
        self::assertResponseRedirects();
    }

    private function token(string $url, string $selector = 'input[name=_token]'): string
    {
        $crawler = $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();

        return (string) $crawler->filter($selector)->first()->attr('value');
    }

    public function testPositionsAndAccessRules(): void
    {
        $markova = $this->user('markova');
        $belov = $this->user('belov');
        $smirnov = $this->user('smirnov');
        self::assertSame([User::POSITION_MARKETER, User::POSITION_ASSISTANT, User::POSITION_USER, User::POSITION_ADMIN], [
            $markova->getPosition(), $belov->getPosition(), $smirnov->getPosition(), $this->user('admin')->getPosition(),
        ]);
        self::assertSame('Помощник маркетолога', $belov->getPositionLabel());

        // Роль одна: назначение новой снимает прежнюю, прочие роли сохраняются.
        $probe = (new User())->setUsername('probe')->setRoles(['ROLE_API_X', User::ROLE_ADMIN]);
        $probe->setPosition(User::POSITION_ASSISTANT);
        self::assertSame(['ROLE_API_X', User::ROLE_ASSISTANT, User::ROLE_USER], $probe->getRoles());
        self::assertTrue($probe->isAssistant());
        $probe->setPosition(User::POSITION_USER);
        self::assertSame(['ROLE_API_X', User::ROLE_USER], $probe->getRoles());

        $production = $this->section('Технологические карты');
        $access = $this->access();
        self::assertTrue($access->canManageSection($markova, $production), 'маркетолог управляет любым разделом');
        self::assertNull($access->managedSectionIds($markova));
        self::assertTrue($access->viewer($markova)->unrestricted);
        self::assertFalse($access->canManageSection($belov, $production));
        self::assertTrue($access->canContribute($belov, $production), 'помощник добавляет в любой раздел');
        self::assertTrue($access->needsModeration($belov, $production));
        self::assertFalse($access->needsModeration($markova, $production));
        self::assertFalse($access->canContribute($smirnov, $production));
        self::assertFalse($access->isContributor($smirnov));
        // Модератор своего раздела в нём публикует сам, даже если он ещё и помощник.
        self::assertFalse($access->needsModeration($this->user('sidorov'), $production));
    }

    public function testAssistantMaterialGoesThroughModeration(): void
    {
        // Помощник создаёт материал — он сохраняется черновиком и уходит в очередь модерации.
        $this->loginAs('belov');
        $crawler = $this->client->request('GET', '/documents/new?section='.$this->section('Инструкции')->getId().'&type=page');
        self::assertStringContainsString('Сразу отправить на модерацию', $crawler->text());
        self::assertStringNotContainsString('Опубликовать сразу', $crawler->text());
        $this->createViaForm('Инструкции', 'Памятка по выкладке каталога', '<p>Каталог выкладывается на стойку у входа.</p>');
        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('отправлен на модерацию', $crawler->text());

        $document = $this->byTitle('Памятка по выкладке каталога');
        self::assertTrue($document->isOnReview());
        $approval = $this->approvals()->findPending($document);
        self::assertNotNull($approval);
        self::assertTrue($approval->isModeration());
        self::assertSame('belov', $approval->getRequestedBy()?->getUsername());
        self::assertSame('на модерации', $approval->getDecisionLabel());

        // Письмо о модерации получают маркетологи и модераторы раздела, не автор.
        $recipients = array_map(static fn (User $u): string => $u->getUsername(), static::getContainer()->get(ApprovalService::class)->moderatorsFor($document));
        self::assertContains('markova', $recipients);
        self::assertContains('ivanov', $recipients, 'модератор «Общих документов» (по наследованию)');
        self::assertNotContains('belov', $recipients);
        self::assertNotContains('smirnov', $recipients);

        // Пользователь материал не видит, помощник — видит, в том числе в разделе.
        $this->loginAs('smirnov');
        $this->client->request('GET', '/documents/'.$document->getId());
        self::assertResponseStatusCodeSame(403);
        $this->loginAs('belov');
        $crawler = $this->client->request('GET', '/documents/'.$document->getId());
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Материал на модерации', $crawler->text());
        self::assertSelectorNotExists('form[action$="/publish"]');
        $crawler = $this->client->request('GET', '/sections/'.$this->section('Инструкции')->getId());
        self::assertStringContainsString('Памятка по выкладке каталога', $crawler->text());

        // Помощник не может опубликовать, архивировать, удалить и решать по модерации.
        $token = $this->token('/documents/'.$document->getId(), 'form[action$="/moderation"] input[name=_token], form[action*="/approvals/"] input[name=_token]');
        foreach (['publish', 'archive', 'delete', 'unpublish'] as $action) {
            $this->client->request('POST', '/documents/'.$document->getId().'/'.$action, ['_token' => $token]);
            self::assertResponseStatusCodeSame(403, $action);
        }
        $this->client->request('GET', '/admin');
        self::assertResponseStatusCodeSame(403);
        $this->client->request('POST', '/approvals/'.$approval->getId().'/decide', ['_token' => $token, 'decision' => 'approve']);
        self::assertResponseStatusCodeSame(403);

        // Страница «Модерация»: помощник видит свои материалы, пользователю она закрыта.
        $crawler = $this->client->request('GET', '/moderation');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Памятка по выкладке каталога', $crawler->filter('.admin-table')->text());
        $this->loginAs('smirnov');
        $this->client->request('GET', '/moderation');
        self::assertResponseStatusCodeSame(403);

        // Маркетолог видит очередь и счётчик, возврат без комментария не принимается.
        $this->loginAs('markova');
        $crawler = $this->client->request('GET', '/moderation');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Памятка по выкладке каталога', $crawler->filter('.attention-list')->text());
        self::assertStringContainsString('на модерации', $crawler->filter('.site-header')->text());
        $decideToken = (string) $crawler->filter('form[action="/approvals/'.$approval->getId().'/decide"] input[name=_token]')->attr('value');
        $this->client->request('POST', '/approvals/'.$approval->getId().'/decide', ['_token' => $decideToken, 'decision' => 'reject', 'from_list' => '1']);
        self::assertResponseRedirects('/moderation');
        $this->em()->clear();
        self::assertTrue($this->approvals()->find($approval->getId())?->isPending(), 'без комментария материал не возвращается');

        $this->client->request('POST', '/approvals/'.$approval->getId().'/decide', ['_token' => $decideToken, 'decision' => 'reject', 'note' => 'Добавьте фото стойки', 'from_list' => '1']);
        self::assertResponseRedirects('/moderation');
        $rejected = $this->approvals()->find($approval->getId());
        self::assertSame(DocumentApproval::REJECTED, $rejected?->getDecision());
        self::assertSame('markova', $rejected?->getDecidedBy()?->getUsername());
        $document = $this->byTitle('Памятка по выкладке каталога');
        self::assertTrue($document->isDraft(), 'возвращённый материал — снова черновик');

        // Помощник исправляет и отправляет заново, маркетолог одобряет — материал опубликован.
        $this->loginAs('belov');
        $crawler = $this->client->request('GET', '/documents/'.$document->getId());
        self::assertStringContainsString('Материал возвращён на доработку', $crawler->text());
        self::assertStringContainsString('Добавьте фото стойки', $crawler->text());
        $submitToken = (string) $crawler->filter('form[action$="/moderation"] input[name=_token]')->attr('value');
        $this->client->request('POST', '/documents/'.$document->getId().'/moderation', ['_token' => $submitToken, 'note' => 'Фото добавлено']);
        self::assertResponseRedirects('/documents/'.$document->getId());
        $second = $this->approvals()->findPending($this->byTitle('Памятка по выкладке каталога'));
        self::assertNotNull($second);
        self::assertSame('Фото добавлено', $second->getRequestNote());

        $this->loginAs('markova');
        $crawler = $this->client->request('GET', '/documents/'.$document->getId());
        self::assertStringContainsString('Материал ждёт модерации', $crawler->text());
        $decideToken = (string) $crawler->filter('form[action="/approvals/'.$second->getId().'/decide"] input[name=_token]')->attr('value');
        $this->client->request('POST', '/approvals/'.$second->getId().'/decide', ['_token' => $decideToken, 'decision' => 'approve']);
        self::assertResponseRedirects('/documents/'.$document->getId());
        $document = $this->byTitle('Памятка по выкладке каталога');
        self::assertTrue($document->isPublished(), 'одобренный материал публикуется сразу');
        self::assertSame('одобрено', $this->approvals()->find($second->getId())?->getDecisionLabel());

        $this->loginAs('smirnov');
        $this->client->request('GET', '/documents/'.$document->getId());
        self::assertResponseIsSuccessful();
    }

    public function testAssistantNewEditionWaitsForModeration(): void
    {
        // Опубликованный документ: помощник предлагает новую редакцию, карточку не меняет.
        $document = (new Document($this->section('Охрана труда')))->setTitle('Памятка для стенда')->setType(Document::TYPE_FILE)->setPublic(true);
        static::getContainer()->get(DocumentManager::class)->create($document, $this->upload('stand.txt', 'Редакция 1'), null, null, $this->user('markova'), true);
        self::assertTrue($document->isPublished());
        $id = (int) $document->getId();

        $this->loginAs('belov');
        $crawler = $this->client->request('GET', '/documents/'.$id);
        self::assertStringContainsString('Предложить новую редакцию', $crawler->text());
        $crawler = $this->client->request('GET', '/documents/'.$id.'/edit');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Карточку документа меняет маркетолог', $crawler->text());
        self::assertSame('disabled', $crawler->filter('input[name="document[title]"]')->attr('disabled'));
        $form = $crawler->selectButton('Сохранить изменения')->form();
        $values = $form->getPhpValues();
        $values['document']['title'] = 'Переименовано помощником';
        $values['document']['submitModeration'] = '1';
        $this->client->request('POST', '/documents/'.$id.'/edit', $values, ['document' => ['file' => $this->upload('stand-v2.txt', 'Редакция 2')]]);
        self::assertResponseRedirects('/documents/'.$id);

        $document = $this->byTitle('Памятка для стенда');
        self::assertSame(1, $document->getCurrentVersion()?->getNumber(), 'сотрудники видят прежнюю редакцию');
        self::assertSame(2, $document->getPendingVersion()?->getNumber());
        self::assertTrue($document->isPublished());
        $approval = $this->approvals()->findPending($document);
        self::assertTrue($approval?->isModeration());
        self::assertSame(2, $approval?->getVersionNumber());

        // Пользователь новую редакцию не видит.
        $this->loginAs('smirnov');
        $this->client->request('GET', '/documents/'.$id.'/versions/2/download');
        self::assertResponseStatusCodeSame(404);

        // Маркетолог одобряет — редакция вступает в силу (сущности загружаются заново: запрос клиента сбрасывает EntityManager).
        $approval = $this->approvals()->findPending($this->byTitle('Памятка для стенда'));
        self::assertNotNull($approval);
        static::getContainer()->get(ApprovalService::class)->decide($approval, true, null, $this->user('markova'));
        $document = $this->byTitle('Памятка для стенда');
        self::assertSame(2, $document->getCurrentVersion()?->getNumber());
        self::assertNull($document->getPendingVersion());
    }

    public function testMaterialsTableForAssistant(): void
    {
        $pumps = $this->section('Насосные станции');
        $this->loginAs('belov');
        $crawler = $this->client->request('GET', '/sections/'.$pumps->getId().'/materials');
        self::assertResponseIsSuccessful();
        $config = json_decode($crawler->filter('#mt-config')->text(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertFalse($config['canStructure']);
        self::assertSelectorNotExists('[data-mt-action="add-column"]');
        $url = '/sections/'.$pumps->getId().'/materials';
        $headers = ['HTTP_X_CSRF_TOKEN' => $config['token'], 'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest'];

        // Строки — можно, устройство таблицы — нельзя.
        $this->client->request('POST', $url.'/rows', ['title' => 'Листовка НС-80'], [], $headers);
        self::assertResponseIsSuccessful();
        $row = json_decode((string) $this->client->getResponse()->getContent(), true)['row'];
        $this->client->request('POST', $url.'/parameters', ['name' => 'Лишний столбец', 'kind' => 'text'], [], $headers);
        self::assertResponseStatusCodeSame(403);
        $this->client->request('POST', $url.'/rows/'.$row['id'].'/delete', [], [], $headers);
        self::assertResponseStatusCodeSame(403);

        // Загруженный из строки файл — черновик на модерации.
        $path = sys_get_temp_dir().'/'.bin2hex(random_bytes(4)).'-leaflet.pdf';
        file_put_contents($path, "%PDF-1.4\n% leaflet\n%%EOF\n");
        $this->client->request('POST', $url.'/rows/'.$row['id'].'/upload', [], ['file' => new UploadedFile($path, 'Листовка НС-80.pdf', 'application/pdf', null, true)], $headers);
        self::assertResponseIsSuccessful();
        $result = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertStringContainsString('отправлен на модерацию', $result['message']);
        $document = $this->byTitle('Листовка НС-80');
        self::assertFalse($document->isPublished());
        self::assertTrue($this->approvals()->findPending($document)?->isModeration());
    }

    public function testAdminAssignsRoleAndDirectorySync(): void
    {
        $this->loginAs('admin');
        $smirnov = $this->user('smirnov');
        $crawler = $this->client->request('GET', '/admin/users/'.$smirnov->getId().'/edit');
        self::assertStringContainsString('Помощник маркетолога', $crawler->text());
        $form = $crawler->selectButton('Сохранить')->form();
        $form['user[role]'] = User::POSITION_MARKETER;
        $this->client->submit($form);
        self::assertResponseRedirects('/admin/users');
        $this->em()->clear();
        self::assertSame(User::POSITION_MARKETER, $this->user('smirnov')->getPosition());
        $crawler = $this->client->request('GET', '/admin/users');
        self::assertStringContainsString('Маркетолог', $crawler->text());

        // Роль по данным каталога (группы AD, утверждение SSO); администратора синхронизация не трогает.
        $manager = static::getContainer()->get(UserManager::class);
        $user = $this->user('smirnov');
        $manager->syncPositionFromDirectory($user, User::POSITION_ASSISTANT, 'AD');
        self::assertSame(User::POSITION_ASSISTANT, $user->getPosition());
        $admin = $this->user('admin');
        $manager->syncPositionFromDirectory($admin, User::POSITION_USER, 'SSO');
        self::assertTrue($admin->isAdmin());
        $user->setPosition(User::POSITION_USER);
        $this->em()->flush();
    }

    public function testConsoleCreatesUserWithRole(): void
    {
        self::bootKernel();
        $app = new Application(self::$kernel);
        $tester = new CommandTester($app->find('app:user:create'));
        self::assertSame(0, $tester->execute(['username' => 'romanova', '--role' => 'assistant', '--password' => 'Assist12345', '--name' => 'Романова А.']));
        self::assertStringContainsString('помощник маркетолога', $tester->getDisplay());
        self::assertSame(User::POSITION_ASSISTANT, $this->user('romanova')->getPosition());
        self::assertSame(1, $tester->execute(['username' => 'romanova2', '--role' => 'boss', '--password' => 'Assist12345']));

        $list = new CommandTester($app->find('app:user:list'));
        $list->execute([]);
        self::assertStringContainsString('помощник маркетолога', $list->getDisplay());
    }
}
