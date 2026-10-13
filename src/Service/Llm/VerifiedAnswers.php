<?php

declare(strict_types=1);

namespace App\Service\Llm;

use App\Entity\AiVerifiedAnswer;
use App\Entity\Document;
use App\Entity\User;
use App\Repository\AiVerifiedAnswerRepository;
use App\Repository\DocumentRepository;
use App\Security\Access;
use App\Service\PortalSettings;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Проверенные ответы ИИ: поиск подходящего ответа для вопроса сотрудника и источники ответа, которые ему
 * открыты. Вопрос подходит, если совпадает дословно (без учёта регистра и знаков) или по основам значимых
 * слов — общих не меньше трёх четвертей от всех слов вопроса и формулировки (в коротком вопросе все слова
 * те же, в длинном одно может отличаться) — и в нём те же отрицания
 * («не», «без», «нельзя»…): «отпуск без сохранения зарплаты» не закрывается ответом про отпуск с сохранением,
 * а общий вопрос «как оформить отпуск» — ответом про частный случай («…учебный отпуск»). Лучше ответит модель,
 * чем выдастся чужой ответ: другие формулировки того же вопроса администратор добавляет в проверенный ответ.
 *
 * Если включён поиск по смыслу, вопрос другими словами («что делать при возгорании» к «что делать при пожаре»)
 * сравнивается с формулировками и по смыслу (векторы модели эмбеддингов; векторы формулировок кэшируются):
 * подходит при сходстве не ниже настройки «Сходство для проверенных ответов», тех же отрицаниях и том же числе
 * значимых слов — так синоним заменяет слово, но уточнение («учебный отпуск») ответ не получает.
 */
final class VerifiedAnswers
{
    /** Доля общих основ: при коротких вопросах — все слова совпадают, при длинных — одно может отличаться. */
    private const MIN_SHARE = 0.75;

    /** Слова, меняющие смысл вопроса на противоположный: должны совпадать у вопроса и формулировки. */
    private const POLARITY = ['не', 'нет', 'ни', 'без', 'нельзя', 'невозможно', 'запрещено', 'кроме', 'not', 'no', 'without'];

    /** Сколько живут векторы формулировок в кэше (меняются только с моделью — она входит в ключ). */
    private const VECTOR_TTL = 30 * 86400;

    private const EMBED_TIMEOUT = 8;

    /** Формулировок в одном запросе к модели эмбеддингов (как у смыслового индекса). */
    private const BATCH = 32;

