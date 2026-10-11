<?php

declare(strict_types=1);

namespace App\Service\Text;

/**
 * Разбор поискового запроса и подготовка его для индекса FULLTEXT и для подсветки найденного.
 *
 * Морфологии в MySQL/MariaDB нет, поэтому у слов длиннее четырёх символов отбрасывается окончание
 * и добавляется «*»: «инструкции» → «инструкц*» найдёт и «инструкция», и «инструкциями».
 *
 * Фраза в кавычках ("…", «…», “…”, „…“) ищется дословно: слова подряд и в том же порядке, без отбрасывания
 * окончаний («"план эвакуации"» не найдёт «эвакуации план»).
 */
final class SearchQuery
{
    /** Минимальная длина слова: слова короче в индекс FULLTEXT не попадают (innodb_ft_min_token_size). */
    public const MIN_TERM = 3;

    /**
     * Стоп-слова InnoDB по умолчанию (длиной от MIN_TERM): в индекс не попадают, и обязательное такое слово
     * («+the») делает пустым весь результат — в запрос FULLTEXT они не передаются.
     */
    private const STOPWORDS = ['about', 'are', 'com', 'for', 'from', 'how', 'that', 'the', 'this', 'was', 'what', 'when', 'where', 'who', 'will', 'with', 'und', 'www'];

    /** Пары кавычек, которыми выделяют фразу. */
    private const QUOTES = ['"' => '"', '«' => '»', '“' => '”', '„' => '“', "'" => "'"];

    /** @var list<string> слова запроса в нижнем регистре */
    public readonly array $terms;

    /** @var list<string> фразы в кавычках (нижний регистр, одиночные пробелы) */
    public readonly array $phrases;

    /** @var list<string> слова вне кавычек (их ищут с отбрасыванием окончаний) */
    public readonly array $looseTerms;

    private function __construct(array $terms, array $phrases = [], ?array $looseTerms = null)
    {
        $this->terms = $terms;
        $this->phrases = $phrases;
        $this->looseTerms = $looseTerms ?? $terms;
    }

    public static function parse(string $query): self
    {
        $query = mb_strtolower(trim($query));
        $phrases = [];
        $rest = self::extractPhrases($query, $phrases);
        $terms = [];
        $loose = [];
        foreach ([$rest => false, implode(' ', $phrases) => true] as $text => $quoted) {
            foreach (preg_split('/[^\p{L}\p{N}_-]+/u', (string) $text) ?: [] as $word) {
                $word = trim($word, '-_');
                if (mb_strlen($word) < 2) {
                    continue;
                }
                if (!\in_array($word, $terms, true)) {
                    $terms[] = $word;
                }
                if (!$quoted && !\in_array($word, $loose, true)) {
                    $loose[] = $word;
                }
            }
        }

        return new self(\array_slice($terms, 0, 8), \array_slice($phrases, 0, 3), \array_slice($loose, 0, 8));
    }

    /**
     * Вынимает из запроса фразы в кавычках; возвращает остаток запроса. Непарная кавычка — обычный символ.
     *
     * @param list<string> $phrases
     */
    private static function extractPhrases(string $query, array &$phrases): string
    {
        $rest = '';
        $length = mb_strlen($query);
        for ($i = 0; $i < $length; ++$i) {
            $char = mb_substr($query, $i, 1);
            $close = self::QUOTES[$char] ?? null;
            // Апостроф внутри слова (д'Артаньян) кавычкой не считается.
            if (null !== $close && "'" === $char && $i > 0 && 1 === preg_match('/[\p{L}\p{N}]/u', mb_substr($query, $i - 1, 1))) {
                $close = null;
            }
            $end = null === $close ? false : mb_strpos($query, $close, $i + 1);
            if (false === $end) {
                $rest .= $char;
                continue;
            }
            $phrase = trim((string) preg_replace('/\s+/u', ' ', mb_substr($query, $i + 1, $end - $i - 1)));
            if (mb_strlen($phrase) >= 2 && !\in_array($phrase, $phrases, true)) {
                $phrases[] = $phrase;
            }
            $rest .= ' ';
            $i = $end;
        }

        return $rest;
    }

