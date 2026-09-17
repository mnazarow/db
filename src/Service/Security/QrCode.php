<?php

declare(strict_types=1);

namespace App\Service\Security;

/**
 * Генератор QR-кода (SVG) — нужен, чтобы показать ссылку otpauth:// для приложения-аутентификатора.
 *
 * Реализованы версии 1–10 с уровнем коррекции M и байтовым режимом: этого хватает для ссылок
 * до 213 байт. Внешних библиотек нет намеренно: портал ставится в закрытом контуре.
 *
 * Стандарт: ISO/IEC 18004. Проверяется тестом на совпадение с эталонным кодировщиком и
 * распознаванием готовой картинки.
 */
final class QrCode
{
    /** Максимальная длина данных (байт) по версиям 1–10 для уровня коррекции M. */
    private const CAPACITY = [1 => 14, 2 => 26, 3 => 42, 4 => 62, 5 => 84, 6 => 106, 7 => 122, 8 => 152, 9 => 180, 10 => 213];

    /** Всего кодовых слов в символе по версиям. */
    private const TOTAL_CODEWORDS = [1 => 26, 2 => 44, 3 => 70, 4 => 100, 5 => 134, 6 => 172, 7 => 196, 8 => 242, 9 => 292, 10 => 346];

    /**
     * Структура блоков уровня M: [число слов коррекции на блок, [[число блоков, число слов данных], …]].
     */
    private const BLOCKS = [
        1 => [10, [[1, 16]]],
        2 => [16, [[1, 28]]],
        3 => [26, [[1, 44]]],
        4 => [18, [[2, 32]]],
        5 => [24, [[2, 43]]],
        6 => [16, [[4, 27]]],
        7 => [18, [[4, 31]]],
        8 => [22, [[2, 38], [2, 39]]],
        9 => [22, [[3, 36], [2, 37]]],
        10 => [26, [[4, 43], [1, 44]]],
    ];

    /** Центры выравнивающих узоров по версиям. */
    private const ALIGNMENT = [
        1 => [], 2 => [6, 18], 3 => [6, 22], 4 => [6, 26], 5 => [6, 30],
        6 => [6, 34], 7 => [6, 22, 38], 8 => [6, 24, 42], 9 => [6, 26, 46], 10 => [6, 28, 50],
    ];

    /** Уровень коррекции M в битах индикатора формата. */
    private const EC_LEVEL_BITS = 0b00;

    /** @var list<int> таблица экспонент GF(256) */
    private static array $exp = [];

    /** @var list<int> таблица логарифмов GF(256) */
    private static array $log = [];

    /**
     * Матрица модулей QR-кода: [строка][столбец] => true (чёрный) | false (белый).
     *
     * @return list<list<bool>>
     *
     * @throws \InvalidArgumentException если данные не помещаются в версии 1–10
     */
    public static function matrix(string $data): array
    {
        $version = self::versionFor(\strlen($data));
        $codewords = self::encode($data, $version);
        $size = 17 + 4 * $version;
        [$matrix, $reserved] = self::template($version, $size);
        self::placeData($matrix, $reserved, $codewords, $size);

        // Восемь масок: выбираем ту, у которой меньше штраф (читаемость для сканера).
        $best = null;
        $bestPenalty = \PHP_INT_MAX;
        for ($mask = 0; $mask < 8; ++$mask) {
            $candidate = self::applyMask($matrix, $reserved, $size, $mask);
            self::placeFormat($candidate, $size, $mask);
            $penalty = self::penalty($candidate, $size);
            if ($penalty < $bestPenalty) {
                $bestPenalty = $penalty;
                $best = $candidate;
            }
        }

        return $best ?? $matrix;
    }

