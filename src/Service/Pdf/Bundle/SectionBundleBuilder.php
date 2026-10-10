<?php

declare(strict_types=1);

namespace App\Service\Pdf\Bundle;

use App\Entity\Document;
use App\Entity\DocumentVersion;
use App\Entity\Section;
use App\Entity\User;
use App\Repository\DocumentRepository;
use App\Repository\SectionRepository;
use App\Repository\UserRepository;
use App\Security\Access;
use App\Service\FileStorage;
use App\Service\Pdf\HtmlPdfWriter;
use App\Service\Pdf\PdfDocument;
use App\Service\Pdf\PdfFactory;
use App\Service\PortalUrl;
use App\Service\Preview\DocumentPreviewer;
use App\Service\Process\Shell;
use App\Service\Text\TextExtractor;

/**
 * PDF-сборник раздела: титульный лист с содержанием и действующие редакции документов раздела одним файлом.
 *
 * PDF включаются как есть, офисные файлы — через LibreOffice (тот же кэш, что у предпросмотра), страницы
 * портала, картинки и текстовые файлы — отрисовываются заново. Документы, которые в PDF не перевести
 * (архивы, чертежи, файлы под паролем), представлены страницей с реквизитами и ссылкой на портал.
 * Части объединяются программой pdfunite (пакет poppler-utils).
 */
final class SectionBundleBuilder
{
    /** Больше документов в одном сборнике не бывает: такой файл неудобно и читать, и собирать. */
    public const MAX_DOCUMENTS = 300;

    /** Предел общего объёма исходных файлов. */
    public const MAX_SOURCE_BYTES = 1024 * 1024 * 1024;

    public const IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'];
    public const TEXT_EXTENSIONS = ['txt', 'md', 'markdown', 'csv', 'tsv', 'log', 'json', 'xml', 'yaml', 'yml', 'ini', 'conf', 'sql', 'rst'];

    /** Картинки и текстовые файлы больше этого не отрисовываются (только реквизиты). */
    private const MAX_IMAGE_BYTES = 40 * 1024 * 1024;
    private const MAX_TEXT_BYTES = 400 * 1024;

    private const TOC_NUMBER_WIDTH = 34.0;

    public function __construct(
        private readonly DocumentRepository $documents,
        private readonly SectionRepository $sections,
        private readonly UserRepository $users,
        private readonly FileStorage $storage,
        private readonly DocumentPreviewer $previewer,
        private readonly PdfFactory $pdfFactory,
        private readonly PortalUrl $portalUrl,
        private readonly Access $access,
        private readonly string $appName,
        private readonly string $appCompany,
    ) {
    }

    /** Сборник доступен, если установлены pdfunite и pdfinfo (пакет poppler-utils). */
    public function isAvailable(): bool
    {
        return Shell::exists('pdfunite') && Shell::exists('pdfinfo');
    }

    /** Переводятся ли офисные файлы (нужен LibreOffice). */
    public function officeAvailable(): bool
    {
        return $this->previewer->isAvailable();
    }

    /**
     * Документы, которые можно включить в сборник: опубликованные документы раздела и его подразделов, видимые
     * пользователю, — по разделам в порядке дерева, внутри раздела по названию.
     *
     * @return list<array{section: Section, depth: int, documents: list<Document>}>
     */
    public function candidates(Section $root, User $user): array
    {
        $byParent = [];
        foreach ($this->sections->findSubtree($root) as $section) {
            if ($section !== $root) {
                $byParent[$section->getParent()?->getId() ?? 0][] = $section;
            }
        }
        foreach ($byParent as &$children) {
            usort($children, static fn (Section $a, Section $b): int => [$a->getPosition(), mb_strtolower($a->getName())] <=> [$b->getPosition(), mb_strtolower($b->getName())]);
        }
        unset($children);
        $ordered = [];
        $walk = static function (Section $section, int $depth) use (&$walk, &$ordered, $byParent): void {
            $ordered[] = [$section, $depth];
            foreach ($byParent[$section->getId()] ?? [] as $child) {
                $walk($child, $depth + 1);
            }
        };
        $walk($root, 0);

        $bySection = [];
        foreach ($this->documents->findInSubtree($root, [Document::STATUS_PUBLISHED], 'title', 0, false, $this->access->viewer($user)) as $document) {
            if (null !== $document->getCurrentVersion()) {
                $bySection[$document->getSection()->getId()][] = $document;
            }
        }
        $groups = [];
        foreach ($ordered as [$section, $depth]) {
            if ([] !== ($bySection[$section->getId()] ?? [])) {
                $groups[] = ['section' => $section, 'depth' => $depth, 'documents' => $bySection[$section->getId()]];
            }
        }

        return $groups;
    }

