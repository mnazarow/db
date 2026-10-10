<?php

declare(strict_types=1);

namespace App\Service\Pdf;

use App\Entity\Document;
use App\Entity\DocumentAcknowledgement;
use App\Entity\DocumentApproval;
use App\Entity\DocumentVersion;
use App\Entity\SignatureSheet;
use App\Entity\User;
use App\Repository\DocumentAcknowledgementRepository;
use App\Repository\DocumentApprovalRepository;
use App\Repository\SignatureSheetRepository;
use App\Service\FileStorage;
use App\Service\PortalUrl;
use App\Service\Security\QrCode;
use App\Twig\AppExtension;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Лист ознакомления в PDF: реквизиты редакции с контрольной суммой файла, согласование, кто и когда
 * ознакомился, и код проверки с QR — по нему страница /verify/<код> подтверждает, что лист выдан порталом
 * и совпадает ли он с текущими записями.
 */
final class SignatureSheetService
{
    /** Алфавит кода (Crockford base32: без I, L, O, U — их не спутать с цифрами). */
    private const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SignatureSheetRepository $sheets,
        private readonly DocumentAcknowledgementRepository $acknowledgements,
        private readonly DocumentApprovalRepository $approvals,
        private readonly PdfFactory $pdfFactory,
        private readonly PortalUrl $portalUrl,
        private readonly LoggerInterface $auditLogger,
        private readonly string $appName,
        private readonly string $appCompany,
    ) {
    }

    /**
     * Записи по редакции: назначения ознакомления (все, с подтверждением и без), решения согласования
     * и отпечаток (SHA-256) всего, что печатается на листе: реквизиты документа и редакции, каждое
     * назначение (сотрудник, подразделение, даты, проверка знаний) и каждое решение согласования.
     * Один и тот же код получает только лист с теми же данными.
     *
     * @return array{version: ?DocumentVersion, acknowledgements: list<DocumentAcknowledgement>, approvals: list<DocumentApproval>, file_checksum: ?string, hash: string, acknowledged: int, state: string}
     */
    public function snapshot(Document $document, int $versionNumber): array
    {
        $version = $document->findVersion($versionNumber);
        $acknowledgements = $this->acknowledgements->findForDocument($document, $versionNumber);
        usort($acknowledgements, static fn (DocumentAcknowledgement $a, DocumentAcknowledgement $b): int => [mb_strtolower($a->getUser()->getDisplayName()), $a->getId()] <=> [mb_strtolower($b->getUser()->getDisplayName()), $b->getId()]);
        $approvals = array_values(array_filter($this->approvals->findForDocument($document), static fn (DocumentApproval $a): bool => $a->getVersionNumber() === $versionNumber && \in_array($a->getDecision(), [DocumentApproval::APPROVED, DocumentApproval::REJECTED], true)));
        usort($approvals, static fn (DocumentApproval $a, DocumentApproval $b): int => [$a->getDecidedAt(), $a->getId()] <=> [$b->getDecidedAt(), $b->getId()]);
        $fileChecksum = null === $version ? null : ($version->getChecksum() ?? (null !== $version->getContent() ? hash('sha256', (string) $version->getContent()) : null));
        $confirmed = array_values(array_filter($acknowledgements, static fn (DocumentAcknowledgement $a): bool => null !== $a->getConfirmedAt()));
        $state = null === $version ? '' : self::versionState($document, $version);
        $date = static fn (?\DateTimeInterface $d, string $format = 'Y-m-d H:i:s'): ?string => $d?->format($format);
        $canonical = [
            'format' => 2,
            'document' => [$document->getId(), $document->getTitle(), $document->getCode(), $document->getSection()->getFullName(), $date($document->getValidUntil(), 'Y-m-d'), $state],
            'version' => [$versionNumber, $date($version?->getCreatedAt(), 'Y-m-d'), $version?->isFile(), $version?->getOriginalName(), $version?->getSize(), $fileChecksum],
            'assignments' => array_map(static fn (DocumentAcknowledgement $a): array => [
                $a->getUser()->getId(), $a->getUser()->getDisplayName(), $a->getUser()->getUsername(), (string) $a->getUser()->getDepartment(),
                $date($a->getAssignedAt(), 'Y-m-d'), $date($a->getDueAt(), 'Y-m-d'), $date($a->getConfirmedAt()),
                $a->hasQuizResult() ? [$a->getQuizScore(), $a->getQuizTotal()] : null,
            ], $acknowledgements),
            // Модерация (без назначенного согласующего): в реквизиты листа попадает принявший решение.
            'approvals' => array_map(static fn (DocumentApproval $a): array => [
                ($a->getApprover() ?? $a->getDecidedBy())?->getId(), $a->getApprover()?->getDisplayName() ?? $a->getDeciderName(), ($a->getApprover() ?? $a->getDecidedBy())?->getUsername() ?? '',
                $a->getDecision(), $date($a->getDecidedAt()), (string) $a->getDecisionNote(),
            ], $approvals),
        ];

        return [
            'version' => $version,
            'acknowledgements' => $acknowledgements,
            'approvals' => $approvals,
            'file_checksum' => $fileChecksum,
            'hash' => hash('sha256', (string) json_encode($canonical, \JSON_UNESCAPED_UNICODE)),
            'acknowledged' => \count($confirmed),
            'state' => $state,
        ];
    }

    /** Состояние редакции для листа: действующая, прежняя, ждёт согласования. */
    private static function versionState(Document $document, DocumentVersion $version): string
    {
        return match (true) {
            !$document->isVersionReleased($version) => 'ждёт согласования (сотрудники видят прежнюю редакцию)',
            $version->isCurrent() && $document->isPublished() => 'действующая редакция',
            $version->isCurrent() => 'текущая редакция (документ: '.mb_strtolower(AppExtension::STATUS_LABELS[$document->getStatus()] ?? $document->getStatus()).')',
            default => 'прежняя редакция (заменена версией '.($document->getCurrentVersion()?->getNumber() ?? '?').')',
        };
    }

    /**
     * Формирует лист: при тех же записях — с тем же кодом, что и прежде (повторная выгрузка не плодит листы).
     *
     * @return array{sheet: SignatureSheet, pdf: string, filename: string}
     */
    public function generate(Document $document, int $versionNumber, User $by): array
    {
        $snapshot = $this->snapshot($document, $versionNumber);
        if (null === $snapshot['version']) {
            throw new \DomainException('У документа нет редакции '.$versionNumber.'.');
        }
        $sheet = $this->sheets->findSame($document, $versionNumber, $snapshot['hash']);
        if (null === $sheet) {
            $sheet = new SignatureSheet($this->newCode(), $document, $versionNumber, $snapshot['file_checksum'], $snapshot['hash'],
                \count($snapshot['acknowledgements']), $snapshot['acknowledged'], \count($snapshot['approvals']), $by);
            $this->em->persist($sheet);
            $this->em->flush();
            $this->auditLogger->info('Сформирован лист ознакомления', ['document' => $document->getId(), 'version' => $versionNumber, 'code' => $sheet->getCode(), 'user' => $by->getUsername()]);
        }
        $title = 'Лист ознакомления — '.($document->getCode() ? $document->getCode().' ' : '').$document->getTitle();
        // Символы, недопустимые в именах файлов Windows и macOS, — в пробелы.
        $title = trim((string) preg_replace('/\s+/u', ' ', (string) preg_replace('~[\\\\/:*?"<>|\x00-\x1f]+~u', ' ', $title)));

        return ['sheet' => $sheet, 'pdf' => $this->render($document, $snapshot, $sheet), 'filename' => mb_substr($title, 0, 140).' (версия '.$versionNumber.').pdf'];
    }

    /**
     * Сверка листа с порталом.
     *
     * @return array{document: ?Document, matches: bool, snapshot: ?array<string, mixed>, file_matches: ?bool}
     */
    public function verify(SignatureSheet $sheet): array
    {
        $document = $sheet->getDocument();
        if (null === $document) {
            return ['document' => null, 'matches' => false, 'snapshot' => null, 'file_matches' => null];
        }
        $snapshot = $this->snapshot($document, $sheet->getVersionNumber());

        return [
            'document' => $document,
            'matches' => hash_equals($sheet->getContentHash(), $snapshot['hash']),
            'snapshot' => $snapshot,
            'file_matches' => null === $sheet->getFileChecksum() ? null : $sheet->getFileChecksum() === $snapshot['file_checksum'],
        ];
    }

    /** Код в виде XXXX-XXXX-XXXX-XXXX из ввода человека (строчные, пробелы, O вместо 0, I/L вместо 1). */
    public static function normalizeCode(string $input): ?string
    {
        // Код набирают и в русской раскладке: похожие кириллические буквы — латинскими.
        $latin = strtr(mb_strtoupper((string) preg_replace('/[\s\-–—_]+/u', '', $input)), [
            'А' => 'A', 'В' => 'B', 'Е' => 'E', 'К' => 'K', 'М' => 'M', 'Н' => 'H', 'О' => '0', 'Р' => 'P', 'С' => 'C', 'Т' => 'T', 'Х' => 'X', 'З' => '3',
        ]);
        $clean = strtr($latin, ['O' => '0', 'I' => '1', 'L' => '1']);
        if (1 !== preg_match('/^['.self::ALPHABET.']{16}$/', $clean)) {
            return null;
        }

        return implode('-', str_split($clean, 4));
    }

    private function newCode(): string
    {
        do {
            $code = '';
            foreach (str_split(random_bytes(16)) as $byte) {
                $code .= self::ALPHABET[\ord($byte) & 31];
            }
            $code = implode('-', str_split($code, 4));
        } while (null !== $this->sheets->findOneByCode($code));

        return $code;
    }

    /** @param array<string, mixed> $snapshot */
    private function render(Document $document, array $snapshot, SignatureSheet $sheet): string
    {
        /** @var DocumentVersion $version */
        $version = $snapshot['version'];
        $verifyUrl = $this->portalUrl->absolute('app_verify', ['code' => $sheet->getCode()]);
        $pdf = $this->pdfFactory->create();
        $pdf->setInfo('Лист ознакомления: '.$document->getTitle(), $this->appName, 'Код проверки '.$sheet->getCode());
        $label = ($document->getCode() ? $document->getCode().' · ' : '').'версия '.$version->getNumber();
        $pdf->setFooter(static function (PdfDocument $p, int $page, int $count) use ($label, $sheet): void {
            $y = PdfDocument::A4_HEIGHT - 38;
            $p->line($p->marginLeft, $y - 6, PdfDocument::A4_WIDTH - $p->marginRight, $y - 6, 0.4);
            $p->text($p->marginLeft, $y, $label.' · код проверки '.$sheet->getCode(), 7.5, PdfDocument::REGULAR, PdfDocument::MUTED);
            $right = 'Стр. '.$page.' из '.$count;
            $p->text(PdfDocument::A4_WIDTH - $p->marginRight - $p->textWidth($right, 7.5), $y, $right, 7.5, PdfDocument::REGULAR, PdfDocument::MUTED);
        });

        $pdf->addPage();
        $pdf->write(mb_strtoupper(trim($this->appCompany.' · '.$this->appName, ' ·')), 8, PdfDocument::BOLD, PdfDocument::MUTED);
        $pdf->space(6);
        $pdf->write('Лист ознакомления с документом', 17, PdfDocument::BOLD, PdfDocument::BRAND, 1.2);
        $pdf->space(8);
        $state = $snapshot['state'];
        $pairs = [
            'Документ' => $document->getTitle(),
            'Обозначение' => (string) $document->getCode(),
            'Раздел' => $document->getSection()->getFullName(),
            'Редакция' => \sprintf('версия %d от %s', $version->getNumber(), $version->getCreatedAt()->format('d.m.Y')),
            'Состояние редакции' => $state,
        ];
        if ($version->isFile()) {
            $pairs['Файл'] = \sprintf('%s (%s)', (string) $version->getOriginalName(), FileStorage::humanSize((int) $version->getSize()));
            $pairs['Контрольная сумма файла (SHA-256)'] = (string) $snapshot['file_checksum'];
        } else {
            $pairs['Контрольная сумма текста (SHA-256)'] = (string) $snapshot['file_checksum'];
        }
        if (null !== $document->getValidUntil()) {
            $pairs['Срок актуальности'] = $document->getValidUntil()->format('d.m.Y');
        }
        $pdf->definitionList($pairs, 9.5, 165);

        if ([] !== $snapshot['approvals']) {
            $pdf->space(14);
            $pdf->ensureSpace(60);
            $pdf->write('Согласование', 12, PdfDocument::BOLD, PdfDocument::BRAND);
            $pdf->space(4);
            $rows = [];
            foreach ($snapshot['approvals'] as $approval) {
                $rows[] = [
                    $approval->getDeciderName().(null !== ($approval->getApprover() ?? $approval->getDecidedBy()) ? ' ('.($approval->getApprover() ?? $approval->getDecidedBy())->getUsername().')' : '').($approval->isModeration() ? ', модерация' : ''),
                    $approval->getDecisionLabel(),
                    $approval->getDecidedAt()?->format('d.m.Y H:i') ?? '',
                    (string) $approval->getDecisionNote(),
                ];
            }
            $pdf->table([
                ['title' => 'Согласующий', 'width' => 0.34],
                ['title' => 'Решение', 'width' => 0.16],
                ['title' => 'Дата и время', 'width' => 0.17],
                ['title' => 'Комментарий', 'width' => 0.33],
            ], $rows);
        }

        $pdf->space(14);
        $pdf->ensureSpace(80);
        $pdf->write('Ознакомление', 12, PdfDocument::BOLD, PdfDocument::BRAND);
        $assigned = \count($snapshot['acknowledgements']);
        // Просрочка — на дату листа: повторная выгрузка того же листа печатает те же цифры.
        $asOf = $sheet->getCreatedAt()->setTime(0, 0);
        $overdue = \count(array_filter($snapshot['acknowledgements'], static fn (DocumentAcknowledgement $a): bool => $a->isOverdue($asOf)));
        $pdf->write(\sprintf('Назначено: %d · ознакомились: %d · не ознакомились: %d%s', $assigned, $snapshot['acknowledged'], $assigned - $snapshot['acknowledged'], $overdue > 0 ? ' (из них с истёкшим сроком: '.$overdue.')' : ''), 9.5, PdfDocument::REGULAR, PdfDocument::MUTED);
        $pdf->space(4);
        if (0 === $assigned) {
            $pdf->write('Ознакомление с этой редакцией никому не назначалось.', 9.5);
        } else {
            $rows = [];
            foreach ($snapshot['acknowledgements'] as $i => $acknowledgement) {
                $user = $acknowledgement->getUser();
                $rows[] = [
                    (string) ($i + 1),
                    $user->getDisplayName()."\n".$user->getUsername(),
                    (string) $user->getDepartment(),
                    $acknowledgement->getAssignedAt()->format('d.m.Y').(null !== $acknowledgement->getDueAt() ? "\nсрок ".$acknowledgement->getDueAt()->format('d.m.Y') : ''),
                    null !== $acknowledgement->getConfirmedAt() ? $acknowledgement->getConfirmedAt()->format('d.m.Y H:i') : 'не ознакомлен',
                    $acknowledgement->hasQuizResult() ? \sprintf('%d из %d', $acknowledgement->getQuizScore(), $acknowledgement->getQuizTotal()) : '',
                ];
            }
            $pdf->table([
                ['title' => '№', 'width' => 0.05, 'align' => 'right'],
                ['title' => 'Сотрудник (учётная запись)', 'width' => 0.32],
                ['title' => 'Подразделение', 'width' => 0.2],
                ['title' => 'Назначено', 'width' => 0.14],
                ['title' => 'Ознакомлен', 'width' => 0.16],
                ['title' => 'Проверка знаний', 'width' => 0.13],
            ], $rows, ['size' => 8.5]);
        }
        $pdf->space(8);
        $pdf->write('Отметку «Ознакомлен» сотрудник ставит в портале сам, войдя под своей учётной записью; дата, время, номер редакции и адрес компьютера сохраняются в журнале портала.', 8, PdfDocument::REGULAR, PdfDocument::MUTED);

        // Блок проверки подлинности — целиком на одной странице.
        $pdf->space(14);
        $pdf->ensureSpace(112);
        $top = $pdf->y;
        $pdf->rect($pdf->marginLeft, $top, $pdf->contentWidth(), 104, [0.957, 0.973, 0.984]);
        $pdf->qr(QrCode::matrix($verifyUrl), $pdf->marginLeft + 10, $top + 10, 84);
        $pdf->link($pdf->marginLeft + 10, $top + 10, 84, 84, $verifyUrl);
        $x = $pdf->marginLeft + 108;
        $width = $pdf->contentWidth() - 118;
        $pdf->text($x, $top + 12, 'Проверка подлинности листа', 10.5, PdfDocument::BOLD, PdfDocument::BRAND);
        $pdf->text($x, $top + 30, 'Код проверки: '.$sheet->getCode(), 10, PdfDocument::BOLD);
        $lineY = $top + 47;
        foreach ($pdf->wrap('Отсканируйте QR-код или откройте адрес — портал покажет, выдавал ли он этот лист и совпадают ли записи с текущими:', 8, PdfDocument::REGULAR, $width) as $line) {
            $pdf->text($x, $lineY, $line, 8, PdfDocument::REGULAR, PdfDocument::MUTED);
            $lineY += 10.5;
        }
        foreach ($pdf->wrap($verifyUrl, 8, PdfDocument::REGULAR, $width) as $line) {
            $pdf->text($x, $lineY, $line, 8, PdfDocument::REGULAR, PdfDocument::BRAND);
            $pdf->link($x, $lineY, $pdf->textWidth($line, 8), 10, $verifyUrl);
            $lineY += 10.5;
        }
        $pdf->text($x, $lineY + 2, \sprintf('Сформирован %s, %s', $sheet->getCreatedAt()->format('d.m.Y H:i'), $sheet->getCreatedByName()), 8, PdfDocument::REGULAR, PdfDocument::MUTED);
        $pdf->y = $top + 104;

        return $pdf->output();
    }
}
