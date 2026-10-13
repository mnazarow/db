<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\AiQuestion;
use App\Entity\AiVerifiedAnswer;
use App\Entity\User;
use App\Repository\AiQuestionRepository;
use App\Repository\AiVerifiedAnswerRepository;
use App\Service\Csv;
use App\Service\Llm\AnswerService;
use App\Service\Llm\VerifiedAnswers;
use App\Service\PortalSettings;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Вопросы к ИИ: на что в документах портала ответа не нашлось (по темам — подсказка, какие документы написать),
 * какие ответы сотрудники отметили как неполезные, все вопросы. Имена сотрудников не показываются.
 */
#[Route('/admin/ai-questions')]
#[IsGranted('ROLE_ADMIN')]
final class AiQuestionsController extends AbstractController
{
    public const VIEWS = ['unanswered' => 'Без ответа', 'unhelpful' => 'Неполезные ответы', 'all' => 'Все вопросы', 'verified' => 'Проверенные ответы'];
    private const MAX_ANSWER = 20000;
    public const PERIODS = ['7' => '7 дней', '30' => '30 дней', '90' => '90 дней', 'all' => 'всё время'];

    public function __construct(
        private readonly AiQuestionRepository $questions,
        private readonly PortalSettings $settings,
        private readonly AnswerService $answers,
        private readonly AiVerifiedAnswerRepository $verifiedRepository,
        private readonly VerifiedAnswers $verified,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $auditLogger,
    ) {
    }

    #[Route('', name: 'admin_ai_questions', methods: ['GET'])]
    public function index(Request $request): Response
    {
        [$view, $period, $since] = $this->filters($request);

        return $this->render('admin/ai_questions/index.html.twig', [
            'view' => $view,
            'period' => $period,
            'views' => self::VIEWS,
            'periods' => self::PERIODS,
            'stats' => $this->questions->stats($since),
            'topics' => 'unanswered' === $view ? $this->questions->unansweredTopics($since) : [],
            'rows' => 'unhelpful' === $view ? $this->questions->unhelpful($since) : ('all' === $view ? $this->questions->recent($since) : []),
            'log_days' => $this->settings->embeddings()['log_days'],
            'answers_enabled' => $this->answers->isEnabled(),
            'semantic_ready' => $this->answers->isSemanticReady(),
            'verified' => 'verified' === $view ? $this->verifiedRepository->findAllOrdered() : [],
            'verified_check' => 'verified' === $view ? $this->checkQuestion((string) $request->query->get('check', '')) : null,
        ]);
    }

    /** Новый проверенный ответ: пустой или по вопросу из журнала (?from=…) / по теме (?question=…). */
    #[Route('/answers/new', name: 'admin_ai_verified_new', methods: ['GET', 'POST'])]
    public function verifiedNew(Request $request, #[CurrentUser] User $user): Response
    {
        $answer = new AiVerifiedAnswer();
        $sources = '';
        if ($request->isMethod('GET')) {
            $from = $request->query->getInt('from');
            $source = $from > 0 ? $this->questions->find($from) : null;
            if (null !== $source) {
                $answer->setQuestion($source->getQuestion());
                if (null !== $source->getAnswer() && AiQuestion::STATUS_ANSWERED === $source->getStatus()) {
                    $answer->setAnswer($source->getAnswer());
                }
                $sources = implode("\n", array_map(static fn (array $s): string => (string) $s['url'], array_filter($source->getSources(), static fn (array $s): bool => 'document' === ($s['type'] ?? 'document'))));
            } else {
                $answer->setQuestion((string) $request->query->get('question', ''));
            }
        }

        return $this->verifiedForm($request, $answer, $user, $sources);
    }

    #[Route('/answers/{id}', name: 'admin_ai_verified_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function verifiedEdit(AiVerifiedAnswer $answer, Request $request, #[CurrentUser] User $user): Response
    {
        return $this->verifiedForm($request, $answer, $user, $this->verified->sourcesInput($answer));
    }

    #[Route('/answers/{id}/delete', name: 'admin_ai_verified_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function verifiedDelete(AiVerifiedAnswer $answer, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('ai_verified', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Неверный CSRF-токен.');
        }
        $this->auditLogger->info('Проверенный ответ ИИ удалён', ['question' => $answer->getQuestion(), 'by' => $this->getUser()?->getUserIdentifier()]);
        $this->em->remove($answer);
        $this->em->flush();
        $this->addFlash('success', 'Проверенный ответ удалён.');