    /**
     * Элементы задания для выбранных документов (в порядке $documents).
     *
     * @param list<Document> $documents
     *
     * @return list<array{document: int, version: int, title: string, code: ?string, section: string, kind: string, extension: string, status: string, pages: int, note: ?string, files: list<string>}>
     */
    public function items(array $documents): array
    {
        $items = [];
        foreach ($documents as $document) {
            $version = $document->getCurrentVersion();
            if (null === $version) {
                continue;
            }
            $items[] = [
                'document' => (int) $document->getId(),
                'version' => $version->getNumber(),
                'title' => $document->getTitle(),
                'code' => $document->getCode(),
                'section' => $document->getSection()->getFullName(),
                'kind' => $this->kindOf($version),
                'extension' => $version->isFile() ? $version->getExtension() : '',
                'status' => BundleJob::ITEM_PENDING,
                'pages' => 0,
                'note' => null,
                'files' => [],
            ];
        }

        return $items;
    }

    /** @param list<Document> $documents */
    public static function sourceBytes(array $documents): int
    {
        $bytes = 0;
        foreach ($documents as $document) {
            $bytes += (int) $document->getCurrentVersion()?->getSize();
        }

        return $bytes;
    }

    public function kindOf(DocumentVersion $version): string
    {
        if (!$version->isFile()) {
            return BundleJob::KIND_PAGE;
        }
        $extension = $version->getExtension();

        return match (true) {
            'pdf' === $extension => BundleJob::KIND_PDF,
            \in_array($extension, DocumentPreviewer::CONVERTIBLE, true) => BundleJob::KIND_OFFICE,
            \in_array($extension, self::IMAGE_EXTENSIONS, true) => BundleJob::KIND_IMAGE,
            \in_array($extension, self::TEXT_EXTENSIONS, true) => BundleJob::KIND_TEXT,
            default => BundleJob::KIND_OTHER,
        };
    }

