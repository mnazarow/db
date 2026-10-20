<?php

declare(strict_types=1);

namespace App\Service\Price;

/**
 * Разбор листа прайс-листа: строка заголовков и роли столбцов (определяются сами, их можно поправить),
 * затем строки → позиции. Строка без цены и артикула, но с текстом в одной-двух ячейках — заголовок группы
 * («Насосные станции», «Комплектующие»): он становится группой следующих позиций.
 *
 * Сопоставление (mapping): ['header' => номер строки заголовков (0 — без заголовков),
 *   'columns' => [номер столбца => ['role' => sku|name|unit|price|currency|group|extra|skip, 'label' => подпись]]].
 */
final class PriceMapper
{
    public const ROLES = [
        'skip' => 'Не загружать',
        'sku' => 'Артикул (код)',
        'name' => 'Наименование',
        'unit' => 'Единица',
        'price' => 'Цена',
        'group' => 'Группа',
        'extra' => 'Доп. столбец',
    ];

    /** Сколько первых строк просматривать в поисках заголовков. */
    private const HEADER_SCAN = 40;

    private const PATTERNS = [
        'sku' => '/^(арт(икул)?\.?|код( товара| позиции)?|артикул производителя|каталожный номер|кат\.? ?№|sku|part ?(no|number)|№ по каталогу|модель)$|артикул|^код\b/iu',
        'name' => '/наименован|название|номенклатур|^товар|продукци|^описание$|^позиция$|^изделие|name/iu',
        'unit' => '/^ед\.?|единиц|^упак|^unit/iu',
        'price' => '/цен|стоимост|ррц|^опт|розн|дилер|прайс|руб|usd|eur|\bprice\b|^сумма|ндс/iu',
        'group' => '/^групп|^категори|^раздел|^серия|^семейство/iu',
    ];

    /**
     * Сопоставление по содержимому листа: строка заголовков и роли столбцов; $previous — сопоставление прошлой
     * загрузки (роли переносятся по совпадающим подписям столбцов).
     *
     * $header — строка заголовков, указанная вручную (0 — заголовков нет), null — найти самому.
     *
     * @param array<int, array<int, string>> $rows
     * @param array<string, mixed>|null      $previous
     *
     * @return array{header: int, columns: array<int, array{role: string, label: string}>}
     */
    public function guess(array $rows, ?array $previous = null, ?int $header = null): array
    {
        $fixed = null !== $header;
        $header = max(0, (int) $header);
        $bestScore = 0;
        $scanned = 0;
        foreach ($rows as $r => $cells) {
            if ($fixed || ++$scanned > self::HEADER_SCAN) {
                break;
            }
            $score = 0;
            $roles = [];
            foreach ($cells as $value) {
                $role = self::roleOf($value);
                if (null !== $role) {
                    ++$score;
                    $roles[$role] = true;
                }
            }
            // Заголовки — строка, где есть наименование или цена и ещё хотя бы одна узнаваемая подпись.
            if ($score >= 2 && (isset($roles['name']) || isset($roles['price'])) && $score > $bestScore) {
                $bestScore = $score;
                $header = $r;
            }
        }
        $columns = [];
        $labels = $header > 0 ? ($rows[$header] ?? []) : [];
        $maxCol = 0;
        foreach ($rows as $cells) {
            $maxCol = max($maxCol, [] === $cells ? 0 : max(array_keys($cells)));
        }
        $maxCol = min($maxCol, 60);
        $prevByLabel = [];
        foreach ((array) ($previous['columns'] ?? []) as $col) {
            if (\is_array($col) && isset($col['label'], $col['role']) && '' !== $col['label']) {
                $prevByLabel[mb_strtolower(trim((string) $col['label']))] = (string) $col['role'];
            }
        }
        for ($c = 1; $c <= $maxCol; ++$c) {
            $label = trim((string) ($labels[$c] ?? ''));
            $role = $prevByLabel[mb_strtolower($label)] ?? ('' !== $label ? self::roleOf($label) : null);
            $columns[$c] = ['role' => $role ?? ('' !== $label ? 'extra' : 'skip'), 'label' => '' !== $label ? $label : self::letter($c)];
        }
        $columns = $this->byContent($rows, $header, $columns);

        return ['header' => $header, 'columns' => $columns];
    }

