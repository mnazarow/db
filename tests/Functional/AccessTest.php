<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Security\Access;
use App\Tests\PortalTestCase;

/**
 * Права доступа: читатель, модератор (с наследованием на подразделы), администратор.
 */
final class AccessTest extends PortalTestCase
{
    public function testAnonymousIsRedirectedToLogin(): void
    {
        $this->client->request('GET', '/');
        self::assertResponseRedirects('/login');
        $this->client->request('GET', '/health');
        self::assertResponseIsSuccessful();
    }

    public function testReaderSeesPublishedButCannotManage(): void
    {
        $this->loginAs('smirnov');
        $this->client->request('GET', '/');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.h-display', 'Портал документации');
        self::assertSelectorNotExists('.attention');

        $published = $this->document('ИТ-РМ-002');
        $this->client->request('GET', '/documents/'.$published->getId());
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('.card--actions');

        $draft = $this->document('ИБ-003');
        self::assertTrue($draft->isDraft());
        $this->client->request('GET', '/documents/'.$draft->getId());
        self::assertResponseStatusCodeSame(403);

        foreach (['/admin', '/admin/users', '/documents/new', '/documents/'.$published->getId().'/edit', '/documents/'.$published->getId().'/stats', '/sections/'.$published->getSection()->getId().'/new'] as $url) {
            $this->client->request('GET', $url);
            self::assertResponseStatusCodeSame(403, $url);
        }
    }

    public function testModeratorRightsAreInheritedBySubsections(): void
    {
        $access = static::getContainer()->get(Access::class);
        $petrova = $this->user('petrova');   // модератор раздела «ИТ»
        $ivanov = $this->user('ivanov');     // модератор раздела «Общие документы» (родитель «ИТ»)
        $sidorov = $this->user('sidorov');   // модератор раздела «Производство»
        $workplace = $this->section('Рабочее место'); // ИТ → Рабочее место
        $it = $this->section('ИТ');
        $common = $this->section('Общие документы');
        $production = $this->section('Производство');

        self::assertTrue($access->canManageSection($petrova, $it));
        self::assertTrue($access->canManageSection($petrova, $workplace), 'права наследуются подразделами');
        self::assertFalse($access->canManageSection($petrova, $common), 'вверх по дереву права не распространяются');
        self::assertFalse($access->canManageSection($petrova, $production));
        self::assertTrue($access->canManageSection($ivanov, $workplace), 'модератор корня управляет всем поддеревом');
        self::assertTrue($access->canManageSection($sidorov, $production));
        self::assertFalse($access->canManageSection($sidorov, $it));
        self::assertTrue($access->canManageSection($this->user('admin'), $production));
        self::assertFalse($access->isModerator($this->user('smirnov')));
        self::assertTrue($access->isModerator($petrova));
    }

    public function testModeratorCanEditOwnSectionOnly(): void
    {
        $this->loginAs('petrova');
        $own = $this->document('ИТ-РМ-002');
        $this->client->request('GET', '/documents/'.$own->getId().'/edit');
        self::assertResponseIsSuccessful();
        $this->client->request('GET', '/documents/'.$own->getId().'/stats');
        self::assertResponseIsSuccessful();

        $foreign = $this->document('ТК-040');
        $this->client->request('GET', '/documents/'.$foreign->getId());
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('.card--actions');
        $this->client->request('GET', '/documents/'.$foreign->getId().'/edit');
        self::assertResponseStatusCodeSame(403);
        $this->client->request('GET', '/admin');
        self::assertResponseStatusCodeSame(403);
    }

    public function testAdminHasFullAccess(): void
    {
        $this->loginAs('admin');
        foreach (['/admin', '/admin/sections', '/admin/users', '/admin/documents', '/admin/statistics', '/admin/events', '/admin/settings', '/admin/sections/moderators', '/documents/new', '/sections/new'] as $url) {
            $this->client->request('GET', $url);
            self::assertResponseIsSuccessful($url);
        }
        $draft = $this->document('ИБ-003');
        $this->client->request('GET', '/documents/'.$draft->getId());
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.card--actions');
    }

    public function testLoginForm(): void
    {
        $crawler = $this->client->request('GET', '/login');
        self::assertResponseIsSuccessful();
        $form = $crawler->selectButton('Войти')->form(['_username' => 'smirnov', '_password' => 'wrong']);
        $this->client->submit($form);
        self::assertResponseRedirects('/login');
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('.alert--danger', 'Неверный логин или пароль');

        $form = $crawler->selectButton('Войти')->form(['_username' => 'SMIRNOV', '_password' => 'Demo12345']);
        $this->client->submit($form);
        self::assertResponseRedirects('/');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.user-chip__name', 'Смирнов');
    }
}
