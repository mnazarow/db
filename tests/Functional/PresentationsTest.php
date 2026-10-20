<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Meeting;
use App\Entity\Presentation;
use App\Entity\PresentationVersion;
use App\Repository\MeetingRepository;
use App\Repository\PresentationRepository;
use App\Repository\PresentationVersionRepository;
use App\Service\Presentation\DeckValidator;
use App\Service\Presentation\MeetingService;
use App\Service\Presentation\PptxImporter;
use App\Service\Presentation\PresentationService;
use App\Tests\PortalTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Разделы «Подготовка презентаций» и «Подготовка пятничной презентации»: создание и правка слайдов,
 * автосохранение с проверкой версий, изображения, модерация маркетологом и помощником (свою помощник
 * не одобряет), видимость одобренных, выгрузка в PowerPoint и импорт из него, замечания, история версий,
 * собрание: темы, порядок, публикация и сборка общей презентации.
 */
final class PresentationsTest extends PortalTestCase
{
    private function presentations(): PresentationRepository
    {
        return static::getContainer()->get(PresentationRepository::class);
    }

    private function find(int $id): Presentation
    {
        $this->em()->clear();
        $p = $this->presentations()->find($id);
        self::assertNotNull($p);

        return $p;
    }

    /** Новая презентация через форму раздела; возвращает её id. */
    private function create(string $user, string $title): int
    {
        $this->loginAs($user);
        $crawler = $this->client->request('GET', '/presentations');
        self::assertResponseIsSuccessful();
        $token = $crawler->filter('#deck-new input[name=_token]')->attr('value');
        $this->client->request('POST', '/presentations/new', ['_token' => $token, 'title' => $title, 'description' => 'Проверка']);
        self::assertResponseRedirects();
        self::assertMatchesRegularExpression('#/presentations/(\d+)/edit$#', (string) $this->client->getResponse()->headers->get('Location'));
        preg_match('#/presentations/(\d+)/edit$#', (string) $this->client->getResponse()->headers->get('Location'), $m);

        return (int) $m[1];
    }

    /** @return array<string, mixed> настройки редактора со страницы (токены, адреса) */
    private function editor(int $id): array
    {
        $crawler = $this->client->request('GET', '/presentations/'.$id.'/edit');
        self::assertResponseIsSuccessful();

        return json_decode($crawler->filter('#deck-editor-config')->text(), true, 512, \JSON_THROW_ON_ERROR);
    }

    /** @return array<string, mixed> */
    private function api(string $method, string $url, string $token, ?array $json = null, array $files = [], array $post = []): array
    {
        $this->client->request($method, $url, $post, $files, ['HTTP_X_CSRF_TOKEN' => $token, 'CONTENT_TYPE' => null !== $json ? 'application/json' : null, 'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest'], null !== $json ? json_encode($json, \JSON_THROW_ON_ERROR) : null);

        return json_decode((string) $this->client->getResponse()->getContent(), true) ?? [];
    }

    private function png(): UploadedFile
    {
        $path = sys_get_temp_dir().'/'.bin2hex(random_bytes(4)).'-pic.png';
        $img = imagecreatetruecolor(40, 20);
        imagefill($img, 0, 0, imagecolorallocate($img, 15, 67, 130));
        imagepng($img, $path);

        return new UploadedFile($path, 'Фото.png', 'image/png', null, true);
    }

