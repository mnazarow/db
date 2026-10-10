<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Service\DocumentManager;
use App\Tests\PortalTestCase;

/**
 * Исправления интерфейса (1.6.1): ошибки форм, сообщения об ошибках, неразрывные пробелы из редактора,
 * параметры импорта при возврате к форме.
 */
final class FrontendFixesTest extends PortalTestCase
{
    public function testFormLevelErrorsAreShown(): void
    {
        $this->loginAs('admin');
        // Устаревший CSRF-токен — ошибка всей формы, раньше она не выводилась, и форма «молча» не сохранялась.
        $crawler = $this->client->request('GET', '/admin/sections/new');
        self::assertResponseIsSuccessful();
        $form = $crawler->filter('form.form')->form();
        $values = $form->getPhpValues();
        foreach ($values as $name => $fields) {
            if (\is_array($fields) && isset($fields['_token'])) {
                $values[$name]['_token'] = 'устаревший';
                $values[$name]['name'] = 'Раздел с устаревшим токеном';
            }
        }
        $this->client->request('POST', $form->getUri(), $values);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorExists('.alert--danger[role="alert"]');
    }

    public function testPasswordMismatchIsShown(): void
    {
        $this->loginAs('smirnov');
        $crawler = $this->client->request('GET', '/profile/password');
        $form = $crawler->filter('form.form')->form();
        $prefix = $form->getName();
        $form[$prefix.'[currentPassword]'] = 'Demo12345';
        $form[$prefix.'[newPassword][first]'] = 'НовыйПароль-2026';
        $form[$prefix.'[newPassword][second]'] = 'ДругойПароль-2026';
        $this->client->submit($form);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.form-error', 'Пароли не совпадают');
    }

    public function testErrorFlashesAreStyledAsDanger(): void
    {
        $this->loginAs('smirnov');
        $document = $this->document('ПОЛ-001');
        $crawler = $this->client->request('GET', '/documents/'.$document->getId());
        $token = (string) $crawler->filter('#comment-form input[name="_token"]')->attr('value');
        $this->client->request('POST', '/documents/'.$document->getId().'/comments', ['_token' => $token, 'body' => '   ']);
        $this->client->followRedirect();
        self::assertSelectorExists('.alert--danger[role="alert"]');
        self::assertSelectorNotExists('.alert--error');
    }

    public function testEditorSpacesAreNormalizedOnSave(): void
    {
        $manager = static::getContainer()->get(DocumentManager::class);
        $html = $manager->sanitize("<p>Абзац\u{00A0}из\u{00A0}редактора\u{00A0}без\u{00A0}обычных\u{00A0}пробелов</p>");
        self::assertSame('<p>Абзац из редактора без обычных пробелов</p>', $html);
    }

    public function testImportBackLinkKeepsFalseAndZeroValues(): void
    {
        $this->loginAs('admin');
        $dir = static::getContainer()->getParameter('app.import_dir').'/Проверка ссылки';
        @mkdir($dir, 0770, true);
        file_put_contents($dir.'/файл.pdf', '%PDF-1.4 test');
        $crawler = $this->client->request('GET', '/admin/import');
        $token = (string) $crawler->filter('form input[name="_token"]')->first()->attr('value');
        $crawler = $this->client->request('POST', '/admin/import/preview', [
            '_token' => $token, 'folder' => 'Проверка ссылки', 'section' => (string) $this->section('ИТ')->getId(),
            'status' => 'draft', 'validity' => '0',
        ]);
        self::assertResponseIsSuccessful();
        $href = (string) $crawler->filter('a:contains("Изменить параметры")')->attr('href');
        parse_str((string) parse_url($href, \PHP_URL_QUERY), $query);
        self::assertSame('0', $query['validity'] ?? null, 'срок «бессрочно» не превращается в срок по умолчанию');
        self::assertSame('0', $query['update'] ?? null, 'снятый флажок остаётся снятым');
        self::assertSame('0', $query['public'] ?? null);
        @unlink($dir.'/файл.pdf');
        @rmdir($dir);
    }
}
