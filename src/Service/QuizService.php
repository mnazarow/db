<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Document;
use App\Entity\DocumentAcknowledgement;
use App\Entity\DocumentQuestion;
use App\Repository\DocumentQuestionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Проверка знаний после ознакомления: вопросы к документу и проверка ответов.
 *
 * Ознакомление подтверждается, только если сотрудник ответил верно на все вопросы;
 * при ошибке показывается, где именно, и попытку можно повторить (все попытки считаются).
 */
final class QuizService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly DocumentQuestionRepository $questions,
        private readonly LoggerInterface $auditLogger,
    ) {
    }

    /** Фиксирует неудачную попытку проверки знаний (удачная сохраняется вместе с подтверждением). */
    public function recordAttempt(DocumentAcknowledgement $acknowledgement, int $correct, int $total): void
    {
        $acknowledgement->addQuizAttempt()->setQuizResult($correct, $total);
        $this->em->flush();
    }

    /** @return list<DocumentQuestion> */
    public function questions(Document $document): array
    {
        return $this->questions->findForDocument($document);
    }

    public function hasQuiz(Document $document): bool
    {
        return $this->questions->countForDocument($document) > 0;
    }

    public function count(Document $document): int
    {
        return $this->questions->countForDocument($document);
    }

    /**
     * Проверяет ответы сотрудника.
     *
     * @param array<int, int|string|null> $answers идентификатор вопроса => номер варианта
     *
     * @return array{passed: bool, correct: int, total: int, wrong: list<int>}
     */
    public function check(Document $document, array $answers): array
    {
        $questions = $this->questions($document);
        $correct = 0;
        $wrong = [];
        foreach ($questions as $question) {
            $id = (int) $question->getId();
            $given = $answers[$id] ?? null;
            if (null !== $given && '' !== $given && $question->isCorrect((int) $given)) {
                ++$correct;
            } else {
                $wrong[] = $id;
            }
        }
        $total = \count($questions);

        return ['passed' => $total > 0 && $correct === $total, 'correct' => $correct, 'total' => $total, 'wrong' => $wrong];
    }

    /**
     * Сохраняет вопрос (новый или существующий).
     *
     * @param list<string> $options
     *
     * @throws \InvalidArgumentException при пустом тексте или недостатке вариантов
     */
    public function save(Document $document, ?DocumentQuestion $question, string $text, array $options, int $correct): DocumentQuestion
    {
        $isNew = null === $question;
        $question ??= new DocumentQuestion($document);
        $question->setText($text)->setOptions($options, $correct);
        if ($isNew) {
            $question->setPosition($this->count($document) + 1);
            $this->em->persist($question);
        }
        $this->em->flush();
        $this->auditLogger->info($isNew ? 'Добавлен вопрос для проверки знаний' : 'Изменён вопрос для проверки знаний', [
            'document' => $document->getId(), 'question' => $question->getId(),
        ]);

        return $question;
    }

    public function delete(DocumentQuestion $question): void
    {
        $this->auditLogger->info('Удалён вопрос для проверки знаний', ['document' => $question->getDocument()->getId(), 'question' => $question->getId()]);
        $this->em->remove($question);
        $this->em->flush();
    }
}
