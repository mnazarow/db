<?php

declare(strict_types=1);

namespace App\Service\Llm;

use App\Entity\Document;
use App\Entity\User;
use App\Repository\DocumentRepository;
use App\Repository\DocumentTextRepository;
use App\Security\Access;
use App\Service\PortalSettings;
use App\Service\Text\SearchQuery;
use App\Service\Video\VideoApi;
use Psr\Log\LoggerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Ответы на вопросы сотрудников по документам и видео портала (страница поиска, кнопка «Ответить»).
 *
 * Схема — поиск с дополнением (RAG) без отдельной векторной базы: слова вопроса ищутся в содержимом файлов
 * (индекс FULLTEXT, «хотя бы одно слово»), в названиях и реквизитах документов и в расшифровках речи видео;
 * из найденного берутся фрагменты вокруг совпадений — только из документов и видео, которые этот
 * сотрудник может открыть. Модель отвечает строго по фрагментам и ссылается на них номерами [1], [2]…
 * Ничего не найдено — модель не вызывается.
 */
final class AnswerService
{
    /** Документов-источников в одном ответе. */
    public const MAX_DOCUMENTS = 5;

    /** Видео-источников в одном ответе. */
    public const MAX_VIDEOS = 2;

    /** Символов текста одного источника. */
    private const SOURCE_CHARS = 1800;

    /** Служебные слова вопроса — по ним не ищется. */
    private const STOPWORDS = ['как', 'какой', 'какая', 'какое', 'какие', 'каков', 'каковы', 'что', 'чем', 'чего', 'где', 'когда', 'кто', 'кого', 'кому',
        'почему', 'зачем', 'куда', 'откуда', 'сколько', 'можно', 'нужно', 'надо', 'ли', 'для', 'при', 'это', 'этот', 'эта', 'эти', 'есть', 'мне', 'нам',
        'наш', 'наша', 'наши', 'нашей', 'вас', 'вам', 'ваш', 'его', 'её', 'ее', 'их', 'они', 'она', 'оно', 'был', 'была', 'были', 'будет', 'быть',
        'или', 'если', 'чтобы', 'тоже', 'также', 'так', 'такой', 'такие', 'уже', 'ещё', 'еще', 'все', 'всё', 'всех', 'весь', 'вся', 'без', 'под',
        'над', 'про', 'после', 'перед', 'между', 'через', 'около', 'который', 'которая', 'которые', 'кроме', 'только', 'очень', 'сейчас', 'тогда',
        'расскажи', 'подскажи', 'подскажите', 'скажи', 'скажите', 'пожалуйста', 'объясни', 'объясните', 'найди', 'найдите', 'покажи', 'нас', 'себя',
        'свой', 'своя', 'свои', 'делать', 'сделать', 'the', 'and', 'what', 'how', 'where', 'when', 'who', 'why', 'for', 'with', 'from'];

    private const SYSTEM = <<<'PROMPT'
        Ты — помощник корпоративного портала документации. Отвечай на вопрос сотрудника на русском языке, кратко и по делу
        (обычно 2–6 предложений или короткий список шагов), опираясь ТОЛЬКО на приведённые фрагменты документов и видео.
        После каждого утверждения указывай номер источника в квадратных скобках, например [1] или [2][3].
        Если во фрагментах нет ответа, так и скажи: «В документах портала ответа не нашлось» — и предложи, где уточнить
        (например, у ответственного за документ). Не придумывай сведений, которых нет во фрагментах, не цитируй
        фрагменты целиком и не упоминай слово «фрагмент» — говори «документ» или «видео».
        PROMPT;

    public function __construct(
        private readonly LlmClient $llm,
        private readonly PortalSettings $settings,
        private readonly DocumentRepository $documents,
        private readonly DocumentTextRepository $texts,
        private readonly Access $access,
        private readonly UrlGeneratorInterface $urls,
        private readonly LoggerInterface $logger,
        private readonly ?VideoApi $videoApi = null,
    ) {
    }

    /** Включены ли ответы (LLM настроена и ответы не выключены в «Интеграциях»). */
    public function isEnabled(): bool
    {
        $cfg = $this->settings->llm();

        return $cfg['enabled'] && $cfg['answers'];
    }

    /**
     * Слова вопроса, по которым ищутся источники.
     *
     * @return list<string>
     */
    public static function keywords(string $question): array
    {
        $out = [];
        foreach (preg_split('/[^\p{L}\p{N}-]+/u', mb_strtolower($question)) ?: [] as $word) {
            $word = trim($word, '-');
            if (mb_strlen($word) < 3 || \in_array($word, self::STOPWORDS, true) || \in_array($word, $out, true)) {
                continue;
            }
            $out[] = $word;
        }

        return \array_slice($out, 0, 12);
    }