    public function testEmployeePreparesPresentationAndMarketingModeratesIt(): void
    {
        $id = $this->create('smirnov', 'Итоги продаж за III квартал');
        $p = $this->find($id);
        self::assertSame(Presentation::KIND_GENERAL, $p->getKind());
        self::assertSame(3, $p->getSlideCount(), 'титульный, «Заголовок и текст», финальный');
        $deck = DeckValidator::decode($p->getContent());
        self::assertSame(['cover', 'title-text', 'closing'], array_column($deck['slides'], 'layout'));
        $title = array_values(array_filter($deck['slides'][0]['elements'], static fn (array $e): bool => 'title' === $e['bind']))[0];
        self::assertSame('<p>Итоги продаж за III квартал</p>', $title['html']);

        // Автосохранение: правка, проверка версии, очистка разметки, название из редактора.
        $cfg = $this->editor($id);
        self::assertSame('general', $cfg['kind']);
        self::assertArrayHasKey('cover', $cfg['layouts']);
        $deck['meta']['title'] = 'Итоги продаж: III квартал';
        $deck['slides'][1]['elements'][] = ['id' => 'etext1', 'type' => 'text', 'x' => 100, 'y' => 200, 'w' => 400, 'h' => 80,
            'html' => '<p onclick="x()">Выручка <b>выросла</b><script>alert(1)</script></p><ul><li>пункт</li></ul>', 'fontFamily' => 'Comic Sans', 'fontSize' => 9999];
        $res = $this->api('POST', $cfg['urls']['save'], $cfg['token'], ['deck' => $deck, 'revision' => $cfg['revision']]);
        self::assertTrue($res['ok'] ?? false, json_encode($res, \JSON_UNESCAPED_UNICODE));
        self::assertSame($cfg['revision'] + 1, $res['revision']);
        self::assertSame('Итоги продаж: III квартал', $res['title']);
        $saved = DeckValidator::decode($this->find($id)->getContent());
        $el = array_values(array_filter($saved['slides'][1]['elements'], static fn (array $e): bool => 'etext1' === $e['id']))[0];
        self::assertSame('<p>Выручка <b>выросла</b></p><p data-list="bullet">пункт</p>', $el['html']);
        self::assertSame(['Roboto', 400.0], [$el['fontFamily'], $el['fontSize']]);
        $cover = array_values(array_filter($saved['slides'][0]['elements'], static fn (array $e): bool => 'title' === $e['bind']))[0];
        self::assertSame('<p>Итоги продаж: III квартал</p>', $cover['html'], 'привязанный заголовок — из названия');

        // Сохранение поверх чужой правки — конфликт; «сохранить поверх» — можно.
        $res = $this->api('POST', $cfg['urls']['save'], $cfg['token'], ['deck' => $deck, 'revision' => $cfg['revision']]);
        self::assertResponseStatusCodeSame(409);
        self::assertTrue($res['conflict']);
        $res = $this->api('POST', $cfg['urls']['save'], $cfg['token'], ['deck' => ['format' => 'other', 'slides' => []], 'revision' => $res['revision']]);
        self::assertResponseStatusCodeSame(422);
        $this->api('POST', $cfg['urls']['save'], 'чужой-токен', ['deck' => $deck]);
        self::assertResponseStatusCodeSame(403);

        // Изображение: загрузка, выдача, не картинка — отказ.
        $img = $this->api('POST', $cfg['urls']['upload'], $cfg['token'], null, ['file' => $this->png()]);
        self::assertMatchesRegularExpression('/^asset:[a-f0-9]{24}$/', $img['src'] ?? '');
        self::assertSame([40, 20], [$img['width'], $img['height']]);
        $this->client->request('GET', $img['url']);
        self::assertResponseIsSuccessful();
        self::assertSame('image/png', $this->client->getResponse()->headers->get('Content-Type'));
        $txt = sys_get_temp_dir().'/'.bin2hex(random_bytes(4)).'.png';
        file_put_contents($txt, 'not an image');
        $res = $this->api('POST', $cfg['urls']['upload'], $cfg['token'], null, ['file' => new UploadedFile($txt, 'x.png', 'image/png', null, true)]);
        self::assertResponseStatusCodeSame(422);

        // Показ, печать, PowerPoint.
        foreach (['/present', '/print', ''] as $suffix) {
            $this->client->request('GET', '/presentations/'.$id.$suffix);
            self::assertResponseIsSuccessful($suffix);
        }
        $this->client->request('GET', '/presentations/'.$id.'/export.pptx');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('presentationml', (string) $this->client->getResponse()->headers->get('Content-Type'));
        self::assertStringContainsString('.pptx', (string) $this->client->getResponse()->headers->get('Content-Disposition'));

        // Другой сотрудник черновик не видит.
        $this->loginAs('kuznetsova');
        $this->client->request('GET', '/presentations/'.$id);
        self::assertResponseStatusCodeSame(403);

        // Отправка на модерацию: автор больше не правит, помощник маркетолога видит очередь.
        $this->loginAs('smirnov');
        $this->client->request('POST', '/presentations/'.$id.'/submit', ['_token' => $cfg['formToken'], 'note' => 'Проверьте цифры']);
        self::assertResponseRedirects('/presentations/'.$id);
        self::assertSame(Presentation::STATUS_SUBMITTED, $this->find($id)->getStatus());
        $this->client->request('GET', '/presentations/'.$id.'/edit');
        self::assertResponseRedirects('/presentations/'.$id);
        $res = $this->api('POST', $cfg['urls']['save'], $cfg['token'], ['deck' => $deck]);
        self::assertResponseStatusCodeSame(403);
        self::assertStringContainsString('модерации', $res['error']);
        self::assertNotEmpty(static::getContainer()->get(PresentationVersionRepository::class)->findForPresentation($this->find($id)), 'при отправке — версия в истории');

        $this->loginAs('belov');
        $crawler = $this->client->request('GET', '/moderation');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Итоги продаж: III квартал', $crawler->filter('#presentations')->text());
        self::assertGreaterThan(0, (int) $crawler->filter('.ack-chip--approval .ack-chip__count')->text());
        $form = $crawler->filter('#presentations form.ack-decide')->first();
        // Возврат без комментария не проходит.
        $this->client->request('POST', '/presentations/'.$id.'/decide', ['_token' => $form->filter('input[name=_token]')->attr('value'), 'decision' => 'reject', 'note' => '']);
        self::assertSame(Presentation::STATUS_SUBMITTED, $this->find($id)->getStatus());
        $this->client->request('POST', '/presentations/'.$id.'/decide', ['_token' => $form->filter('input[name=_token]')->attr('value'), 'decision' => 'approve', 'note' => 'Отлично', 'back' => '/moderation']);
        self::assertResponseRedirects('/moderation');
        $p = $this->find($id);
        self::assertSame(Presentation::STATUS_APPROVED, $p->getStatus());
        self::assertSame('belov', $p->getDecidedBy()?->getUsername());
        self::assertSame(1, \count(array_filter(static::getContainer()->get(PresentationVersionRepository::class)->findForPresentation($p), static fn (PresentationVersion $v): bool => PresentationVersion::KIND_APPROVE === $v->getKind())));

        // Одобренную видят все — в «Готовых презентациях», без права правки.
        $this->loginAs('kuznetsova');
        $crawler = $this->client->request('GET', '/presentations?q=III');
        self::assertStringContainsString('Итоги продаж: III квартал', $crawler->filter('#ready')->text());
        $this->client->request('GET', '/presentations/'.$id);
        self::assertResponseIsSuccessful();
        $this->client->request('GET', '/presentations/'.$id.'/edit');
        self::assertResponseRedirects('/presentations/'.$id);
        $this->client->request('GET', '/presentations/'.$id.'/export.pptx');
        self::assertResponseIsSuccessful();

        // Правка автора после одобрения: сотрудники видят одобренную редакцию, пока новая не одобрена.
        $this->loginAs('smirnov');
        $deck = DeckValidator::decode($this->find($id)->getContent());
        $deck['slides'][1]['elements'][0]['name'] = 'Знак шапки';
        $deck['slides'][] = ['id' => 'snew', 'elements' => []];
        $res = $this->api('POST', $cfg['urls']['save'], $cfg['token'], ['deck' => $deck]);
        self::assertSame('изменена после одобрения', $res['statusLabel']);
        $p = $this->find($id);
        self::assertSame([4, 3], [$p->getSlideCount(), $p->getApprovedSlideCount()]);
        $this->loginAs('kuznetsova');
        $crawler = $this->client->request('GET', '/presentations/'.$id);
        self::assertCount(3, $crawler->filter('.deck-slides__item'), 'сотрудник видит одобренную редакцию');
    }

