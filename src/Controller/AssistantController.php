<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\ChatConversation;
use App\Entity\User;
use App\Service\Assistant\ChatAssistant;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Помощник на портале: окно чата (кнопка «Помощник» на всех страницах) и страница /assistant со списком
 * разговоров. Вопросы — только вошедшим сотрудникам, не больше 60 в час.
 */
#[Route('/assistant')]
#[IsGranted('ROLE_USER')]
final class AssistantController extends AbstractController
{
    public const CSRF = 'assistant';

    public function __construct(
        private readonly ChatAssistant $assistant,
        #[Target]
        private readonly RateLimiterFactoryInterface $assistantChatLimiter,
    ) {
    }

    #[Route('', name: 'app_assistant', methods: ['GET'])]
    public function page(Request $request, #[CurrentUser] User $user): Response
    {
        if (!$this->assistant->isAvailable()) {
            if (!$user->isAdmin()) {
                throw $this->createNotFoundException('Помощник выключен администратором.');
            }

            // Администратору — как подключить модель.
            return $this->render('assistant/page.html.twig', ['conversations' => [], 'current' => null, 'has_model' => false, 'setup' => true]);
        }
        $current = $request->query->getInt('c') > 0 ? $this->assistant->find($user, $request->query->getInt('c')) : null;

        return $this->render('assistant/page.html.twig', [
            'conversations' => array_map(fn (ChatConversation $c): array => $this->assistant->serializeConversation($c), $this->assistant->recent($user)),
            'current' => $current?->getId(),
            'has_model' => $this->assistant->hasModel(),
        ]);
    }

    #[Route('/conversations', name: 'app_assistant_conversations', methods: ['GET'])]
    public function conversations(#[CurrentUser] User $user): JsonResponse
    {
        return self::reply(['conversations' => array_map(fn (ChatConversation $c): array => $this->assistant->serializeConversation($c), $this->assistant->recent($user))]);
    }

    #[Route('/conversations/{id}', name: 'app_assistant_conversation', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function conversation(int $id, #[CurrentUser] User $user): JsonResponse
    {
        $conversation = $this->assistant->find($user, $id);
        if (null === $conversation) {
            return self::reply(['error' => 'Разговор не найден.'], 404);
        }

        return self::reply([
            'conversation' => $this->assistant->serializeConversation($conversation),
            'messages' => array_map(fn ($m): array => $this->assistant->serialize($m), $this->assistant->messages($conversation)),
        ]);
    }

    #[Route('/conversations/{id}/delete', name: 'app_assistant_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(int $id, Request $request, #[CurrentUser] User $user): JsonResponse
    {
        if (!$this->isCsrfTokenValid(self::CSRF, (string) $request->request->get('_token'))) {
            return self::reply(['error' => 'Страница устарела — обновите её.'], 400);
        }
        $conversation = $this->assistant->find($user, $id);
        if (null !== $conversation) {
            $this->assistant->delete($conversation);
        }

        return self::reply(['ok' => true]);
    }

    #[Route('/ask', name: 'app_assistant_ask', methods: ['POST'])]
    public function ask(Request $request, #[CurrentUser] User $user): JsonResponse
    {
        if (!$this->isCsrfTokenValid(self::CSRF, (string) $request->request->get('_token'))) {
            return self::reply(['error' => 'Страница устарела — обновите её и повторите.'], 400);
        }
        if (!$this->assistant->isAvailable()) {
            return self::reply(['error' => 'Помощник выключен администратором.'], 404);
        }
        $conversation = null;
        $id = $request->request->getInt('conversation');
        if ($id > 0) {
            $conversation = $this->assistant->find($user, $id);
            if (null === $conversation) {
                return self::reply(['error' => 'Разговор не найден — начните новый.'], 404);
            }
        }
        $limit = $this->assistantChatLimiter->create('assistant-'.$user->getId())->consume();
        if (!$limit->isAccepted()) {
            return self::reply(['error' => \sprintf('Слишком много вопросов подряд — повторите через %d мин.', max(1, (int) ceil(($limit->getRetryAfter()->getTimestamp() - time()) / 60)))], 429);
        }
        try {
            $result = $this->assistant->ask($user, $conversation, (string) $request->request->get('message', ''));
        } catch (\InvalidArgumentException|\DomainException $e) {
            return self::reply(['error' => $e->getMessage()], 422);
        }

        return self::reply([
            'conversation' => $this->assistant->serializeConversation($result['conversation']),
            'question' => $this->assistant->serialize($result['question']),
            'reply' => $this->assistant->serialize($result['reply']),
        ]);
    }

    /** @param array<string, mixed> $data */
    private static function reply(array $data, int $status = 200): JsonResponse
    {
        $response = new JsonResponse($data, $status);
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
