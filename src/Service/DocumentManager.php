<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Document;
use App\Entity\DocumentApproval;
use App\Entity\DocumentDeletion;
use App\Entity\DocumentEvent;
use App\Entity\DocumentVersion;
use App\Entity\Section;
use App\Entity\User;
use App\Repository\DocumentApprovalRepository;
use App\Security\Access;
use App\Service\Html\PageHtml;
use App\Service\Material\MaterialLinker;
use App\Service\Preview\DocumentPreviewer;
use App\Service\Text\TextIndexer;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
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
        #[Target]
        private readonly HtmlSanitizerInterface $documentPage,
        private readonly LoggerInterface $auditLogger,
        private readonly TextIndexer $indexer,
        private readonly DocumentPreviewer $previewer,
        private readonly ?DocumentKindService $kinds = null,
        private readonly ?PortalSettings $settings = null,
        private readonly ?DocumentApprovalRepository $approvals = null,
        private readonly ?Access $access = null,
        private readonly ?MaterialLinker $materialLinker = null,
    ) {
    }

    /** Публикует ли этот сотрудник только через модерацию (помощник маркетолога вне своих разделов). */
    public function needsModeration(?User $actor, Section $section): bool
    {
        return null !== $this->access && $this->access->needsModeration($actor, $section);
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
            if (self::isBlankPage($pageContent)) {
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

        return $this->createWith($document, fn (DocumentVersion $version) => $this->storage->storeFromPath($path, $originalName, $version, $moveSource), $changeNote, $actor, $publish, null, $details, $this->sourceKeeper($path, $moveSource));
    }

    /**
     * Действие при откате для файла, перенесённого из каталога импорта: вернуть его на место. Возвращает
     * true, если копию в хранилище можно удалять (см. FileStorage::returnToSource()).
     *
     * @return (callable(DocumentVersion): bool)|null
     */
    private function sourceKeeper(string $path, bool $moveSource): ?callable
    {
        return $moveSource ? fn (DocumentVersion $version): bool => $this->storage->returnToSource($version, $path) : null;
    }

    /**
     * Можно ли при откате удалить файл версии из хранилища. Если перенесённый файл не удалось вернуть на
     * место, он остаётся в хранилище (путь — в журнале): это единственная копия.
     *
     * @param (callable(DocumentVersion): bool)|null $undo
     */
    private function releaseStoredFile(DocumentVersion $version, ?callable $undo): bool
    {
        if (null === $undo || null === $version->getStoredPath() || $undo($version)) {
            return true;
        }
        $this->auditLogger->error('Откат: перенесённый файл не удалось вернуть в каталог импорта, он оставлен в хранилище', [
            'document' => $version->getDocument()->getId(),
            'stored' => $this->storage->absolutePath($version),
            'name' => $version->getOriginalName(),
        ]);

        return false;
    }

    /**
     * Общая часть создания документа: первая версия, событие создания и (при необходимости) публикация.
     *
     * @param callable(DocumentVersion): mixed        $fillVersion заполняет содержимое версии (файл или текст)
     * @param array<string, mixed>|null               $details
     * @param (callable(DocumentVersion): bool)|null $undo        откат сохранения файла (см. sourceKeeper())
     */
    private function createWith(Document $document, callable $fillVersion, ?string $changeNote, User $actor, bool $publish, ?string $ip, ?array $details, ?callable $undo = null): Document
    {
        // Помощник маркетолога сам не публикует: документ остаётся черновиком до решения модератора.
        if ($publish && $this->needsModeration($actor, $document->getSection())) {
            $publish = false;
        }
        $document->setOwner($actor)->setStatus(Document::STATUS_DRAFT);
        $this->em->persist($document);
        $this->em->flush(); // нужен id для каталога хранилища

        $version = new DocumentVersion($document, 1);
        $version->setKind($document->getType())->setCreatedBy($actor)->setChangeNote($changeNote ?? 'Первая версия');
        try {
            $fillVersion($version);
            $this->em->persist($version);
            $this->em->flush();
        } catch (\Throwable $e) {
            // Файл не сохранился — документ без версий не оставляем (иначе в разделе появился бы «пустой» черновик).
            $id = $document->getId();
            $release = $this->releaseStoredFile($version, $undo);
            $document->getVersions()->removeElement($version);
            if ($this->em->isOpen()) {
                if ($this->em->contains($version)) {
                    $this->em->detach($version);
                }
                $this->em->remove($document);
                $this->em->flush();
            } elseif (null !== $id) {
                // После ошибки базы менеджер сущностей закрыт — черновик без версий удаляется напрямую.
                try {
                    $this->em->getConnection()->delete('document', ['id' => $id]);
                } catch (\Throwable $deleteError) {
                    $this->auditLogger->error('Откат: не удалось удалить черновик без версий — удалите его вручную', ['document' => $id, 'error' => $deleteError->getMessage()]);
                }
            }
            if (null !== $id && $release) {
                $this->storage->deleteDocumentDir((int) $id);
            }
            throw $e;
        }

        // Время изменения — момент, когда документ действительно появился (после сохранения файла), а не
        // создания объекта: иначе он попал бы в ленту изменений API «задним числом» и мог бы потеряться.
        $document->setCurrentVersion($version)->touch();
        // Тип файла, если его не выбрали: по названию и расширению («Паспорт…» → «Паспорта и руководства»).
        $this->kinds?->assignIfMissing($document);
        $this->em->persist(new DocumentEvent($document, DocumentEvent::CREATE, $actor, $version, $ip, $details));
        if ($publish) {
            $document->setStatus(Document::STATUS_PUBLISHED)->setPublishedAt(new \DateTimeImmutable());
            $this->em->persist(new DocumentEvent($document, DocumentEvent::PUBLISH, $actor, $version, $ip));
        }
        $this->em->flush();

        $this->indexer->index($document);
        $this->auditLogger->info('Создан документ', ['document' => $document->getId(), 'title' => $document->getTitle(), 'section' => $document->getSection()->getFullName(), 'published' => $publish, 'by' => $actor->getUsername()]);
        // Документ раздела с таблицей материалов становится её строкой («Документы → строки»).
        $this->materialLinker?->documentSaved($document, $actor);

        return $document;
    }

    /**
     * Сохраняет изменения карточки (название, описание, срок, теги, раздел).
     * Если раздел изменился — фиксируется событие перемещения.
     */
    public function updateMetadata(Document $document, ?Section $previousSection, User $actor, ?string $ip = null): void
    {
        $document->setUpdatedAt(new \DateTimeImmutable());
        $this->kinds?->assignIfMissing($document);
        $this->em->persist(new DocumentEvent($document, DocumentEvent::UPDATE, $actor, null, $ip));
        if (null !== $previousSection && $previousSection->getId() !== $document->getSection()->getId()) {
            $this->em->persist(new DocumentEvent($document, DocumentEvent::MOVE, $actor, null, $ip, [
                'from' => $previousSection->getFullName(),
                'to' => $document->getSection()->getFullName(),
            ]));
        }
        $this->em->flush();
        if (null !== $previousSection && $previousSection->getId() !== $document->getSection()->getId()) {
            $this->materialLinker?->documentSaved($document, $actor);
        }
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

        return $this->addFileVersionWith($document, fn (DocumentVersion $version) => $this->storage->storeFromPath($path, $originalName, $version, $moveSource), $changeNote, $actor, null, $this->sourceKeeper($path, $moveSource));
    }

    /**
     * Новые редакции опубликованного документа при обязательном согласовании не становятся текущими
     * сразу: сотрудники видят прежнюю редакцию, пока новая не будет согласована (см. promoteVersion()).
     */
    public function holdsNewVersions(Document $document, ?User $actor = null): bool
    {
        // Новая редакция от помощника маркетолога тоже ждёт решения: сотрудники до модерации видят прежнюю.
        return $document->isPublished() && ($this->approvalRequired() || $this->needsModeration($actor, $document->getSection()));
    }

    /** Включено ли «Публиковать только после согласования». */
    public function approvalRequired(): bool
    {
        return true === ($this->settings?->approval()['required'] ?? false);
    }

    /**
     * Проверяет, что документ можно менять: на время согласования он «заморожен», иначе согласующий
     * одобрил бы одну редакцию, а опубликована оказалась бы другая.
     *
     * @throws \DomainException
     */
    public function assertVersionsEditable(Document $document): void
    {
        if ($document->isOnReview() || null !== $this->approvals?->findPending($document)) {
            throw new \DomainException('Документ на согласовании — отзовите запрос согласования, чтобы загрузить новую редакцию.');
        }
    }

    /**
     * Добавляет версию: номер выдаётся под блокировкой строки документа (две одновременные загрузки
     * не получат один номер), файл сохраняется до записи в базу, при ошибке ничего не остаётся.
     *
     * @param callable(DocumentVersion): mixed        $fillVersion заполняет содержимое версии
     * @param array<string, mixed>                   $details     сведения для события
     * @param (callable(DocumentVersion): bool)|null $undo        откат сохранения файла (см. sourceKeeper())
     */
    private function addVersionWith(Document $document, string $kind, callable $fillVersion, ?string $changeNote, User $actor, ?string $ip, string $eventType, array $details, string $logMessage, ?callable $undo = null): DocumentVersion
    {
        $this->assertVersionsEditable($document);
        $hold = $this->holdsNewVersions($document, $actor);
        $connection = $this->em->getConnection();
        $connection->beginTransaction();
        $version = null;
        $event = null;
        $previousCurrent = $document->getCurrentVersion();
        try {
            $this->em->lock($document, LockMode::PESSIMISTIC_WRITE);
            $max = (int) $this->em->createQuery('SELECT MAX(v.number) FROM App\Entity\DocumentVersion v WHERE v.document = :d')
                ->setParameter('d', $document)->getSingleScalarResult();
            $version = new DocumentVersion($document, max($max, $document->getNextVersionNumber() - 1) + 1);
            $version->setKind($kind)->setCreatedBy($actor)->setChangeNote($changeNote);
            $fillVersion($version);
            $this->em->persist($version);
            $this->em->flush();
            $details = ['number' => $version->getNumber()] + $details;
            if ($hold) {
                $event = new DocumentEvent($document, DocumentEvent::DRAFT_VERSION, $actor, $version, $ip, $details + ['pending' => true]);
            } else {
                $document->setCurrentVersion($version)->touch();
                $event = new DocumentEvent($document, $eventType, $actor, $version, $ip, $details);
            }
            $this->em->persist($event);
            $this->em->flush();
            $connection->commit();
        } catch (\Throwable $e) {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
            if (null !== $version) {
                // Запись версии откатилась — файл, уже сохранённый в хранилище, тоже не нужен, а сама версия
                // не должна попасть в базу при следующем flush() (если менеджер сущностей остался открыт).
                $document->getVersions()->removeElement($version);
                if ($document->getCurrentVersion() === $version) {
                    $document->setCurrentVersion($previousCurrent);
                }
                // Сбой фиксации транзакции оставляет менеджер сущностей открытым, а записанные в ней версию
                // (с текстом страницы) и событие — «сохранёнными»: следующий flush() наткнулся бы на них.
                if ($this->em->isOpen()) {
                    foreach ([$event, $version] as $entity) {
                        if (null !== $entity && $this->em->contains($entity)) {
                            $this->em->detach($entity);
                        }
                    }
                }
                if (null !== $version->getStoredPath() && $this->releaseStoredFile($version, $undo)) {
                    $this->storage->delete($version);
                }
            }
            throw $e;
        }
        if (!$hold) {
            $this->indexer->index($document);
        }
        $this->auditLogger->info($hold ? $logMessage.' (ждёт согласования)' : $logMessage, ['document' => $document->getId(), 'version' => $version->getNumber(), 'by' => $actor->getUsername()]);

        return $version;
    }

    /**
     * @param callable(DocumentVersion): mixed        $fillVersion
     * @param (callable(DocumentVersion): bool)|null $undo
     */
    private function addFileVersionWith(Document $document, callable $fillVersion, ?string $changeNote, User $actor, ?string $ip, ?callable $undo = null): DocumentVersion
    {
        return $this->addVersionWith($document, Document::TYPE_FILE, static function (DocumentVersion $version) use ($fillVersion): void {
            $fillVersion($version);
        }, $changeNote, $actor, $ip, DocumentEvent::NEW_VERSION, [], 'Загружена новая версия файла', $undo);
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
        if (self::isBlankPage($html)) {
            throw new \InvalidArgumentException('Текст страницы пустой.');
        }
        // Сравнение с последней редакцией (в том числе ждущей согласования): повторное сохранение того же текста — не версия.
        $latest = $document->getLatestVersion();
        if (null !== $latest && $latest->getContent() === $html) {
            return null;
        }

        return $this->addVersionWith($document, Document::TYPE_PAGE, static function (DocumentVersion $version) use ($html): void {
            $version->setContent($html)->setSize(\strlen($html))->setMimeType('text/html');
        }, $changeNote, $actor, $ip, DocumentEvent::NEW_VERSION, [], 'Сохранена новая версия страницы');
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

        return $this->addVersionWith($document, $source->getKind(), function (DocumentVersion $version) use ($source): void {
            $version->setChangeNote(\sprintf('Восстановлена версия %d', $source->getNumber()));
            if ($source->isFile()) {
                $this->storage->copyVersionFile($source, $version);
            } else {
                $version->setContent($source->getContent())->setSize($source->getSize())->setMimeType($source->getMimeType());
            }
        }, null, $actor, $ip, DocumentEvent::RESTORE, ['from' => $source->getNumber()], 'Восстановлена версия документа');
    }

    /**
     * Делает новую редакцию (ждавшую согласования) текущей: с этого момента её видят сотрудники,
     * подписчики получают уведомление, как о новой версии.
     *
     * @throws \DomainException если редакция не новее текущей
     */
    public function promoteVersion(Document $document, DocumentVersion $version, User $actor, ?string $ip = null): void
    {
        if ($version->getDocument()->getId() !== $document->getId()) {
            throw new \InvalidArgumentException('Версия принадлежит другому документу.');
        }
        if ($document->isVersionReleased($version)) {
            throw new \DomainException(\sprintf('Редакция %d уже действует или устарела.', $version->getNumber()));
        }
        $document->setCurrentVersion($version)->touch();
        $this->em->persist(new DocumentEvent($document, DocumentEvent::NEW_VERSION, $actor, $version, $ip, ['number' => $version->getNumber(), 'approved' => true]));
        $this->em->flush();
        $this->indexer->index($document);
        $this->auditLogger->info('Новая редакция стала текущей', ['document' => $document->getId(), 'version' => $version->getNumber(), 'by' => $actor->getUsername()]);
    }

    public function publish(Document $document, User $actor, ?string $ip = null): void
    {
        if ($document->isPublished()) {
            return;
        }
        if (null === $document->getCurrentVersion()) {
            throw new \DomainException('У документа нет ни одной версии — публиковать нечего.');
        }
        $document->setStatus(Document::STATUS_PUBLISHED)->setPublishedAt(new \DateTimeImmutable())->setArchivedAt(null)->touch();
        $this->em->persist(new DocumentEvent($document, DocumentEvent::PUBLISH, $actor, $document->getCurrentVersion(), $ip));
        $this->em->flush();
        // Документ, возвращённый из архива, снова считается материалом своей таблицы.
        $this->materialLinker?->documentSaved($document, $actor);
        $this->auditLogger->info('Документ опубликован', ['document' => $document->getId(), 'by' => $actor->getUsername()]);
    }

    /** Снимает документ с публикации (возвращает в черновики); незакрытое согласование отзывается. */
    public function unpublish(Document $document, User $actor, ?string $ip = null): void
    {
        if ($document->isDraft()) {
            return;
        }
        $this->cancelPendingApproval($document, 'документ снят с публикации');
        $document->setStatus(Document::STATUS_DRAFT)->setArchivedAt(null)->touch();
        $this->em->persist(new DocumentEvent($document, DocumentEvent::UNPUBLISH, $actor, null, $ip));
        $this->em->flush();
        $this->auditLogger->info('Документ снят с публикации', ['document' => $document->getId(), 'by' => $actor->getUsername()]);
    }

    /** Переносит документ в архив; незакрытое согласование отзывается (согласовывать архивный документ нечего). */
    public function archive(Document $document, User $actor, ?string $ip = null): void
    {
        if ($document->isArchived()) {
            return;
        }
        $this->cancelPendingApproval($document, 'документ перенесён в архив');
        $document->setStatus(Document::STATUS_ARCHIVED)->setArchivedAt(new \DateTimeImmutable())->touch();
        $this->em->persist(new DocumentEvent($document, DocumentEvent::ARCHIVE, $actor, null, $ip));
        $this->em->flush();
        $this->auditLogger->info('Документ перенесён в архив', ['document' => $document->getId(), 'by' => $actor->getUsername()]);
    }

    private function cancelPendingApproval(Document $document, string $reason): void
    {
        $pending = $this->approvals?->findPending($document);
        if (null !== $pending) {
            $pending->decide(DocumentApproval::CANCELLED, 'Запрос закрыт автоматически: '.$reason.'.');
            $this->auditLogger->info('Запрос согласования закрыт автоматически', ['document' => $document->getId(), 'reason' => $reason]);
        }
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
        $this->previewer->forget($id);
    }

    /**
     * Фиксирует просмотр документа. Счётчики увеличиваются запросом к базе: одновременные просмотры
     * не теряются, а сам документ не считается изменённым (дата изменения, лента изменений API не трогаются).
     */
    public function recordView(Document $document, ?User $user, ?string $ip = null): void
    {
        // Счётчик и время последнего просмотра — отдельным запросом, без изменения updatedAt.
        $this->em->createQuery('UPDATE App\Entity\Document d SET d.viewCount = d.viewCount + 1, d.lastViewedAt = :now WHERE d.id = :id')
            ->setParameter('now', new \DateTimeImmutable())->setParameter('id', $document->getId())->execute();
        $this->em->persist(new DocumentEvent($document, DocumentEvent::VIEW, $user, $document->getCurrentVersion(), $ip));
        $this->em->flush();
    }

    /** Фиксирует скачивание версии (счётчики — так же, как у просмотров). */
    public function recordDownload(Document $document, DocumentVersion $version, ?User $user, ?string $ip = null): void
    {
        $this->em->createQuery('UPDATE App\Entity\Document d SET d.downloadCount = d.downloadCount + 1 WHERE d.id = :id')
            ->setParameter('id', $document->getId())->execute();
        $this->em->createQuery('UPDATE App\Entity\DocumentVersion v SET v.downloadCount = v.downloadCount + 1 WHERE v.id = :id')
            ->setParameter('id', $version->getId())->execute();
        $this->em->persist(new DocumentEvent($document, DocumentEvent::DOWNLOAD, $user, $version, $ip, ['number' => $version->getNumber()]));
        $this->em->flush();
    }

    /**
     * Наибольший размер текста страницы (вместе со вставленными картинками). Такой же предел задан
     * очистителю HTML (framework.html_sanitizer.max_input_length): более длинный текст он молча обрезал бы.
     */
    public const MAX_PAGE_BYTES = 12 * 1024 * 1024;

    /**
     * Очищает HTML страницы от опасной разметки.
     *
     * @throws \InvalidArgumentException если страница больше MAX_PAGE_BYTES
     */
    public function sanitize(?string $html): string
    {
        $html = trim((string) $html);
        if ('' === $html) {
            return '';
        }
        if (\strlen($html) > self::MAX_PAGE_BYTES) {
            throw new \InvalidArgumentException(\sprintf('Страница слишком большая: %s (не более %s). Уменьшите вставленные изображения или загрузите их отдельными документами.', FileStorage::humanSize(\strlen($html)), FileStorage::humanSize(self::MAX_PAGE_BYTES)));
        }

        // Текст из редактора, где все пробелы неразрывные, иначе не переносился бы по строкам.
        return PageHtml::breakableSpaces($this->documentPage->sanitize($html));
    }

    /** Пустая ли страница: нет ни текста, ни изображений. */
    public static function isBlankPage(string $html): bool
    {
        $text = html_entity_decode(strip_tags($html, '<img>'), \ENT_QUOTES | \ENT_HTML5, 'UTF-8');

        return '' === (string) preg_replace('/[\s\x{00A0}\x{200B}]+/u', '', $text);
    }
}
