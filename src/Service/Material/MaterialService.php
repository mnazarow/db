<?php

declare(strict_types=1);

namespace App\Service\Material;

use App\Entity\Document;
use App\Entity\DocumentKind;
use App\Entity\DocumentVersion;
use App\Entity\MaterialParameter;
use App\Entity\MaterialRow;
use App\Entity\MaterialRowChange;
use App\Entity\Section;
use App\Entity\User;
use App\Repository\MaterialParameterRepository;
use App\Repository\MaterialRowRepository;
use App\Security\Access;
use App\Service\ApprovalService;
use App\Service\DocumentKindService;
use App\Service\DocumentManager;
use App\Service\PortalUrl;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Таблицы материалов разделов верхнего уровня — как листы «Продуктовые материалы» в Excel:
 * строки-материалы с типом, состоянием и параметрами, столбцы-параметры, загрузка файла из строки.
 */
final class MaterialService implements ResetInterface
{
    /** @var array<int, list<MaterialParameter>> кэш столбцов по разделам на время запроса */
    private array $parameterCache = [];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MaterialParameterRepository $parameters,
        private readonly MaterialRowRepository $rows,
        private readonly DocumentKindService $kinds,
        private readonly DocumentManager $documents,
        private readonly ApprovalService $approvals,
        private readonly UrlGeneratorInterface $urls,
        private readonly PortalUrl $portalUrl,
        private readonly Access $access,
        private readonly LoggerInterface $auditLogger,
        private readonly int $defaultValidityMonths,
        private readonly ?MaterialHistory $history = null,
        private readonly ?MaterialLinker $linker = null,
    ) {
    }

    // ---- Таблица раздела ------------------------------------------------------------------------------

    /** Таблица материалов бывает только у разделов верхнего уровня. */
    public function supports(Section $section): bool
    {
        return $section->isTopLevel();
    }

    /**
     * Включает таблицу материалов: создаёт столбцы по умолчанию, если их ещё нет.
     *
     * @throws \DomainException для подраздела
     */
    public function enable(Section $section, ?User $by = null): void
    {
        if (!$this->supports($section)) {
            throw new \DomainException('Таблица материалов ведётся только для разделов верхнего уровня.');
        }
        $section->setMaterialsEnabled(true);
        $this->ensureDefaults($section);
        $this->em->flush();
        // Документы, уже лежащие в разделе и подразделах, сразу становятся строками таблицы.
        $this->linker?->syncSection($section, $by);
        $this->auditLogger->info('Включена таблица материалов', ['section' => $section->getFullName(), 'user' => $by?->getUsername()]);
    }

    /**
     * Удаляет таблицу раздела целиком — строки и столбцы — и выключает её. Документы, загруженные
     * из строк, остаются в разделе. Возвращает число удалённых строк.
     */
    public function purge(Section $section, ?User $by = null): int
    {
        $rows = (int) $this->em->createQuery('DELETE FROM App\Entity\MaterialRow r WHERE r.section = :section')
            ->setParameter('section', $section)->execute();
        $this->em->createQuery('DELETE FROM App\Entity\MaterialParameter p WHERE p.section = :section')
            ->setParameter('section', $section)->execute();
        $section->setMaterialsEnabled(false);
        $this->em->flush();
        $this->forgetParameters($section);
        $this->auditLogger->warning('Удалена таблица материалов', ['section' => $section->getFullName(), 'rows' => $rows, 'user' => $by?->getUsername()]);

        return $rows;
    }

    /** Скрывает таблицу; строки и столбцы сохраняются и вернутся при повторном включении. */
    public function disable(Section $section, ?User $by = null): void
    {
        $section->setMaterialsEnabled(false);
        $this->em->flush();
        $this->auditLogger->info('Отключена таблица материалов', ['section' => $section->getFullName(), 'user' => $by?->getUsername()]);
    }

    /** @return list<MaterialParameter> */
    public function ensureDefaults(Section $section): array
    {
        $existing = $this->parameters($section);
        if ([] !== $existing) {
            return $existing;
        }
        foreach (MaterialParameter::DEFAULTS as $position => [$name, $kind, $options, $role, $width]) {
            $this->em->persist(new MaterialParameter($section, $name, $kind, $options ?? [], $role, $position + 1, $width));
        }
        $this->em->flush();
        unset($this->parameterCache[(int) $section->getId()]);

        return $this->parameters($section);
    }

    /** @return list<MaterialParameter> */
    public function parameters(Section $section): array
    {
        $id = (int) $section->getId();
        // Столбцы, отсоединённые EntityManager::clear(), перечитываются: иначе их правка не сохранится.
        if (isset($this->parameterCache[$id][0]) && !$this->em->contains($this->parameterCache[$id][0])) {
            unset($this->parameterCache[$id]);
        }

        return $this->parameterCache[$id] ??= $this->parameters->findForSection($section);
    }

    /** Сброс кэша столбцов между запросами (kernel.reset). */
    public function reset(): void
    {
        $this->parameterCache = [];
    }

    public function forgetParameters(?Section $section = null): void
    {
        if (null === $section) {
            $this->parameterCache = [];
        } else {
            unset($this->parameterCache[(int) $section->getId()]);
        }
    }

    public function roleParameter(Section $section, string $role): ?MaterialParameter
    {
        foreach ($this->parameters($section) as $parameter) {
            if ($parameter->getRole() === $role) {
                return $parameter;
            }
        }

        return null;
    }

    public function findParameter(Section $section, string $key): ?MaterialParameter
    {
        foreach ($this->parameters($section) as $parameter) {
            if ($parameter->key() === $key) {
                return $parameter;
            }
        }

        return null;
    }

    /** @return list<MaterialRow> */
    public function rows(Section $section): array
    {
        return $this->rows->findForSection($section);
    }

    /**
     * Можно ли через строку таблицы менять связанный с ней документ (название, тип, новые версии файла).
     * Документ должен оставаться в разделе таблицы или его подразделе, а у сотрудника должно быть право
     * править документ: иначе документ, который перенесли в чужой раздел, правился бы в обход прав.
     * $by = null — системные операции (импорт из консоли).
     */
    /**
     * Можно ли загрузить в строку новую версию её документа: модератор раздела — всегда, помощник
     * маркетолога — тоже (новая редакция уйдёт на модерацию).
     */
    public function canUploadTo(MaterialRow $row, ?User $by): bool
    {
        $document = $row->getDocument();
        if (null === $document || !$document->getSection()->isSameOrDescendantOf($row->getSection())) {
            return false;
        }

        return null === $by || $this->access->canContributeToDocument($by, $document);
    }

    public function canManageDocument(MaterialRow $row, ?User $by): bool
    {
        $document = $row->getDocument();
        if (null === $document) {
            return false;
        }
        if (!$document->getSection()->isSameOrDescendantOf($row->getSection())) {
            return false;
        }

        return null === $by || $this->access->canEditDocument($by, $document);
    }

    /**
     * Состояние строки по «Требованиям PM» и «Дизайн-требованиям» — как в Excel, с учётом документов
     * раздела: без файла материал «Отсутствует» (см. MaterialState::effective()).
     */
    public function state(MaterialRow $row): string
    {
        $section = $row->getSection();

        return MaterialState::effective(
            $row->getValue($this->roleParameter($section, MaterialParameter::ROLE_PM)),
            $row->getValue($this->roleParameter($section, MaterialParameter::ROLE_DESIGN)),
            $row->hasFile(),
        );
    }

    /**
     * Связывает документы раздела и подразделов с таблицей (кнопка «Связать документы раздела»).
     *
     * @return array{linked: int, created: int, unlinked: int}
     */
    public function linkDocuments(Section $section, ?User $by = null): array
    {
        return $this->linker?->syncSection($section, $by) ?? ['linked' => 0, 'created' => 0, 'unlinked' => 0];
    }

    // ---- Строки ---------------------------------------------------------------------------------------

    /** Добавляет строку в конец таблицы (или сразу после указанной). */
    public function addRow(Section $section, ?User $by = null, string $title = '', ?MaterialRow $after = null): MaterialRow
    {
        $position = $this->rows->nextPosition($section);
        if (null !== $after && $after->getSection()->getId() === $section->getId()) {
            $position = $after->getPosition() + 1;
            $this->em->createQuery('UPDATE App\Entity\MaterialRow r SET r.position = r.position + 1 WHERE r.section = :section AND r.position >= :position')
                ->setParameter('section', $section)->setParameter('position', $position)->execute();
        }
        $row = (new MaterialRow($section, $title, $position))->touch($by);
        $this->em->persist($row);
        $this->history?->record($row, MaterialRowChange::CREATE, $by, null, null, null, '' !== $row->getTitle() ? $row->getTitle() : null);
        $this->em->flush();
        $this->auditLogger->info('Добавлена строка таблицы материалов', ['section' => $section->getFullName(), 'row' => $row->getId(), 'user' => $by?->getUsername()]);

        return $row;
    }

    /**
     * Меняет значение ячейки: «title» — материал, «kind» — тип, «p<id>» — параметр.
     *
     * @throws \DomainException если значение не подходит столбцу
     */
    public function updateCell(MaterialRow $row, string $field, ?string $value, ?User $by = null): void
    {
        $section = $row->getSection();
        $value = (string) $value;
        if ('title' === $field) {
            $old = $row->getTitle();
            $row->setTitle($value);
            $new = $row->getTitle();
            $label = 'Материал';
            if (null !== $row->getDocument() && '' !== $new && $row->getDocument()->getTitle() !== $new && $this->canManageDocument($row, $by)) {
                // Название материала и название документа — одно и то же: правим оба.
                $row->getDocument()->setTitle($new);
                if (null !== $by) {
                    $this->documents->updateMetadata($row->getDocument(), null, $by);
                }
            }
        } elseif ('kind' === $field) {
            $old = $row->getKind()?->getName() ?? '';
            $kind = '' === trim($value) ? null : ($this->kinds->find((int) $value) ?? $this->kinds->match($value));
            if ('' !== trim($value) && null === $kind) {
                throw new \DomainException('Такого типа нет в справочнике.');
            }
            $row->setKind($kind);
            $new = $kind?->getName() ?? '';
            $label = 'Тип';
            if (null !== $kind && null !== $row->getDocument() && $row->getDocument()->getKind()?->getId() !== $kind->getId() && $this->canManageDocument($row, $by)) {
                $row->getDocument()->setKind($kind);
                if (null !== $by) {
                    $this->documents->updateMetadata($row->getDocument(), null, $by);
                }
            }
        } else {
            $parameter = $this->findParameter($section, $field) ?? throw new \DomainException('Столбец не найден — обновите страницу.');
            $old = $row->getValue($parameter);
            $new = $parameter->normalize($value);
            $row->setValue($parameter, $new);
            $label = $parameter->getName();
        }
        if ($old === $new) {
            return;
        }
        $row->touch($by);
        // В журнал — значения в том виде, как их видно в таблице (даты — ДД.ММ.ГГГГ).
        $this->history?->record($row, MaterialRowChange::UPDATE, $by, $field, $label,
            isset($parameter) ? $parameter->display($old) : $old, isset($parameter) ? $parameter->display($new) : $new);
        $this->em->flush();
        $this->auditLogger->info('Изменена таблица материалов', [
            'section' => $section->getFullName(),
            'row' => $row->getId(),
            'material' => $row->getTitle(),
            'field' => $label,
            'from' => $old,
            'to' => $new,
            'user' => $by?->getUsername(),
        ]);
    }

    public function deleteRow(MaterialRow $row, ?User $by = null): void
    {
        $this->auditLogger->info('Удалена строка таблицы материалов', [
            'section' => $row->getSection()->getFullName(),
            'material' => $row->getTitle(),
            'document' => $row->getDocument()?->getId(),
            'user' => $by?->getUsername(),
        ]);
        $this->history?->record($row, MaterialRowChange::DELETE, $by, null, null, $row->getDocument()?->getTitle(), null);
        $this->history?->forgetRow($row);
        if (null !== $row->getDocument()) {
            // Строку удалили — документ раздела не должен снова появиться в таблице сам.
            $this->linker?->exclude($row->getDocument());
        }
        $this->em->remove($row);
        $this->em->flush();
    }

    /** Сдвигает строку вверх (-1) или вниз (+1). */
    public function moveRow(MaterialRow $row, int $direction): void
    {
        $this->reorder($this->rows($row->getSection()), $row, $direction);
    }

    // ---- Столбцы --------------------------------------------------------------------------------------

    /**
     * @param list<string>|string $options варианты для списка (по одному в строке)
     *
     * @throws \DomainException
     */
    public function addParameter(Section $section, string $name, string $kind, array|string $options = [], ?User $by = null): MaterialParameter
    {
        $name = trim($name);
        if ('' === $name) {
            throw new \DomainException('Укажите название столбца.');
        }
        foreach ($this->parameters($section) as $existing) {
            if (mb_strtolower($existing->getName()) === mb_strtolower($name)) {
                throw new \DomainException(\sprintf('Столбец «%s» уже есть.', $existing->getName()));
            }
        }
        $parameter = new MaterialParameter($section, $name, $kind, [], null, $this->parameters->nextPosition($section));
        $parameter->setOptions($options);
        if ($parameter->isList() && [] === $parameter->getOptions()) {
            throw new \DomainException('Для столбца-списка перечислите значения, по одному в строке.');
        }
        $this->em->persist($parameter);
        $this->em->flush();
        $this->forgetParameters($section);
        $this->auditLogger->info('Добавлен столбец таблицы материалов', ['section' => $section->getFullName(), 'column' => $parameter->getName(), 'kind' => $parameter->getKindLabel(), 'user' => $by?->getUsername()]);

        return $parameter;
    }

    /**
     * @param list<string>|string|null $options null — не менять
     *
     * @throws \DomainException
     */
    public function updateParameter(MaterialParameter $parameter, string $name, array|string|null $options = null, ?int $width = null, ?User $by = null): void
    {
        $name = trim($name);
        if ('' === $name) {
            throw new \DomainException('Укажите название столбца.');
        }
        foreach ($this->parameters($parameter->getSection()) as $existing) {
            if ($existing->getId() !== $parameter->getId() && mb_strtolower($existing->getName()) === mb_strtolower($name)) {
                throw new \DomainException(\sprintf('Столбец «%s» уже есть.', $existing->getName()));
            }
        }
        $old = $parameter->getName();
        $parameter->setName($name);
        if (null !== $options && $parameter->isList() && !$parameter->hasLockedOptions()) {
            $parameter->setOptions($options);
            if ([] === $parameter->getOptions()) {
                throw new \DomainException('Список значений не может быть пустым.');
            }
        }
        if (null !== $width) {
            $parameter->setWidth($width);
        }
        $this->em->flush();
        $this->forgetParameters($parameter->getSection());
        $this->auditLogger->info('Изменён столбец таблицы материалов', ['section' => $parameter->getSection()->getFullName(), 'from' => $old, 'to' => $parameter->getName(), 'user' => $by?->getUsername()]);
    }

    /** Удаляет столбец вместе со значениями во всех строках раздела. */
    public function deleteParameter(MaterialParameter $parameter, ?User $by = null): void
    {
        $section = $parameter->getSection();
        $key = $parameter->key();
        foreach ($this->rows->findBy(['section' => $section]) as $row) {
            $row->removeValue($key);
        }
        $this->auditLogger->info('Удалён столбец таблицы материалов', ['section' => $section->getFullName(), 'column' => $parameter->getName(), 'user' => $by?->getUsername()]);
        $this->em->remove($parameter);
        $this->em->flush();
        $this->forgetParameters($section);
    }

    public function moveParameter(MaterialParameter $parameter, int $direction): void
    {
        $this->reorder($this->parameters($parameter->getSection()), $parameter, $direction);
        $this->forgetParameters($parameter->getSection());
    }

    // ---- Загрузка файла из строки ---------------------------------------------------------------------

    /**
     * Загружает файл строки: первый раз создаёт документ в разделе (название и тип — из строки),
     * дальше добавляет новые версии. Дата обновления и ссылка в строке заполняются сами. Возвращает
     * загруженную версию: при обязательном согласовании она может ещё не быть текущей (см. uploadMessage()).
     *
     * @throws \InvalidArgumentException|\DomainException при ошибке файла
     */
    public function upload(MaterialRow $row, UploadedFile $file, User $by, ?string $ip = null): DocumentVersion
    {
        $section = $row->getSection();
        $versionLabel = $row->getValue($this->roleParameter($section, MaterialParameter::ROLE_VERSION));
        $note = 'Загружено из таблицы материалов'.('' !== $versionLabel ? ', версия '.$versionLabel : '');
        $document = $row->getDocument();
        if (null !== $document && !$this->canUploadTo($row, $by)) {
            throw new \DomainException('Документ этой строки перенесён в другой раздел или недоступен вам для правки — новую версию из таблицы загрузить нельзя. Отвяжите файл от строки, чтобы загрузить новый документ.');
        }

        if (null !== $document && $document->isFile()) {
            $version = $this->documents->addFileVersion($document, $file, $note, $by, $ip);
            if (null !== $row->getKind() && $document->getKind()?->getId() !== $row->getKind()->getId()) {
                $document->setKind($row->getKind());
            }
        } else {
            $title = '' !== $row->getTitle() ? $row->getTitle() : self::titleFromFileName($file->getClientOriginalName());
            $document = (new Document($section))
                ->setTitle($title)
                ->setType(Document::TYPE_FILE)
                ->setKind($row->getKind());
            if ($this->defaultValidityMonths > 0) {
                $document->setValidUntil(new \DateTimeImmutable('today +'.$this->defaultValidityMonths.' months'));
            }
            // Документ из строки связывается со своей строкой ниже, а не автоматически с другой.
            $create = fn () => $this->documents->create($document, $file, null, $note, $by, !$this->approvals->isRequired(), $ip);
            null !== $this->linker ? $this->linker->withoutAutoLink($create) : $create();
            $version = $document->getCurrentVersion() ?? throw new \LogicException('У нового документа нет версии.');
            $row->setDocument($document);
            if ('' === $row->getTitle()) {
                $row->setTitle($document->getTitle());
            }
        }
        if (null === $row->getKind()) {
            $row->setKind($document->getKind());
        }
        $this->linker?->allow($document);
        $pm = $this->roleParameter($section, MaterialParameter::ROLE_PM);
        if (null !== $pm && MaterialState::CHECK_ABSENT === $row->getValue($pm) && \in_array(MaterialState::CHECK_NONE, $pm->getOptions(), true)) {
            // Файл загружен — материал больше не «Отсутствует», он ждёт проверки.
            $this->history?->record($row, MaterialRowChange::UPDATE, $by, $pm->key(), $pm->getName().' (файл загружен)', MaterialState::CHECK_ABSENT, MaterialState::CHECK_NONE);
            $row->setValue($pm, MaterialState::CHECK_NONE);
        }

        $updated = $this->roleParameter($section, MaterialParameter::ROLE_UPDATED);
        if (null !== $updated) {
            $row->setValue($updated, (new \DateTimeImmutable('today'))->format('Y-m-d'));
        }
        $link = $this->roleParameter($section, MaterialParameter::ROLE_LINK);
        if (null !== $link && '' === $row->getValue($link)) {
            // Адрес — из настроек портала (APP_DEFAULT_URI), а не из заголовка Host запроса.
            $row->setValue($link, $this->portalUrl->absolute('app_document_show', ['id' => $document->getId()]));
        }
        $row->touch($by);
        $moderation = $this->access->needsModeration($by, $document->getSection());
        $this->history?->record($row, MaterialRowChange::UPLOAD, $by, 'file', 'Файл', null, \sprintf('«%s», версия %d%s',
            $file->getClientOriginalName(), $version->getNumber(), $moderation ? ' (на модерации)' : ($document->isVersionReleased($version) ? '' : ' (ждёт согласования)')));
        $this->em->flush();
        // Файл помощника маркетолога сразу уходит на модерацию: публикует маркетолог.
        if ($moderation && null === $this->approvals->pendingFor($document)) {
            try {
                $this->approvals->submitForModeration($document, 'Загружено из таблицы материалов «'.$section->getName().'»: '.$row->getTitle(), $by);
            } catch (\DomainException $e) {
                $this->auditLogger->warning('Файл из таблицы материалов не отправлен на модерацию', ['document' => $document->getId(), 'error' => $e->getMessage()]);
            }
        }
        $this->auditLogger->info('Загружен файл из таблицы материалов', [
            'section' => $section->getFullName(),
            'material' => $row->getTitle(),
            'document' => $document->getId(),
            'version' => $version->getNumber(),
            'awaits_approval' => !$document->isVersionReleased($version),
            'user' => $by->getUsername(),
        ]);

        return $version;
    }

    /** Сообщение о загрузке для таблицы: номер загруженной версии и что с ней дальше. */
    public function uploadMessage(DocumentVersion $version): string
    {
        $document = $version->getDocument();
        $pending = $this->approvals->pendingFor($document);
        $hint = match (true) {
            null !== $pending && $pending->isModeration() => $document->isPublished()
                ? ' — отправлен на модерацию: до одобрения маркетологом сотрудники видят прежнюю редакцию'
                : ' — отправлен на модерацию: сотрудники увидят его после одобрения маркетологом',
            !$document->isVersionReleased($version) => ' — ждёт согласования: пока его нет, сотрудники видят прежнюю редакцию',
            $document->isPublished() => '',
            $document->isArchived() => ' — документ в архиве: верните его из архива, чтобы сотрудники увидели файл',
            $this->approvals->isRequired() => ' — черновик: отправьте документ на согласование',
            default => ' — черновик: опубликуйте после проверки',
        };

        return \sprintf('Файл загружен: «%s», версия %d%s.', $document->getTitle(), $version->getNumber(), $hint);
    }

    /** Отвязывает документ от строки (сам документ остаётся в разделе). */
    public function detach(MaterialRow $row, ?User $by = null): void
    {
        if (null === $row->getDocument()) {
            return;
        }
        $this->auditLogger->info('Файл отвязан от строки таблицы материалов', ['material' => $row->getTitle(), 'document' => $row->getDocument()->getId(), 'user' => $by?->getUsername()]);
        $this->history?->record($row, MaterialRowChange::DETACH, $by, 'file', 'Файл', $row->getDocument()->getTitle(), null);
        // Отвязанный вручную документ сам к таблице больше не привязывается.
        $this->linker?->exclude($row->getDocument());
        $row->setDocument(null)->touch($by);
        $this->em->flush();
    }

    /** После правки карточки документа переносит название и тип в строки, из которых он загружен. */
    public function syncFromDocument(Document $document, ?User $by = null): void
    {
        $changed = false;
        foreach ($this->rows->findByDocument($document) as $row) {
            if ($row->getTitle() !== $document->getTitle()) {
                $old = $row->getTitle();
                $row->setTitle($document->getTitle());
                $this->history?->record($row, MaterialRowChange::UPDATE, $by, 'title', 'Материал (из карточки документа)', $old, $row->getTitle());
                $changed = true;
            }
            if (null !== $document->getKind() && $row->getKind()?->getId() !== $document->getKind()->getId()) {
                $old = $row->getKind()?->getName() ?? '';
                $row->setKind($document->getKind());
                $this->history?->record($row, MaterialRowChange::UPDATE, $by, 'kind', 'Тип (из карточки документа)', $old, $document->getKind()->getName());
                $changed = true;
            }
        }
        if ($changed) {
            $this->em->flush();
        }
    }

    /** @return list<MaterialRow> */
    public function rowsForDocument(Document $document): array
    {
        return $this->rows->findByDocument($document);
    }

    // ---- Представление для страницы и JSON ------------------------------------------------------------

    /**
     * Строка для страницы и ответов JSON. $viewer — кто смотрит: реквизиты документа, который ему
     * не виден (перенесён в чужой раздел, ограничен доступ), не показываются.
     *
     * @return array<string, mixed>
     */
    public function serializeRow(MaterialRow $row, ?User $viewer = null): array
    {
        $section = $row->getSection();
        $state = $this->state($row);
        $values = [];
        foreach ($this->parameters($section) as $parameter) {
            $value = $row->getValue($parameter);
            $values[$parameter->key()] = ['value' => $value, 'display' => $parameter->display($value)];
        }

        return [
            'id' => $row->getId(),
            'title' => $row->getTitle(),
            'kind' => null !== $row->getKind() ? ['id' => $row->getKind()->getId(), 'name' => $row->getKind()->getName()] : null,
            'state' => $state,
            'stateClass' => MaterialState::css($state),
            'values' => $values,
            'document' => $this->serializeDocument($row, $viewer),
            'updatedAt' => $row->getUpdatedAt()->format('d.m.Y H:i'),
            'updatedBy' => $row->getUpdatedByName(),
        ];
    }

    /** @return array<string, mixed>|null */
    private function serializeDocument(MaterialRow $row, ?User $viewer): ?array
    {
        $document = $row->getDocument();
        if (null === $document) {
            return null;
        }
        if (null !== $viewer && !$this->access->canViewDocument($viewer, $document)) {
            return ['id' => null, 'hidden' => true, 'manageable' => false, 'title' => 'Документ недоступен', 'status' => null, 'url' => null, 'download' => null, 'version' => null, 'fileName' => null, 'extension' => null];
        }
        $version = $document->getCurrentVersion();

        return [
            'id' => $document->getId(),
            'hidden' => false,
            'manageable' => $this->canUploadTo($row, $viewer),
            'title' => $document->getTitle(),
            'status' => $document->getStatus(),
            'url' => $this->urls->generate('app_document_show', ['id' => $document->getId()]),
            'download' => null !== $version && $version->isFile() ? $this->urls->generate('app_document_download', ['id' => $document->getId()]) : null,
            'version' => $version?->getNumber(),
            'fileName' => $version?->getOriginalName(),
            'extension' => $version?->getExtension(),
        ];
    }

    /** @return array<string, mixed> */
    public function serializeParameter(MaterialParameter $parameter): array
    {
        return [
            'key' => $parameter->key(),
            'id' => $parameter->getId(),
            'name' => $parameter->getName(),
            'kind' => $parameter->getKind(),
            'kindLabel' => $parameter->getKindLabel(),
            'options' => $parameter->getOptions(),
            'role' => $parameter->getRole(),
            'roleHint' => $parameter->getRoleHint(),
            'locked' => $parameter->hasLockedOptions(),
            'width' => $parameter->getWidth(),
        ];
    }

    /** Название документа из имени файла: «Паспорт_МРБ-25.pdf» → «Паспорт МРБ-25». */
    public static function titleFromFileName(string $fileName): string
    {
        $base = pathinfo($fileName, \PATHINFO_FILENAME);
        $base = trim(preg_replace('/[_\s]+/u', ' ', $base) ?? '');

        return '' !== $base ? mb_substr($base, 0, 255) : 'Материал';
    }

    /**
     * @template T of MaterialRow|MaterialParameter
     *
     * @param list<T> $items
     * @param T       $item
     */
    private function reorder(array $items, MaterialRow|MaterialParameter $item, int $direction): void
    {
        $index = array_search($item, $items, true);
        if (false === $index) {
            return;
        }
        $target = $index + ($direction < 0 ? -1 : 1);
        if ($target < 0 || $target >= \count($items)) {
            return;
        }
        [$items[$index], $items[$target]] = [$items[$target], $items[$index]];
        foreach ($items as $position => $current) {
            $current->setPosition($position + 1);
        }
        $this->em->flush();
    }
}
