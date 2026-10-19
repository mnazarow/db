<?php

declare(strict_types=1);

namespace App\Service\Material;

use App\Entity\Document;
use App\Entity\MaterialParameter;
use App\Entity\MaterialRow;
use App\Entity\MaterialRowChange;
use App\Entity\Section;
use App\Entity\User;
use App\Repository\MaterialParameterRepository;
use App\Repository\MaterialRowRepository;
use App\Service\PortalSettings;
use App\Service\PortalUrl;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * «Документы → строки»: документ, загруженный в раздел с таблицей материалов или в любой его подраздел,
 * сам попадает в таблицу — привязывается к свободной строке с тем же названием (или к единственной
 * свободной строке того же типа), а если такой нет, становится новой строкой. Так «С файлами»,
 * «Отсутствует» и состояние строк считаются по документам, которые действительно есть в разделе.
 *
 * Документ, который отвязали от строки или чью строку удалили, больше сам не привязывается: его
 * отметка хранится в настройке {@see self::SETTING_EXCLUDED} до явной загрузки файла в строку.
 */
final class MaterialLinker
{
    /** Документы, которые не надо снова привязывать (отвязаны вручную, строка удалена): список id. */
    public const SETTING_EXCLUDED = 'materials.excluded_documents';

    /** Таблицы, документы которых уже связаны со строками (первая связка — при первом открытии таблицы). */
    public const SETTING_SYNCED = 'materials.linked_sections';

    /** Похожесть названий (в процентах), с которой из нескольких строк одного типа выбирается строка. */
    private const SIMILARITY = 60.0;

