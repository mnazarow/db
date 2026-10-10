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
 * к модели — не больше 30 в час на сотрудника.
 */
final class SearchAnswerController extends AbstractController
{
    public function __construct(
        private readonly AnswerService $answers,
        private readonly RateLimiterFactoryInterface $aiAnswerLimiter,
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
        $response = new JsonResponse([
            'html' => null !== $result['answer'] ? AnswerService::toHtml($result['answer'], $result['sources']) : null,
            'sources' => $result['sources'],
            'error' => $result['error'],
        ]);
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