    /** Готовит часть сборника для документа под номером $index: файлы части — в каталоге задания. */
    public function buildPart(BundleJob $job, int $index, string $dir): void
    {
        $item = $job->items[$index];
        $actor = $this->users->find($job->actorId);
        $document = $this->documents->find($item['document']);
        $version = $document?->findVersion($item['version']);
        $prefix = \sprintf('%s/part-%04d', $dir, $index + 1);
        $note = null;
        $source = null;
        if (null === $document || null === $version) {
            $note = 'документ удалён после того, как был запущен сборник.';
        } elseif (!$actor instanceof User || !$actor->isActive() || !$this->access->canViewDocument($actor, $document)) {
            $note = 'документ вам больше не доступен.';
        } elseif (\in_array($item['kind'], [BundleJob::KIND_PDF, BundleJob::KIND_OFFICE], true)) {
            [$source, $note] = $this->sourcePdf($item, $version);
        }

        if (null !== $source) {
            $files = [];
            $pages = $source['pages'];
            if ($job->titlePages) {
                $title = $this->newPdf($job, $item);
                $this->titlePage($title, $job, $index, $document, $version, null);
                $this->save($title, $prefix.'-a.pdf');
                $files[] = basename($prefix.'-a.pdf');
                $pages += $title->pageCount(); // длинные реквизиты могут занять и две страницы
            }
            $target = $prefix.'-b.pdf';
            if (!@link($source['path'], $target) && !@copy($source['path'], $target)) {
                throw new \RuntimeException('Не удалось скопировать файл документа «'.$item['title'].'» в рабочий каталог сборника.');
            }
            $files[] = basename($target);
            $this->complete($job, $index, BundleJob::ITEM_OK, $pages, null, $files);

            return;
        }

        $pdf = $this->newPdf($job, $item);
        $status = BundleJob::ITEM_OK;
        if (null === $note && null !== $document && null !== $version) {
            $note = $this->renderContent($pdf, $job, $index, $document, $version);
        }
        if (null !== $note) {
            // Содержимое не включено: титульная страница (или заголовок) с пояснением и ссылкой на портал.
            $status = BundleJob::ITEM_NOTE;
            $pdf = $this->newPdf($job, $item);
            if ($job->titlePages || null === $document || null === $version) {
                $this->titlePage($pdf, $job, $index, $document, $version, $note);
            } else {
                $this->heading($pdf, $document, $version);
                $this->noteBox($pdf, $note, $document);
            }
        }
        $this->save($pdf, $prefix.'.pdf');
        $this->complete($job, $index, $status, $pdf->pageCount(), $note, [basename($prefix.'.pdf')]);
    }

    /**
     * Документ не удалось подготовить (ошибка чтения, нехватка памяти): в сборник идёт страница с реквизитами
     * и причиной — остальные документы собираются дальше.
     */
    public function failPart(BundleJob $job, int $index, string $dir, string $reason): void
    {
        $item = $job->items[$index];
        foreach ((array) ($item['files'] ?? []) as $file) {
            @unlink($dir.'/'.$file);
        }
        $document = $this->documents->find($item['document']);
        $version = $document?->findVersion($item['version']);
        $note = 'документ не удалось подготовить ('.rtrim(self::shorten($reason, 200), '.').').';
        $pdf = $this->newPdf($job, $item);
        $this->titlePage($pdf, $job, $index, $document, $version, $note);
        $path = \sprintf('%s/part-%04d.pdf', $dir, $index + 1);
        $this->save($pdf, $path);
        $this->complete($job, $index, BundleJob::ITEM_NOTE, $pdf->pageCount(), $note, [basename($path)]);
    }

    /**
     * Титульная часть (обложка и содержание с номерами страниц) и объединение частей в один файл.
     * Номера страниц считаются по самим файлам частей (pdfinfo). Если pdfunite не может объединить
     * какой-то файл, он заменяется страницей с реквизитами, и объединение повторяется.
     * Части не удаляются (см. removeParts()): прерванное объединение можно повторить.
     */
    public function finish(BundleJob $job, string $dir): string
    {
        $output = $dir.'/bundle.pdf';
        for ($attempt = 1; ; ++$attempt) {
            [$files, $expected] = $this->assemble($job, $dir);
            @unlink($output);
            $result = Shell::run(['pdfunite', ...$files, $output], 600);
            if (0 === $result['code'] && is_file($output) && (int) filesize($output) > 0) {
                break;
            }
            $message = trim(strtok($result['err'] ?: $result['out'], "\n") ?: '');
            if ($attempt >= 2 || 0 === $this->replaceUnmergeable($job, $dir)) {
                @unlink($output);
                throw new \RuntimeException('Не удалось объединить файлы сборника'.('' !== $message ? ': '.$message : '.'));
            }
        }
        $job->pages = (self::pdfInfo($output) ?? ['pages' => $expected])['pages'];
        $job->bytes = (int) filesize($output);

        return $output;
    }