    /** Запрос без фраз в кавычках (для поиска по словам в реквизитах). */
    public static function withoutPhrases(string $query): string
    {
        $phrases = [];

        return self::extractPhrases(mb_strtolower(trim($query)), $phrases);
    }

    /** Есть ли в запросе фразы в кавычках. */
    public function hasPhrases(): bool
    {
        return [] !== $this->phrases;
    }

    /**
     * Текст для дословного сравнения фраз: нижний регистр, «ё» как «е», знаки препинания и пробелы — один
     * пробел (так «ПР-2026/01» совпадает с «пр 2026 01», как их делит индекс FULLTEXT).
     */
    public static function normalizeForPhrase(string $text): string
    {
        return trim((string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', str_replace('ё', 'е', mb_strtolower($text))));
    }

    /** Содержит ли текст все фразы запроса (целыми словами). */
    public function matchesPhrases(?string $text): bool
    {
        if ([] === $this->phrases) {
            return true;
        }
        $haystack = ' '.self::normalizeForPhrase((string) $text).' ';
        foreach ($this->phrases as $phrase) {
            $needle = self::normalizeForPhrase($phrase);
            if ('' !== $needle && !str_contains($haystack, ' '.$needle.' ')) {
                return false;
            }
        }

        return true;
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
        foreach ($this->looseTerms as $term) {
            foreach (preg_split('/[^\p{L}\p{N}]+/u', $term) ?: [] as $part) {
                if (self::indexable($part) && !\in_array($part, $out, true)) {
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
        // Фраза — в кавычках, слова через пробел (разделители индекс всё равно не хранит). Короткие слова
        // и стоп-слова индекс не хранит, а фраза в FULLTEXT требует слов строго подряд: поэтому фраза делится
        // на них — подряд идущие индексируемые слова ищутся фразой, одиночные — словом, а точное совпадение
        // всей фразы проверяется потом по тексту (matchesPhrases()).
        foreach ($this->phrases as $phrase) {
            $run = [];
            foreach ([...explode(' ', self::normalizeForPhrase($phrase)), ''] as $word) {
                if ('' !== $word && self::indexable($word)) {
                    $run[] = $word;
                    continue;
                }
                if (\count($run) > 1) {
                    $parts[] = '+"'.implode(' ', $run).'"';
                } elseif (1 === \count($run)) {
                    $parts[] = '+'.$run[0];
                }
                $run = [];
            }
        }

        return implode(' ', array_values(array_unique($parts)));
    }

    /** Попадает ли слово в индекс FULLTEXT (не короче MIN_TERM и не стоп-слово InnoDB). */
    private static function indexable(string $word): bool
    {
        return mb_strlen($word) >= self::MIN_TERM && !\in_array($word, self::STOPWORDS, true);
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
        // Сначала — фраза: фрагмент показывается вокруг неё, а не вокруг первого попавшегося слова.
        foreach ($this->phrases as $phrase) {
            $at = mb_stripos($text, $phrase);
            if (false !== $at && (null === $position || $at < $position)) {
                $position = $at;
                $found = $phrase;
            }
        }
        foreach (null === $position ? $this->terms : [] as $term) {
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
        foreach ($this->phrases as $phrase) {
            // Фраза подсвечивается целиком (пробелы в тексте фрагмента уже сведены к одному).
            $stems[] = preg_quote(htmlspecialchars($phrase, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8'), '/');
        }
        foreach ($this->looseTerms as $term) {
            $stems[] = preg_quote(htmlspecialchars(self::stem($term), \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8'), '/');
        }
        usort($stems, static fn (string $a, string $b): int => \strlen($b) <=> \strlen($a));
        $pattern = '/(?:'.implode('|', $stems).')\p{L}*/iu';

        return preg_replace($pattern, '<mark>$0</mark>', $escaped) ?? $escaped;
    }
}
