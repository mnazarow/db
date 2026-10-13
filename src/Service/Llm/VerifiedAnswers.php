<?php

declare(strict_types=1);

namespace App\Service\Llm;

use App\Entity\AiVerifiedAnswer;
use App\Entity\Document;
use App\Entity\User;
use App\Repository\AiVerifiedAnswerRepository;
use App\Repository\DocumentRepository;
use App\Security\Access;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Проверенные ответы ИИ: поиск подходящего ответа для вопроса сотрудника и источники ответа, которые ему
 * открыты. Вопрос подходит, если совпадает дословно (без учёта регистра и знаков) или по основам значимых
 * слов — общих не меньше трёх четвертей от всех слов вопроса и формулировки (в коротком вопросе все слова
 * те же, в длинном одно может отличаться) — и в нём те же отрицания
 * («не», «без», «нельзя»…): «отпуск без сохранения зарплаты» не закрывается ответом про отпуск с сохранением,
 * а общий вопрос «как оформить отпуск» — ответом про частный случай («…учебный отпуск»). Лучше ответит модель,
 * чем выдастся чужой ответ: другие формулировки того же вопроса администратор добавляет в проверенный ответ.
 */
final class VerifiedAnswers
{
    /** Доля общих основ: при коротких вопросах — все слова совпадают, при длинных — одно может отличаться. */
    private const MIN_SHARE = 0.75;

    /** Слова, меняющие смысл вопроса на противоположный: должны совпадать у вопроса и формулировки. */
    private const POLARITY = ['не', 'нет', 'ни', 'без', 'нельзя', 'невозможно', 'запрещено', 'кроме', 'not', 'no', 'without'];

    public function __construct(
        private readonly AiVerifiedAnswerRepository $repository,
        private readonly DocumentRepository $documents,
        private readonly Access $access,
        private readonly UrlGeneratorInterface $urls,
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
     */
    public function match(string $question, ?User $user = null, bool $checkAccess = false): ?AiVerifiedAnswer
    {
        $scored = [];
        foreach ($this->repository->findActive() as $answer) {
            $score = self::score($question, [$answer->getQuestion(), ...$answer->getAlternativeList()]);
            if ($score > 0) {
                $scored[] = [$score, $answer];
            }
        }
        usort($scored, static fn (array $a, array $b): int => $b[0] <=> $a[0]);
        foreach ($scored as [, $answer]) {
            if (!$checkAccess || $this->isFullyVisible($answer, $user)) {
                return $answer;
            }
        }

        return null;
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
