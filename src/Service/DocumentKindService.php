<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Document;
use App\Entity\DocumentKind;
use App\Entity\User;
use App\Repository\DocumentKindRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Справочник типов файлов: создание, переименование, порядок, удаление с переводом документов
 * в другой тип, сопоставление названий из Excel и определение типа по названию и расширению файла.
 */
final class DocumentKindService implements ResetInterface
{
    /** Расширения, которые считаются фотографиями. */
    private const PHOTO_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'tif', 'tiff', 'bmp', 'heic', 'heif'];

    /** Подсказки для автоматического определения типа по названию документа (порядок важен). */
    private const TITLE_RULES = [
        '/(опросн\w*\s+лист|\bткп\b|коммерческ\w*\s+предложен)/iu' => 'Опросные листы и ТКП',
        '/(паспорт|папорт|руководств|инструкци\w*\s+по\s+(монтажу|эксплуатац)|\bрэ\b)/iu' => 'Паспорта и руководства',
        '/(каталог|брошюр|буклет|листовк|альбом)/iu' => 'Каталоги и брошюры',
        '/(презентац|памятка\s+по\s+продаж)/iu' => 'Презентации',
        '/(фото|снимок|изображени)/iu' => 'Фотографии',
    ];

    /** @var list<DocumentKind>|null */
    private ?array $cache = null;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly DocumentKindRepository $kinds,
        private readonly LoggerInterface $auditLogger,
    ) {
    }

    /** @return list<DocumentKind> */
    public function all(): array
    {
        // После EntityManager::clear() (пакетный импорт, демо-данные) закэшированные типы отсоединены:
        // назначать их документам нельзя — список перечитывается.
        if (null !== $this->cache && [] !== $this->cache && !$this->em->contains($this->cache[0])) {
            $this->cache = null;
        }

        return $this->cache ??= $this->kinds->findOrdered();
    }

    /** Сбрасывает кэш справочника (между запросами — автоматически, kernel.reset). */
    public function reset(): void
    {
        $this->cache = null;
    }

    /**
     * Создаёт недостающие типы по умолчанию (их заводит миграция; метод нужен для демо-данных и тестов,
     * где схема создаётся без миграций). Существующие типы сохраняются; если что-то добавлено, «Прочее» ставится в конец.
     */
    public function ensureDefaults(): void
    {
        $this->reset();
        $created = false;
        foreach (DocumentKind::DEFAULTS as $name) {
            if (null === $this->kinds->findOneByName($name)) {
                $this->em->persist(new DocumentKind($name, $this->kinds->nextPosition(), DocumentKind::FALLBACK === $name));
                $this->em->flush();
                $created = true;
            }
        }
        if ($created) {
            // «Прочее» — в конце списка, как в сводной.
            $position = 0;
            $ordered = $this->kinds->findOrdered();
            foreach ([...array_filter($ordered, static fn (DocumentKind $k): bool => !$k->isSystem()), ...array_filter($ordered, static fn (DocumentKind $k): bool => $k->isSystem())] as $kind) {
                $kind->setPosition(++$position);
            }
            $this->em->flush();
            $this->reset();
        }
    }

    /** Служебный тип «Прочее»; создаётся, если его удалили из базы вручную. */
    public function fallback(): DocumentKind
    {
        $fallback = $this->kinds->findFallback();
        if (null === $fallback) {
            $fallback = new DocumentKind(DocumentKind::FALLBACK, $this->kinds->nextPosition(), true);
            $this->em->persist($fallback);
            $this->em->flush();
            $this->reset();
        }

        return $fallback;
    }

    public function find(?int $id): ?DocumentKind
    {
        if (null === $id || $id <= 0) {
            return null;
        }
        foreach ($this->all() as $kind) {
            if ($kind->getId() === $id) {
                return $kind;
            }
        }

        return null;
    }

    /**
     * Находит тип по названию из таблицы: точное совпадение, затем начало названия
     * («Опросные листы» → «Опросные листы и ТКП»), затем совпадение по основам слов
     * («Паспорта и руководство» → «Паспорта и руководства»).
     */
    public function match(?string $name): ?DocumentKind
    {
        // Сравнение без учёта регистра и «ё»/«е»: «Отчеты» — тот же тип, что «Отчёты».
        $lower = DocumentKind::foldName($name);
        if ('' === $lower) {
            return null;
        }
        foreach ($this->all() as $kind) {
            if (DocumentKind::foldName($kind->getName()) === $lower) {
                return $kind;
            }
        }
        if (mb_strlen($lower) >= 6) {
            foreach ($this->all() as $kind) {
                $candidate = DocumentKind::foldName($kind->getName());
                if (str_starts_with($candidate, $lower) || str_starts_with($lower, $candidate)) {
                    return $kind;
                }
            }
        }
        $stem = self::stem($lower);
        foreach ($this->all() as $kind) {
            if (self::stem(DocumentKind::foldName($kind->getName())) === $stem) {
                return $kind;
            }
        }

        return null;
    }

    /**
     * Тип по названию из таблицы; если такого нет — создаётся (импорт) или берётся «Прочее».
     */
    public function resolve(?string $name, bool $create, ?User $by = null): ?DocumentKind
    {
        if ('' === DocumentKind::normalizeName($name)) {
            return null;
        }
        $kind = $this->match($name);
        if (null !== $kind) {
            return $kind;
        }

        return $create ? $this->create((string) $name, $by) : $this->fallback();
    }

    /** Определяет тип документа по названию и расширению файла; по умолчанию — «Прочее». */
    public function guess(string $title, ?string $extension = null): DocumentKind
    {
        if (null !== $extension && \in_array(mb_strtolower($extension), self::PHOTO_EXTENSIONS, true)) {
            return $this->match('Фотографии') ?? $this->fallback();
        }
        foreach (self::TITLE_RULES as $pattern => $kindName) {
            if (1 === preg_match($pattern, $title)) {
                $kind = $this->match($kindName);
                if (null !== $kind) {
                    return $kind;
                }
            }
        }

        return $this->fallback();
    }

    /** Проставляет тип документу, у которого его нет (создание, импорт, API). */
    public function assignIfMissing(Document $document): void
    {
        if (null === $document->getKind()) {
            $document->setKind($this->guess($document->getTitle(), $document->getCurrentVersion()?->getExtension()));
        }
    }

    /**
     * @throws \DomainException если название пустое или уже занято
     */
    public function create(string $name, ?User $by = null): DocumentKind
    {
        $name = DocumentKind::normalizeName($name);
        if ('' === $name) {
            throw new \DomainException('Укажите название типа.');
        }
        if (null !== $this->kinds->findOneByName($name)) {
            throw new \DomainException(\sprintf('Тип «%s» уже есть.', $name));
        }
        // Новый тип встаёт перед «Прочим», если «Прочее» стоит последним (как в сводной Excel).
        $position = $this->kinds->nextPosition();
        $fallback = $this->kinds->findFallback();
        if (null !== $fallback && $fallback->getPosition() === $position - 1) {
            $position = $fallback->getPosition();
            $fallback->setPosition($position + 1);
        }
        $kind = new DocumentKind($name, $position);
        $this->em->persist($kind);
        $this->em->flush();
        $this->reset();
        $this->auditLogger->info('Добавлен тип файлов', ['kind' => $name, 'user' => $by?->getUsername()]);

        return $kind;
    }

    /**
     * @throws \DomainException
     */
    public function rename(DocumentKind $kind, string $name, ?User $by = null): void
    {
        $name = DocumentKind::normalizeName($name);
        if ('' === $name) {
            throw new \DomainException('Укажите название типа.');
        }
        $existing = $this->kinds->findOneByName($name);
        if (null !== $existing && $existing->getId() !== $kind->getId()) {
            throw new \DomainException(\sprintf('Тип «%s» уже есть.', $name));
        }
        $old = $kind->getName();
        $kind->setName($name);
        $this->em->flush();
        $this->reset();
        $this->auditLogger->info('Переименован тип файлов', ['from' => $old, 'to' => $name, 'user' => $by?->getUsername()]);
    }

    /** Сдвигает тип в списке вверх (-1) или вниз (+1). */
    public function move(DocumentKind $kind, int $direction): void
    {
        $all = $this->kinds->findOrdered();
        $index = array_search($kind, $all, true);
        $target = false === $index ? false : $index + ($direction < 0 ? -1 : 1);
        if (false === $index || $target < 0 || $target >= \count($all)) {
            return;
        }
        [$all[$index], $all[$target]] = [$all[$target], $all[$index]];
        foreach ($all as $position => $item) {
            $item->setPosition($position + 1);
        }
        $this->em->flush();
        $this->reset();
    }

    /**
     * Удаляет тип; документы и строки таблиц материалов переходят в указанный тип (по умолчанию «Прочее»).
     *
     * @return array{documents: int, materials: int}
     *
     * @throws \DomainException для служебного типа
     */
    public function delete(DocumentKind $kind, ?DocumentKind $replacement = null, ?User $by = null): array
    {
        if ($kind->isSystem()) {
            throw new \DomainException(\sprintf('Тип «%s» служебный — в него переводятся документы удалённых типов, удалить его нельзя.', $kind->getName()));
        }
        $replacement ??= $this->fallback();
        if ($replacement->getId() === $kind->getId()) {
            $replacement = $this->fallback();
        }
        $documents = $this->em->createQuery('UPDATE App\Entity\Document d SET d.kind = :to WHERE d.kind = :from')
            ->setParameter('to', $replacement)->setParameter('from', $kind)->execute();
        $materials = $this->em->createQuery('UPDATE App\Entity\MaterialRow r SET r.kind = :to WHERE r.kind = :from')
            ->setParameter('to', $replacement)->setParameter('from', $kind)->execute();
        $name = $kind->getName();
        $this->em->remove($kind);
        $this->em->flush();
        $this->reset();
        $this->auditLogger->info('Удалён тип файлов', ['kind' => $name, 'replacement' => $replacement->getName(), 'documents' => $documents, 'materials' => $materials, 'user' => $by?->getUsername()]);

        return ['documents' => (int) $documents, 'materials' => (int) $materials];
    }

    /** Основы слов: первые пять букв каждого слова — «паспорта и руководство» = «паспорта и руководства». */
    private static function stem(string $name): string
    {
        $words = preg_split('/[^\p{L}\p{N}]+/u', $name, -1, \PREG_SPLIT_NO_EMPTY) ?: [];

        return implode(' ', array_map(static fn (string $word): string => mb_substr($word, 0, 5), $words));
    }
}