    /** Удаляет обложку и части (после того как готовый сборник сохранён). */
    public function removeParts(BundleJob $job, string $dir): void
    {
        @unlink($dir.'/front.pdf');
        foreach ($job->items as $item) {
            foreach ((array) $item['files'] as $file) {
                @unlink($dir.'/'.basename((string) $file));
            }
        }
    }

    /**
     * Обложка с точными номерами страниц и список файлов для объединения.
     *
     * @return array{0: list<string>, 1: int} файлы по порядку и ожидаемое число страниц
     */
    private function assemble(BundleJob $job, string $dir): array
    {
        foreach ($job->items as $i => $item) {
            $pages = 0;
            foreach ($item['files'] as $file) {
                $pages += (self::pdfInfo($dir.'/'.$file) ?? ['pages' => 0])['pages'];
            }
            if ($pages > 0) {
                $job->items[$i]['pages'] = $pages;
            }
        }
        // Обложка печатается дважды: черновик даёт её длину, затем — с настоящими номерами страниц.
        $frontPages = $this->front($job, null)->pageCount();
        for ($pass = 0; $pass < 3; ++$pass) {
            $starts = [];
            $page = $frontPages + 1;
            foreach ($job->items as $i => $item) {
                $starts[$i] = $page;
                $page += max(0, $item['pages']);
            }
            $front = $this->front($job, $starts);
            if ($front->pageCount() === $frontPages) {
                break;
            }
            $frontPages = $front->pageCount();
        }
        $this->save($front, $dir.'/front.pdf');
        $files = [$dir.'/front.pdf'];
        foreach ($job->items as $item) {
            foreach ($item['files'] as $file) {
                $files[] = $dir.'/'.$file;
            }
        }

        return [$files, $page - 1];
    }

    /** Заменяет страницей с реквизитами документы, файлы которых pdfunite не принимает; возвращает их число. */
    private function replaceUnmergeable(BundleJob $job, string $dir): int
    {
        $replaced = 0;
        $probe = $dir.'/probe.pdf';
        foreach ($job->items as $i => $item) {
            foreach ($item['files'] as $file) {
                $result = Shell::run(['pdfunite', $dir.'/'.$file, $probe], 120);
                @unlink($probe);
                if (0 !== $result['code']) {
                    $this->failPart($job, $i, $dir, 'файл не удалось объединить с остальными (повреждённый или нестандартный PDF)');
                    ++$replaced;
                    break;
                }
            }
        }

        return $replaced;
    }

    /**
     * PDF документа (как есть или после LibreOffice) или причина, по которой его не включить.
     *
     * @param array<string, mixed> $item
     *
     * @return array{0: array{path: string, pages: int}|null, 1: ?string}
     */
    private function sourcePdf(array $item, DocumentVersion $version): array
    {
        $extension = mb_strtoupper((string) $item['extension']);
        if (BundleJob::KIND_OFFICE === $item['kind']) {
            if (!$this->previewer->isAvailable()) {
                return [null, 'файлы '.$extension.' переводятся в PDF программой LibreOffice, а она на сервере портала не установлена.'];
            }
            $path = $this->previewer->pdf($version);
            if (null === $path) {
                return [null, 'файл '.$extension.' не удалось перевести в PDF (он повреждён, защищён паролем или слишком велик).'];
            }
        } else {
            $path = $this->storage->absolutePath($version);
            if (null === $path || !is_file($path)) {
                return [null, 'файл не найден в хранилище портала.'];
            }
        }
        $info = self::pdfInfo($path);
        if (null === $info || $info['pages'] < 1) {
            return [null, 'файл PDF повреждён или не читается.'];
        }
        if ($info['encrypted']) {
            return [null, 'файл PDF зашифрован (защищён паролем), объединить его с другими нельзя.'];
        }

        return [['path' => $path, 'pages' => $info['pages']], null];
    }

