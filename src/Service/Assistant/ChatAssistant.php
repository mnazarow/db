<?php

declare(strict_types=1);

namespace App\Service\Assistant;

use App\Entity\AiQuestion;
use App\Entity\ChatConversation;
use App\Entity\ChatMessage;
use App\Entity\User;
use App\Repository\AiQuestionRepository;
use App\Repository\ChatConversationRepository;
use App\Repository\ChatMessageRepository;
use App\Service\Llm\AnswerService;
use App\Service\PortalUrl;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Помощник — чат по документам, видео и прайс-листам портала: на любой странице портала (кнопка «Помощник»)
 * и в Telegram (бот компании, для сотрудников, привязавших чат). Ответы готовит AnswerService (поиск источников
 * с учётом прав сотрудника + языковая модель или проверенные ответы администраторов); помощник хранит разговор
 * и передаёт модели предыдущие вопросы и ответы — можно уточнять: «а для скважины?», «а сколько он стоит?».
 */
final class ChatAssistant
{
    /** Разговор в Telegram продолжается, если с прошлого сообщения прошло не больше стольких часов (иначе — новый). */
    public const TELEGRAM_SESSION_HOURS = 3;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ChatConversationRepository $conversations,
        private readonly ChatMessageRepository $messages,
        private readonly AnswerService $answers,
        private readonly UrlGeneratorInterface $urls,
        private readonly PortalUrl $portalUrl,
        private readonly ?AiQuestionRepository $questions = null,
    ) {
    }

    /** Отвечает ли помощник: включены ответы модели или есть проверенные ответы. */
    public function isAvailable(): bool
    {
        return $this->answers->isAvailable();
    }

    /** Отвечает ли языковая модель (без неё — только проверенные ответы на частые вопросы). */
    public function hasModel(): bool
    {
        return $this->answers->isEnabled();
    }

    /**
     * Вопрос в разговоре (null — новый разговор). Возвращает разговор, вопрос и ответ помощника.
     *
     * @return array{conversation: ChatConversation, question: ChatMessage, reply: ChatMessage}
     *
     * @throws \InvalidArgumentException пустой вопрос
     */
    public function ask(User $user, ?ChatConversation $conversation, string $text, string $channel = ChatConversation::CHANNEL_WEB): array
    {
        $text = trim((string) preg_replace('/[ \t]+/u', ' ', str_replace("\r", '', $text)));
        if (mb_strlen($text) < 2) {
            throw new \InvalidArgumentException('Напишите вопрос.');
        }
        $text = mb_substr($text, 0, ChatMessage::MAX_QUESTION);
        if (null !== $conversation && $conversation->getUser()->getId() !== $user->getId()) {
            throw new \DomainException('Разговор не найден.');
        }
        $history = null !== $conversation ? $this->history($conversation) : [];
        $conversation ??= new ChatConversation($user, $channel, $text);
        $result = $this->answers->answer($user, $text, $history);
        $question = new ChatMessage($conversation, ChatMessage::ROLE_USER, $text);
        $hasAnswer = null !== $result['answer'] && '' !== trim($result['answer']);
        $reply = new ChatMessage($conversation, ChatMessage::ROLE_ASSISTANT, $hasAnswer ? (string) $result['answer'] : (string) ($result['error'] ?? 'Ответа нет.'),
            $result['sources'], $result['status'], $hasAnswer, null !== $result['id'] && null !== $this->questions ? $this->questions->find($result['id']) : null);
        $conversation->added(2);
        $this->em->persist($conversation);
        $this->em->persist($question);
        $this->em->persist($reply);
        $this->em->flush();

        return ['conversation' => $conversation, 'question' => $question, 'reply' => $reply];
    }

    /** Разговор в Telegram, который ещё идёт (или null — следующий вопрос начнёт новый). */
    public function ongoingTelegram(User $user): ?ChatConversation
    {
        return $this->conversations->findOngoing($user, ChatConversation::CHANNEL_TELEGRAM, new \DateTimeImmutable('-'.self::TELEGRAM_SESSION_HOURS.' hours'));
    }

    /** Закончить разговор в Telegram: следующий вопрос начнёт новый (команда /new). */
    public function finishTelegram(User $user): void
    {
        $ongoing = $this->ongoingTelegram($user);
        if (null !== $ongoing) {
            // Разговор остаётся в истории, но «продолжающимся» больше не считается.
            $this->em->getConnection()->executeStatement('UPDATE chat_conversation SET updated_at = ? WHERE id = ?',
                [(new \DateTimeImmutable('-'.(self::TELEGRAM_SESSION_HOURS + 1).' hours'))->format('Y-m-d H:i:s'), $ongoing->getId()]);
            $this->em->refresh($ongoing);
        }
    }

    /** @return list<ChatConversation> */
    public function recent(User $user, int $limit = 30): array
    {
        return $this->conversations->findRecent($user, $limit);
    }

    public function find(User $user, int $id): ?ChatConversation
    {
        $conversation = $this->conversations->find($id);

        return null !== $conversation && $conversation->getUser()->getId() === $user->getId() ? $conversation : null;
    }

    /** @return list<ChatMessage> */
    public function messages(ChatConversation $conversation): array
    {
        return $this->messages->findForConversation($conversation);
    }

    public function delete(ChatConversation $conversation): void
    {
        $this->em->remove($conversation);
        $this->em->flush();
    }

    /**
     * Сообщение для страницы: текст ответа — безопасным HTML со ссылками [n] на источники.
     *
     * @return array<string, mixed>
     */
    public function serialize(ChatMessage $message): array
    {
        $question = $message->getQuestion();
        $rateable = $message->isAssistant() && $message->hasAnswer() && null !== $question
            && $question->getCreatedAt() >= new \DateTimeImmutable('-'.AnswerService::RATE_DAYS.' days');

        return [
            'id' => $message->getId(),
            'role' => $message->getRole(),
            'html' => $message->isAssistant() && $message->hasAnswer()
                ? AnswerService::toHtml($message->getContent(), $message->getSources())
                : nl2br(htmlspecialchars($message->getContent(), \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8')),
            'sources' => $message->isAssistant() ? $message->getSources() : [],
            'status' => $message->getStatus(),
            'verified' => AiQuestion::STATUS_VERIFIED === $message->getStatus(),
            'notice' => $message->isAssistant() && !$message->hasAnswer(),
            'rating' => $question?->getRating(),
            'rate_url' => $rateable ? $this->urls->generate('app_search_answer_rate', ['id' => $question->getId()]) : null,
            'time' => $message->getCreatedAt()->format('H:i'),
        ];
    }

    /** @return array<string, mixed> */
    public function serializeConversation(ChatConversation $conversation): array
    {
        return [
            'id' => $conversation->getId(),
            'title' => $conversation->getTitle(),
            'channel' => $conversation->getChannel(),
            'channel_label' => ChatConversation::CHANNEL_LABELS[$conversation->getChannel()] ?? '',
            'updated' => self::when($conversation->getUpdatedAt()),
            'messages' => $conversation->getMessageCount(),
        ];
    }

    /**
     * Ответ для Telegram (HTML, который принимает Bot API): текст с номерами [n] и список источников — ссылками,
     * если у портала настроен постоянный адрес (APP_DEFAULT_URI), иначе названиями.
     */
    public function telegramText(ChatMessage $reply): string
    {
        $text = htmlspecialchars(trim($reply->getContent()), \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');
        $text = (string) preg_replace('/\*\*(.+?)\*\*/u', '<b>$1</b>', $text);
        $text = (string) preg_replace('/^\s*[-•*]\s+/mu', '• ', $text);
        if ($reply->hasAnswer() && [] !== $reply->getSources()) {
            $base = $this->portalUrl->baseUri();
            $lines = [];
            foreach ($reply->getSources() as $source) {
                $url = (string) $source['url'];
                if (str_starts_with($url, '/') && null !== $base) {
                    $url = $base.$url;
                }
                $title = htmlspecialchars((string) $source['title'], \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');
                $kind = ['video' => 'видео', 'price' => 'прайс-лист'][$source['type']] ?? 'документ';
                $lines[] = \sprintf('[%d] %s%s', (int) $source['n'], preg_match('#^https?://#', $url) ? '<a href="'.htmlspecialchars($url, \ENT_QUOTES, 'UTF-8').'">'.$title.'</a>' : $title, ' — '.$kind);
            }
            $text .= "\n\n<b>Источники</b>\n".implode("\n", $lines);
        }
        if (AiQuestion::STATUS_VERIFIED === $reply->getStatus()) {
            $text .= "\n\n<i>Проверенный ответ администраторов портала.</i>";
        }

        return $text;
    }

    /**
     * Предыдущие вопросы и ответы разговора для модели (последние AnswerService::HISTORY_TURNS).
     *
     * @return list<array{question: string, answer: string}>
     */
    private function history(ChatConversation $conversation): array
    {
        $turns = [];
        $pending = null;
        foreach ($this->messages->findForConversation($conversation, 2 * AnswerService::HISTORY_TURNS + 2) as $message) {
            if (!$message->isAssistant()) {
                $pending = $message->getContent();
                continue;
            }
            if (null !== $pending) {
                $turns[] = ['question' => $pending, 'answer' => $message->hasAnswer() ? $message->getContent() : ''];
                $pending = null;
            }
        }

        return \array_slice($turns, -AnswerService::HISTORY_TURNS);
    }

    private static function when(\DateTimeImmutable $at): string
    {
        $today = new \DateTimeImmutable('today');

        return $at >= $today ? 'сегодня, '.$at->format('H:i') : ($at >= $today->modify('-1 day') ? 'вчера, '.$at->format('H:i') : $at->format('d.m.Y'));
    }
}
