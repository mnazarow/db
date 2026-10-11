<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Document;
use App\Entity\SignatureSheet;
use App\Repository\SignatureSheetRepository;
use App\Service\AcknowledgementService;
use App\Service\DocumentManager;
use App\Service\Pdf\SignatureSheetService;
use App\Service\PortalSettings;
use App\Tests\PortalTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Лист ознакомления в PDF с кодом проверки (1.7.0): формирование, повторная выгрузка с тем же кодом,
 * публичная страница проверки (сводка без входа, список сотрудников — ответственным), расхождение
 * после новых подтверждений, ошибки ввода кода.
 */
final class SignatureSheetTest extends PortalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->em()->getConnection()->beginTransaction();
    }

    protected function tearDown(): void
    {
        $connection = $this->em()->getConnection();
        while ($connection->isTransactionActive()) {
            $connection->rollBack();
        }
        $this->em()->clear();
        static::getContainer()->get('cache.app')->clear();
        parent::tearDown();
    }

    /**
     * Свой документ в «Охране труда» (модератор — ivanov): назначен четверым, двое ознакомились.
     * Демо-документы не годятся: другие тесты процесса меняют их ознакомления.
     */
    private function acknowledgedDocument(): Document
    {
        $path = sys_get_temp_dir().'/'.bin2hex(random_bytes(4)).'-instr.txt';
        file_put_contents($path, 'Инструкция для листа ознакомления');
        $document = (new Document($this->section('Охрана труда')))->setTitle('Инструкция для проверки листа')->setCode('ЛСТ-001')->setType(Document::TYPE_FILE);
        static::getContainer()->get(DocumentManager::class)->create($document, new UploadedFile($path, 'instr.txt', null, null, true), null, null, $this->user('admin'), true);
        $service = static::getContainer()->get(AcknowledgementService::class);
        $service->assign($document, [$this->user('sidorov'), $this->user('kuznetsova'), $this->user('smirnov'), $this->user('petrova')], new \DateTimeImmutable('+10 days'), $this->user('admin'), false);
        $service->confirm($document, $this->user('sidorov'), '10.0.0.1');
        $service->confirm($document, $this->user('kuznetsova'), '10.0.0.2');

        return $document;
    }

    /** @return list<SignatureSheet> */
    private function sheetsFor(int $documentId): array
    {
        $this->em()->clear();

        return static::getContainer()->get(SignatureSheetRepository::class)->findBy(['document' => $documentId], ['id' => 'ASC']);
    }

    private function downloadPdf(int $documentId): string
    {
        $this->client->request('GET', '/documents/'.$documentId.'/acknowledgements/sheet.pdf');
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/pdf');
        $disposition = (string) $this->client->getResponse()->headers->get('Content-Disposition');
        self::assertStringStartsWith('attachment; filename="', $disposition);
        self::assertStringContainsString("filename*=UTF-8''", $disposition);
        $pdf = (string) $this->client->getResponse()->getContent();
        self::assertStringStartsWith('%PDF-1.', $pdf);
        self::assertStringEndsWith("%%EOF\n", $pdf);

        return $pdf;
    }

    private static function pdfText(string $pdf): ?string
    {
        if (!is_executable('/usr/bin/pdftotext') && '' === trim((string) shell_exec('command -v pdftotext 2>/dev/null'))) {
            return null;
        }
        $path = tempnam(sys_get_temp_dir(), 'sheet');
        file_put_contents($path, $pdf);
        $text = (string) shell_exec('pdftotext -layout '.escapeshellarg($path).' - 2>/dev/null');
        unlink($path);

        return $text;
    }

    public function testNormalizeCode(): void
    {
        self::assertSame('7K2M-9QXD-4TRB-H8NA', SignatureSheetService::normalizeCode('7k2m 9qxd 4trb h8na'));
        self::assertSame('7K2M-9QXD-4TRB-H8NA', SignatureSheetService::normalizeCode(' 7K2M9QXD4TRBH8NA '));
        self::assertSame('0011-ABCD-EFGH-JKMN', SignatureSheetService::normalizeCode('OoIl–abcd—efgh_jkmn'), 'O → 0, I и L → 1, любые тире');
        self::assertSame('ABCE-HKMP-TX03-0000', SignatureSheetService::normalizeCode('АВСЕ НКМР ТХОЗ 0000'), 'кириллица русской раскладки');
        self::assertNull(SignatureSheetService::normalizeCode('ABCD-EFGH-JKMN-PQR'), 'короче 16 знаков');
        self::assertNull(SignatureSheetService::normalizeCode('ABCD-EFGH-JKMN-PQRU'), 'U в коде не бывает');
        self::assertNull(SignatureSheetService::normalizeCode('ABCD-EFGH-JKMN-PQRS-T'), 'длиннее 16 знаков');
        self::assertNull(SignatureSheetService::normalizeCode(''));
    }

    public function testSheetIsGeneratedOnceAndVerifiedPublicly(): void
    {
        $document = $this->acknowledgedDocument();
        $id = (int) $document->getId();
        $this->loginAs('ivanov');

        $pdf = $this->downloadPdf($id);
        $sheets = $this->sheetsFor($id);
        self::assertCount(1, $sheets);
        $sheet = $sheets[0];
        self::assertMatchesRegularExpression('/^[0-9A-HJKMNP-TV-Z]{4}(-[0-9A-HJKMNP-TV-Z]{4}){3}$/', $sheet->getCode());
        self::assertSame(4, $sheet->getAssigned());
        self::assertSame(2, $sheet->getAcknowledged());
        self::assertSame($document->getCurrentVersion()?->getChecksum(), $sheet->getFileChecksum());
        self::assertSame('Иванов Игорь Петрович', $sheet->getCreatedByName());

        $text = self::pdfText($pdf);
        if (null !== $text) {
            $flat = (string) preg_replace('/\s+/u', ' ', $text);
            self::assertStringContainsString('Лист ознакомления с документом', $flat);
            self::assertStringContainsString('Инструкция для проверки листа', $flat);
            self::assertStringContainsString($sheet->getCode(), $flat);
            self::assertStringContainsString($this->user('sidorov')->getDisplayName(), $flat);
            self::assertStringContainsString('не ознакомлен', $flat);
            self::assertStringContainsString('/verify/'.$sheet->getCode(), $flat);
            self::assertStringContainsString('Стр. 1 из', $flat);
        }

        // Записи не менялись — тот же лист и тот же код.
        $this->downloadPdf($id);
        self::assertCount(1, $this->sheetsFor($id));

        // Проверка без входа при закрытом гостевом доступе: сводка есть, сотрудников нет.
        static::getContainer()->get(PortalSettings::class)->set(PortalSettings::GUEST_MODE, PortalSettings::GUEST_OFF);
        $this->client->getCookieJar()->clear(); // выход: без cookie сессии — аноним
        $this->client->request('GET', '/verify/'.$sheet->getCode());
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('X-Robots-Tag', 'noindex, nofollow');
        self::assertResponseHeaderSame('Referrer-Policy', 'no-referrer');
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Лист актуален', $html);
        self::assertStringContainsString('Инструкция для проверки листа', $html);
        self::assertStringContainsString('назначено 4 · ознакомились 2', $html);
        self::assertStringNotContainsString($this->user('sidorov')->getDisplayName(), $html, 'список сотрудников без входа не показывается');

        // Ещё один сотрудник ознакомился — лист расходится с порталом.
        $fresh = $this->document('ЛСТ-001');
        static::getContainer()->get(AcknowledgementService::class)->confirm($fresh, $this->user('smirnov'), '10.0.0.5');
        $this->client->request('GET', '/verify/'.$sheet->getCode());
        self::assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('После формирования листа данные изменились', $html);
        self::assertStringNotContainsString('ознакомились 3', $html, 'текущие цифры без входа не показываются');

        // Ответственный видит сотрудников и может сформировать актуальный лист — с новым кодом.
        $this->loginAs('ivanov');
        $this->client->request('GET', '/verify/'.$sheet->getCode());
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString($this->user('smirnov')->getDisplayName(), $html);
        self::assertStringContainsString('Сформировать актуальный лист', $html);
        self::assertStringContainsString('назначено 4 · ознакомились 3', $html, 'ответственному — текущие цифры');
        $this->downloadPdf($id);
        $sheets = $this->sheetsFor($id);
        self::assertCount(2, $sheets);
        self::assertNotSame($sheets[0]->getCode(), $sheets[1]->getCode());
        self::assertSame(3, $sheets[1]->getAcknowledged());
    }

    public function testAnyPrintedChangeMakesSheetOutdated(): void
    {
        $service = static::getContainer()->get(SignatureSheetService::class);
        $document = $this->acknowledgedDocument();
        $version = (int) $document->getCurrentVersion()?->getNumber();
        $sheet = $service->generate($document, $version, $this->user('admin'))['sheet'];
        self::assertTrue($service->verify($sheet)['matches']);

        // Новое назначение без подтверждения — на листе изменилось бы «Назначено» и список сотрудников.
        static::getContainer()->get(AcknowledgementService::class)->assign($document, [$this->user('ivanov')], null, $this->user('admin'), false);
        self::assertFalse($service->verify($sheet)['matches']);
        $second = $service->generate($document, $version, $this->user('admin'))['sheet'];
        self::assertNotSame($sheet->getCode(), $second->getCode());

        // Переименование документа — тоже другой лист.
        $document->setTitle('Инструкция для проверки листа (ред.)');
        $this->em()->flush();
        self::assertFalse($service->verify($second)['matches']);
        $third = $service->generate($document, $version, $this->user('admin'))['sheet'];
        self::assertNotSame($second->getCode(), $third->getCode());
        // А без изменений — тот же лист.
        self::assertSame($third->getCode(), $service->generate($document, $version, $this->user('admin'))['sheet']->getCode());
    }

    public function testOnlyDocumentEditorsGetThePdf(): void
    {
        $id = (int) $this->acknowledgedDocument()->getId();
        $this->loginAs('smirnov');
        $this->client->request('GET', '/documents/'.$id.'/acknowledgements/sheet.pdf');
        self::assertResponseStatusCodeSame(403);
        $this->client->getCookieJar()->clear(); // выход: без cookie сессии — аноним
        $this->client->request('GET', '/documents/'.$id.'/acknowledgements/sheet.pdf');
        self::assertResponseRedirects();
        self::assertCount(0, $this->sheetsFor($id));
    }

    public function testVerifyFormAndBadCodes(): void
    {
        $this->loginAs('admin');
        $document = $this->acknowledgedDocument();
        $code = static::getContainer()->get(SignatureSheetService::class)->generate($document, (int) $document->getCurrentVersion()?->getNumber(), $this->user('admin'))['sheet']->getCode();
        $this->client->getCookieJar()->clear(); // выход: без cookie сессии — аноним

        $this->client->request('GET', '/verify');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('input#verify-code[name="code"]');

        $this->client->request('GET', '/verify?code='.urlencode(strtolower(str_replace('-', ' ', $code))));
        self::assertResponseRedirects('/verify/'.$code);

        $this->client->request('GET', '/verify?code=12345');
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.alert--danger', 'Код набран неверно');

        $this->client->request('GET', '/verify/'.strtolower(str_replace('-', '', $code)));
        self::assertResponseRedirects('/verify/'.$code, 301);

        $this->client->request('GET', '/verify/not-a-code');
        self::assertResponseStatusCodeSame(404);

        $this->client->request('GET', '/verify/0000-0000-0000-0000');
        self::assertResponseStatusCodeSame(404);
        self::assertSelectorTextContains('.alert--danger', 'порталом не выдавался');
    }

    public function testSheetOfDeletedDocumentStillVerifies(): void
    {
        $this->loginAs('admin');
        $document = $this->acknowledgedDocument();
        $sheet = static::getContainer()->get(SignatureSheetService::class)->generate($document, (int) $document->getCurrentVersion()?->getNumber(), $this->user('admin'))['sheet'];
        $this->em()->getConnection()->executeStatement('UPDATE signature_sheet SET document_id = NULL WHERE id = ?', [$sheet->getId()]);
        $this->em()->clear();

        $this->client->request('GET', '/verify/'.$sheet->getCode());
        self::assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Документ удалён из портала', $html);
        self::assertStringContainsString('Инструкция для проверки листа', $html);
    }
}
