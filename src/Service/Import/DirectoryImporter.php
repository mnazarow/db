<?php

declare(strict_types=1);

namespace App\Service\Import;

use App\Entity\Document;
use App\Entity\Section;
use App\Entity\User;
use App\Repository\DocumentRepository;
use App\Repository\SectionRepository;
use App\Repository\UserRepository;
use App\Service\DocumentManager;
use App\Service\SectionManager;
use App\Service\Validity;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Импорт каталога с диска: выполняет план, построенный DirectoryScanner.
 *
 *  - папка → раздел (существующий раздел с таким же названием у того же родителя переиспользуется);
 *  - файл → документ-файл (название — имя файла без расширения);
 *  - если в разделе уже есть документ с таким именем файла: файл не изменился — пропуск,
 *    изменился — новая версия (если включено обновление).
 *
 * Состояние выполнения (соответствие папок разделам, счётчики, журнал) хранится в ImportState,
 * поэтому план можно выполнять порциями (панель администратора) или целиком (консоль).
 */
final class DirectoryImporter
{
    /** Через сколько элементов очищать EntityManager при выполнении целиком (экономия памяти). */
    private const CLEAR_EVERY = 100;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly DirectoryScanner $scanner,
        private readonly SectionRepository $sections,
        private readonly DocumentRepository $documents,
        private readonly UserRepository $users,
        private readonly SectionManager $sectionManager,
        private readonly DocumentManager $documentManager,
        private readonly Validity $validity,
        private readonly LoggerInterface $auditLogger,
    ) {
    }

    public function getScanner(): DirectoryScanner
    {
        return $this->scanner;
    }

    /**
     * Строит план импорта каталога с учётом глубины целевого раздела.
     *
     * @throws \InvalidArgumentException если каталог или целевой раздел не найдены
     */
    public function scan(string $dir, ImportOptions $options): ImportPlan
    {
        $target = $this->targetSection($options);

        return $this->scanner->scan($dir, $options, null === $target ? -1 : $target->getDepth());
    }

    /**
     * Предпросмотр: помечает элементы плана ожидаемым результатом (новый/существующий раздел,
     * новый документ, новая версия, без изменений), ничего не меняя в базе.
     */
    public function preview(ImportPlan $plan, ImportOptions $options): ImportPlan
    {
        $target = $this->targetSection($options);
        /** @var array<string, Section|null|false> $map ключ папки → раздел (false — раздел будет создан) */
        $map = ['' => $options->rootAsSection ? false : $target];
        foreach ($plan->entries as $i => $entry) {
            if (null !== ($entry['skip'] ?? null)) {
                $plan->entries[$i]['result'] = ImportPlan::RESULT_SKIPPED;
                $plan->entries[$i]['message'] = ImportPlan::SKIP_LABELS[$entry['skip']] ?? $entry['skip'];
                continue;
            }
            if ('dir' === $entry['type']) {
                $parent = null === $entry['dir'] ? $target : ($map[$entry['dir']] ?? false);
                if (false === $parent) {
                    $map[$entry['path']] = false; // родитель новый — значит и эта папка новая
                    $plan->entries[$i]['result'] = ImportPlan::RESULT_SECTION_NEW;
                    continue;
                }
                $existing = $this->sections->findChildByName($parent, $entry['name']);
                $map[$entry['path']] = $existing ?? false;
                $plan->entries[$i]['result'] = null !== $existing ? ImportPlan::RESULT_SECTION_EXISTS : ImportPlan::RESULT_SECTION_NEW;
                if (null !== $existing) {
                    $plan->entries[$i]['sectionId'] = $existing->getId();
                }
                continue;
            }
            $section = $map[$entry['dir']] ?? false;
            if (null === $section) {
                $plan->entries[$i]['result'] = ImportPlan::RESULT_SKIPPED;
                $plan->entries[$i]['message'] = ImportPlan::SKIP_LABELS[ImportPlan::SKIP_NO_SECTION];
                continue;
            }
            if (false === $section) {
                $plan->entries[$i]['result'] = ImportPlan::RESULT_DOC_NEW;
                continue;
            }
            $existing = $this->documents->findFileByName($section, $entry['name']);
            if (null === $existing) {
                $plan->entries[$i]['result'] = ImportPlan::RESULT_DOC_NEW;
                continue;
            }
            $plan->entries[$i]['documentId'] = $existing->getId();
            if ($this->isSameFile($existing, $plan->absolutePath($entry), (int) $entry['size'])) {
                $plan->entries[$i]['result'] = ImportPlan::RESULT_UNCHANGED;
            } else {
                $plan->entries[$i]['result'] = $options->updateExisting ? ImportPlan::RESULT_VERSION_NEW : ImportPlan::RESULT_EXISTS;
            }
        }

        return $plan;
    }

    /**
     * Выполняет план целиком (консольная команда). $onProgress вызывается после каждого элемента:
     * function (int $done, int $total, array $entry, string $result, string $message).
     */
    public function run(ImportPlan $plan, ImportOptions $options, User $actor, ?callable $onProgress = null): ImportState
    {
        $state = ImportState::start($options);
        $actorId = (int) $actor->getId();
        $total = $plan->count();
        while ($state->position < $total) {
            $this->step($plan, $options, $state, $actorId);
            if (null !== $onProgress) {
                $last = $state->log[array_key_last($state->log)] ?? ['result' => '', 'message' => ''];
                $onProgress($state->position, $total, $plan->entries[$state->position - 1], $last['result'], $last['message']);
            }
            if (0 === $state->position % self::CLEAR_EVERY) {
                $this->em->clear();
            }
        }
        $this->finish($plan, $options, $state);

        return $state;
    }

    /**
     * Выполняет очередной элемент плана и сдвигает позицию. Возвращает false, если план уже выполнен.
     */
    public function step(ImportPlan $plan, ImportOptions $options, ImportState $state, int $actorId): bool
    {
        if ($state->position >= $plan->count()) {
            return false;
        }
        $entry = $plan->entries[$state->position];
        try {
            [$result, $message] = 'dir' === $entry['type']
                ? $this->importDir($plan, $entry, $options, $state, $actorId)
                : $this->importFile($plan, $entry, $options, $state, $actorId);
        } catch (\Throwable $e) {
            if (!$this->em->isOpen()) {
                throw new \RuntimeException('Импорт прерван: ошибка базы данных при обработке «'.$entry['path'].'»: '.$e->getMessage(), 0, $e);
            }
            $result = ImportPlan::RESULT_ERROR;
            $message = $e->getMessage();
            $this->auditLogger->error('Ошибка импорта элемента', ['path' => $plan->absolutePath($entry), 'error' => $e->getMessage()]);
        }
        $state->record($entry, $result, $message);
        ++$state->position;

        return true;
    }

    /**
     * Завершение: при переносе файлов удаляет использованные файлы описаний и опустевшие папки.
     */
    public function finish(ImportPlan $plan, ImportOptions $options, ImportState $state): void
    {
        if ($state->finished) {
            return;
        }
        $state->finished = true;
        if (!$options->deleteSource) {
            return;
        }
        // Файлы описаний разделов, которые были использованы.
        foreach ($plan->entries as $entry) {
            if ('file' === $entry['type'] && ImportPlan::SKIP_DESC === ($entry['skip'] ?? null) && isset($state->sections[$entry['dir']])) {
                @unlink($plan->absolutePath($entry));
            }
        }
        // Опустевшие папки — от глубоких к верхним.
        $dirs = array_values(array_filter($plan->entries, static fn (array $e) => 'dir' === $e['type'] && '' !== $e['path']));
        foreach (array_reverse($dirs) as $entry) {
            $abs = $plan->absolutePath($entry);
            if (is_dir($abs) && self::isEmptyDir($abs)) {
                @rmdir($abs);
            }
        }
        if ($options->removeRootIfEmpty && is_dir($plan->dir) && self::isEmptyDir($plan->dir)) {
            @rmdir($plan->dir);
        }
    }

    /** @return array{0: string, 1: string} */
    private function importDir(ImportPlan $plan, array $entry, ImportOptions $options, ImportState $state, int $actorId): array
    {
        if (null !== ($entry['skip'] ?? null)) {
            return [ImportPlan::RESULT_SKIPPED, ImportPlan::SKIP_LABELS[$entry['skip']] ?? $entry['skip']];
        }
        if (null === $entry['dir']) {
            $parent = $this->targetSection($options);
        } else {
            if (!\array_key_exists($entry['dir'], $state->sections)) {
                return [ImportPlan::RESULT_SKIPPED, 'родительский раздел не создан'];
            }
            $parent = $this->sectionById($state->sections[$entry['dir']]);
        }
        $description = null !== ($entry['descFile'] ?? null) ? DirectoryScanner::readDescription($plan->absolutePath($entry).'/'.$entry['descFile']) : null;
        $existing = $this->sections->findChildByName($parent, $entry['name']);
        if (null !== $existing) {
            if (null !== $description && null === $existing->getDescription()) {
                $existing->setDescription($description);
                $this->em->flush();
            }
            $state->sections[$entry['path']] = $existing->getId();

            return [ImportPlan::RESULT_SECTION_EXISTS, $existing->getFullName()];
        }
        $section = $this->sectionManager->create($entry['name'], $parent, $description, $this->actor($actorId));
        $state->sections[$entry['path']] = $section->getId();

        return [ImportPlan::RESULT_SECTION_NEW, $section->getFullName()];
    }

    /** @return array{0: string, 1: string} */
    private function importFile(ImportPlan $plan, array $entry, ImportOptions $options, ImportState $state, int $actorId): array
    {
        if (null !== ($entry['skip'] ?? null)) {
            return [ImportPlan::RESULT_SKIPPED, ImportPlan::SKIP_LABELS[$entry['skip']] ?? $entry['skip']];
        }
        if (!\array_key_exists($entry['dir'], $state->sections) || null === $state->sections[$entry['dir']]) {
            return [ImportPlan::RESULT_SKIPPED, ImportPlan::SKIP_LABELS[ImportPlan::SKIP_NO_SECTION]];
        }
        $section = $this->sectionById($state->sections[$entry['dir']]);
        $abs = $plan->absolutePath($entry);
        $actor = $this->actor($actorId);
        $source = (string) $entry['path'];

        $existing = $this->documents->findFileByName($section, $entry['name']);
        if (null !== $existing) {
            if ($this->isSameFile($existing, $abs, (int) $entry['size'])) {
                if ($options->deleteSource) {
                    @unlink($abs);
                }

                return [ImportPlan::RESULT_UNCHANGED, $existing->getTitle()];
            }
            if (!$options->updateExisting) {

                return [ImportPlan::RESULT_EXISTS, $existing->getTitle()];
            }
            $version = $this->documentManager->addFileVersionFromPath($existing, $abs, $entry['name'], 'Импорт из каталога: '.$source, $actor, $options->deleteSource, $options->anyExtension);

            return [ImportPlan::RESULT_VERSION_NEW, \sprintf('%s — версия %d', $existing->getTitle(), $version->getNumber())];
        }

        $document = (new Document($section))
            ->setTitle(DirectoryScanner::documentTitle($entry['name']))
            ->setType(Document::TYPE_FILE)
            ->setValidUntil($options->validityMonths > 0 ? $this->validity->today()->modify('+'.$options->validityMonths.' months') : null);
        $this->documentManager->createFromPath($document, $abs, $entry['name'], 'Импорт из каталога: '.$source, $actor, $options->publish, $options->deleteSource, $options->anyExtension, ['import' => $abs]);

        return [ImportPlan::RESULT_DOC_NEW, $document->getTitle()];
    }

    /** Совпадает ли файл на диске с текущей версией документа (по размеру и контрольной сумме). */
    private function isSameFile(Document $document, string $path, int $size): bool
    {
        $current = $document->getCurrentVersion();
        if (null === $current || (int) $current->getSize() !== $size) {
            return false;
        }
        $checksum = $current->getChecksum();
        if (null === $checksum || '' === $checksum) {
            return true; // контрольной суммы нет (старые данные) — считаем совпадением по размеру
        }

        return hash_file('sha256', $path) === $checksum;
    }

    private function targetSection(ImportOptions $options): ?Section
    {
        if (null === $options->targetSectionId) {
            return null;
        }
        $section = $this->sections->find($options->targetSectionId);
        if (null === $section) {
            throw new \InvalidArgumentException('Целевой раздел не найден (id '.$options->targetSectionId.').');
        }

        return $section;
    }

    private function sectionById(?int $id): ?Section
    {
        if (null === $id) {
            return null;
        }
        $section = $this->sections->find($id);
        if (null === $section) {
            throw new \RuntimeException('Раздел, созданный при импорте, не найден (id '.$id.').');
        }

        return $section;
    }

    private function actor(int $id): User
    {
        $user = $this->users->find($id);
        if (null === $user) {
            throw new \RuntimeException('Пользователь, от имени которого выполняется импорт, не найден.');
        }

        return $user;
    }

    private static function isEmptyDir(string $dir): bool
    {
        foreach (@scandir($dir, \SCANDIR_SORT_NONE) ?: [] as $name) {
            if ('.' !== $name && '..' !== $name) {
                return false;
            }
        }

        return true;
    }
}