    public function testAssistantCannotApproveOwnPresentationButMarketerCan(): void
    {
        $id = $this->create('belov', 'Новинки AQUASTRONG');
        $cfg = $this->editor($id);
        $this->client->request('POST', '/presentations/'.$id.'/submit', ['_token' => $cfg['formToken']]);
        $cfgAfter = $this->editor($id); // модератор (помощник) может править и отправленную
        self::assertFalse($cfgAfter['canDecide'], 'свою презентацию помощник не одобряет');
        $this->client->request('POST', '/presentations/'.$id.'/decide', ['_token' => $cfg['formToken'], 'decision' => 'approve']);
        self::assertSame(Presentation::STATUS_SUBMITTED, $this->find($id)->getStatus());

        // Маркетолог — и свою, и чужую.
        $mid = $this->create('markova', 'План маркетинга');
        $mcfg = $this->editor($mid);
        $this->client->request('POST', '/presentations/'.$mid.'/submit', ['_token' => $mcfg['formToken']]);
        self::assertTrue($this->editor($mid)['canDecide']);
        $this->client->request('POST', '/presentations/'.$mid.'/decide', ['_token' => $mcfg['formToken'], 'decision' => 'approve']);
        self::assertSame(Presentation::STATUS_APPROVED, $this->find($mid)->getStatus());
        $crawler = $this->client->request('GET', '/presentations/'.$id);
        $token = $crawler->filter('form[action$="/decide"] input[name=_token]')->attr('value');
        $this->client->request('POST', '/presentations/'.$id.'/decide', ['_token' => $token, 'decision' => 'reject', 'note' => 'Добавьте цены']);
        $p = $this->find($id);
        self::assertSame([Presentation::STATUS_REJECTED, 'Добавьте цены'], [$p->getStatus(), $p->getDecisionNote()]);

        // Помощник снова правит, видит замечание; снять одобрение может модератор.
        $this->loginAs('belov');
        self::assertSame('Добавьте цены', $this->editor($id)['decisionNote']);
        $this->loginAs('markova');
        $this->client->request('POST', '/presentations/'.$mid.'/revoke', ['_token' => $mcfg['formToken']]);
        self::assertFalse($this->find($mid)->hasApprovedVersion());

        // Обычный сотрудник не модерирует и не видит очередь.
        $this->loginAs('kuznetsova');
        $this->client->request('POST', '/presentations/'.$id.'/decide', ['_token' => $mcfg['formToken'], 'decision' => 'approve']);
        self::assertResponseStatusCodeSame(403);
    }