        return $this->redirectToRoute('admin_ai_questions', ['view' => 'verified']);
    }

    private function verifiedForm(Request $request, AiVerifiedAnswer $answer, User $user, string $sources): Response
    {
        $errors = [];
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('ai_verified', (string) $request->request->get('_token'))) {
                throw $this->createAccessDeniedException('Неверный CSRF-токен.');
            }
            $answer->setQuestion((string) $request->request->get('question', ''))
                ->setAlternatives((string) $request->request->get('alternatives', ''))
                ->setAnswer(mb_substr((string) $request->request->get('answer', ''), 0, self::MAX_ANSWER))
                ->setActive($request->request->getBoolean('active'));
            $sources = (string) $request->request->get('sources', '');
            $parsed = $this->verified->parseSources($sources);
            if (mb_strlen($answer->getQuestion()) < 3 || [] === VerifiedAnswers::stems($answer->getQuestion())) {
                $errors[] = 'Напишите вопрос так, как его задают сотрудники (несколько значимых слов).';
            }
            if ('' === $answer->getAnswer()) {
                $errors[] = 'Напишите ответ.';
            }
            if ([] !== $parsed['unknown']) {
                $errors[] = 'Не найдены документы: '.implode(', ', \array_slice($parsed['unknown'], 0, 5)).'. Укажите ссылку на документ портала, «#номер» или обозначение.';
            }
            if (\count($parsed['ids']) > AiVerifiedAnswer::MAX_SOURCES) {
                $errors[] = \sprintf('Источников — не больше %d.', AiVerifiedAnswer::MAX_SOURCES);
            }
            preg_match_all('/\[(\d{1,2})\]/', $answer->getAnswer(), $refs);
            $maxRef = [] === $refs[1] ? 0 : max(array_map('intval', $refs[1]));
            if ($maxRef > \count($parsed['ids']) && [] === $parsed['unknown']) {
                $errors[] = \sprintf('В ответе есть ссылка [%d], а документов-источников %d — добавьте документ или уберите ссылку.', $maxRef, \count($parsed['ids']));
            }
            if ([] === $errors) {
                $isNew = null === $answer->getId();
                $answer->setDocumentIds($parsed['ids'])->touch($user);
                $this->em->persist($answer);
                $this->em->flush();
                $this->auditLogger->info($isNew ? 'Проверенный ответ ИИ добавлен' : 'Проверенный ответ ИИ изменён', ['question' => $answer->getQuestion(), 'active' => $answer->isActive(), 'by' => $user->getUsername()]);
                $this->addFlash('success', $isNew ? 'Проверенный ответ сохранён: на похожие вопросы сотрудники получат его сразу.' : 'Проверенный ответ сохранён.');
                $limited = array_filter($this->verified->documentsOf($answer), static fn (\App\Entity\Document $d): bool => $d->isRestricted() || !$d->isPublished());
                if ([] !== $limited) {
                    $this->addFlash('warning', 'Среди источников есть документы с ограниченным доступом или не опубликованные ('.implode(', ', array_map(static fn (\App\Entity\Document $d): string => '«'.$d->getTitle().'»', \array_slice(array_values($limited), 0, 3))).'). Этот ответ получат только сотрудники, которым открыты все источники; остальным ответит модель.');
                }

                return $this->redirectToRoute('admin_ai_verified_edit', ['id' => $answer->getId()]);
            }
        }
        $preview = '' !== $answer->getAnswer() ? AnswerService::toHtml($answer->getAnswer(), $this->previewSources($answer, $sources)) : null;

        return $this->render('admin/ai_questions/verified.html.twig', [
            'answer' => $answer,
            'sources' => $sources,
            'errors' => $errors,
            'preview' => $preview,
            'preview_sources' => $this->previewSources($answer, $sources),
            'max_sources' => AiVerifiedAnswer::MAX_SOURCES,
        ]);
    }

    /** @return list<array{n: int, type: string, title: string, url: string, meta: string}> */
    private function previewSources(AiVerifiedAnswer $answer, string $sources): array
    {
        $out = [];
        $parsed = $this->verified->parseSources($sources);
        foreach (array_values($parsed['ids']) as $i => $id) {
            $document = $this->em->find(\App\Entity\Document::class, $id);
            if (null !== $document) {
                $out[] = ['n' => $i + 1, 'type' => 'document', 'title' => (string) $document->getTitle(), 'url' => $this->generateUrl('app_document_show', ['id' => $id]), 'meta' => (string) $document->getCode()];
            }
        }

        return $out;
    }

    /**
     * «Проверить вопрос»: какой проверенный ответ получит сотрудник на такой вопрос.
     *
     * @return array{question: string, answer: ?AiVerifiedAnswer}|null
     */
    private function checkQuestion(string $question): ?array
    {
        $question = trim($question);
        if ('' === $question) {
            return null;
        }

        return ['question' => $question, 'answer' => $this->verified->match($question)];
    }

    #[Route('.csv', name: 'admin_ai_questions_csv', methods: ['GET'])]
    public function csv(Request $request): StreamedResponse
    {
        [$view, , $since] = $this->filters($request);
        $topics = 'unanswered' === $view ? $this->questions->unansweredTopics($since, 5000) : [];
        $rows = 'unhelpful' === $view ? $this->questions->unhelpful($since, 5000) : ('all' === $view ? $this->questions->recent($since, 5000) : []);
        $verified = 'verified' === $view ? $this->verifiedRepository->findAllOrdered() : [];
        $response = new StreamedResponse(static function () use ($view, $topics, $rows, $verified): void {
            $out = fopen('php://output', 'wb');
            fwrite($out, "\xEF\xBB\xBF");
            if ('verified' === $view) {
                Csv::put($out, ['Вопрос', 'Другие формулировки', 'Ответ', 'Документы-источники', 'Включён', 'Выдан раз', 'Последний раз', 'Изменён']);
                foreach ($verified as $a) {
                    Csv::put($out, [$a->getQuestion(), implode('; ', $a->getAlternativeList()), $a->getAnswer(), implode(', ', array_map(static fn (int $id): string => '#'.$id, $a->getDocumentIds())),
                        $a->isActive() ? 'да' : 'нет', $a->getUses(), $a->getLastUsedAt()?->format('d.m.Y H:i') ?? '', $a->getUpdatedAt()->format('d.m.Y H:i')]);
                }
            } elseif ('unanswered' === $view) {
                Csv::put($out, ['Вопрос (последняя формулировка)', 'Тема', 'Сколько раз спрашивали', 'Сотрудников', 'Последний раз', 'Позже ответ нашёлся']);
                foreach ($topics as $t) {
                    Csv::put($out, [$t['question'], $t['topic'], $t['asked'], $t['users'], $t['last_at']->format('d.m.Y H:i'), $t['resolved'] ? 'да' : 'нет']);
                }
            } else {
                Csv::put($out, ['Дата', 'Вопрос', 'Итог', 'Оценка', 'Комментарий', 'Источники', 'По смыслу', 'Время ответа, с']);
                foreach ($rows as $q) {
                    /** @var AiQuestion $q */
                    Csv::put($out, [
                        $q->getCreatedAt()->format('d.m.Y H:i'),
                        $q->getQuestion(),
                        $q->getStatusLabel(),
                        null === $q->getRating() ? '' : ($q->getRating() > 0 ? 'помог' : 'не помог'),
                        (string) $q->getComment(),
                        implode('; ', array_map(static fn (array $s): string => $s['title'], $q->getSources())),
                        $q->isSemantic() ? 'да' : 'нет',
                        round($q->getDurationMs() / 1000, 1),
                    ]);
                }
            }
            fclose($out);
        });
        $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $response->headers->set('Content-Disposition', 'attachment; filename="ai-questions-'.$view.'-'.date('Y-m-d').'.csv"');
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }

    /** @return array{0: string, 1: string, 2: ?\DateTimeImmutable} */
    private function filters(Request $request): array
    {
        $view = (string) $request->query->get('view', 'unanswered');
        $view = \array_key_exists($view, self::VIEWS) ? $view : 'unanswered';
        $period = (string) $request->query->get('period', '30');
        $period = \array_key_exists($period, self::PERIODS) ? $period : '30';
        $since = 'all' === $period ? null : new \DateTimeImmutable('-'.$period.' days');

        return [$view, $period, $since];
    }
}
