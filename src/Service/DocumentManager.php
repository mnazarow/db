<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Document;
use App\Entity\DocumentDeletion;
use App\Entity\DocumentEvent;
use App\Entity\DocumentVersion;
use App\Entity\Section;
use App\Entity\User;
use App\Service\Text\TextIndexer;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Жизненный цикл документа: создание, версии, публикация, архив, восстановление, удаление,
 * учёт просмотров и скачиваний. Каждое действие фиксируется событием (DocumentEvent).
 */
final class DocumentManager
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly FileStorage $storage,
        private readonly HtmlSanitizerInterface $documentPage,
        private readonly LoggerInterface $auditLogger,
        private readonly TextIndexer $indexer,
    ) {
    }

    public function getStorage(): FileStorage
    {
        return $this->storage;
    }

    /**
     * Создаёт документ с первой версией.
     *
     * @throws \InvalidArgumentException при некорректном файле/содержимом
     */
    public function create(Document $document, ?UploadedFile $file, ?string $pageContent, ?string $changeNote, User $actor, bool $publish, ?string $ip = null): Document
    {
        if ($document->isFile()) {
            if (null === $file) {
                throw new \InvalidArgumentException('Выберите файл документа.');
            }
            $errors = $this->storage->validate($file);
            if ([] !== $errors) {
                throw new \InvalidArgumentException(implode(' ', $errors));
            }
            $fill = fn (DocumentVersion $version) => $this->storage->store($file, $version);
        } else {
            $pageContent = $this->sanitize($pageContent);
            if ('' === trim(strip_tags($pageContent))) {
                throw new \InvalidArgumentException('Текст страницы пустой.');
            }
            $fill = static fn (DocumentVersion $version) => $version->setContent($pageContent)->setSize(\strlen($pageContent))->setMimeType('text/html');
        }

        return $this->createWith($document, $fill, $changeNote, $actor, $publish, $ip, null);
    }

    /**
     * Создаёт документ-файл из файла, уже лежащего на диске сервера (импорт из каталога).
     * $originalName — имя, под которым файл будет показан пользователям; $moveSource — перенести файл, а не копировать.
     *
     * @param array<string, mixed>|null $details дополнительные сведения для события создания (например, источник импорта)
     *
     * @throws \InvalidArgumentException при некорректном файле
     */
    public function createFromPath(Document $document, string $path, string $originalName, ?string $changeNote, User $actor, bool $publish, bool $moveSource = false, bool $anyExtension = false, ?array $details = null): Document
    {
        if (!$document->isFile()) {
            throw new \InvalidArgumentException('Из файла на диске можно создать только документ-файл.');
        }
        $errors = $this->storage->validatePath($path, $originalName, $anyExtension);
        if ([] !== $errors) {
            throw new \InvalidArgumentException(implode(' ', $errors));
        }

        return $this->createWith($document, fn (DocumentVersion $version) => $this->storage->storeFromPath($path, $originalName, $version, $moveSource), $changeNote, $actor, $publish, null, $details);
    }

    /**
     * Общая часть создания документа: первая версия, событие создания и (при необходимости) публикация.
     *
     * @param callable(DocumentVersion): mixed $fillVersion заполняет содержимое версии (файл или текст)
     * @param array<string, mixed>|null        $details
     */
    private function createWith(Document $document, callable $fillVersion, ?string $changeNote, User $actor, bool $publish, ?string $ip, ?array $details): Document
    {
        $document->setOwner($actor)->setStatus(Document::STATUS_DRAFT);
        $this->em->persist($document);
        $this->em->flush(); // нужен id для каталога хранилища

        $version = new DocumentVersion($document, 1);
        $version->setKind($document->getType())->setCreatedBy($actor)->setChangeNote($changeNote ?? 'Первая версия');
        $fillVersion($version);
        $this->em->persist($version);
        $this->em->flush();

        $document->setCurrentVersion($version);
        $this->em->persist(new DocumentEvent($document, DocumentEvent::CREATE, $actor, $version, $ip, $details));
        if ($publish) {
            $document->setStatus(Document::STATUS_PUBLISHED)->setPublishedAt(new \DateTimeImmutable());
            $this->em->persist(new DocumentEvent($document, DocumentEvent::PUBLISH, $actor, $version, $ip));
        }
        $this->em->flush();

        $this->indexer->index($document);
        $this->auditLogger->info('Создан документ', ['document' => $document->getId(), 'title' => $document->getTitle(), 'section' => $document->getSection()->getFullName(), 'published' => $publish, 'by' => $actor->getUsername()]);

        return $document;
    }

    /**
     * Сохраняет изменения карточки (название, описание, срок, теги, раздел).
     * Если раздел изменился — фиксируется событие перемещения.
     */
    public function updateMetadata(Document $document, ?Section $previousSection, User $actor, ?string $ip = null): void
    {
        $document->setUpdatedAt(new \DateTimeImmutable());
        $this->em->persist(new DocumentEvent($document, DocumentEvent::UPDATE, $actor, null, $ip));
        if (null !== $previousSection && $previousSection->getId() !== $document->getSection()->getId()) {
            $this->em->persist(new DocumentEvent($document, DocumentEvent::MOVE, $actor, null, $ip, [
                'from' => $previousSection->getFullName(),
                'to' => $document->getSection()->getFullName(),
            ]));
        }
        $this->em->flush();
        $this->auditLogger->info('Изменена карточка документа', ['document' => $document->getId(), 'by' => $actor->getUsername()]);
    }

    /**
     * Загружает новую версию файла.
     *
     * @throws \InvalidArgumentException при некорректном файле
     */
    public function addFileVersion(Document $document, UploadedFile $file, ?string $changeNote, User $actor, ?string $ip = null): DocumentVersion
    {
        if (!$document->isFile()) {
            throw new \InvalidArgumentException('Для страницы нельзя загрузить файл.');
        }
        $errors = $this->storage->validate($file);
        if ([] !== $errors) {
            throw new \InvalidArgumentException(implode(' ', $errors));
        }

        return $this->addFileVersionWith($document, fn (DocumentVersion $version) => $this->storage->store($file, $version), $changeNote, $actor, $ip);
    }

    /**
     * Загружает новую версию из файла на диске сервера (импорт из каталога).
     *
     * @throws \InvalidArgumentException при некорректном файле
     */
    public function addFileVersionFromPath(Document $document, string $path, string $originalName, ?string $changeNote, User $actor, bool $moveSource = false, bool $anyExtension = false): DocumentVersion
    {
        if (!$document->isFile()) {
            throw new \InvalidArgumentException('Для страницы нельзя загрузить файл.');
        }
        $errors = $this->storage->validatePath($path, $originalName, $anyExtension);
        if ([] !== $errors) {
            throw new \InvalidArgumentException(implode(' ', $errors));
        }

        return $this->addFileVersionWith($document, fn (DocumentVersion $version) => $this->storage->storeFromPath($path, $originalName, $version, $moveSource), $changeNote, $actor, null);
    }

    /** @param callable(DocumentVersion): mixed $fillVersion */
    private function addFileVersionWith(Document $document, callable $fillVersion, ?string $changeNote, User $actor, ?string $ip): DocumentVersion
    {
        $version = new DocumentVersion($document, $document->getNextVersionNumber());
        $version->setKind(Document::TYPE_FILE)->setCreatedBy($actor)->setChangeNote($changeNote);
        $fillVersion($version);
        $this->em->persist($version);
        $this->em->flush();
        $document->setCurrentVersion($version)->setUpdatedAt(new \DateTimeImmutable());
        $this->em->persist(new DocumentEvent($document, DocumentEvent::NEW_VERSION, $actor, $version, $ip, ['number' => $version->getNumber(), 'file' => $version->getOriginalName()]));
        $this->em->flush();
        $this->indexer->index($document);
        $this->auditLogger->info('Загружена новая версия файла', ['document' => $document->getId(), 'version' => $version->getNumber(), 'by' => $actor->getUsername()]);

        return $version;
    }

    /**
     * Сохраняет новую версию текста страницы. Если текст не изменился — версия не создаётся (возвращает null).
     *
     * @throws \InvalidArgumentException если текст пустой
     */
    public function addPageVersion(Document $document, ?string $html, ?string $changeNote, User $actor, ?string $ip = null): ?DocumentVersion
    {
        if (!$document->isPage()) {
            throw new \InvalidArgumentException('Для файла нельзя сохранить текст страницы.');
        }
        $html = $this->sanitize($html);
        if ('' === trim(strip_tags($html))) {
            throw new \InvalidArgumentException('Текст страницы пустой.');
        }
        $current = $document->getCurrentVersion();
        if (null !== $current && $current->getContent() === $html) {
            return null;
        }
        $version = new DocumentVersion($document, $document->getNextVersionNumber());
        $version->setKind(Document::TYPE_PAGE)->setCreatedBy($actor)->setChangeNote($changeNote)
            ->setContent($html)->setSize(\strlen($html))->setMimeType('text/html');
        $this->em->persist($version);
        $this->em->flush();
        $document->setCurrentVersion($version)->setUpdatedAt(new \DateTimeImmutable());
        $this->em->persist(new DocumentEvent($document, DocumentEvent::NEW_VERSION, $actor, $version, $ip, ['number' => $version->getNumber()]));
        $this->em->flush();
        $this->indexer->index($document);
        $this->auditLogger->info('Сохранена новая версия страницы', ['document' => $document->getId(), 'version' => $version->getNumber(), 'by' => $actor->getUsername()]);

        return $version;
    }

    /** Делает старую версию текущей, создавая её копию как новую версию (история остаётся линейной). */
    public function restoreVersion(Document $document, DocumentVersion $source, User $actor, ?string $ip = null): DocumentVersion
    {
        if ($source->getDocument()->getId() !== $document->getId()) {
            throw new \InvalidArgumentException('Версия принадлежит другому документу.');
        }
        if ($source->isCurrent()) {
            throw new \DomainException('Эта версия уже является текущей.');
        }
        $version = new DocumentVersion($document, $document->getNextVersionNumber());
        $version->setKind($source->getKind())->setCreatedBy($actor)->setChangeNote(\sprintf('Восстановлена версия %d', $source->getNumber()));
        if ($source->isFile()) {
            $this->storage->copyVersionFile($source, $version);
        } else {
            $version->setContent($source->getContent())->setSize($source->getSize())->setMimeType($source->getMimeType());
        }
        $this->em->persist($version);
        $this->em->flush();
        $document->setCurrentVersion($version)->setUpdatedAt(new \DateTimeImmutable());
        $this->em->persist(new DocumentEvent($document, DocumentEvent::RESTORE, $actor, $version, $ip, ['from' => $source->getNumber(), 'number' => $version->getNumber()]));
        $this->em->flush();
        $this->indexer->index($document);
        $this->auditLogger->info('Восстановлена версия документа', ['document' => $document->getId(), 'from' => $source->getNumber(), 'version' => $version->getNumber(), 'by' => $actor->getUsername()]);

        return $version;
    }

    public function publish(Document $document, User $actor, ?string $ip = null): void
    {
        if ($document->isPublished()) {
            return;
        }
        if (null === $document->getCurrentVersion()) {
            throw new \DomainException('У документа нет ни одной версии — публиковать нечего.');
        }
        $document->setStatus(Document::STATUS_PUBLISHED)->setPublishedAt(new \DateTimeImmutable())->setArchivedAt(null)->setUpdatedAt(new \DateTimeImmutable());
        $this->em->persist(new DocumentEvent($document, DocumentEvent::PUBLISH, $actor, $document->getCurrentVersion(), $ip));
        $this->em->flush();
        $this->auditLogger->info('Документ опубликован', ['document' => $document->getId(), 'by' => $actor->getUsername()]);
    }

    /** Снимает документ с публикации (возвращает в черновики). */
    public function unpublish(Document $document, User $actor, ?string $ip = null): void
    {
        if ($document->isDraft()) {
            return;
        }
        $document->setStatus(Document::STATUS_DRAFT)->setArchivedAt(null)->setUpdatedAt(new \DateTimeImmutable());
        $this->em->persist(new DocumentEvent($document, DocumentEvent::UNPUBLISH, $actor, null, $ip));
        $this->em->flush();
        $this->auditLogger->info('Документ снят с публикации', ['document' => $document->getId(), 'by' => $actor->getUsername()]);
    }

    public function archive(Document $document, User $actor, ?string $ip = null): void
    {
        if ($document->isArchived()) {
            return;
        }
        $document->setStatus(Document::STATUS_ARCHIVED)->setArchivedAt(new \DateTimeImmutable())->setUpdatedAt(new \DateTimeImmutable());
        $this->em->persist(new DocumentEvent($document, DocumentEvent::ARCHIVE, $actor, null, $ip));
        $this->em->flush();
        $this->auditLogger->info('Документ перенесён в архив', ['document' => $document->getId(), 'by' => $actor->getUsername()]);
    }

    /** Удаляет документ со всеми версиями, файлами и событиями; оставляет отметку об удалении для API. */
    public function delete(Document $document, User $actor): void
    {
        $id = (int) $document->getId();
        $title = $document->getTitle();
        $sectionPath = $document->getSection()->getFullName();
        $this->auditLogger->info('Удалён документ', ['document' => $id, 'title' => $title, 'section' => $sectionPath, 'versions' => $document->getVersionCount(), 'by' => $actor->getUsername()]);
        $document->setCurrentVersion(null);
        $this->em->flush();
        $this->em->remove($document);
        $existing = $this->em->find(DocumentDeletion::class, $id);
        if (null !== $existing) {
            $this->em->remove($existing);
            $this->em->flush();
        }
        $this->em->persist(new DocumentDeletion($id, $title, $sectionPath, $actor->getDisplayName()));
        $this->em->flush();
        $this->storage->deleteDocumentDir($id);
    }

    /** Фиксирует просмотр документа. */
    public function recordView(Document $document, ?User $user, ?string $ip = null): void
    {
        $document->incrementViewCount();
        $this->em->persist(new DocumentEvent($document, DocumentEvent::VIEW, $user, $document->getCurrentVersion(), $ip));
        $this->em->flush();
    }

    /** Фиксирует скачивание версии. */
    public function recordDownload(Document $document, DocumentVersion $version, ?User $user, ?string $ip = null): void
    {
        $document->incrementDownloadCount();
        $version->incrementDownloadCount();
        $this->em->persist(new DocumentEvent($document, DocumentEvent::DOWNLOAD, $user, $version, $ip, ['number' => $version->getNumber()]));
        $this->em->flush();
    }

    /** Очищает HTML страницы от опасной разметки. */
    public function sanitize(?string $html): string
    {
        $html = trim((string) $html);
        if ('' === $html) {
            return '';
        }

        return $this->documentPage->sanitize($html);
    }
}