    public function __construct(
        private readonly AiVerifiedAnswerRepository $repository,
        private readonly DocumentRepository $documents,
        private readonly Access $access,
        private readonly UrlGeneratorInterface $urls,
        private readonly ?PortalSettings $settings = null,
        private readonly ?SemanticIndex $semantic = null,
        private readonly ?LlmClient $llm = null,
        private readonly ?CacheItemPoolInterface $cache = null,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /** Основы значимых слов (первые пять букв, как тема вопроса в отчёте «Вопросы к ИИ»), все. @return list<string> */
    public static function stems(string $text): array
    {
        $stems = [];
        foreach (AnswerService::keywords($text) as $word) {
            $stems[] = mb_strlen($word) > 5 ? mb_substr($word, 0, 5) : $word;
        }

        return array_values(array_unique($stems));
    }

    /** Отрицания в вопросе («не», «без», «нельзя»…). @return list<string> */
    public static function polarity(string $text): array
    {
        $out = array_values(array_unique(array_intersect(explode(' ', self::normalize($text)), self::POLARITY)));
        sort($out);

        return $out;
    }

    public static function normalize(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', (string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', mb_strtolower(str_replace('ё', 'е', $text)))));
    }

    /**
     * Насколько вопрос подходит к формулировкам проверенного ответа: 0 — не подходит, 2 — дословно.
     *
     * @param list<string> $phrasings
     */
    public static function score(string $question, array $phrasings): float
    {
        $normalized = self::normalize($question);
        $q = self::stems($question);
        $polarity = self::polarity($question);
        $best = 0.0;
        foreach ($phrasings as $phrasing) {
            if ('' !== $normalized && self::normalize($phrasing) === $normalized) {
                return 2.0;
            }
            $v = self::stems($phrasing);
            if ([] === $q || [] === $v || self::polarity($phrasing) !== $polarity) {
                continue;
            }
            $share = \count(array_intersect($q, $v)) / \count(array_unique([...$q, ...$v]));
            if ($share >= self::MIN_SHARE) {
                $best = max($best, $share);
            }
        }

        return $best;
    }

    /**
     * Самый подходящий проверенный ответ; если сотрудник указан — из тех, все источники которых ему открыты.
     *
     * @param (callable(): ?list<float>)|null $questionVector вектор вопроса (по смыслу) — запрашивается, только
     *                                                         если по словам ответ не подошёл
     */
    public function match(string $question, ?User $user = null, bool $checkAccess = false, ?callable $questionVector = null): ?AiVerifiedAnswer
    {
        return $this->explain($question, $user, $checkAccess, $questionVector)['answer'];
    }

    /**
     * То же с пояснением: как подошёл ответ — дословно, по словам или по смыслу (и сходство по смыслу).
     *
     * @param (callable(): ?list<float>)|null $questionVector
     *
     * @return array{answer: ?AiVerifiedAnswer, how: ?string, similarity: ?float, semantic: bool}
     */
    public function explain(string $question, ?User $user = null, bool $checkAccess = false, ?callable $questionVector = null): array
    {
        $answers = $this->repository->findActive();
        $visible = fn (AiVerifiedAnswer $a): bool => !$checkAccess || $this->isFullyVisible($a, $user);
        $scored = [];
        foreach ($answers as $answer) {
            $score = self::score($question, [$answer->getQuestion(), ...$answer->getAlternativeList()]);
            if ($score > 0) {
                $scored[] = [$score, $answer];
            }
        }
        usort($scored, static fn (array $a, array $b): int => $b[0] <=> $a[0]);
        foreach ($scored as [$score, $answer]) {
            if ($visible($answer)) {
                return ['answer' => $answer, 'how' => $score >= 2.0 ? 'exact' : 'words', 'similarity' => null, 'semantic' => false];
            }
        }
        // По смыслу — только формулировки с тем же числом значимых слов и теми же отрицаниями.
        $semantic = $this->isSemanticReady();
        if (!$semantic || [] === $answers) {
            return ['answer' => null, 'how' => null, 'similarity' => null, 'semantic' => $semantic];
        }
        $q = self::stems($question);
        $polarity = self::polarity($question);
        $candidates = [];
        foreach ($answers as $answer) {
            foreach ([$answer->getQuestion(), ...$answer->getAlternativeList()] as $phrasing) {
                if ([] !== $q && \count(self::stems($phrasing)) === \count($q) && self::polarity($phrasing) === $polarity) {
                    $candidates[] = [$answer, $phrasing];
                }
            }
        }
        if ([] === $candidates) {
            return ['answer' => null, 'how' => null, 'similarity' => null, 'semantic' => true];
        }
        $vector = null !== $questionVector ? $questionVector() : $this->embedOne($question);
        if (null === $vector) {
            return ['answer' => null, 'how' => null, 'similarity' => null, 'semantic' => true];
        }
        // Векторов формулировок нет в кэше (например, сразу после обновления) — за один вопрос досчитывается
        // не больше одного запроса к модели, остальное — в следующие разы и в ежечасном app:ai:index.
        $vectors = $this->phrasingVectors(array_values(array_unique(array_column($candidates, 1))), self::BATCH);
        $threshold = $this->settings?->embeddings()['verified_min'] ?? PortalSettings::EMBED_DEFAULT_VERIFIED_MIN;
        $best = [];
        foreach ($candidates as [$answer, $phrasing]) {
            if (!isset($vectors[$phrasing]) || \count($vectors[$phrasing]) !== \count($vector)) {
                continue;
            }
            $similarity = SemanticIndex::dot($vector, $vectors[$phrasing]);
            $id = spl_object_id($answer);
            if (!isset($best[$id]) || $similarity > $best[$id][0]) {
                $best[$id] = [$similarity, $answer];
            }
        }
        usort($best, static fn (array $a, array $b): int => $b[0] <=> $a[0]);
        $closest = null;
        foreach ($best as [$similarity, $answer]) {
            $closest ??= $similarity;
            if ($similarity >= $threshold && $visible($answer)) {
                return ['answer' => $answer, 'how' => 'meaning', 'similarity' => $similarity, 'semantic' => true];
            }
        }

        return ['answer' => null, 'how' => null, 'similarity' => $closest, 'semantic' => true];
    }

    /** Сравнение по смыслу доступно: включён поиск по смыслу и модель эмбеддингов настроена. */
    public function isSemanticReady(): bool
    {
        return null !== $this->semantic && null !== $this->llm && $this->semantic->isEnabled();
    }

    /**
     * Заранее посчитать векторы формулировок ответа (при сохранении): тогда первый же вопрос сотрудника не ждёт
     * модель дольше обычного. Ошибка модели не мешает сохранению.
     */
    public function warm(AiVerifiedAnswer $answer): void
    {
        if ($this->isSemanticReady()) {
            $this->phrasingVectors([$answer->getQuestion(), ...$answer->getAlternativeList()], self::BATCH);
        }
    }

    /**
     * Досчитать векторы формулировок всех выдаваемых ответов (ежечасно в app:ai:index): после обновления портала
     * или смены модели кэш пуст, и без этого первые вопросы сотрудников ждали бы модель.
     *
     * @return array{phrasings: int, embedded: int}
     */
    public function warmAll(): array
    {
        if (!$this->isSemanticReady()) {
            return ['phrasings' => 0, 'embedded' => 0];
        }
        $phrasings = [];
        foreach ($this->repository->findActive() as $answer) {
            foreach ([$answer->getQuestion(), ...$answer->getAlternativeList()] as $phrasing) {
                $phrasings[$phrasing] = true;
            }
        }
        $embedded = 0;
        $vectors = $this->phrasingVectors(array_keys($phrasings), \PHP_INT_MAX, 60, $embedded);

        return ['phrasings' => \count($vectors), 'embedded' => $embedded];
    }

    /** @return list<float>|null */
    private function embedOne(string $text): ?array
    {
        if (null === $this->llm || $this->llm->cacheHas(AnswerService::EMBED_PAUSE_KEY)) {
            return null;
        }
        try {
            return $this->llm->embed([$text], self::EMBED_TIMEOUT)[0] ?? null;
        } catch (\Throwable $e) {
            $this->llm->cacheSet(AnswerService::EMBED_PAUSE_KEY, AnswerService::EMBED_PAUSE_SECONDS);
            $this->logger?->warning('Проверенные ответы: модель эмбеддингов недоступна — сравнение только по словам', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Векторы формулировок: из кэша, недостающие — у модели пачками по BATCH (каждая пачка сразу в кэш), не больше
     * $maxMissing за вызов. Сбой модели ставит поиск по смыслу на паузу (как в AnswerService), пока она идёт — к
     * модели не обращаемся.
     *
     * @param list<string> $phrasings
     *
     * @return array<string, list<float>>
     */
    private function phrasingVectors(array $phrasings, int $maxMissing, int $timeout = self::EMBED_TIMEOUT, int &$embedded = 0): array
    {
        if (null === $this->llm || null === $this->semantic) {
            return [];
        }
        $signature = $this->semantic->signature();
        $out = [];
        $missing = [];
        foreach ($phrasings as $phrasing) {
            $key = 'verified_vec_'.sha1($signature.'|'.self::normalize($phrasing));
            $item = $this->cache?->getItem($key);
            if (null !== $item && $item->isHit() && \is_string($item->get())) {
                $out[$phrasing] = SemanticIndex::unpack($item->get());
            } else {
                $missing[$phrasing] = $key;
            }
        }
        if ([] === $missing || $this->llm->cacheHas(AnswerService::EMBED_PAUSE_KEY)) {
            return $out;
        }
        foreach (array_chunk(\array_slice($missing, 0, max(0, $maxMissing), true), self::BATCH, true) as $batch) {
            try {
                $vectors = $this->llm->embed(array_keys($batch), $timeout);
            } catch (\Throwable $e) {
                $this->llm->cacheSet(AnswerService::EMBED_PAUSE_KEY, AnswerService::EMBED_PAUSE_SECONDS);
                $this->logger?->warning('Проверенные ответы: векторы формулировок не получены', ['error' => $e->getMessage()]);

                break;
            }
            foreach (array_keys($batch) as $i => $phrasing) {
                if (!isset($vectors[$i])) {
                    continue;
                }
                $out[$phrasing] = $vectors[$i];
                ++$embedded;
                if (null !== $this->cache) {
                    $item = $this->cache->getItem($batch[$phrasing]);
                    $this->cache->save($item->set(SemanticIndex::pack($vectors[$i]))->expiresAfter(self::VECTOR_TTL));
                }
            }
        }

        return $out;
    }

    public function hasActive(): bool
    {
        try {
            return $this->repository->countActive() > 0;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Все ли источники ответа открыты сотруднику (опубликованы и доступны ему). Иначе ответ ему не выдаётся:
     * текст мог быть написан по документу с ограниченным доступом или по отменённой редакции.
     */
    public function isFullyVisible(AiVerifiedAnswer $answer, ?User $user): bool
    {
        return \count($this->sourcesFor($answer, $user)) === \count($answer->getDocumentIds());
    }

    /**
     * Источники проверенного ответа, открытые сотруднику (опубликованные и доступные ему); номера — как в
     * ответе ([1] — первый документ из списка), недоступные источники пропускаются.
     *
     * @return list<array{n: int, type: string, title: string, url: string, meta: string}>
     */
    public function sourcesFor(AiVerifiedAnswer $answer, ?User $user): array
    {
        $ids = $answer->getDocumentIds();
        if ([] === $ids) {
            return [];
        }
        $byId = [];
        foreach ($this->documents->findBy(['id' => $ids]) as $document) {
            $byId[(int) $document->getId()] = $document;
        }
        $out = [];
        foreach ($ids as $i => $id) {
            $document = $byId[$id] ?? null;
            if (null === $document || !$document->isPublished() || !$this->access->canViewDocument($user, $document)) {
                continue;
            }
            $out[] = [
                'n' => $i + 1,
                'type' => 'document',
                'title' => (string) $document->getTitle(),
                'url' => $this->urls->generate('app_document_show', ['id' => $document->getId()]),
                'meta' => implode(', ', array_filter([(string) $document->getCode(), $document->getSection()->getFullName()])),
            ];
        }

        return $out;
    }

    /**
     * Документы-источники из поля формы: ссылки на документы портала (…/documents/123), «#123» (после номера
     * может идти название) или обозначения («ОТ-002»), по одной в строке.
     *
     * @return array{ids: list<int>, unknown: list<string>}
     */
    public function parseSources(string $input): array
    {
        $ids = [];
        $unknown = [];
        foreach (preg_split('/\R+/u', $input) ?: [] as $line) {
            $line = trim($line);
            if ('' === $line) {
                continue;
            }
            $document = null;
            if (preg_match('~/documents/(\d+)~', $line, $m) || preg_match('/^#(\d+)\b/', $line, $m) || preg_match('/^(\d+)$/', $line, $m)) {
                $document = $this->documents->find((int) $m[1]);
            } else {
                $document = $this->documents->findOneBy(['code' => $line]);
            }
            if ($document instanceof Document) {
                $ids[] = (int) $document->getId();
            } else {
                $unknown[] = $line;
            }
        }

        return ['ids' => array_values(array_unique($ids)), 'unknown' => $unknown];
    }

    /** Строки поля «Источники» для формы: ссылки на документы. */
    public function sourcesInput(AiVerifiedAnswer $answer): string
    {
        $lines = [];
        $byId = [];
        foreach ($this->documents->findBy(['id' => $answer->getDocumentIds()]) as $document) {
            $byId[(int) $document->getId()] = $document;
        }
        foreach ($answer->getDocumentIds() as $id) {
            $document = $byId[$id] ?? null;
            $lines[] = '#'.$id.(null !== $document ? ' '.$document->getTitle() : ' (документ удалён)');
        }

        return implode("\n", $lines);
    }

    /** @return array<int, Document> */
    public function documentsOf(AiVerifiedAnswer $answer): array
    {
        $out = [];
        foreach ($this->documents->findBy(['id' => $answer->getDocumentIds()]) as $document) {
            $out[(int) $document->getId()] = $document;
        }

        return $out;
    }
}