    /**
     * Отрисовывает содержимое страницы, картинки или текстового файла; возвращает причину, если не вышло.
     */
    private function renderContent(PdfDocument $pdf, BundleJob $job, int $index, Document $document, DocumentVersion $version): ?string
    {
        $kind = $job->items[$index]['kind'];
        if (BundleJob::KIND_OTHER === $kind) {
            return 'файлы '.mb_strtoupper($version->getExtension() ?: 'этого формата').' нельзя показать в PDF.';
        }
        if ($job->titlePages) {
            $this->titlePage($pdf, $job, $index, $document, $version, null);
            $pdf->addPage();
        } else {
            $this->heading($pdf, $document, $version);
        }
        if (BundleJob::KIND_PAGE === $kind) {
            (new HtmlPdfWriter($pdf, rtrim($this->portalUrl->absolute('app_home'), '/')))->write((string) $version->getContent());

            return null;
        }
        $path = $this->storage->absolutePath($version);
        if (null === $path || !is_file($path)) {
            return 'файл не найден в хранилище портала.';
        }
        if (BundleJob::KIND_IMAGE === $kind) {
            if ((int) filesize($path) > self::MAX_IMAGE_BYTES) {
                return 'картинка слишком велика для сборника.';
            }
            $bytes = (string) file_get_contents($path);
            $maxHeight = PdfDocument::A4_HEIGHT - $pdf->marginBottom - $pdf->y - 4;
            $fullHeight = PdfDocument::A4_HEIGHT - $pdf->marginBottom - $pdf->marginTop - 4;
            $fit = PdfDocument::fitImage($bytes, $pdf->contentWidth(), $maxHeight);
            $fullFit = PdfDocument::fitImage($bytes, $pdf->contentWidth(), $fullHeight);
            if (null === $fit || null === $fullFit) {
                return 'картинку не удалось прочитать.';
            }
            // Под заголовком картинка сильно уменьшилась бы — тогда она печатается на отдельной странице.
            if ($fit['height'] < $fullFit['height'] * 0.75) {
                $pdf->addPage();
                $maxHeight = $fullHeight;
            }
            if (null === $pdf->image($bytes, $pdf->marginLeft, $pdf->y, $pdf->contentWidth(), $maxHeight)) {
                return 'картинку этого формата не удалось встроить.';
            }

            return null;
        }
        // Текстовый файл.
        $handle = @fopen($path, 'rb');
        if (false === $handle) {
            return 'файл не читается.';
        }
        $raw = (string) fread($handle, self::MAX_TEXT_BYTES + 1);
        fclose($handle);
        $truncated = \strlen($raw) > self::MAX_TEXT_BYTES;
        $text = TextExtractor::toUtf8($truncated ? substr($raw, 0, self::MAX_TEXT_BYTES) : $raw);
        $text = str_replace(["\r\n", "\r", "\t"], ["\n", "\n", '    '], $text);
        $text = (string) mb_convert_encoding($text, 'UTF-8', 'UTF-8');
        $pdf->write('' !== trim($text) ? $text : 'Файл пуст.', 8.5, PdfDocument::REGULAR, PdfDocument::BLACK, 1.3);
        if ($truncated) {
            $pdf->space(6);
            $pdf->write('Файл длиннее, чем помещается в сборник: полностью он доступен в портале.', 8.5, PdfDocument::BOLD, PdfDocument::MUTED);
        }

        return null;
    }

    /** @param array<string, mixed> $item */
    private function newPdf(BundleJob $job, array $item): PdfDocument
    {
        $pdf = $this->pdfFactory->create();
        $pdf->setInfo((string) $item['title'], $this->appName, 'Сборник документов: '.$job->sectionName);
        $left = (null !== $item['code'] && '' !== $item['code'] ? $item['code'].' · ' : '').'версия '.$item['version'];
        $right = 'Сборник «'.self::shorten($job->sectionName, 60).'»';
        $pdf->setFooter(static function (PdfDocument $p) use ($left, $right): void {
            $y = PdfDocument::A4_HEIGHT - 38;
            $p->line($p->marginLeft, $y - 6, PdfDocument::A4_WIDTH - $p->marginRight, $y - 6, 0.4);
            $p->text($p->marginLeft, $y, $left, 7.5, PdfDocument::REGULAR, PdfDocument::MUTED);
            $p->text(PdfDocument::A4_WIDTH - $p->marginRight - $p->textWidth($right, 7.5), $y, $right, 7.5, PdfDocument::REGULAR, PdfDocument::MUTED);
        });

        return $pdf;
    }