    /**
     * Ответ на вопрос: текст модели (с номерами источников) и список источников.
     *
     * @return array{answer: ?string, sources: list<array{n: int, type: string, title: string, url: string, meta: string}>, error: ?string}
     */
    public function answer(User $user, string $question): array
    {
        $question = trim((string) preg_replace('/\s+/u', ' ', $question));
        if (mb_strlen($question) < 3) {
            return ['answer' => null, 'sources' => [], 'error' => 'Задайте вопрос подробнее.'];
        }
        if (!$this->isEnabled()) {
            return ['answer' => null, 'sources' => [], 'error' => 'Ответы на вопросы выключены администратором.'];
        }
        $sources = $this->collect($user, mb_substr($question, 0, 500));
        if ([] === $sources) {
            return ['answer' => null, 'sources' => [], 'error' => 'В документах и видео портала не нашлось материалов по этому вопросу — попробуйте сформулировать иначе или воспользуйтесь обычным поиском.'];
        }
        $budget = max(2000, $this->settings->llm()['max_input_chars']);
        $context = '';
        $out = [];
        foreach ($sources as $i => $source) {
            $block = \sprintf("[%d] %s «%s»%s:\n%s\n\n", $i + 1, 'video' === $source['type'] ? 'Видео' : 'Документ', $source['title'], '' !== $source['meta'] ? ' ('.$source['meta'].')' : '', $source['text']);
            if ('' !== $context && mb_strlen($context) + mb_strlen($block) > $budget) {
                break;
            }
            $context .= $block;
            $out[] = ['n' => $i + 1, 'type' => $source['type'], 'title' => $source['title'], 'url' => $source['url'], 'meta' => $source['meta']];
        }
        try {
            $answer = $this->llm->complete(self::SYSTEM, "Вопрос сотрудника: {$question}\n\nИсточники:\n\n".rtrim($context), 700);
        } catch (LlmException $e) {
            $this->logger->warning('Ответ на вопрос не получен', ['error' => $e->getMessage()]);

            return ['answer' => null, 'sources' => $out, 'error' => 'Языковая модель сейчас не отвечает — посмотрите найденные материалы ниже или повторите позже.'];
        }

        return ['answer' => $answer, 'sources' => $out, 'error' => null];
    }