    /**
     * Матрица с заданной маской (0–7) — для проверки кодировщика в тестах.
     *
     * @return list<list<bool>>
     */
    public static function matrixWithMask(string $data, int $mask): array
    {
        $version = self::versionFor(\strlen($data));
        $size = 17 + 4 * $version;
        [$matrix, $reserved] = self::template($version, $size);
        self::placeData($matrix, $reserved, self::encode($data, $version), $size);
        $masked = self::applyMask($matrix, $reserved, $size, $mask);
        self::placeFormat($masked, $size, $mask);

        return $masked;
    }

    /** QR-код как SVG: масштаб — размер модуля в пикселях, поле — «тихая зона» в модулях. */
    public static function svg(string $data, int $scale = 4, int $quiet = 4): string
    {
        $matrix = self::matrix($data);
        $size = \count($matrix);
        $side = ($size + 2 * $quiet) * $scale;
        $path = '';
        foreach ($matrix as $y => $row) {
            foreach ($row as $x => $dark) {
                if ($dark) {
                    $path .= \sprintf('M%d %dh%dv%dh-%dz', ($x + $quiet) * $scale, ($y + $quiet) * $scale, $scale, $scale, $scale);
                }
            }
        }

        return \sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" width="%d" height="%d" viewBox="0 0 %d %d" role="img" aria-label="QR-код для приложения-аутентификатора">'
            .'<rect width="%d" height="%d" fill="#fff"/><path d="%s" fill="#000"/></svg>',
            $side, $side, $side, $side, $side, $side, $path
        );
    }

    /** Наименьшая версия, в которую помещаются данные. */
    private static function versionFor(int $length): int
    {
        foreach (self::CAPACITY as $version => $capacity) {
            if ($length <= $capacity) {
                return $version;
            }
        }

        throw new \InvalidArgumentException('Слишком длинные данные для QR-кода: '.$length.' байт (максимум '.max(self::CAPACITY).').');
    }

    /** Кодовые слова: данные с заголовком, дополнением и словами коррекции, в порядке записи. */
    private static function encode(string $data, int $version): array
    {
        [$ecPerBlock, $groups] = self::BLOCKS[$version];
        $dataCodewords = self::TOTAL_CODEWORDS[$version] - $ecPerBlock * self::blockCount($groups);

        // Битовый поток: режим 0100 (байты), длина, данные.
        $bits = '0100';
        $bits .= str_pad(decbin(\strlen($data)), $version >= 10 ? 16 : 8, '0', \STR_PAD_LEFT);
        foreach (str_split($data) as $char) {
            $bits .= str_pad(decbin(\ord($char)), 8, '0', \STR_PAD_LEFT);
        }
        $capacityBits = $dataCodewords * 8;
        $bits .= str_repeat('0', min(4, max(0, $capacityBits - \strlen($bits))));
        if (0 !== \strlen($bits) % 8) {
            $bits .= str_repeat('0', 8 - \strlen($bits) % 8);
        }
        $words = [];
        foreach (str_split($bits, 8) as $byte) {
            $words[] = bindec($byte);
        }
        $pad = [0xEC, 0x11];
        for ($i = 0; \count($words) < $dataCodewords; ++$i) {
            $words[] = $pad[$i % 2];
        }

        // Разбиение на блоки и коррекция Рида — Соломона.
        $dataBlocks = [];
        $ecBlocks = [];
        $offset = 0;
        foreach ($groups as [$count, $size]) {
            for ($i = 0; $i < $count; ++$i) {
                $block = \array_slice($words, $offset, $size);
                $offset += $size;
                $dataBlocks[] = $block;
                $ecBlocks[] = self::reedSolomon($block, $ecPerBlock);
            }
        }

        // Чередование: сначала данные по столбцам блоков, затем слова коррекции.
        $out = [];
        $maxData = max(array_map('count', $dataBlocks));
        for ($i = 0; $i < $maxData; ++$i) {
            foreach ($dataBlocks as $block) {
                if (isset($block[$i])) {
                    $out[] = $block[$i];
                }
            }
        }
        for ($i = 0; $i < $ecPerBlock; ++$i) {
            foreach ($ecBlocks as $block) {
                $out[] = $block[$i];
            }
        }

        return $out;
    }

    /** @param list<array{int, int}> $groups */
    private static function blockCount(array $groups): int
    {
        $count = 0;
        foreach ($groups as [$blocks]) {
            $count += $blocks;
        }

        return $count;
    }

    /**
     * Слова коррекции Рида — Соломона для блока данных.
     *
     * @param list<int> $data
     *
     * @return list<int>
     */
    private static function reedSolomon(array $data, int $ecCount): array
    {
        self::initGalois();
        // Порождающий многочлен: произведение (x - a^i).
        $generator = [1];
        for ($i = 0; $i < $ecCount; ++$i) {
            $next = array_fill(0, \count($generator) + 1, 0);
            foreach ($generator as $index => $coefficient) {
                $next[$index] ^= $coefficient;
                $next[$index + 1] ^= self::multiply($coefficient, self::$exp[$i]);
            }
            $generator = $next;
        }
        $remainder = array_merge($data, array_fill(0, $ecCount, 0));
        for ($i = 0; $i < \count($data); ++$i) {
            $factor = $remainder[$i];
            if (0 === $factor) {
                continue;
            }
            foreach ($generator as $index => $coefficient) {
                $remainder[$i + $index] ^= self::multiply($coefficient, $factor);
            }
        }

        return array_values(\array_slice($remainder, \count($data), $ecCount));
    }

    private static function initGalois(): void
    {
        if ([] !== self::$exp) {
            return;
        }
        $exp = array_fill(0, 512, 0);
        $log = array_fill(0, 256, 0);
        $value = 1;
        for ($i = 0; $i < 255; ++$i) {
            $exp[$i] = $value;
            $log[$value] = $i;
            $value <<= 1;
            if ($value & 0x100) {
                $value ^= 0x11D;
            }
        }
        for ($i = 255; $i < 512; ++$i) {
            $exp[$i] = $exp[$i - 255];
        }
        self::$exp = $exp;
        self::$log = $log;
    }

    private static function multiply(int $a, int $b): int
    {
        if (0 === $a || 0 === $b) {
            return 0;
        }

        return self::$exp[(self::$log[$a] + self::$log[$b]) % 255];
    }

    /**
     * Пустая матрица со служебными узорами и картой занятых модулей.
     *
     * @return array{list<list<bool>>, list<list<bool>>}
     */
    private static function template(int $version, int $size): array
    {
        $matrix = array_fill(0, $size, array_fill(0, $size, false));
        $reserved = array_fill(0, $size, array_fill(0, $size, false));

        $finder = static function (int $row, int $col) use (&$matrix, &$reserved, $size): void {
            for ($r = -1; $r <= 7; ++$r) {
                for ($c = -1; $c <= 7; ++$c) {
                    $y = $row + $r;
                    $x = $col + $c;
                    if ($y < 0 || $y >= $size || $x < 0 || $x >= $size) {
                        continue;
                    }
                    $inside = $r >= 0 && $r <= 6 && $c >= 0 && $c <= 6;
                    $dark = $inside && (0 === $r || 6 === $r || 0 === $c || 6 === $c || ($r >= 2 && $r <= 4 && $c >= 2 && $c <= 4));
                    $matrix[$y][$x] = $dark;
                    $reserved[$y][$x] = true;
                }
            }
        };
        $finder(0, 0);
        $finder(0, $size - 7);
        $finder($size - 7, 0);

        // Синхронизирующие дорожки.
        for ($i = 8; $i < $size - 8; ++$i) {
            $dark = 0 === $i % 2;
            $matrix[6][$i] = $dark;
            $reserved[6][$i] = true;
            $matrix[$i][6] = $dark;
            $reserved[$i][6] = true;
        }

        // Выравнивающие узоры (не накладываются на поисковые).
        $centers = self::ALIGNMENT[$version];
        foreach ($centers as $row) {
            foreach ($centers as $col) {
                if (($row <= 8 && $col <= 8) || ($row <= 8 && $col >= $size - 9) || ($row >= $size - 9 && $col <= 8)) {
                    continue;
                }
                for ($r = -2; $r <= 2; ++$r) {
                    for ($c = -2; $c <= 2; ++$c) {
                        $matrix[$row + $r][$col + $c] = 2 === max(abs($r), abs($c)) || (0 === $r && 0 === $c);
                        $reserved[$row + $r][$col + $c] = true;
                    }
                }
            }
        }

        // Тёмный модуль и места под сведения о формате.
        $matrix[$size - 8][8] = true;
        $reserved[$size - 8][8] = true;
        for ($i = 0; $i < 9; ++$i) {
            if (6 !== $i) {
                $reserved[8][$i] = true;
                $reserved[$i][8] = true;
            }
        }
        for ($i = 0; $i < 8; ++$i) {
            $reserved[8][$size - 1 - $i] = true;
            $reserved[$size - 1 - $i][8] = true;
        }

        // Сведения о версии (для версий 7 и выше).
        if ($version >= 7) {
            $bits = self::versionBits($version);
            for ($i = 0; $i < 18; ++$i) {
                $bit = (bool) (($bits >> $i) & 1);
                $row = intdiv($i, 3);
                $col = $size - 11 + $i % 3;
                $matrix[$row][$col] = $bit;
                $reserved[$row][$col] = true;
                $matrix[$col][$row] = $bit;
                $reserved[$col][$row] = true;
            }
        }

        return [$matrix, $reserved];
    }

    /** @param list<int> $codewords */
    private static function placeData(array &$matrix, array $reserved, array $codewords, int $size): void
    {
        $bits = '';
        foreach ($codewords as $word) {
            $bits .= str_pad(decbin($word), 8, '0', \STR_PAD_LEFT);
        }
        $index = 0;
        $upward = true;
        for ($right = $size - 1; $right > 0; $right -= 2) {
            if (6 === $right) {
                --$right; // столбец синхронизации пропускаем
            }
            for ($step = 0; $step < $size; ++$step) {
                $row = $upward ? $size - 1 - $step : $step;
                foreach ([$right, $right - 1] as $col) {
                    if ($reserved[$row][$col]) {
                        continue;
                    }
                    $matrix[$row][$col] = isset($bits[$index]) && '1' === $bits[$index];
                    ++$index;
                }
            }
            $upward = !$upward;
        }
    }

    /**
     * @param list<list<bool>> $matrix
     * @param list<list<bool>> $reserved
     *
     * @return list<list<bool>>
     */
    private static function applyMask(array $matrix, array $reserved, int $size, int $mask): array
    {
        for ($row = 0; $row < $size; ++$row) {
            for ($col = 0; $col < $size; ++$col) {
                if ($reserved[$row][$col]) {
                    continue;
                }
                $flip = match ($mask) {
                    0 => 0 === ($row + $col) % 2,
                    1 => 0 === $row % 2,
                    2 => 0 === $col % 3,
                    3 => 0 === ($row + $col) % 3,
                    4 => 0 === (intdiv($row, 2) + intdiv($col, 3)) % 2,
                    5 => 0 === ($row * $col) % 2 + ($row * $col) % 3,
                    6 => 0 === ((($row * $col) % 2 + ($row * $col) % 3) % 2),
                    default => 0 === ((($row + $col) % 2 + ($row * $col) % 3) % 2),
                };
                if ($flip) {
                    $matrix[$row][$col] = !$matrix[$row][$col];
                }
            }
        }

        return $matrix;
    }

    /**
     * Сведения о формате (уровень коррекции и маска) — две копии по стандарту:
     * старший бит ложится в (8,0) и в (size-1,8), младший — в (0,8) и в (8,size-1).
     *
     * @param list<list<bool>> $matrix
     */
    private static function placeFormat(array &$matrix, int $size, int $mask): void
    {
        $bits = self::formatBits($mask);
        for ($i = 0; $i < 15; ++$i) {
            $bit = (bool) (($bits >> $i) & 1);
            // Первая копия — вокруг левого верхнего поискового узора.
            if ($i >= 9) {
                $matrix[8][14 - $i] = $bit;
            } elseif (8 === $i) {
                $matrix[8][7] = $bit;
            } elseif (7 === $i) {
                $matrix[8][8] = $bit;
            } elseif (6 === $i) {
                $matrix[7][8] = $bit;
            } else {
                $matrix[$i][8] = $bit;
            }
            // Вторая копия — восемь младших битов справа в строке 8, старшие снизу в столбце 8.
            if ($i >= 8) {
                $matrix[$size - 15 + $i][8] = $bit;
            } else {
                $matrix[8][$size - 1 - $i] = $bit;
            }
        }
        // Тёмный модуль: он стоит на месте одного из битов второй копии и всегда чёрный.
        $matrix[$size - 8][8] = true;
    }

    private static function formatBits(int $mask): int
    {
        $data = (self::EC_LEVEL_BITS << 3) | $mask;
        $value = $data << 10;
        for ($i = 14; $i >= 10; --$i) {
            if (($value >> $i) & 1) {
                $value ^= 0x537 << ($i - 10); // порождающий многочлен BCH(15,5)
            }
        }

        return (($data << 10) | $value) ^ 0x5412;
    }

    private static function versionBits(int $version): int
    {
        $value = $version << 12;
        for ($i = 17; $i >= 12; --$i) {
            if (($value >> $i) & 1) {
                $value ^= 0x1F25 << ($i - 12);
            }
        }

        return ($version << 12) | $value;
    }

    /** @param list<list<bool>> $matrix */
    private static function penalty(array $matrix, int $size): int
    {
        $penalty = 0;
        // Правило 1: серии одинаковых модулей длиной 5 и больше.
        for ($i = 0; $i < $size; ++$i) {
            foreach ([true, false] as $horizontal) {
                $run = 1;
                for ($j = 1; $j < $size; ++$j) {
                    $current = $horizontal ? $matrix[$i][$j] : $matrix[$j][$i];
                    $previous = $horizontal ? $matrix[$i][$j - 1] : $matrix[$j - 1][$i];
                    if ($current === $previous) {
                        ++$run;
                        continue;
                    }
                    if ($run >= 5) {
                        $penalty += 3 + ($run - 5);
                    }
                    $run = 1;
                }
                if ($run >= 5) {
                    $penalty += 3 + ($run - 5);
                }
            }
        }
        // Правило 2: блоки 2×2 одного цвета.
        for ($row = 0; $row < $size - 1; ++$row) {
            for ($col = 0; $col < $size - 1; ++$col) {
                $value = $matrix[$row][$col];
                if ($value === $matrix[$row][$col + 1] && $value === $matrix[$row + 1][$col] && $value === $matrix[$row + 1][$col + 1]) {
                    $penalty += 3;
                }
            }
        }
        // Правило 3: узор, похожий на поисковый.
        $patterns = [[true, false, true, true, true, false, true, false, false, false, false], [false, false, false, false, true, false, true, true, true, false, true]];
        for ($row = 0; $row < $size; ++$row) {
            for ($col = 0; $col <= $size - 11; ++$col) {
                foreach ($patterns as $pattern) {
                    $horizontal = true;
                    $vertical = true;
                    for ($k = 0; $k < 11; ++$k) {
                        $horizontal = $horizontal && $matrix[$row][$col + $k] === $pattern[$k];
                        $vertical = $vertical && $matrix[$col + $k][$row] === $pattern[$k];
                    }
                    $penalty += ($horizontal ? 40 : 0) + ($vertical ? 40 : 0);
                }
            }
        }
        // Правило 4: перекос доли тёмных модулей.
        $dark = 0;
        foreach ($matrix as $row) {
            foreach ($row as $value) {
                $dark += $value ? 1 : 0;
            }
        }
        $percent = (int) (abs($dark * 100 / ($size * $size) - 50) / 5);

        return $penalty + $percent * 10;
    }
}
