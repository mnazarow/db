<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Section;
use App\Repository\SectionRepository;
use App\Service\SectionManager;
use App\Tests\PortalTestCase;

/**
 * Дерево разделов любой вложенности: пути, перемещение поддеревьев, ограничения удаления.
 */
final class SectionTreeTest extends PortalTestCase
{
    public function testPathsAndDepth(): void
    {
        $workplace = $this->section('Рабочее место');
        self::assertSame(3, $workplace->getDepth());
        self::assertSame('Общие документы / Инструкции / ИТ / Рабочее место', $workplace->getFullName());
        self::assertCount(4, $workplace->getPathIds());
        self::assertTrue($workplace->isDescendantOf($this->section('Общие документы')));
        self::assertFalse($workplace->isDescendantOf($this->section('Производство')));
    }

    public function testCreateDeepNestingAndMoveSubtree(): void
    {
        $manager = static::getContainer()->get(SectionManager::class);
        $repo = static::getContainer()->get(SectionRepository::class);
        $admin = $this->user('admin');

        $a = $manager->create('Уровень A', $this->section('Рабочее место'), null, $admin);
        $b = $manager->create('Уровень B', $a, null, $admin);
        $c = $manager->create('Уровень C', $b, null, $admin);
        self::assertSame(6, $c->getDepth());
        self::assertStringEndsWith('/'.$a->getId().'/'.$b->getId().'/'.$c->getId().'/', $c->getPath());

        // Перенос поддерева A под «Производство» пересчитывает пути всех потомков.
        $manager->update($a, $this->section('Производство'), $admin);
        $this->em()->clear();
        $c = $repo->find($c->getId());
        self::assertSame(3, $c->getDepth());
        self::assertTrue($c->isDescendantOf($repo->findOneBy(['name' => 'Производство'])));
        self::assertFalse($c->isDescendantOf($repo->findOneBy(['name' => 'ИТ'])));
        self::assertCount(3, $repo->findSubtreeIds($repo->find($a->getId())));

        // Нельзя перенести раздел в собственного потомка.
        $this->expectException(\DomainException::class);
        $manager->update($repo->find($a->getId()), $repo->find($c->getId()), $admin);
    }

    public function testDeleteRequiresEmptySection(): void
    {
        $manager = static::getContainer()->get(SectionManager::class);
        try {
            $manager->delete($this->section('ИТ'), $this->user('admin'));
            self::fail('Раздел с подразделами не должен удаляться');
        } catch (\DomainException $e) {
            self::assertStringContainsString('подразделы', $e->getMessage());
        }
        try {
            $manager->delete($this->section('Приказы'), $this->user('admin'));
            self::fail('Раздел с документами не должен удаляться');
        } catch (\DomainException $e) {
            self::assertStringContainsString('документы', $e->getMessage());
        }
        $empty = $manager->create('Пустой', null, null, $this->user('admin'));
        $id = $empty->getId();
        $manager->delete($empty, $this->user('admin'));
        self::assertNull(static::getContainer()->get(SectionRepository::class)->find($id));
    }

    public function testModeratorCreatesSubsectionViaForm(): void
    {
        $this->loginAs('petrova');
        $it = $this->section('ИТ');
        $crawler = $this->client->request('GET', '/sections/'.$it->getId().'/new');
        self::assertResponseIsSuccessful();
        $form = $crawler->selectButton('Создать раздел')->form();
        $form['section[name]'] = 'Принтеры';
        $form['section[description]'] = 'Инструкции по печати';
        $this->client->submit($form);
        self::assertResponseRedirects();
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert--success', 'создан');
        $created = static::getContainer()->get(SectionRepository::class)->findOneBy(['name' => 'Принтеры']);
        self::assertInstanceOf(Section::class, $created);
        self::assertSame($it->getId(), $created->getParent()?->getId());

        // Чужой раздел — нельзя.
        $this->client->request('GET', '/sections/'.$this->section('Производство')->getId().'/new');
        self::assertResponseStatusCodeSame(403);
    }

    public function testAdminAssignsAndRemovesModerator(): void
    {
        $this->loginAs('admin');
        $section = $this->section('Охрана труда');
        $smirnov = $this->user('smirnov');
        $crawler = $this->client->request('GET', '/admin/sections/'.$section->getId().'/moderators');
        self::assertResponseIsSuccessful();
        $token = $crawler->filter('form.form input[name=_token]')->attr('value');
        $this->client->request('POST', '/admin/sections/'.$section->getId().'/moderators', ['_token' => $token, 'user_id' => $smirnov->getId()]);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert--success', 'модератором');
        $this->em()->clear();
        $access = static::getContainer()->get(\App\Security\Access::class);
        $access->resetCache();
        self::assertTrue($access->canManageSection($this->user('smirnov'), $this->section('Охрана труда')));

        // Повторное назначение — ошибка, снятие — успех.
        $this->client->request('POST', '/admin/sections/'.$section->getId().'/moderators', ['_token' => $token, 'user_id' => $smirnov->getId()]);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert--danger', 'уже является');
        $moderator = static::getContainer()->get(\App\Repository\SectionModeratorRepository::class)->findOneBySectionAndUser($this->section('Охрана труда'), $this->user('smirnov'));
        $this->client->request('POST', '/admin/sections/'.$section->getId().'/moderators/'.$moderator->getId().'/remove', ['_token' => $token]);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert--success', 'больше не модерирует');
    }
}
