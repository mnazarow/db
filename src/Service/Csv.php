<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Запись CSV для Excel: значения, которые Excel принял бы за формулу (=, +, -, @, табуляция, перевод
 * строки в начале), экранируются апострофом. Иначе название документа или комментарий вида
 * «=HYPERLINK(…)» выполнились бы как формула при открытии выгрузки.
 */
final class Csv
{
    /**
     * @param list<mixed> $values
     *
     * @return list<string|int|float|null>
     */
    public static function row(array $values): array
    {
        return array_map(self::cell(...), array_values($values));
    }

    public static function cell(mixed $value): string|int|float|null
    {
        if (null === $value || \is_int($value) || \is_float($value)) {
            return $value;
        }
        $value = \is_bool($value) ? ($value ? '1' : '0') : (string) $value;
        if ('' !== $value && str_contains("=+-@\t\r\n", $value[0])) {
            // Отрицательные числа вроде «-5» оставляем как есть — это не формула.
            if (1 === preg_match('/^-\d+([.,]\d+)?$/', $value)) {
                return $value;
            }

            return "'".$value;
        }

        return $value;
    }

    /**
     * @param resource    $handle
     * @param list<mixed> $values
     */
    public static function put($handle, array $values): void
    {
        // Без escape-символа: кавычки внутри значения удваиваются (RFC 4180). С «\\» последовательность
        // «\\"» закрывала бы поле раньше времени, и следующая ячейка могла бы оказаться формулой.
        fputcsv($handle, self::row($values), ';', '"', '');
    }
}
