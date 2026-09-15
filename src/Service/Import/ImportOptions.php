<?php

declare(strict_types=1);

namespace App\Service\Import;

/**
 * Параметры импорта каталога с диска в портал.
 */
final class ImportOptions
{
    public function __construct(
        /** Раздел, в который импортируется дерево (null — верхний уровень). */
        public readonly ?int $targetSectionId = null,
        /** Создать раздел с именем самого каталога (иначе его содержимое попадает прямо в целевой раздел). */
        public readonly bool $rootAsSection = false,
        /** Публиковать документы сразу (иначе — черновики). */
        public readonly bool $publish = true,
        /** Срок актуальности в месяцах от сегодняшней даты (0 — бессрочно). */
        public readonly int $validityMonths = 0,
        /** Если документ с таким именем файла уже есть в разделе и файл изменился — загрузить новую версию. */
        public readonly bool $updateExisting = true,
        /** Удалять исходные файлы после успешного импорта (перенос вместо копирования). */
        public readonly bool $deleteSource = false,
        /** Не ограничивать импорт списком разрешённых расширений ALLOWED_EXTENSIONS. */
        public readonly bool $anyExtension = false,
        /** Удалить сам каталог импорта, если после переноса он опустел (только вместе с deleteSource). */
        public readonly bool $removeRootIfEmpty = false,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'targetSectionId' => $this->targetSectionId,
            'rootAsSection' => $this->rootAsSection,
            'publish' => $this->publish,
            'validityMonths' => $this->validityMonths,
            'updateExisting' => $this->updateExisting,
            'deleteSource' => $this->deleteSource,
            'anyExtension' => $this->anyExtension,
            'removeRootIfEmpty' => $this->removeRootIfEmpty,
        ];
    }

    /** @param array<string, mixed> $a */
    public static function fromArray(array $a): self
    {
        return new self(
            targetSectionId: isset($a['targetSectionId']) && (int) $a['targetSectionId'] > 0 ? (int) $a['targetSectionId'] : null,
            rootAsSection: (bool) ($a['rootAsSection'] ?? false),
            publish: (bool) ($a['publish'] ?? true),
            validityMonths: max(0, (int) ($a['validityMonths'] ?? 0)),
            updateExisting: (bool) ($a['updateExisting'] ?? true),
            deleteSource: (bool) ($a['deleteSource'] ?? false),
            anyExtension: (bool) ($a['anyExtension'] ?? false),
            removeRootIfEmpty: (bool) ($a['removeRootIfEmpty'] ?? false),
        );
    }
}