    /** Отдельная страница с реквизитами документа (перед его содержимым или вместо него). */
    private function titlePage(PdfDocument $pdf, BundleJob $job, int $index, ?Document $document, ?DocumentVersion $version, ?string $note): void
    {
        $item = $job->items[$index];
        $pdf->addPage();
        $pdf->write(mb_strtoupper(trim($this->appCompany.' · '.$this->appName, ' ·')), 8, PdfDocument::BOLD, PdfDocument::MUTED);
        $pdf->space(2);
        $pdf->write('Сборник «'.$job->sectionName.'» · документ '.($index + 1).' из '.$job->total(), 8.5, PdfDocument::REGULAR, PdfDocument::MUTED);
        $pdf->space(70);
        $pdf->write((string) $item['title'], 20, PdfDocument::BOLD, PdfDocument::BRAND, 1.25);
        if (null !== $item['code'] && '' !== $item['code']) {
            $pdf->space(4);
            $pdf->write((string) $item['code'], 12, PdfDocument::BOLD, PdfDocument::MUTED);
        }
        $pdf->space(18);
        $pairs = ['Раздел' => (string) $item['section']];
        if (null !== $document && null !== $version) {
            $pairs['Редакция'] = \sprintf('версия %d от %s', $version->getNumber(), $version->getCreatedAt()->format('d.m.Y'));
            $pairs['Действует до'] = null !== $document->getValidUntil() ? $document->getValidUntil()->format('d.m.Y') : 'без ограничения срока';
            $pairs['Вид'] = $version->isFile()
                ? \sprintf('файл %s (%s)', (string) $version->getOriginalName(), FileStorage::humanSize((int) $version->getSize()))
                : 'страница портала';
            if (null !== $document->getOwner()) {
                $pairs['Ответственный'] = $document->getOwner()->getDisplayName();
            }
            if (null !== $document->getDescription() && '' !== trim($document->getDescription())) {
                $pairs['Описание'] = self::shorten(trim($document->getDescription()), 700);
            }
            $pairs['В портале'] = $this->portalUrl->absolute('app_document_show', ['id' => $document->getId()]);
        } else {
            $pairs['Редакция'] = 'версия '.$item['version'];
        }
        $pdf->definitionList($pairs, 10, 120);
        if (null !== $note) {
            $pdf->space(16);
            $this->noteBox($pdf, $note, $document);
        }
    }

    /** Заголовок документа над его содержимым (когда отдельных титульных страниц нет). */
    private function heading(PdfDocument $pdf, Document $document, DocumentVersion $version): void
    {
        $pdf->addPage();
        $pdf->write($document->getTitle(), 14, PdfDocument::BOLD, PdfDocument::BRAND, 1.25);
        $meta = array_filter([
            $document->getCode(),
            \sprintf('версия %d от %s', $version->getNumber(), $version->getCreatedAt()->format('d.m.Y')),
            null !== $document->getValidUntil() ? 'действует до '.$document->getValidUntil()->format('d.m.Y') : null,
            $document->getSection()->getFullName(),
        ], static fn (?string $v): bool => null !== $v && '' !== $v);
        $pdf->space(2);
        $pdf->write(implode(' · ', $meta), 8.5, PdfDocument::REGULAR, PdfDocument::MUTED);
        $pdf->space(6);
        $pdf->line($pdf->marginLeft, $pdf->y, PdfDocument::A4_WIDTH - $pdf->marginRight, $pdf->y, 0.6);
        $pdf->space(12);
    }

