<?php

declare(strict_types=1);

namespace App\Service\Llm;

use App\Entity\AiQuestion;
use App\Entity\Document;
use App\Entity\User;
use App\Repository\AiQuestionRepository;
use App\Repository\DocumentRepository;
use App\Repository\DocumentTextRepository;
use App\Security\Access;
use App\Service\PortalSettings;
use App\Service\Text\SearchQuery;
use App\Service\Video\VideoApi;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Ответы на вопросы сотрудников по документам и видео портала (страница поиска, кнопка «Ответить»).
 *
 * Схема — поиск с дополнением (RAG). Источники ищутся двумя способами и объединяются (reciprocal rank fusion):
 *  - по словам: слова вопроса — в содержимом файлов (FULLTEXT, «хотя бы одно слово»), в названиях и реквизитах
 *    документов, в расшифровках речи видео; из текста берутся окна вокруг совпадений;
 *  - по смыслу (если включён смысловой индекс, SemanticIndex): вектор вопроса сравнивается с векторами
 *    документов и их фрагментов — находятся и документы, где нужные слова не встречаются («возгорание» →
 *    «пожарная безопасность»); источником становятся самые близкие к вопросу фрагменты.
 * В источники попадают только документы и видео, которые этот сотрудник может открыть. Модель отвечает строго
 * по ним и ссылается номерами [1], [2]… Ничего не найдено — модель не вызывается.
 *
 * Каждый вопрос с итогом (ответ, «в документах ответа нет», ничего не найдено, ошибка) пишется в журнал
 * (AiQuestion), если он включён: сотрудник оценивает ответ, администратор видит вопросы без ответа.
 */
final class AnswerService
{
    /** Документов-источников в одном ответе. */
    public const MAX_DOCUMENTS = 5;

    /** Видео-источников в одном ответе. */
    public const MAX_VIDEOS = 2;

    /** Символов текста одного источника. */
    private const SOURCE_CHARS = 1800;

    /** Сглаживание в reciprocal rank fusion: вклад места k в списке — 1 / (RRF_K + k). */
    private const RRF_K = 60;

    /** Сколько дней после вопроса сотрудник может оценить ответ. */
    public const RATE_DAYS = 7;

    /** Ожидание вектора вопроса, секунд: для ответа и для блока «Похоже по смыслу» (страница поиска не ждёт долго). */
    private const EMBED_TIMEOUT_ANSWER = 8;
    private const EMBED_TIMEOUT_SIMILAR = 4;

    /** Модель эмбеддингов не ответила — минуту поиск по смыслу не пробуется (страницы не ждут тайм-аута). */
    private const EMBED_PAUSE_SECONDS = 60;
    private const EMBED_PAUSE_KEY = 'semantic_search_paused';