    public function testCommentsVersionsQrImportAndDelete(): void
    {
        $id = $this->create('smirnov', 'Обучение дилеров');
        $cfg = $this->editor($id);
        $p = $this->find($id);
        $slideId = DeckValidator::decode($p->getContent())['slides'][1]['id'];

        // Замечание маркетолога, ответ автора, «решено».
        $this->loginAs('markova');
        $mcfg = $this->editor($id);
        $res = $this->api('POST', $mcfg['urls']['comments'], $mcfg['token'], ['text' => 'Добавьте фото', 'slideId' => $slideId]);
        self::assertCount(1, $res['comments']);
        $commentId = $res['comments'][0]['id'];
        $this->loginAs('smirnov');
        $res = $this->api('POST', $cfg['urls']['comments'], $cfg['token'], ['text' => 'Добавил', 'parentId' => $commentId]);
        self::assertSame($commentId, $res['comments'][1]['parentId']);
        $res = $this->api('POST', str_replace('/0', '/'.$commentId, $cfg['urls']['commentBase']), $cfg['token'], ['action' => 'resolve']);
        self::assertTrue($res['comments'][0]['resolved']);
        $this->api('POST', str_replace('/0', '/'.$commentId, $cfg['urls']['commentBase']), $cfg['token'], ['action' => 'delete']);
        self::assertResponseStatusCodeSame(403, 'чужое замечание автор не удаляет');
        $this->loginAs('kuznetsova');
        $this->client->request('GET', $cfg['urls']['comments']);
        self::assertResponseStatusCodeSame(403);

        // QR-код.
        $this->client->request('GET', '/presentations/qr.svg?text='.rawurlencode('https://t.me/vodokomfort'));
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('<svg', (string) $this->client->getResponse()->getContent());

        // Импорт из PowerPoint: выгрузка презентации и обратная загрузка.
        $this->loginAs('smirnov');
        $this->client->request('GET', '/presentations/'.$id.'/export.pptx');
        self::assertResponseIsSuccessful();
        $service = static::getContainer()->get(PresentationService::class);
        $path = static::getContainer()->get(\App\Service\Presentation\PptxWriter::class)->write($service->deck($this->find($id)), 'Обучение дилеров', 'Смирнов', 'ВОДОКОМФОРТ');
        $res = $this->api('POST', $cfg['urls']['import'], $cfg['token'], null, ['file' => new UploadedFile($path, 'Обучение.pptx', null, null, true)], ['mode' => 'append']);
        self::assertSame(3, $res['imported'] ?? null, json_encode($res, \JSON_UNESCAPED_UNICODE));
        self::assertCount(6, $res['deck']['slides']);
        $texts = json_encode($res['deck']['slides'][3], \JSON_UNESCAPED_UNICODE);
        self::assertStringContainsString('ОБУЧЕНИЕ ДИЛЕРОВ', mb_strtoupper($texts), 'название с титульного слайда перенесено');
        $versions = $this->api('GET', $cfg['urls']['versions'], $cfg['token']);
        self::assertContains('import', array_column($versions['versions'], 'kind'));

        // Не .pptx — понятная ошибка.
        $bad = sys_get_temp_dir().'/'.bin2hex(random_bytes(4)).'.pptx';
        file_put_contents($bad, 'not a zip');
        $res = $this->api('POST', $cfg['urls']['import'], $cfg['token'], null, ['file' => new UploadedFile($bad, 'x.pptx', null, null, true)]);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('PowerPoint', $res['error']);

        // Восстановление версии (до импорта): снова 3 слайда.
        $import = array_values(array_filter($versions['versions'], static fn (array $v): bool => 'import' === $v['kind']))[0];
        $this->client->request('POST', '/presentations/'.$id.'/versions/'.$import['id'].'/restore', ['_token' => $cfg['formToken']]);
        self::assertResponseRedirects('/presentations/'.$id.'/edit');
        self::assertSame(3, $this->find($id)->getSlideCount());

        // Копия и удаление.
        $this->client->request('POST', '/presentations/'.$id.'/duplicate', ['_token' => $cfg['formToken']]);
        self::assertResponseRedirects();
        $this->client->request('POST', '/presentations/'.$id.'/delete', ['_token' => $cfg['formToken']]);
        self::assertResponseRedirects('/presentations');
        $this->em()->clear();
        self::assertNull($this->presentations()->find($id));
    }