    private function noteBox(PdfDocument $pdf, string $note, ?Document $document): void
    {
        $text = 'Содержимое не включено в сборник: '.$note;
        if (null !== $document) {
            $text .= ' Документ доступен в портале: '.$this->portalUrl->absolute('app_document_show', ['id' => $document->getId()]);
        }
        $lines = $pdf->wrap($text, 10, PdfDocument::REGULAR, $pdf->contentWidth() - 24);
        $height = \count($lines) * 13.5 + 20;
        $pdf->ensureSpace($height);
        $pdf->rect($pdf->marginLeft, $pdf->y, $pdf->contentWidth(), $height, [1.0, 0.973, 0.906], [0.95, 0.85, 0.63]);
        $y = $pdf->y + 10;
        foreach ($lines as $line) {
            $pdf->text($pdf->marginLeft + 12, $y + 1.75, $line, 10, PdfDocument::REGULAR, [0.42, 0.29, 0.0]);
            $y += 13.5;
        }
        $pdf->y += $height;
    }

    /**
     * Обложка и содержание. $starts — номера первых страниц документов (null — черновой проход).
     *
     * @param array<int, int>|null $starts
     */
    private function front(BundleJob $job, ?array $starts): PdfDocument
    {
        $pdf = $this->pdfFactory->create();
        $pdf->setInfo('Сборник документов: '.$job->sectionName, $this->appName, 'Действующие редакции документов раздела на '.$job->createdAt->format('d.m.Y'));
        $sectionName = self::shorten($job->sectionName, 80);
        $pdf->setFooter(static function (PdfDocument $p, int $page, int $count) use ($sectionName): void {
            $y = PdfDocument::A4_HEIGHT - 38;
            $p->line($p->marginLeft, $y - 6, PdfDocument::A4_WIDTH - $p->marginRight, $y - 6, 0.4);
            $p->text($p->marginLeft, $y, 'Сборник «'.$sectionName.'» · содержание', 7.5, PdfDocument::REGULAR, PdfDocument::MUTED);
            $right = 'Стр. '.$page;
            $p->text(PdfDocument::A4_WIDTH - $p->marginRight - $p->textWidth($right, 7.5), $y, $right, 7.5, PdfDocument::REGULAR, PdfDocument::MUTED);
        });
        $pdf->addPage();
        $pdf->write(mb_strtoupper(trim($this->appCompany.' · '.$this->appName, ' ·')), 8, PdfDocument::BOLD, PdfDocument::MUTED);
        $pdf->space(40);
        $pdf->write('Сборник документов', 24, PdfDocument::BOLD, PdfDocument::BRAND, 1.2);
        $pdf->space(6);
        $pdf->write($job->sectionName, 15, PdfDocument::BOLD, PdfDocument::BLACK, 1.25);
        $pdf->space(14);
        $counters = $job->counters();
        $pdf->definitionList(array_filter([
            'Сформирован' => $job->createdAt->format('d.m.Y H:i').', '.$job->actorName,
            'Документов' => (string) $job->total().($counters['note'] > 0 ? ' (из них '.$counters['note'].' — только реквизиты: содержимое в PDF не переводится)' : ''),
            'Редакции' => 'действующие на дату формирования сборника',
            'Актуальные версии' => $this->portalUrl->absolute('app_section_show', ['id' => $job->sectionId]),
        ]), 9.5, 120);
        $pdf->space(22);
        $pdf->write('Содержание', 14, PdfDocument::BOLD, PdfDocument::BRAND);
        $pdf->write('Номера страниц — по порядку в этом файле (так их показывает программа просмотра PDF).', 8, PdfDocument::REGULAR, PdfDocument::MUTED);
        $pdf->space(6);

        $rootPrefix = $job->sectionName.' / ';
        $currentSection = null;
        $size = 9.5;
        $step = $size * 1.4;
        $textWidth = $pdf->contentWidth() - self::TOC_NUMBER_WIDTH - 10;
        foreach ($job->items as $i => $item) {
            if ($item['section'] !== $currentSection) {
                $currentSection = $item['section'];
                $label = str_starts_with($currentSection, $rootPrefix) ? mb_substr($currentSection, mb_strlen($rootPrefix)) : ($currentSection === $job->sectionName ? '' : $currentSection);
                if ('' !== $label) {
                    $pdf->space(4);
                    $pdf->ensureSpace($step * 3);
                    $pdf->write($label, 9.5, PdfDocument::BOLD, PdfDocument::BRAND, 1.4);
                }
            }
            $title = ($i + 1).'. '.$item['title'].(null !== $item['code'] && '' !== $item['code'] ? ' — '.$item['code'] : '');
            if (BundleJob::ITEM_NOTE === $item['status']) {
                $title .= ' (только реквизиты)';
            }
            $lines = $pdf->wrap($title, $size, PdfDocument::REGULAR, $textWidth);
            $pdf->ensureSpace(\count($lines) * $step);
            foreach ($lines as $n => $line) {
                $top = $pdf->y + ($step - $size) / 2;
                $pdf->text($pdf->marginLeft + ($n > 0 ? 14 : 0), $top, $line, $size, PdfDocument::REGULAR, BundleJob::ITEM_NOTE === $item['status'] ? PdfDocument::MUTED : PdfDocument::BLACK);
                if ($n === \count($lines) - 1) {
                    $number = null === $starts ? '0000' : (string) $starts[$i];
                    $numberX = PdfDocument::A4_WIDTH - $pdf->marginRight - $pdf->textWidth($number, $size);
                    $dotsFrom = $pdf->marginLeft + ($n > 0 ? 14 : 0) + $pdf->textWidth($line, $size) + 4;
                    $dotWidth = $pdf->textWidth('.', $size);
                    $dots = (int) floor(($numberX - 4 - $dotsFrom) / max(0.1, $dotWidth));
                    if ($dots > 0 && null !== $starts) {
                        $pdf->text($dotsFrom, $top, str_repeat('.', $dots), $size, PdfDocument::REGULAR, PdfDocument::MUTED);
                    }
                    if (null !== $starts) {
                        $pdf->text($numberX, $top, $number, $size, PdfDocument::REGULAR);
                    }
                }
                $pdf->y += $step;
            }
        }

        return $pdf;
    }