    /** Признак ответа «в документах ответа нет» (инструкция модели просит именно эти слова). */
    private const NOT_FOUND_PATTERN = '/(ответа|ответ)\s+не\s+нашл|не\s+нашлось\s+ответа|нет\s+(сведений|информации|ответа)/iu';

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
        private readonly ?SemanticIndex $semantic = null,
        private readonly ?EntityManagerInterface $em = null,
        private readonly ?AiQuestionRepository $questions = null,
        private readonly ?VerifiedAnswers $verified = null,
    ) {
    }

    /** Поиск по смыслу включён и в индексе есть документы. */
    public function isSemanticReady(): bool
    {
        try {
            return null !== $this->semantic && $this->semantic->isEnabled() && $this->semantic->hasData();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Тема вопроса для группировки похожих формулировок: первые пять букв значимых слов, по алфавиту
     * («Сколько дней отпуска?» и «отпуск сколько дней» — одна тема «дней отпус»).
     */
    public static function topic(string $question): string
    {
        $stems = [];
        foreach (self::keywords($question) as $word) {
            $stems[] = mb_strlen($word) > 5 ? mb_substr($word, 0, 5) : $word;
        }
        $stems = array_values(array_unique($stems));
        sort($stems);
        $topic = implode(' ', \array_slice($stems, 0, 8));

        return '' !== $topic ? $topic : mb_substr(mb_strtolower(trim($question)), 0, 60);
    }

    /** Включены ли ответы модели (LLM настроена и ответы не выключены в «Интеграциях»). */
    public function isEnabled(): bool
    {
        $cfg = $this->settings->llm();

        return $cfg['enabled'] && $cfg['answers'];
    }

    /**
     * Есть ли блок «Ответ на вопрос» на странице поиска: отвечает модель или — даже без неё — есть
     * проверенные ответы администраторов на частые вопросы.
     */
    public function isAvailable(): bool
    {
        return $this->isEnabled() || (null !== $this->verified && $this->verified->hasActive());
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
     * Ответ на вопрос: текст модели (с номерами источников), список источников, итог и номер записи в журнале
     * (по нему сотрудник оценивает ответ; null — журнал выключен).
     *
     * Проверенный ответ администратора (если вопрос подходит к нему) выдаётся сразу, без обращения к модели:
     * verified — когда он обновлён.
     *
     * @return array{answer: ?string, sources: list<array{n: int, type: string, title: string, url: string, meta: string}>, error: ?string, status: string, id: ?int, semantic: bool, verified?: array{updated_at: \DateTimeImmutable}}
     */
    public function answer(User $user, string $question): array
    {
        $started = microtime(true);
        $question = trim((string) preg_replace('/\s+/u', ' ', $question));
        if (mb_strlen($question) < 3) {
            return ['answer' => null, 'sources' => [], 'error' => 'Задайте вопрос подробнее.', 'status' => AiQuestion::STATUS_NO_SOURCES, 'id' => null, 'semantic' => false];
        }
        if (!$this->isAvailable()) {
            return ['answer' => null, 'sources' => [], 'error' => 'Ответы на вопросы выключены администратором.', 'status' => AiQuestion::STATUS_ERROR, 'id' => null, 'semantic' => false];
        }
        $question = mb_substr($question, 0, AiQuestion::MAX_QUESTION);
        $verified = null;
        try {
            $verified = $this->verified?->match($question, $user, true);
        } catch (\Throwable $e) {
            $this->logger->warning('Проверенные ответы недоступны', ['error' => $e->getMessage()]);
        }
        // Ответ, источники которого сотруднику не открыты (ограниченный доступ, снятый с публикации документ),
        // ему не выдаётся (match() их пропускает) — отвечает модель по доступным ему документам.
        if (null !== $verified) {
            $verified->markUsed();
            $result = $this->finish($user, $question, $started, AiQuestion::STATUS_VERIFIED, $verified->getAnswer(), $this->verified->sourcesFor($verified, $user), false, null);
            try {
                $this->em?->flush();
            } catch (\Throwable) {
                // Счётчик выдач — не повод отказать в ответе.
            }

            return $result + ['verified' => ['updated_at' => $verified->getUpdatedAt()]];
        }
        if (!$this->isEnabled()) {
            // Модель выключена, а готового ответа на этот вопрос нет.
            return $this->finish($user, $question, $started, AiQuestion::STATUS_NO_SOURCES, null, [], false,
                'Готового ответа на этот вопрос пока нет — посмотрите результаты поиска ниже.');
        }
        ['sources' => $sources, 'semantic' => $semantic] = $this->collect($user, $question);
        if ([] === $sources) {
            return $this->finish($user, $question, $started, AiQuestion::STATUS_NO_SOURCES, null, [], $semantic,
                'В документах и видео портала не нашлось материалов по этому вопросу — попробуйте сформулировать иначе или воспользуйтесь обычным поиском.');
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

            return $this->finish($user, $question, $started, AiQuestion::STATUS_ERROR, null, $out, $semantic,
                'Языковая модель сейчас не отвечает — посмотрите найденные материалы ниже или повторите позже.');
        }
        // «Ответа не нашлось» в начале ответа (как просит инструкция) — даже со ссылкой «уточните у ответственного [1]»;
        // в середине ответа со ссылками — это частичный ответ.
        $notFound = preg_match(self::NOT_FOUND_PATTERN, mb_substr($answer, 0, 160)) || (preg_match(self::NOT_FOUND_PATTERN, $answer) && !preg_match('/\[\d{1,2}\]/', $answer));
        $status = $notFound ? AiQuestion::STATUS_NOT_FOUND : AiQuestion::STATUS_ANSWERED;

        return $this->finish($user, $question, $started, $status, $answer, $out, $semantic, null);
    }

    /**
     * Документы, близкие к запросу по смыслу (блок «Похоже по смыслу» на странице поиска): только доступные
     * сотруднику, с самым близким фрагментом. Пусто — поиск по смыслу выключен, индекс пуст или модель не ответила.
     *
     * @return list<array{title: string, url: string, meta: string, snippet: string, score: float}>
     */
    public function similarDocuments(User $user, string $query, int $limit = 6): array
    {
        $query = trim((string) preg_replace('/\s+/u', ' ', $query));
        if (mb_strlen($query) < 3 || !$this->isSemanticReady()) {
            return [];
        }
        $hits = $this->semanticHits($user, $query, $limit, self::EMBED_TIMEOUT_SIMILAR);
        $out = [];
        foreach ($hits as $hit) {
            $document = $hit['document'];
            $best = self::snippetText($hit['chunks'][0]['content'] ?? '', $document);
            $out[] = [
                'title' => (string) $document->getTitle(),
                'url' => $this->urls->generate('app_document_show', ['id' => $document->getId()]),
                'meta' => implode(', ', array_filter([(string) $document->getCode(), $document->getSection()->getFullName()])),
                'snippet' => mb_strimwidth(trim((string) preg_replace('/\s+/u', ' ', $best)), 0, 260, '…'),
                'score' => round($hit['score'], 4),
            ];
        }

        return $out;
    }

    /** Текст фрагмента для показа: без строк с названием, обозначением и тегами (они и так видны). */
    private static function snippetText(string $chunk, Document $document): string
    {
        $lines = [];
        foreach (preg_split('/\n+/u', $chunk) ?: [] as $line) {
            $line = trim($line);
            if ('' === $line || $line === trim((string) $document->getTitle()) || str_starts_with($line, 'Обозначение: ') || str_starts_with($line, 'Теги: ')) {
                continue;
            }
            $lines[] = $line;
        }
        $text = implode(' ', $lines);

        return '' !== $text ? $text : trim((string) $document->getDescription());
    }

    /**
     * Оценка ответа сотрудником: только автор вопроса и не позже RATE_DAYS дней.
     *
     * @throws \DomainException
     */
    public function rate(User $user, int $id, int $rating, ?string $comment): AiQuestion
    {
        $question = $this->questions?->find($id);
        if (null === $question || null === $question->getUser() || $question->getUser()->getId() !== $user->getId()) {
            throw new \DomainException('Вопрос не найден.');
        }
        if ($question->getCreatedAt() < new \DateTimeImmutable('-'.self::RATE_DAYS.' days')) {
            throw new \DomainException('Оценить ответ можно в течение недели.');
        }
        $question->rate($rating, $comment);
        $this->em?->flush();

        return $question;
    }

    /**
     * Итог ответа и запись в журнал.
     *
     * @param list<array{n: int, type: string, title: string, url: string, meta: string}> $sources
     *
     * @return array{answer: ?string, sources: list<array{n: int, type: string, title: string, url: string, meta: string}>, error: ?string, status: string, id: ?int, semantic: bool}
     */
    private function finish(User $user, string $question, float $started, string $status, ?string $answer, array $sources, bool $semantic, ?string $error): array
    {
        $id = null;
        if (null !== $this->em && $this->settings->embeddings()['log_days'] > 0) {
            try {
                $entry = (new AiQuestion($user, $question, self::topic($question)))
                    ->finish($status, $answer, $sources, $semantic, (int) round((microtime(true) - $started) * 1000));
                $this->em->persist($entry);
                $this->em->flush();
                $id = $entry->getId();
            } catch (\Throwable $e) {
                // Журнал не должен мешать ответу.
                $this->logger->warning('Вопрос к ИИ не записан в журнал', ['error' => $e->getMessage()]);
            }
        }

        return ['answer' => $answer, 'sources' => $sources, 'error' => $error, 'status' => $status, 'id' => $id, 'semantic' => $semantic];
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
        $html = htmlspecialchars(trim(str_replace(["\r\n", "\r"], "\n", $answer)), \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');
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
            // Строки «- …», «• …», «1. …» подряд — список; текст до и после него — абзацы («Шаги:» и список под ним).
            $text = [];
            $items = [];
            foreach ($lines as $line) {
                if (preg_match('/^([-•*]|\d+[.)])\s+/u', $line)) {
                    if ([] !== $text) {
                        $paragraphs[] = '<p>'.implode('<br>', $text).'</p>';
                        $text = [];
                    }
                    $items[] = '<li>'.preg_replace('/^([-•*]|\d+[.)])\s+/u', '', $line).'</li>';
                    continue;
                }
                if ([] !== $items) {
                    $paragraphs[] = '<ul>'.implode('', $items).'</ul>';
                    $items = [];
                }
                $text[] = $line;
            }
            if ([] !== $text) {
                $paragraphs[] = '<p>'.implode('<br>', $text).'</p>';
            }
            if ([] !== $items) {
                $paragraphs[] = '<ul>'.implode('', $items).'</ul>';
            }
        }

        return implode('', $paragraphs);
    }

    /**
     * Источники: документы — по словам и по смыслу, объединённые по местам в обоих списках, — и видео.
     *
     * @return array{sources: list<array{type: string, title: string, url: string, meta: string, text: string}>, semantic: bool}
     */
    private function collect(User $user, string $question): array
    {
        $words = self::keywords($question);
        $viewer = $this->access->viewer($user);
        $statuses = [Document::STATUS_PUBLISHED];
        /** @var array<int, array{document: Document, score: float, text: ?string}> $found */
        $found = [];
        if ([] !== $words) {
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
        }

        // Поиск по смыслу и объединение двух списков: место k в списке даёт 1 / (60 + k).
        $semanticHits = $this->isSemanticReady() ? $this->semanticHits($user, $question, 8, self::EMBED_TIMEOUT_ANSWER) : [];
        $fused = [];
        $rank = 0;
        foreach ($found as $id => $hit) {
            $fused[$id] = ['document' => $hit['document'], 'score' => 1 / (self::RRF_K + ++$rank), 'text' => $hit['text'], 'chunks' => []];
        }
        $rank = 0;
        foreach ($semanticHits as $id => $hit) {
            $fused[$id] ??= ['document' => $hit['document'], 'score' => 0.0, 'text' => null, 'chunks' => []];
            $fused[$id]['score'] += 1 / (self::RRF_K + ++$rank);
            $fused[$id]['chunks'] = $hit['chunks'];
        }
        uasort($fused, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);

        $sources = [];
        foreach ($fused as $hit) {
            $document = $hit['document'];
            if (!$this->access->canViewDocument($user, $document)) {
                continue;
            }
            $text = [] !== $hit['chunks'] ? self::chunkText($hit['chunks'], $hit['text'], $words) : self::fragments($hit['text'] ?? $this->documentText($document), $words);
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
        if (null !== $this->videoApi && [] !== $words) {
            foreach ($this->videoApi->context($question, $user->isActive(), self::MAX_VIDEOS) as $video) {
                $text = self::fragments($video['text'], $words);
                if ('' !== $text) {
                    $sources[] = ['type' => 'video', 'title' => $video['title'], 'url' => $video['url'], 'meta' => '' !== $video['channel'] ? 'канал «'.$video['channel'].'»' : '', 'text' => $text];
                }
            }
        }

        return ['sources' => $sources, 'semantic' => [] !== $semanticHits];
    }

    /**
     * Документы, ближайшие к вопросу по смыслу, с лучшими фрагментами (только доступные сотруднику).
     *
     * @return array<int, array{document: Document, score: float, chunks: list<array{position: int, content: string, score: float}>}>
     */
    private function semanticHits(User $user, string $question, int $limit, int $timeout): array
    {
        if (null === $this->semantic || $this->llm->cacheHas(self::EMBED_PAUSE_KEY)) {
            return [];
        }
        try {
            $vector = $this->llm->embed([$question], $timeout)[0];
        } catch (LlmException $e) {
            $this->llm->cacheSet(self::EMBED_PAUSE_KEY, self::EMBED_PAUSE_SECONDS);
            $this->logger->warning('Поиск по смыслу недоступен — минуту ищем только по словам', ['error' => $e->getMessage()]);

            return [];
        }
        // Шаг 1 — кандидаты по векторам документов; шаг 2 — их фрагменты: место документа решает лучший фрагмент
        // (у длинного документа о многом вектор «размыт», а нужный абзац в нём может быть самым точным).
        $ranked = $this->semantic->rankDocuments($vector, 60);
        if ([] === $ranked) {
            return [];
        }
        $documents = [];
        foreach ($this->documents->findBy(['id' => array_keys($ranked)]) as $document) {
            $documents[(int) $document->getId()] = $document;
        }
        $hits = [];
        foreach ($ranked as $id => $score) {
            $document = $documents[$id] ?? null;
            if (null === $document || !$document->isPublished() || !$this->access->canViewDocument($user, $document)) {
                continue;
            }
            $hits[$id] = ['document' => $document, 'score' => $score, 'chunks' => []];
            if (\count($hits) >= max(20, $limit * 3)) {
                break;
            }
        }
        foreach ($this->semantic->bestChunks($vector, array_keys($hits), 3) as $id => $chunks) {
            if (isset($hits[$id]) && [] !== $chunks) {
                $hits[$id]['chunks'] = $chunks;
                $hits[$id]['score'] = max($hits[$id]['score'], $chunks[0]['score']);
            }
        }
        uasort($hits, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);
        // Слишком далёкие от лучшего документы — шум: оставляем близкие к лучшему (разброс зависит от модели).
        // Ниже порога из настроек — не найдено по смыслу (иначе на любой вопрос нашёлся бы «самый близкий» документ).
        $best = [] !== $hits ? (float) reset($hits)['score'] : 0.0;
        $floor = max($this->settings->embeddings()['min_score'], $best - 0.25);
        $hits = array_filter($hits, static fn (array $h): bool => $h['score'] >= $floor);

        return \array_slice($hits, 0, $limit, true);
    }

    /**
     * Текст источника из фрагментов, найденных по смыслу (в порядке следования в документе), с добавлением окон
     * вокруг слов вопроса, если документ нашёлся и по словам.
     *
     * @param list<array{position: int, content: string, score: float}> $chunks
     * @param list<string>                                                $words
     */
    private static function chunkText(array $chunks, ?string $keywordText, array $words): string
    {
        usort($chunks, static fn (array $a, array $b): int => $a['position'] <=> $b['position']);
        $text = '';
        foreach ($chunks as $chunk) {
            $piece = trim((string) preg_replace('/\s+/u', ' ', $chunk['content']));
            if ('' === $piece) {
                continue;
            }
            if ('' !== $text && mb_strlen($text) + mb_strlen($piece) > self::SOURCE_CHARS) {
                $piece = mb_substr($piece, 0, max(0, self::SOURCE_CHARS - mb_strlen($text))).'…';
            }
            $text .= ('' !== $text ? ' … ' : '').$piece;
            if (mb_strlen($text) >= self::SOURCE_CHARS) {
                break;
            }
        }
        if (null !== $keywordText && [] !== $words && mb_strlen($text) < self::SOURCE_CHARS - 300) {
            $extra = self::fragments($keywordText, $words);
            if ('' !== $extra && !str_contains($text, mb_substr($extra, 0, 60))) {
                $text .= ' … '.mb_substr($extra, 0, self::SOURCE_CHARS - mb_strlen($text));
            }
        }

        return $text;
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