    /** Библиотека фирменных слайдов: маркетолог сохраняет слайд, сотрудник вставляет копию; изображения не теряются. */
    public function testSlideLibrary(): void
    {
        $id = $this->create('markova', 'Образцы слайдов');
        $cfg = $this->editor($id);
        self::assertArrayHasKey('library', $cfg);
        $img = $this->api('POST', $cfg['urls']['upload'], $cfg['token'], null, ['file' => $this->png()]);
        $deck = DeckValidator::decode($this->find($id)->getContent());
        $slide = $deck['slides'][1];
        $slide['notes'] = 'Заметка докладчика';
        $slide['elements'][] = ['id' => 'pic1', 'type' => 'image', 'x' => 700, 'y' => 200, 'w' => 400, 'h' => 300, 'src' => $img['src'], 'link' => ['slide' => $deck['slides'][0]['id']]];

        // Сотрудник не сохраняет в библиотеку; без токена — никто.
        $res = $this->api('POST', $cfg['library']['create'], 'wrong', ['title' => 'x', 'slide' => $slide]);
        self::assertResponseStatusCodeSame(403);
        $res = $this->api('POST', $cfg['library']['create'], $cfg['library']['token'], ['title' => '  Итоги   месяца ', 'category' => 'Отчёты', 'description' => 'Цифры и выводы', 'slide' => $slide]);
        self::assertTrue($res['ok'] ?? false, json_encode($res, \JSON_UNESCAPED_UNICODE));
        self::assertSame('Итоги месяца', $res['template']['title']);
        self::assertSame('', $res['template']['slide']['notes'], 'заметки докладчика в библиотеку не попадают');
        $saved = array_values(array_filter($res['template']['slide']['elements'], static fn (array $e): bool => 'pic1' === $e['id']))[0];
        self::assertSame($img['src'], $saved['src']);
        self::assertNull($saved['link'], 'ссылки на слайды презентации отброшены');
        $templateId = $res['template']['id'];
        $res = $this->api('POST', $cfg['library']['create'], $cfg['library']['token'], ['title' => '', 'slide' => $slide]);
        self::assertResponseStatusCodeSame(422);
        $res = $this->api('POST', $cfg['library']['create'], $cfg['library']['token'], ['title' => 'Пустой', 'slide' => 'не слайд']);
        self::assertResponseStatusCodeSame(422);

        $other = $this->create('smirnov', 'Моя презентация');
        $scfg = $this->editor($other);
        $res = $this->api('POST', $scfg['library']['create'], $scfg['library']['token'], ['title' => 'Мой', 'slide' => $slide]);
        self::assertResponseStatusCodeSame(403);
        self::assertStringContainsString('маркетологи', $res['error']);

        // Сотрудник видит библиотеку в редакторе и на странице, вставка учитывается.
        $list = $this->api('GET', $scfg['library']['url'], $scfg['library']['token']);
        self::assertSame(['Отчёты'], $list['categories']);
        self::assertFalse($list['canManage']);
        self::assertSame('Итоги месяца', $list['templates'][0]['title']);
        $this->api('POST', str_replace('/0/', '/'.$templateId.'/', $scfg['library']['use']), $scfg['library']['token'], []);
        self::assertTrue($this->client->getResponse()->isSuccessful());
        $crawler = $this->client->request('GET', '/presentations/library');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Итоги месяца', $crawler->filter('#tpl-'.$templateId)->text());
        self::assertStringContainsString('1 вставка', $crawler->filter('#tpl-'.$templateId)->text());
        self::assertSame(0, $crawler->filter('#tpl-'.$templateId.' form')->count(), 'сотрудник библиотеку не меняет');

        // Удаление презентации, из которой сохранили слайд, не удаляет его изображение.
        $this->loginAs('markova');
        $cfg = $this->editor($id);
        $assets = static::getContainer()->get(\App\Repository\PresentationAssetRepository::class);
        $token = substr($img['src'], 6);
        $file = static::getContainer()->get(\App\Service\Presentation\AssetStore::class)->path($assets->findOneByToken($token));
        self::assertFileExists($file);
        $this->client->request('POST', '/presentations/'.$id.'/delete', ['_token' => $cfg['formToken']]);
        self::assertResponseRedirects('/presentations');
        $this->em()->clear();
        self::assertNotNull($assets->findOneByToken($token));
        self::assertFileExists($file);
        $this->client->request('GET', '/presentations/assets/'.$token.'.png');
        self::assertResponseIsSuccessful();

        // Правка, порядок и удаление на странице библиотеки; с удалением уходит и ничейное изображение.
        $crawler = $this->client->request('GET', '/presentations/library');
        $form = $crawler->filter('#tpl-'.$templateId.' form[action$="/'.$templateId.'"]')->form(['title' => 'Итоги квартала', 'category' => 'Отчёты для руководства']);
        $this->client->submit($form);
        self::assertResponseRedirects('/presentations/library#tpl-'.$templateId);
        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('Отчёты для руководства', $crawler->text());
        $libToken = $crawler->filter('#tpl-'.$templateId.' input[name=_token]')->attr('value');
        $this->client->request('POST', '/presentations/library/'.$templateId.'/move', ['_token' => $libToken, 'direction' => -1]);
        self::assertResponseRedirects();
        $this->client->request('POST', '/presentations/library/'.$templateId.'/delete', ['_token' => 'wrong']);
        self::assertResponseStatusCodeSame(403);
        $this->client->request('POST', '/presentations/library/'.$templateId.'/delete', ['_token' => $libToken]);
        self::assertResponseRedirects('/presentations/library');
        $this->em()->clear();
        self::assertNull($assets->findOneByToken($token));
        self::assertFileDoesNotExist($file);
        $crawler = $this->client->request('GET', '/presentations/library');
        self::assertStringContainsString('В библиотеке пока нет слайдов', $crawler->text());
    }

