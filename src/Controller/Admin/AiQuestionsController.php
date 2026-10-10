<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\AiQuestion;
use App\Repository\AiQuestionRepository;
use App\Service\Csv;
use App\Service\Llm\AnswerService;
use App\Service\PortalSettings;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Вопросы к ИИ: на что в документах портала ответа не нашлось (по темам — подсказка, какие документы написать),
 * какие ответы сотрудники отметили как неполезные, все вопросы. Имена сотрудников не показываются.
 */
#[Route('/admin/ai-questions')]
#[IsGranted('ROLE_ADMIN')]
final class AiQuestionsController extends AbstractController
{
    public const VIEWS = ['unanswered' => 'Без ответа', 'unhelpful' => 'Неполезные ответы', 'all' => 'Все вопросы'];
    public const PERIODS = ['7' => '7 дней', '30' => '30 дней', '90' => '90 дней', 'all' => 'всё время'];

    public function __construct(
        private readonly AiQuestionRepository $questions,
        private readonly PortalSettings $settings,
        private readonly AnswerService $answers,
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
        ]);
    }

    #[Route('.csv', name: 'admin_ai_questions_csv', methods: ['GET'])]
    public function csv(Request $request): StreamedResponse
    {
        [$view, , $since] = $this->filters($request);
        $topics = 'unanswered' === $view ? $this->questions->unansweredTopics($since, 5000) : [];
        $rows = 'unhelpful' === $view ? $this->questions->unhelpful($since, 5000) : ('all' === $view ? $this->questions->recent($since, 5000) : []);
        $response = new StreamedResponse(static function () use ($view, $topics, $rows): void {
            $out = fopen('php://output', 'wb');
            fwrite($out, "\xEF\xBB\xBF");
            if ('unanswered' === $view) {
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
