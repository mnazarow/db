<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ChatMessageRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Сообщение разговора с помощником: вопрос сотрудника или ответ (текст с номерами источников [n], источники,
 * итог — как в журнале вопросов к ИИ; question — запись журнала, по которой сотрудник оценивает ответ).
 */
#[ORM\Entity(repositoryClass: ChatMessageRepository::class)]
#[ORM\Table(name: 'chat_message')]
#[ORM\Index(name: 'idx_chat_message_conversation', columns: ['conversation_id', 'id'])]
class ChatMessage
{
    public const ROLE_USER = 'user';
    public const ROLE_ASSISTANT = 'assistant';

    /** Длина вопроса. */
    public const MAX_QUESTION = 1000;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: ChatConversation::class)]
    #[ORM\JoinColumn(name: 'conversation_id', nullable: false, onDelete: 'CASCADE')]
    private ChatConversation $conversation;

    #[ORM\Column(length: 16)]
    private string $role;

    #[ORM\Column(type: Types::TEXT)]
    private string $content;

    /** @var list<array{n: int, type: string, title: string, url: string, meta: string}> */
    #[ORM\Column(type: Types::JSON)]
    private array $sources = [];

    /** Итог ответа (AiQuestion::STATUS_*), у вопроса — null. */
    #[ORM\Column(length: 16, nullable: true)]
    private ?string $status = null;

    /** Ответ есть (не сообщение об ошибке или «ничего не найдено»). */
    #[ORM\Column(name: 'has_answer', options: ['default' => false])]
    private bool $hasAnswer = false;

    #[ORM\ManyToOne(targetEntity: AiQuestion::class)]
    #[ORM\JoinColumn(name: 'question_id', nullable: true, onDelete: 'SET NULL')]
    private ?AiQuestion $question = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    /** @param list<array{n: int, type: string, title: string, url: string, meta: string}> $sources */
    public function __construct(ChatConversation $conversation, string $role, string $content, array $sources = [], ?string $status = null, bool $hasAnswer = false, ?AiQuestion $question = null)
    {
        $this->conversation = $conversation;
        $this->role = self::ROLE_ASSISTANT === $role ? self::ROLE_ASSISTANT : self::ROLE_USER;
        $this->content = $content;
        $this->sources = array_values($sources);
        $this->status = $status;
        $this->hasAnswer = $hasAnswer;
        $this->question = $question;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getConversation(): ChatConversation
    {
        return $this->conversation;
    }

    public function getRole(): string
    {
        return $this->role;
    }

    public function isAssistant(): bool
    {
        return self::ROLE_ASSISTANT === $this->role;
    }

    public function getContent(): string
    {
        return $this->content;
    }

    /** @return list<array{n: int, type: string, title: string, url: string, meta: string}> */
    public function getSources(): array
    {
        return $this->sources;
    }

    public function getStatus(): ?string
    {
        return $this->status;
    }

    public function hasAnswer(): bool
    {
        return $this->hasAnswer;
    }

    public function getQuestion(): ?AiQuestion
    {
        return $this->question;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