    public function testPptxImporterReadsSampleStyleSlides(): void
    {
        // Колода из макетов (таблица, диаграмма, QR, фото) → PowerPoint → импорт: элементы на месте.
        $service = static::getContainer()->get(PresentationService::class);
        $p = $service->create(Presentation::KIND_GENERAL, 'Макеты', null, $this->user('admin'));
        $deck = $service->deck($p);
        foreach (['table', 'chart', 'stats', 'two-columns'] as $layout) {
            $deck['slides'][] = \App\Service\Presentation\SlideLayouts::slide($layout);
        }
        $deck['slides'][1]['notes'] = 'Заметка докладчика';
        $deck['slides'][1]['hidden'] = true;
        $service->save($p, $deck, null, $this->user('admin'), true);
        $path = static::getContainer()->get(\App\Service\Presentation\PptxWriter::class)->write($service->deck($p), 'Макеты', 'Администратор', 'ВОДОКОМФОРТ');
        $result = static::getContainer()->get(PptxImporter::class)->import($path, $p, $this->user('admin'));
        @unlink($path);
        self::assertCount(7, $result['slides']);
        $types = static fn (array $s): array => array_values(array_unique(array_column($s['elements'], 'type')));
        self::assertContains('table', $types($result['slides'][3]));
        self::assertContains('chart', $types($result['slides'][4]));
        self::assertSame('Заметка докладчика', $result['slides'][1]['notes']);
        self::assertTrue($result['slides'][1]['hidden']);
        self::assertSame('#0F4382', $result['slides'][0]['background']['color']);
        $chart = array_values(array_filter($result['slides'][4]['elements'], static fn (array $e): bool => 'chart' === $e['type']))[0];
        self::assertNotEmpty($chart['categories']);
        $images = array_values(array_filter($result['slides'][0]['elements'], static fn (array $e): bool => 'image' === $e['type']));
        self::assertNotEmpty($images);
        self::assertMatchesRegularExpression('/^asset:/', $images[0]['src']);
        // Результат импорта — допустимая колода.
        $normalized = static::getContainer()->get(DeckValidator::class)->normalize(['slides' => $result['slides']]);
        self::assertCount(7, $normalized['slides']);
    }