    private int $suspended = 0;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MaterialRowRepository $rows,
        private readonly MaterialParameterRepository $parameters,
        private readonly PortalUrl $portalUrl,
        private readonly LoggerInterface $auditLogger,
        private readonly ?MaterialHistory $history = null,
        private readonly ?PortalSettings $settings = null,
    ) {
    }

    /** Раздел, в таблицу материалов которого попадают документы раздела: корень дерева, если таблица включена. */
    public static function tableSection(Section $section): ?Section
    {
        $root = $section;
        while (null !== $root->getParent()) {
            $root = $root->getParent();
        }

        return $root->isMaterialsEnabled() ? $root : null;
    }

    /**
     * Выполняет действие без автоматической привязки (загрузка файла из строки сама связывает документ
     * со своей строкой).
     *
     * @template T
     *
     * @param callable(): T $action
     *
     * @return T
     */
    public function withoutAutoLink(callable $action): mixed
    {
        ++$this->suspended;
        try {
            return $action();
        } finally {
            --$this->suspended;
        }
    }

    /**
     * Документ создан или перенесён в другой раздел: снять его со строк чужих таблиц и привязать к
     * строке таблицы нового раздела. Ошибка привязки не мешает сохранению документа — она в журнале.
     */
    public function documentSaved(Document $document, ?User $by = null): void
    {
        if ($this->suspended > 0 || null === $document->getId()) {
            return;
        }
        try {
            $changed = $this->unlinkForeign($document, $by);
            $row = $this->link($document, $by);
            if ($changed || null !== $row) {
                $this->em->flush();
            }
        } catch (\Throwable $e) {
            $this->auditLogger->warning('Документ не привязан к таблице материалов', ['document' => $document->getId(), 'error' => $e->getMessage()]);
        }
    }

    /**
     * Связывает все документы раздела и подразделов с таблицей: привязывает новые, отвязывает перенесённые
     * в чужие разделы. Вызывается при включении таблицы, переносе раздела, после импорта из Excel и
     * кнопкой «Связать документы».
     *
     * @return array{linked: int, created: int, unlinked: int}
     */
    public function syncSection(Section $root, ?User $by = null, bool $flush = true): array
    {
        $stats = ['linked' => 0, 'created' => 0, 'unlinked' => 0];
        if (!$root->isTopLevel() || !$root->isMaterialsEnabled() || null === $root->getId()) {
            return $stats;
        }
        $rows = $this->rows->findForSection($root);
        $linked = [];
        foreach ($rows as $row) {
            $document = $row->getDocument();
            if (null === $document) {
                continue;
            }
            if (!$document->getSection()->isSameOrDescendantOf($root)) {
                $this->detach($row, $by, \sprintf('Документ перенесён в раздел «%s»', $document->getSection()->getFullName()));
                ++$stats['unlinked'];
                continue;
            }
            $linked[(int) $document->getId()] = true;
        }
        $excluded = $this->excluded();
        /** @var list<Document> $documents */
        $documents = $this->em->createQuery(
            'SELECT d, k FROM App\Entity\Document d JOIN d.section s LEFT JOIN d.kind k
             WHERE s.path LIKE :path AND d.status <> :archived ORDER BY d.createdAt ASC, d.id ASC'
        )->setParameter('path', $root->getPath().'%')->setParameter('archived', Document::STATUS_ARCHIVED)->getResult();
        foreach ($documents as $document) {
            $id = (int) $document->getId();
            if (isset($linked[$id]) || isset($excluded[$id])) {
                continue;
            }
            $row = $this->match($rows, $document);
            if (null === $row) {
                $row = $this->newRow($root, $document, $by);
                $rows[] = $row;
                ++$stats['created'];
            } else {
                ++$stats['linked'];
            }
            $this->attach($row, $document, $by);
            $linked[$id] = true;
        }
        if ($flush && array_sum($stats) > 0) {
            $this->em->flush();
        }
        if (array_sum($stats) > 0) {
            $this->auditLogger->info('Документы раздела связаны с таблицей материалов', ['section' => $root->getFullName(), 'user' => $by?->getUsername()] + $stats);
        }
        $this->markSynced($root);

        return $stats;
    }

    /**
     * Первая связка документов с таблицей: таблицы, которые вели до появления связки «Документы → строки»,
     * получают строки для уже загруженных документов при первом показе таблицы или сводной.
     */
    public function ensureSynced(Section $root): void
    {
        if (null === $this->settings || !$root->isTopLevel() || !$root->isMaterialsEnabled() || $this->isSynced($root)) {
            return;
        }
        try {
            $this->syncSection($root);
        } catch (\Throwable $e) {
            $this->auditLogger->warning('Документы раздела не связаны с таблицей материалов', ['section' => $root->getFullName(), 'error' => $e->getMessage()]);
        }
    }

    public function isSynced(Section $root): bool
    {
        $ids = $this->settings?->get(self::SETTING_SYNCED, []);

        return \is_array($ids) && \in_array((int) $root->getId(), array_map('intval', $ids), true);
    }

    private function markSynced(Section $root): void
    {
        if (null === $this->settings || $this->isSynced($root)) {
            return;
        }
        $ids = $this->settings->get(self::SETTING_SYNCED, []);
        $ids = \is_array($ids) ? array_map('intval', $ids) : [];
        $ids[] = (int) $root->getId();
        $this->settings->set(self::SETTING_SYNCED, array_values(array_unique($ids)));
    }

    /** Документ больше не привязывается сам (отвязан от строки, строка удалена). */
    public function exclude(Document $document): void
    {
        $id = (int) $document->getId();
        if ($id <= 0 || null === $this->settings) {
            return;
        }
        $excluded = $this->excluded();
        if (!isset($excluded[$id])) {
            $excluded[$id] = true;
            $this->settings->set(self::SETTING_EXCLUDED, array_keys($excluded));
        }
    }

    /** Документ снова может привязываться (в его строку загрузили файл). */
    public function allow(Document $document): void
    {
        $id = (int) $document->getId();
        $excluded = $this->excluded();
        if (null !== $this->settings && isset($excluded[$id])) {
            unset($excluded[$id]);
            $this->settings->set(self::SETTING_EXCLUDED, array_keys($excluded));
        }
    }

    public function isExcluded(Document $document): bool
    {
        return isset($this->excluded()[(int) $document->getId()]);
    }

    // ---- Внутреннее -----------------------------------------------------------------------------

    /** Привязывает документ к строке таблицы его раздела; возвращает строку или null — ничего не поменялось. */
    private function link(Document $document, ?User $by): ?MaterialRow
    {
        $table = self::tableSection($document->getSection());
        if (null === $table || $document->isArchived() || $this->isExcluded($document)) {
            return null;
        }
        foreach ($this->rows->findByDocument($document) as $row) {
            if ($row->getSection()->getId() === $table->getId()) {
                return null;
            }
        }
        $row = $this->match($this->rows->findForSection($table), $document) ?? $this->newRow($table, $document, $by);
        $this->attach($row, $document, $by);

        return $row;
    }

    /** Строки других таблиц, к которым документ привязан, хотя теперь лежит в другом разделе. */
    private function unlinkForeign(Document $document, ?User $by): bool
    {
        $changed = false;
        foreach ($this->rows->findByDocument($document) as $row) {
            if (!$document->getSection()->isSameOrDescendantOf($row->getSection())) {
                $this->detach($row, $by, \sprintf('Документ перенесён в раздел «%s»', $document->getSection()->getFullName()));
                $changed = true;
            }
        }

        return $changed;
    }

    /**
     * Свободная строка для документа: с тем же названием, иначе единственная свободная строка того же типа
     * (из нескольких — самая похожая по названию).
     *
     * @param list<MaterialRow> $rows
     */
    private function match(array $rows, Document $document): ?MaterialRow
    {
        $title = self::normalize($document->getTitle());
        $free = array_values(array_filter($rows, static fn (MaterialRow $r): bool => null === $r->getDocument()));
        foreach ($free as $row) {
            if ('' !== $title && self::normalize($row->getTitle()) === $title) {
                return $row;
            }
        }
        $kind = $document->getKind();
        if (null === $kind) {
            return null;
        }
        $sameKind = array_values(array_filter($free, static fn (MaterialRow $r): bool => $r->getKind()?->getId() === $kind->getId()));
        if (1 === \count($sameKind)) {
            return $sameKind[0];
        }
        $best = null;
        $bestScore = 0.0;
        foreach ($sameKind as $row) {
            similar_text($title, self::normalize($row->getTitle()), $score);
            if ($score > $bestScore) {
                $best = $row;
                $bestScore = $score;
            }
        }

        return $bestScore >= self::SIMILARITY ? $best : null;
    }

    private function newRow(Section $table, Document $document, ?User $by): MaterialRow
    {
        $row = (new MaterialRow($table, $document->getTitle(), $this->nextPosition($table)))->setKind($document->getKind())->touch($by);
        $this->em->persist($row);
        $this->history?->record($row, MaterialRowChange::CREATE, $by, null, null, null, $row->getTitle());

        return $row;
    }

    /** @var array<int, int> следующая позиция строки по разделам (на время синхронизации) */
    private array $positions = [];

    private function nextPosition(Section $table): int
    {
        $id = (int) $table->getId();
        $this->positions[$id] = max($this->positions[$id] ?? 0, $this->rows->nextPosition($table));

        return $this->positions[$id]++;
    }

    private function attach(MaterialRow $row, Document $document, ?User $by): void
    {
        $row->setDocument($document);
        if ('' === $row->getTitle()) {
            $row->setTitle($document->getTitle());
        }
        if (null === $row->getKind() && null !== $document->getKind()) {
            $row->setKind($document->getKind());
        }
        foreach ($this->parameters->findForSection($row->getSection()) as $parameter) {
            $value = $row->getValue($parameter);
            if (MaterialParameter::ROLE_UPDATED === $parameter->getRole() && '' === $value) {
                $date = $document->getCurrentVersion()?->getCreatedAt() ?? $document->getUpdatedAt();
                $row->setValue($parameter, $date->format('Y-m-d'));
            } elseif (MaterialParameter::ROLE_LINK === $parameter->getRole() && '' === $value) {
                $row->setValue($parameter, $this->portalUrl->absolute('app_document_show', ['id' => $document->getId()]));
            } elseif (MaterialParameter::ROLE_PM === $parameter->getRole() && MaterialState::CHECK_ABSENT === $value
                && \in_array(MaterialState::CHECK_NONE, $parameter->getOptions(), true)) {
                // Файл появился — «Отсутствует» больше не верно: материал ждёт проверки.
                $row->setValue($parameter, MaterialState::CHECK_NONE);
                $this->history?->record($row, MaterialRowChange::UPDATE, $by, $parameter->key(), $parameter->getName().' (документ загружен)', $value, MaterialState::CHECK_NONE);
            }
        }
        $row->touch($by);
        $this->history?->record($row, MaterialRowChange::LINK, $by, 'file', 'Файл', null, \sprintf('«%s» (%s)', $document->getTitle(), $document->getSection()->getFullName()));
        $this->auditLogger->info('Документ привязан к строке таблицы материалов', ['document' => $document->getId(), 'section' => $row->getSection()->getFullName(), 'material' => $row->getTitle(), 'user' => $by?->getUsername()]);
    }

    private function detach(MaterialRow $row, ?User $by, string $reason): void
    {
        $this->history?->record($row, MaterialRowChange::DETACH, $by, 'file', $reason, $row->getDocument()?->getTitle(), null);
        $row->setDocument(null)->touch($by);
    }

    /** @return array<int, true> */
    private function excluded(): array
    {
        $ids = $this->settings?->get(self::SETTING_EXCLUDED, []);

        return \is_array($ids) ? array_fill_keys(array_map('intval', $ids), true) : [];
    }

    /** Название для сравнения: без регистра, «ё», кавычек, знаков и лишних пробелов. */
    public static function normalize(string $title): string
    {
        $title = mb_strtolower(str_replace(['ё', 'Ё'], ['е', 'е'], $title));
        $title = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $title) ?? $title;

        return trim($title);
    }
}