    /**
     * Безопасный HTML ответа: текст экранируется, «**жирный**» и маркированные строки сохраняются, номера
     * источников [n] становятся ссылками на них.
     *
     * @param list<array{n: int, type: string, title: string, url: string, meta: string}> $sources
     */
    public static function toHtml(string $answer, array $sources): string
    {
        $byNumber = [];
        foreach ($sources as $source) {
            $byNumber[$source['n']] = $source;
        }
        $html = htmlspecialchars(trim($answer), \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');
        $html = (string) preg_replace('/\*\*(.+?)\*\*/u', '<b>$1</b>', $html);
        $html = (string) preg_replace_callback('/\[(\d{1,2})\]/', static function (array $m) use ($byNumber): string {
            $source = $byNumber[(int) $m[1]] ?? null;

            return null === $source ? $m[0] : \sprintf('<a class="ai-answer__ref" href="%s" title="%s">[%d]</a>',
                htmlspecialchars($source['url'], \ENT_QUOTES, 'UTF-8'), htmlspecialchars($source['title'], \ENT_QUOTES, 'UTF-8'), $source['n']);
        }, $html);
        $paragraphs = [];
        foreach (preg_split('/\n{2,}/u', $html) ?: [] as $block) {
            $lines = array_values(array_filter(array_map('trim', explode("\n", $block)), static fn (string $l): bool => '' !== $l));
            if ([] === $lines) {
                continue;
            }
            $isList = [] === array_filter($lines, static fn (string $l): bool => !preg_match('/^([-•*]|\d+[.)])\s+/u', $l));
            $paragraphs[] = $isList
                ? '<ul>'.implode('', array_map(static fn (string $l): string => '<li>'.preg_replace('/^([-•*]|\d+[.)])\s+/u', '', $l).'</li>', $lines)).'</ul>'
                : '<p>'.implode('<br>', $lines).'</p>';
        }

        return implode('', $paragraphs);
    }

    /**
     * Источники: документы (сначала найденные по содержимому, затем по названию и реквизитам) и видео.
     *
     * @return list<array{type: string, title: string, url: string, meta: string, text: string}>
     */
    private function collect(User $user, string $question): array
    {
        $words = self::keywords($question);
        if ([] === $words) {
            return [];
        }
        $viewer = $this->access->viewer($user);
        $statuses = [Document::STATUS_PUBLISHED];
        /** @var array<int, array{document: Document, score: float, text: ?string}> $found */
        $found = [];
        foreach ($this->documents->searchContentAny($words, $statuses, 12, false, $viewer) as $hit) {
            $found[(int) $hit['document']->getId()] = ['document' => $hit['document'], 'score' => 100 + $hit['score'], 'text' => $hit['text']];
        }
        // Название, обозначение, теги, описание, текст страниц: каждое слово отдельно, документы с бо́льшим числом совпадений — выше.
        foreach (\array_slice($words, 0, 5) as $word) {
            foreach ($this->documents->search(SearchQuery::stem($word), $statuses, null, 15, false, $viewer) as $document) {
                $id = (int) $document->getId();
                $found[$id] ??= ['document' => $document, 'score' => 0.0, 'text' => null];
                $found[$id]['score'] += 1;
            }
        }
        uasort($found, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);
        $sources = [];
        foreach ($found as $hit) {
            $document = $hit['document'];
            if (!$this->access->canViewDocument($user, $document)) {
                continue;
            }
            $text = self::fragments($hit['text'] ?? $this->documentText($document), $words);
            if ('' === $text) {
                continue;
            }
            $version = $document->getCurrentVersion();
            $sources[] = [
                'type' => 'document',
                'title' => (string) $document->getTitle(),
                'url' => $this->urls->generate('app_document_show', ['id' => $document->getId()]),
                'meta' => implode(', ', array_filter([(string) $document->getCode(), $document->getSection()->getFullName(), null !== $version ? 'редакция '.$version->getNumber() : ''])),
                'text' => $text,
            ];
            if (\count($sources) >= self::MAX_DOCUMENTS) {
                break;
            }
        }
        if (null !== $this->videoApi) {
            foreach ($this->videoApi->context($question, $user->isActive(), self::MAX_VIDEOS) as $video) {
                $text = self::fragments($video['text'], $words);
                if ('' !== $text) {
                    $sources[] = ['type' => 'video', 'title' => $video['title'], 'url' => $video['url'], 'meta' => '' !== $video['channel'] ? 'канал «'.$video['channel'].'»' : '', 'text' => $text];
                }
            }
        }

        return $sources;
    }

    /** Текст документа: индекс содержимого, иначе текст страницы, иначе описание. */
    private function documentText(Document $document): string
    {
        // Описание документа (часто — краткая суть) идёт первым, за ним текст файла или страницы.
        $description = trim((string) $document->getDescription());
        $content = '';
        $indexed = $this->texts->findForDocument($document);
        if (null !== $indexed && '' !== trim($indexed->getContent())) {
            $content = $indexed->getContent();
        } else {
            $html = $document->getCurrentVersion()?->getContent();
            if (null !== $html && '' !== trim($html)) {
                $content = html_entity_decode(strip_tags(str_replace(['</p>', '<br>', '</li>', '</h2>', '</h3>'], "\n", $html)), \ENT_QUOTES | \ENT_HTML5, 'UTF-8');
            }
        }

        return trim(('' !== $description ? $description."\n" : '').$content);
    }

    /**
     * Фрагменты текста вокруг слов вопроса (окна по ~350 символов, соседние склеиваются), не длиннее SOURCE_CHARS.
     * Слова не найдены — начало текста.
     *
     * @param list<string> $words
     */
    public static function fragments(?string $text, array $words): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', (string) $text));
        if ('' === $text) {
            return '';
        }
        $length = mb_strlen($text);
        if ($length <= self::SOURCE_CHARS) {
            return $text;
        }
        $lower = mb_strtolower($text);
        $ranges = [];
        foreach ($words as $word) {
            $stem = SearchQuery::stem($word);
            $offset = 0;
            $hits = 0;
            while ($hits < 4 && false !== ($at = mb_strpos($lower, $stem, $offset))) {
                $ranges[] = [max(0, $at - 350), min($length, $at + mb_strlen($stem) + 350)];
                $offset = $at + mb_strlen($stem);
                ++$hits;
            }
        }
        if ([] === $ranges) {
            return mb_substr($text, 0, self::SOURCE_CHARS).'…';
        }
        usort($ranges, static fn (array $a, array $b): int => $a[0] <=> $b[0]);
        $merged = [];
        foreach ($ranges as [$start, $end]) {
            $last = \count($merged) - 1;
            if ($last >= 0 && $start <= $merged[$last][1] + 40) {
                $merged[$last][1] = max($merged[$last][1], $end);
            } else {
                $merged[] = [$start, $end];
            }
        }
        // Сначала окна с наибольшим числом слов вопроса.
        usort($merged, static function (array $a, array $b) use ($lower, $words): int {
            $score = static function (array $r) use ($lower, $words): int {
                $chunk = mb_substr($lower, $r[0], $r[1] - $r[0]);
                $n = 0;
                foreach ($words as $w) {
                    $n += mb_substr_count($chunk, SearchQuery::stem($w));
                }

                return $n;
            };

            return $score($b) <=> $score($a);
        });
        $out = [];
        $total = 0;
        foreach ($merged as [$start, $end]) {
            $piece = mb_substr($text, $start, $end - $start);
            if ($total + mb_strlen($piece) > self::SOURCE_CHARS) {
                $piece = mb_substr($piece, 0, max(0, self::SOURCE_CHARS - $total));
            }
            if ('' === $piece) {
                break;
            }
            $out[] = ($start > 0 ? '…' : '').$piece.($end < $length ? '…' : '');
            $total += mb_strlen($piece);
            if ($total >= self::SOURCE_CHARS) {
                break;
            }
        }

        return implode(' ', $out);
    }
}
