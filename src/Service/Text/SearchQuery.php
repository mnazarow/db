<?php

declare(strict_types=1);

namespace App\Service\Text;

/**
 * Разбор поискового запроса и подготовка его для индекса FULLTEXT и для подсветки найденного.
 *
 * Морфологии в MySQL/MariaDB нет, поэтому у слов длиннее четырёх символов отбрасывается окончание
 * и добавляется «*»: «инструкции» → «инструкц*» найдёт и «инструкция», и «инструкциями».
 */
final class SearchQuery
{
    /** Минимальная длина слова: слова короче в индекс FULLTEXT не попадают (innodb_ft_min_token_size). */
    public const MIN_TERM = 3;

    /** @var list<string> слова запроса в нижнем регистре */
    public readonly array $terms;

    private function __construct(array $terms)
    {
        $this->terms = $terms;
    }

    public static function parse(string $query): self
    {
        $words = preg_split('/[^\p{L}\p{N}_-]+/u', mb_strtolower(trim($query))) ?: [];
        $terms = [];
        foreach ($words as $word) {
            $word = trim($word, '-_');
            if (mb_strlen($word) >= 2 && !\in_array($word, $terms, true)) {
                $terms[] = $word;
            }
        }

        return new self(\array_slice($terms, 0, 8));
    }

    public function isEmpty(): bool
    {
        return [] === $this->terms;
    }

    /**
     * Слова, пригодные для индекса FULLTEXT: только буквы и цифры (как их делит разборщик MySQL)
     * и не короче MIN_TERM. Обозначения вроде «ПР-2026-01» распадаются на части: дефис в булевом
     * режиме — оператор «кроме», и передавать его в запрос нельзя.
     *
     * @return list<string>
     */
    public function indexableTerms(): array
    {
        $out = [];
        foreach ($this->terms as $term) {
            foreach (preg_split('/[^\p{L}\p{N}]+/u', $term) ?: [] as $part) {
                if (mb_strlen($part) >= self::MIN_TERM && !\in_array($part, $out, true)) {
                    $out[] = $part;
                }
            }
        }

        return $out;
    }

    /**
     * Запрос для MATCH … AGAINST (… IN BOOLEAN MODE): все слова обязательны, у длинных отбрасывается окончание.
     * Пустая строка — искать по индексу нечего.
     */
    public function booleanMode(): string
    {
        $parts = [];
        foreach ($this->indexableTerms() as $term) {
            $parts[] = '+'.self::stem($term).'*';
        }

        return implode(' ', $parts);
    }

    /** Основа слова для поиска по префиксу: у длинных слов отбрасываются 1–2 последних символа. */
    public static function stem(string $term): string
    {
        $length = mb_strlen($term);
        if ($length >= 6) {
            return mb_substr($term, 0, $length - 2);
        }
        if ($length >= 5) {
            return mb_substr($term, 0, $length - 1);
        }

        return $term;
    }

    /**
     * Фрагмент текста вокруг первого найденного слова с подсветкой (<mark>), пригодный для вывода в шаблоне.
     * Возвращает null, если ни одно слово не найдено.
     */
    public function snippet(?string $text, int $radius = 140): ?string
    {
        $text = trim(preg_replace('/\s+/u', ' ', (string) $text) ?? '');
        if ('' === $text) {
            return null;
        }
        $position = null;
        $found = '';
        foreach ($this->terms as $term) {
            $stem = self::stem($term);
            $at = mb_stripos($text, $stem);
            if (false !== $at && (null === $position || $at < $position)) {
                $position = $at;
                $found = $stem;
            }
        }
        if (null === $position) {
            return null;
        }
        $start = max(0, $position - $radius);
        $length = mb_strlen($found) + 2 * $radius;
        $fragment = mb_substr($text, $start, $length);
        if ($start > 0) {
            $fragment = '…'.preg_replace('/^\S*\s/u', '', $fragment);
        }
        if ($start + $length < mb_strlen($text)) {
            $fragment = (preg_replace('/\s\S*$/u', '', $fragment) ?? $fragment).'…';
        }
        $escaped = htmlspecialchars($fragment, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');
        // Подсветка — за один проход: иначе основа вроде «mar» попала бы внутрь уже вставленного тега <mark>.
        $stems = [];
        foreach ($this->terms as $term) {
            $stems[] = preg_quote(htmlspecialchars(self::stem($term), \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8'), '/');
        }
        usort($stems, static fn (string $a, string $b): int => \strlen($b) <=> \strlen($a));
        $pattern = '/(?:'.implode('|', $stems).')\p{L}*/iu';

        return preg_replace($pattern, '<mark>$0</mark>', $escaped) ?? $escaped;
    }
}
