<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\ChatConversation;
use App\Entity\User;
use App\Repository\UserRepository;
use App\Security\Api\ApiPrincipal;
use App\Service\Assistant\ChatAssistant;
use App\Service\PortalUrl;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * API помощника для внешних чат-ботов (корпоративный мессенджер, бот на другом сервере, виджет сайта):
 * бот передаёт вопрос и логин (или почту) сотрудника — помощник отвечает по документам, видео и прайс-листам,
 * которые доступны этому сотруднику, и продолжает разговор, если передан его номер.
 *
 * Ответ от имени сотрудника — доверенное действие: нужен ключ API с правом «внутренние документы» (его выдаёт
 * администратор в «Интеграциях»). Вопросы считаются в ограничение сотрудника (60 в час), как на портале.
 *
 *   POST /api/v1/assistant  {"user": "ivanov", "question": "Сколько стоит станция Аква-50?", "conversation": 12}
 *   → {"answer": "…[1]", "html": "…", "sources": [{"n": 1, "type": "price", "title": "…", "url": "https://…"}],
 *      "status": "answered", "conversation": 12}
 */
#[Route('/api/v1/assistant', name: 'api_assistant')]
final class AssistantApiController extends AbstractController
{
    public function __construct(
        private readonly ChatAssistant $assistant,
        private readonly UserRepository $users,
        private readonly PortalUrl $portalUrl,
        private readonly LoggerInterface $auditLogger,
        #[Target]
        private readonly RateLimiterFactoryInterface $assistantChatLimiter,
    ) {
    }

    #[Route('', name: '', methods: ['POST'])]
    public function ask(Request $request, #[CurrentUser] ApiPrincipal $principal): ApiResponse
    {
        if (!$principal->includesInternal()) {
            return ApiResponse::error('forbidden', 'Помощник отвечает от имени сотрудника — нужен ключ API с доступом к внутренним документам (Интеграции → Ключи REST API).', 403);
        }
        if (!$this->assistant->isAvailable()) {
            return ApiResponse::error('assistant_disabled', 'Помощник выключен администратором портала.', 503);
        }
        $data = self::payload($request);
        $login = trim((string) ($data['user'] ?? ''));
        $question = trim((string) ($data['question'] ?? $data['message'] ?? ''));
        if ('' === $login) {
            return ApiResponse::error('user_required', 'Укажите сотрудника: user — логин или адрес почты.', 400);
        }
        if ('' === $question) {
            return ApiResponse::error('question_required', 'Укажите вопрос: question.', 400);
        }
        $user = $this->findUser($login);
        if (null === $user || !$user->isActive()) {
            return ApiResponse::error('user_not_found', 'Сотрудник не найден или отключён.', 404);
        }
        $conversation = null;
        if (isset($data['conversation']) && '' !== (string) $data['conversation']) {
            $conversation = $this->assistant->find($user, (int) $data['conversation']);
            if (null === $conversation) {
                return ApiResponse::error('conversation_not_found', 'Разговор не найден — начните новый (без conversation).', 404);
            }
        }
        $limit = $this->assistantChatLimiter->create('assistant-'.$user->getId())->consume();
        if (!$limit->isAccepted()) {
            return ApiResponse::error('rate_limited', 'Слишком много вопросов от сотрудника подряд.', 429, ['Retry-After' => (string) max(1, $limit->getRetryAfter()->getTimestamp() - time())]);
        }
        try {
            $result = $this->assistant->ask($user, $conversation, $question, ChatConversation::CHANNEL_API);
        } catch (\InvalidArgumentException|\DomainException $e) {
            return ApiResponse::error('bad_request', $e->getMessage(), 400);
        }
        $reply = $result['reply'];
        $base = $this->portalUrl->baseUri() ?? rtrim($request->getSchemeAndHttpHost().$request->getBasePath(), '/');
        $sources = array_map(static function (array $s) use ($base): array {
            $url = (string) $s['url'];

            return ['n' => (int) $s['n'], 'type' => (string) $s['type'], 'title' => (string) $s['title'], 'meta' => (string) $s['meta'], 'url' => str_starts_with($url, '/') ? $base.$url : $url];
        }, $reply->hasAnswer() ? $reply->getSources() : []);
        $serialized = $this->assistant->serialize($reply);
        $this->auditLogger->info('Вопрос помощнику через API', ['key' => $principal->getKey()->getName(), 'user' => $user->getUsername(), 'conversation' => $result['conversation']->getId()]);

        return new ApiResponse([
            'answer' => $reply->hasAnswer() ? $reply->getContent() : null,
            'html' => $reply->hasAnswer() ? (string) preg_replace('#href="/#', 'href="'.$base.'/', (string) $serialized['html']) : null,
            'message' => $reply->hasAnswer() ? null : $reply->getContent(),
            'sources' => $sources,
            'status' => $reply->getStatus(),
            'verified' => $serialized['verified'],
            'conversation' => $result['conversation']->getId(),
            'telegram_html' => $this->assistant->telegramText($reply),
        ]);
    }

    /** @return array<string, mixed> */
    private static function payload(Request $request): array
    {
        if (str_contains((string) $request->headers->get('Content-Type'), 'json')) {
            $json = json_decode($request->getContent(), true);

            return \is_array($json) ? $json : [];
        }

        return $request->request->all();
    }

    private function findUser(string $login): ?User
    {
        $user = $this->users->findOneByUsername($login);
        if (null === $user && str_contains($login, '@')) {
            $user = $this->users->findOneBy(['email' => mb_strtolower($login)]) ?? $this->users->findOneBy(['email' => $login]);
        }

        return $user;
    }
}
