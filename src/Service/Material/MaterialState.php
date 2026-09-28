<?php

declare(strict_types=1);

namespace App\Service\Material;

/**
 * Состояние материала — та же формула, что в столбце «Состояние» таблицы «Продуктовые материалы»:
 *
 *   =ЕСЛИ(PM="";"";ЕСЛИ(PM="Не соответствует";"Не соответствует";ЕСЛИ(PM="Не выбрано";"Неизвестно";
 *    ЕСЛИ(PM="Отсутствует";"Отсутствует";ЕСЛИ(И(PM="Соответствует";Дизайн="Соответствует");"Готово";
 *    ЕСЛИ(И(PM="Соответствует";Дизайн="Не соответствует");"Допустимо";""))))))
 *
 * где PM — «Требования PM», Дизайн — «Дизайн-требования».
 */
final class MaterialState
{
    public const READY = 'Готово';
    public const ACCEPTABLE = 'Допустимо';
    public const MISMATCH = 'Не соответствует';
    public const ABSENT = 'Отсутствует';
    public const UNKNOWN = 'Неизвестно';

    /** Значения проверок «Требования PM» и «Дизайн-требования». */
    public const CHECK_OK = 'Соответствует';
    public const CHECK_FAIL = 'Не соответствует';
    public const CHECK_ABSENT = 'Отсутствует';
    public const CHECK_NONE = 'Не выбрано';

    /** Состояния блока «Общее состояние» сводной (в том же порядке, что в файле). */
    public const SUMMARY = [self::READY, self::ACCEPTABLE, self::MISMATCH, self::ABSENT];

    /** Состояния, которые считаются в блоке «Несоответствие / Отсутствие по типу». */
    public const PROBLEMS = [self::MISMATCH, self::ABSENT];

    /** Модификаторы CSS для окраски ячеек и диаграмм. */
    public const CSS = [
        self::READY => 'ready',
        self::ACCEPTABLE => 'acceptable',
        self::MISMATCH => 'mismatch',
        self::ABSENT => 'absent',
        self::UNKNOWN => 'unknown',
    ];

    /** Цвета состояний для диаграмм и выгрузки в Excel. */
    public const COLORS = [
        self::READY => '#1F8F4E',
        self::ACCEPTABLE => '#D98A00',
        self::MISMATCH => '#D00024',
        self::ABSENT => '#8A8F98',
        self::UNKNOWN => '#C8CCD2',
    ];

    public static function compute(?string $pm, ?string $design): string
    {
        $pm = trim((string) $pm);
        $design = trim((string) $design);

        return match (true) {
            '' === $pm => '',
            self::CHECK_FAIL === $pm => self::MISMATCH,
            self::CHECK_NONE === $pm => self::UNKNOWN,
            self::CHECK_ABSENT === $pm => self::ABSENT,
            self::CHECK_OK === $pm && self::CHECK_OK === $design => self::READY,
            self::CHECK_OK === $pm && self::CHECK_FAIL === $design => self::ACCEPTABLE,
            default => '',
        };
    }

    public static function css(string $state): string
    {
        return self::CSS[$state] ?? 'empty';
    }

    public static function isProblem(string $state): bool
    {
        return \in_array($state, self::PROBLEMS, true);
    }
}