    /** @param list<string> $files */
    private function complete(BundleJob $job, int $index, string $status, int $pages, ?string $note, array $files): void
    {
        $job->items[$index]['status'] = $status;
        $job->items[$index]['pages'] = $pages;
        $job->items[$index]['note'] = null !== $note ? mb_strtoupper(mb_substr($note, 0, 1)).mb_substr($note, 1) : null;
        $job->items[$index]['files'] = $files;
    }

    private function save(PdfDocument $pdf, string $path): void
    {
        if (false === @file_put_contents($path, $pdf->output())) {
            throw new \RuntimeException('Не удалось записать файл сборника: '.basename($path));
        }
    }

    /** @return array{pages: int, encrypted: bool}|null сведения о PDF (pdfinfo) или null, если файл не читается */
    public static function pdfInfo(string $path): ?array
    {
        $result = Shell::run(['pdfinfo', $path], 60, null, [], 1_048_576);
        if (0 !== $result['code'] || 1 !== preg_match('/^Pages:\s+(\d+)/m', $result['out'], $pages)) {
            return null;
        }

        return ['pages' => (int) $pages[1], 'encrypted' => 1 === preg_match('/^Encrypted:\s+yes/m', $result['out'])];
    }

    private static function shorten(string $text, int $length): string
    {
        return mb_strlen($text) > $length ? rtrim(mb_substr($text, 0, $length - 1)).'…' : $text;
    }
}