    public function testFridayMeetingIsAssembledFromApprovedTopics(): void
    {
        $this->loginAs('smirnov');
        $crawler = $this->client->request('GET', '/meetings');
        self::assertResponseIsSuccessful();
        $meeting = static::getContainer()->get(MeetingRepository::class)->findUpcoming(new \DateTimeImmutable('today'));
        self::assertNotNull($meeting);
        self::assertSame('5', $meeting->getDate()->format('N'), 'собрание — в пятницу');
        self::assertEquals($meeting->getDate()->modify('-1 day')->setTime(18, 0), $meeting->getDeadline());
        self::assertStringContainsString($meeting->getDisplayTitle(), $crawler->filter('.deck-meeting-card')->text());

        // Две темы от разных сотрудников.
        $form = $crawler->filter('.deck-meeting-card form.deck-topic-form');
        $this->client->request('POST', '/meetings/'.$meeting->getId().'/topics', ['_token' => $form->filter('input[name=_token]')->attr('value'), 'title' => 'Выставка «Акватерм»', 'responsible' => 'Дмитрий Смирнов']);
        self::assertResponseRedirects();
        preg_match('#/presentations/(\d+)/edit$#', (string) $this->client->getResponse()->headers->get('Location'), $m);
        $topicA = (int) $m[1];
        $a = $this->find($topicA);
        self::assertSame([Presentation::KIND_TOPIC, $meeting->getId()], [$a->getKind(), $a->getMeeting()?->getId()]);
        $deckA = DeckValidator::decode($a->getContent());
        self::assertCount(1, $deckA['slides']);
        self::assertSame('<p>Выставка «Акватерм»</p>', array_values(array_filter($deckA['slides'][0]['elements'], static fn (array $e): bool => 'title' === $e['bind']))[0]['html']);
        $cfgA = $this->editor($topicA);
        $this->client->request('POST', '/presentations/'.$topicA.'/submit', ['_token' => $cfgA['formToken']]);

        $this->loginAs('kuznetsova');
        $crawler = $this->client->request('GET', '/meetings/'.$meeting->getId());
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('Акватерм', $crawler->filter('#topics')->text(), 'чужие темы до публикации не видны');
        $this->client->request('GET', '/meetings/'.$meeting->getId().'/present');
        self::assertResponseStatusCodeSame(403);
        $form = $crawler->filter('form.deck-topic-form');
        $this->client->request('POST', '/meetings/'.$meeting->getId().'/topics', ['_token' => $form->filter('input[name=_token]')->attr('value'), 'title' => 'Новые сотрудники']);
        preg_match('#/presentations/(\d+)/edit$#', (string) $this->client->getResponse()->headers->get('Location'), $m);
        $topicB = (int) $m[1];
        $cfgB = $this->editor($topicB);
        $this->client->request('POST', '/presentations/'.$topicB.'/submit', ['_token' => $cfgB['formToken']]);
        $this->client->request('POST', '/meetings/'.$meeting->getId().'/publish', ['_token' => 'x']);
        self::assertResponseStatusCodeSame(403);

        // Маркетолог: одобряет, задаёт порядок (Б раньше А), публикует.
        $this->loginAs('markova');
        $crawler = $this->client->request('GET', '/meetings/'.$meeting->getId());
        $mtoken = $crawler->filter('form[action$="/publish"] input[name=_token]')->attr('value');
        $this->client->request('POST', '/meetings/'.$meeting->getId().'/publish', ['_token' => $mtoken]);
        self::assertFalse(static::getContainer()->get(MeetingRepository::class)->find($meeting->getId())->isPublished(), 'без одобренных тем публиковать нечего');
        foreach ([$topicA, $topicB] as $tid) {
            $token = $crawler->filter('form[action="/presentations/'.$tid.'/decide"] input[name=_token]')->attr('value');
            $this->client->request('POST', '/presentations/'.$tid.'/decide', ['_token' => $token, 'decision' => 'approve']);
        }
        $this->client->request('POST', '/meetings/'.$meeting->getId().'/order', ['_token' => $mtoken, 'order' => $topicB.','.$topicA], [], ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
        self::assertSame(['ok' => true], json_decode((string) $this->client->getResponse()->getContent(), true));

        $deck = static::getContainer()->get(MeetingService::class)->assemble(static::getContainer()->get(MeetingRepository::class)->find($meeting->getId()));
        self::assertSame('cover', $deck['slides'][0]['layout']);
        $cover = json_encode($deck['slides'][0], \JSON_UNESCAPED_UNICODE);
        self::assertStringContainsString($meeting->getDisplayTitle(), $cover);
        self::assertStringContainsString($meeting->getDateLabel(), $cover);
        self::assertSame('contents', $deck['slides'][1]['layout']);
        $contents = json_encode($deck['slides'][1], \JSON_UNESCAPED_UNICODE);
        self::assertLessThan(strpos($contents, 'Выставка'), strpos($contents, 'Новые сотрудники'), 'в «Содержании» — по заданному порядку');
        self::assertSame('closing', end($deck['slides'])['layout']);
        self::assertCount(5, $deck['slides']);

        $this->client->request('POST', '/meetings/'.$meeting->getId().'/settings', ['_token' => $mtoken, 'number' => '132', 'date' => $meeting->getDate()->format('Y-m-d'), 'deadline' => '', 'title' => 'Итоги недели']);
        self::assertSame(132, static::getContainer()->get(MeetingRepository::class)->find($meeting->getId())->getNumber());
        $this->client->request('POST', '/meetings/'.$meeting->getId().'/publish', ['_token' => $mtoken]);
        self::assertTrue(static::getContainer()->get(MeetingRepository::class)->find($meeting->getId())->isPublished());
        $this->client->request('GET', '/meetings/'.$meeting->getId().'/export.pptx');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('132', rawurldecode((string) $this->client->getResponse()->headers->get('Content-Disposition')));

        // Следующее собрание — через неделю, номер 133.
        $this->client->request('POST', '/meetings/'.$meeting->getId().'/next', ['_token' => $mtoken]);
        self::assertResponseRedirects();
        $next = static::getContainer()->get(MeetingRepository::class)->findOneByNumber(133);
        self::assertInstanceOf(Meeting::class, $next);
        self::assertSame($meeting->getDate()->modify('+7 days')->format('Y-m-d'), $next->getDate()->format('Y-m-d'));

        // После публикации сотрудники видят одобренные темы и общую презентацию.
        $this->loginAs('kuznetsova');
        $crawler = $this->client->request('GET', '/meetings/'.$meeting->getId());
        self::assertStringContainsString('Акватерм', $crawler->filter('#topics')->text());
        $this->client->request('GET', '/meetings/'.$meeting->getId().'/present');
        self::assertResponseIsSuccessful();
        $this->client->request('GET', '/meetings/'.$meeting->getId().'/print');
        self::assertResponseIsSuccessful();
        $this->client->request('GET', '/presentations/'.$topicA);
        self::assertResponseIsSuccessful();
    }
}
