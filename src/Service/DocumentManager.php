<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Document;
use App\Entity\DocumentEvent;
use App\Entity\DocumentVersion;
use App\Entity\Section;
use App\Entity\User;
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
        } else {
            $pageContent = $this->sanitize($pageContent);
            if ('' === trim(strip_tags($pageContent))) {
                throw new \InvalidArgumentException('Текст страницы пустой.');
            }
        }

        $document->setOwner($actor)->setStatus(Document::STATUS_DRAFT);
        $this->em->persist($document);
        $this->em->flush(); // нужен id для каталога хранилища

        $version = new DocumentVersion($document, 1);
        $version->setKind($document->getType())->setCreatedBy($actor)->setChangeNote($changeNote ?? 'Первая версия');
        if ($document->isFile()) {
            $this->storage->store($file, $version);
        } else {
            $version->setContent($pageContent)->setSize(\strlen((string) $pageContent))->setMimeType('text/html');
        }
        $this->em->persist($version);
        $this->em->flush();

        $document->setCurrentVersion($version);
        $this->em->persist(new DocumentEvent($document, DocumentEvent::CREATE, $actor, $version, $ip));
        if ($publish) {
            $document->setStatus(Document::STATUS_PUBLISHED)->setPublishedAt(new \DateTimeImmutable());
            $this->em->persist(new DocumentEvent($document, DocumentEvent::PUBLISH, $actor, $version, $ip));
        }
        $this->em->flush();

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
        $version = new DocumentVersion($document, $document->getNextVersionNumber());
        $version->setKind(Document::TYPE_FILE)->setCreatedBy($actor)->setChangeNote($changeNote);
        $this->storage->store($file, $version);
        $this->em->persist($version);
        $this->em->flush();
        $document->setCurrentVersion($version)->setUpdatedAt(new \DateTimeImmutable());
        $this->em->persist(new DocumentEvent($document, DocumentEvent::NEW_VERSION, $actor, $version, $ip, ['number' => $version->getNumber(), 'file' => $version->getOriginalName()]));
        $this->em->flush();
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

    /** Удаляет документ со всеми версиями, файлами и событиями. */
    public function delete(Document $document, User $actor): void
    {
        $id = (int) $document->getId();
        $title = $document->getTitle();
        $this->auditLogger->info('Удалён документ', ['document' => $id, 'title' => $title, 'section' => $document->getSection()->getFullName(), 'versions' => $document->getVersionCount(), 'by' => $actor->getUsername()]);
        $document->setCurrentVersion(null);
        $this->em->flush();
        $this->em->remove($document);
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