    /**
     * Позиции листа по сопоставлению.
     *
     * @param array<int, array<int, string>>                                          $rows
     * @param array{header: int, columns: array<int, array{role: string, label: string}>} $mapping
     *
     * @return array{items: list<array{sku: ?string, name: string, group: ?string, unit: ?string, prices: list<string>, price: ?string, extra: list<string>}>, priceColumns: list<string>, extraColumns: list<string>, warnings: list<string>, groups: int}
     */
    public function apply(array $rows, array $mapping): array
    {
        $cols = ['sku' => [], 'name' => [], 'unit' => [], 'price' => [], 'group' => [], 'extra' => []];
        foreach ($mapping['columns'] as $c => $col) {
            $role = (string) ($col['role'] ?? 'skip');
            if (isset($cols[$role])) {
                $cols[$role][(int) $c] = trim((string) ($col['label'] ?? self::letter((int) $c)));
            }
        }
        if ([] === $cols['name']) {
            throw new \InvalidArgumentException('Укажите столбец с наименованием.');
        }
        if ([] === $cols['price']) {
            throw new \InvalidArgumentException('Укажите хотя бы один столбец с ценой.');
        }
        $header = (int) $mapping['header'];
        $items = [];
        $warnings = [];
        $group = null;
        $groups = 0;
        $noPrice = 0;
        $seen = [];
        $duplicates = 0;
        foreach ($rows as $r => $cells) {
            if ($r <= $header) {
                continue;
            }
            $name = self::join($cells, array_keys($cols['name']));
            $sku = self::join($cells, array_keys($cols['sku']));
            $prices = [];
            $hasPrice = false;
            foreach (array_keys($cols['price']) as $c) {
                $value = self::price($cells[$c] ?? '');
                $prices[] = $value;
                $hasPrice = $hasPrice || '' !== $value;
            }
            $explicitGroup = self::join($cells, array_keys($cols['group']));
            if ('' !== $explicitGroup) {
                $group = mb_substr($explicitGroup, 0, 255);
            }
            if (!$hasPrice && '' === $sku) {
                // Заголовок группы: одна-две непустые ячейки с текстом, без цены и артикула.
                $texts = array_values(array_filter($cells, static fn (string $v): bool => '' !== $v));
                if ([] !== $texts && \count($texts) <= 2 && !is_numeric(str_replace([' ', ','], ['', '.'], $texts[0])) && mb_strlen($texts[0]) <= 255) {
                    $group = $texts[0];
                    ++$groups;
                }
                continue;
            }
            if ('' === $name) {
                $name = '' !== $sku ? $sku : '';
                if ('' === $name) {
                    continue;
                }
            }
            $key = mb_strtolower('' !== $sku ? 'sku:'.$sku : 'name:'.$name);
            if (isset($seen[$key])) {
                ++$duplicates;
            }
            $seen[$key] = true;
            $numeric = null;
            foreach ($prices as $p) {
                if (is_numeric($p)) {
                    $numeric = $p;
                    break;
                }
            }
            if (null === $numeric) {
                ++$noPrice;
            }
            $items[] = [
                'sku' => '' !== $sku ? mb_substr($sku, 0, 100) : null,
                'name' => mb_substr($name, 0, 1000),
                'group' => null !== $group ? mb_substr($group, 0, 255) : null,
                'unit' => self::nullable(self::join($cells, array_keys($cols['unit'])), 32),
                'prices' => $prices,
                'price' => $numeric,
                'extra' => array_map(static fn (int $c): string => mb_substr((string) ($cells[$c] ?? ''), 0, 500), array_keys($cols['extra'])),
            ];
        }
        if ([] === $items) {
            throw new \InvalidArgumentException('На листе не нашлось позиций с наименованием — проверьте строку заголовков и столбцы.');
        }
        if ($noPrice > 0) {
            $warnings[] = \sprintf('Без цены: %d %s (показываются с прочерком; «по запросу» и подобный текст сохраняется как есть).', $noPrice, self::plural($noPrice, 'позиция', 'позиции', 'позиций'));
        }
        if ($duplicates > 0) {
            $warnings[] = \sprintf('Повторяются артикулы или наименования: %d — при сравнении с прошлой редакцией учитывается последняя.', $duplicates);
        }

        return ['items' => $items, 'priceColumns' => array_values($cols['price']), 'extraColumns' => array_values($cols['extra']), 'warnings' => $warnings, 'groups' => $groups];
    }

