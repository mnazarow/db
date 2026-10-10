<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Service\Llm\AnswerService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Ответ ИИ на вопрос со страницы поиска: кнопка «Ответить» отправляет вопрос сюда и показывает ответ с
 * пронумерованными ссылками на документы и видео (см. AnswerService). Только для вошедших сотрудников; запросов
 * к модели — не больше 30 в час на сотрудника. Здесь же — оценка ответа («помог» / «не помог») и блок
 * «Похоже по смыслу» (документы, близкие к запросу по смыслу).
 */
final class SearchAnswerController extends AbstractController
{
    public function __construct(
        private readonly AnswerService $answers,
        private readonly RateLimiterFactoryInterface $aiAnswerLimiter,
        private readonly RateLimiterFactoryInterface $semanticSearchLimiter,
    ) {
    }

    #[Route('/search/answer', name: 'app_search_answer', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function answer(Request $request, #[CurrentUser] User $user): JsonResponse
    {
        if (!$this->isCsrfTokenValid('search_answer', (string) $request->request->get('_token'))) {
            return new JsonResponse(['error' => 'Страница устарела — обновите её и повторите.'], 400);
        }
        if (!$this->answers->isEnabled()) {
            return new JsonResponse(['error' => 'Ответы на вопросы выключены администратором.'], 404);
        }
        $limit = $this->aiAnswerLimiter->create('ai-answer-'.$user->getId())->consume();
        if (!$limit->isAccepted()) {
            return new JsonResponse(['error' => \sprintf('Слишком много вопросов подряд — повторите через %d мин.', max(1, (int) ceil(($limit->getRetryAfter()->getTimestamp() - time()) / 60)))], 429);
        }
        $result = $this->answers->answer($user, (string) $request->request->get('q', ''));

        return self::noStore([
            'html' => null !== $result['answer'] ? AnswerService::toHtml($result['answer'], $result['sources']) : null,
            'sources' => $result['sources'],
            'error' => $result['error'],
            'status' => $result['status'],
            'semantic' => $result['semantic'],
            // Номер вопроса для оценки ответа (нет журнала или ответа — оценивать нечего).
            'id' => null !== $result['answer'] ? $result['id'] : null,
            'rate_url' => null !== $result['answer'] && null !== $result['id'] ? $this->generateUrl('app_search_answer_rate', ['id' => $result['id']]) : null,
        ]);
    }

    /** «Ответ помог?» — да / нет (с необязательным комментарием, чего не хватило). */
    #[Route('/search/answer/{id}/rate', name: 'app_search_answer_rate', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function rate(int $id, Request $request, #[CurrentUser] User $user): JsonResponse
    {
        if (!$this->isCsrfTokenValid('search_answer', (string) $request->request->get('_token'))) {
            return self::noStore(['error' => 'Страница устарела — обновите её и повторите.'], 400);
        }
        $rating = (int) $request->request->get('rating', 0);
        if (1 !== $rating && -1 !== $rating) {
            return self::noStore(['error' => 'Оценка — «помог» или «не помог».'], 400);
        }
        try {
            $question = $this->answers->rate($user, $id, $rating, (string) $request->request->get('comment', ''));
        } catch (\DomainException $e) {
            return self::noStore(['error' => $e->getMessage()], 404);
        }

        return self::noStore(['ok' => true, 'rating' => $question->getRating()]);
    }

    /** Документы, близкие к запросу по смыслу — для блока под результатами поиска. */
    #[Route('/search/semantic', name: 'app_search_semantic', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function semantic(Request $request, #[CurrentUser] User $user): JsonResponse
    {
        if (!$this->isCsrfTokenValid('search_answer', (string) $request->request->get('_token'))) {
            return self::noStore(['error' => 'Страница устарела — обновите её и повторите.', 'items' => []], 400);
        }
        if (!$this->answers->isSemanticReady()) {
            return self::noStore(['items' => []]);
        }
        if (!$this->semanticSearchLimiter->create('semantic-'.$user->getId())->consume()->isAccepted()) {
            return self::noStore(['items' => [], 'error' => 'Слишком много запросов подряд.'], 429);
        }

        return self::noStore(['items' => $this->answers->similarDocuments($user, (string) $request->request->get('q', ''), 6)]);
    }

    /** @param array<string, mixed> $data */
    private static function noStore(array $data, int $status = 200): JsonResponse
    {
        $response = new JsonResponse($data, $status);
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