    /**
     * Цена из ячейки: «1 234,56 ₽» → «1234.56»; текст («по запросу») — как есть; пусто — «».
     */
    public static function price(string $value): string
    {
        $value = trim(str_replace(["\u{00A0}", "\u{202F}"], ' ', $value));
        if ('' === $value) {
            return '';
        }
        $clean = (string) preg_replace('/(руб(\.|лей|ля)?|р\.|₽|\$|€|usd|eur|rub|у\.\s?е\.)/iu', '', $value);
        $clean = str_replace([' ', "'"], '', trim($clean));
        if (preg_match('/^-?\d{1,3}(,\d{3})+(\.\d+)?$/', $clean)) {
            $clean = str_replace(',', '', $clean); // 1,234.56
        } elseif (preg_match('/^-?\d{1,3}(\.\d{3})+(,\d+)?$/', $clean)) {
            $clean = str_replace(['.', ','], ['', '.'], $clean); // 1.234,56
        } else {
            $clean = str_replace(',', '.', $clean);
        }
        if (is_numeric($clean) && abs((float) $clean) < 1e13) {
            return rtrim(rtrim(number_format((float) $clean, 2, '.', ''), '0'), '.');
        }

        return mb_substr($value, 0, 100);
    }

    public static function roleOf(string $label): ?string
    {
        $label = trim(mb_strtolower(str_replace("\n", ' ', $label)));
        if ('' === $label || mb_strlen($label) > 80) {
            return null;
        }
        if (preg_match('/^(№|n|#|nn)\s*(п\.?\s?\/?\s?п\.?)?$|^п\s?\/\s?п$/iu', $label)) {
            return 'skip'; // номер строки по порядку
        }
        foreach (['sku', 'unit', 'group', 'price', 'name'] as $role) {
            if (preg_match(self::PATTERNS[$role], $label)) {
                // «Цена за ед.» — цена, а не единица; «Код ТН ВЭД» — дополнительный столбец.
                if ('unit' === $role && preg_match(self::PATTERNS['price'], $label)) {
                    return 'price';
                }
                if ('sku' === $role && preg_match('/тн ?вэд|окпд|штрих|ean|barcode/iu', $label)) {
                    return 'extra';
                }

                return $role;
            }
        }

        return null;
    }

    public static function letter(int $col): string
    {
        $s = '';
        for ($n = $col; $n > 0; $n = intdiv($n - 1, 26)) {
            $s = \chr(65 + ($n - 1) % 26).$s;
        }

        return $s;
    }

    /**
     * Без заголовков (или когда подписи не узнаны): самый «текстовый» столбец — наименование, числовые — цены.
     *
     * @param array<int, array<int, string>>                   $rows
     * @param array<int, array{role: string, label: string}> $columns
     *
     * @return array<int, array{role: string, label: string}>
     */
    private function byContent(array $rows, int $header, array $columns): array
    {
        $roles = array_column($columns, 'role');
        $hasName = \in_array('name', $roles, true);
        $hasPrice = \in_array('price', $roles, true);
        if ($hasName && $hasPrice) {
            return $columns;
        }
        $stats = [];
        $n = 0;
        foreach ($rows as $r => $cells) {
            if ($r <= $header) {
                continue;
            }
            if (++$n > 300) {
                break;
            }
            foreach ($cells as $c => $v) {
                $stats[$c] ??= ['num' => 0, 'text' => 0, 'len' => 0];
                if (is_numeric(self::price($v))) {
                    ++$stats[$c]['num'];
                } else {
                    ++$stats[$c]['text'];
                    $stats[$c]['len'] += mb_strlen($v);
                }
            }
        }
        if (!$hasName) {
            $best = null;
            foreach ($stats as $c => $s) {
                if (isset($columns[$c]) && \in_array($columns[$c]['role'], ['skip', 'extra'], true) && (null === $best || $s['len'] > $stats[$best]['len'])) {
                    $best = $c;
                }
            }
            if (null !== $best) {
                $columns[$best]['role'] = 'name';
            }
        }
        if (!$hasPrice) {
            foreach ($stats as $c => $s) {
                if (isset($columns[$c]) && \in_array($columns[$c]['role'], ['skip', 'extra'], true) && $s['num'] > 0 && $s['num'] >= 2 * $s['text']) {
                    $columns[$c]['role'] = 'price';
                    break;
                }
            }
        }

        return $columns;
    }

    /**
     * @param array<int, string> $cells
     * @param list<int>          $cols
     */
    private static function join(array $cells, array $cols): string
    {
        $parts = [];
        foreach ($cols as $c) {
            $v = trim((string) ($cells[$c] ?? ''));
            if ('' !== $v) {
                $parts[] = $v;
            }
        }

        return trim((string) preg_replace('/\s+/u', ' ', implode(' ', $parts)));
    }

    private static function nullable(string $value, int $max): ?string
    {
        return '' !== $value ? mb_substr($value, 0, $max) : null;
    }

    private static function plural(int $n, string $one, string $few, string $many): string
    {
        $a = $n % 100;
        $b = $n % 10;

        return ($a >= 11 && $a <= 14) ? $many : (1 === $b ? $one : ($b >= 2 && $b <= 4 ? $few : $many));
    }
}
