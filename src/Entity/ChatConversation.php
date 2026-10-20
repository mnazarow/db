<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ChatConversationRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Разговор с помощником (чат на портале или в Telegram): вопросы сотрудника и ответы по документам, видео
 * и прайс-листам. Хранится, сколько хранится журнал вопросов к ИИ (настройка «Интеграции» → «Журнал вопросов»).
 */
#[ORM\Entity(repositoryClass: ChatConversationRepository::class)]
#[ORM\Table(name: 'chat_conversation')]
#[ORM\Index(name: 'idx_chat_conversation_user', columns: ['user_id', 'updated_at'])]
class ChatConversation
{
    public const CHANNEL_WEB = 'web';
    public const CHANNEL_TELEGRAM = 'telegram';
    /** Внешний чат-бот через API помощника (/api/v1/assistant). */
    public const CHANNEL_API = 'api';
    public const CHANNEL_LABELS = [self::CHANNEL_WEB => 'портал', self::CHANNEL_TELEGRAM => 'Telegram', self::CHANNEL_API => 'внешний чат-бот'];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 16)]
    private string $channel;

    #[ORM\Column(length: 255)]
    private string $title;

    #[ORM\Column(name: 'message_count')]
    private int $messageCount = 0;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(User $user, string $channel, string $title)
    {
        $this->user = $user;
        $this->channel = isset(self::CHANNEL_LABELS[$channel]) ? $channel : self::CHANNEL_WEB;
        $this->title = mb_strimwidth(trim((string) preg_replace('/\s+/u', ' ', $title)), 0, 120, '…');
        $this->createdAt = $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getChannel(): string
    {
        return $this->channel;
    }

    public function isTelegram(): bool
    {
        return self::CHANNEL_TELEGRAM === $this->channel;
    }

    public function getTitle(): string
    {
        return '' !== $this->title ? $this->title : 'Разговор';
    }

    public function getMessageCount(): int
    {
        return $this->messageCount;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function added(int $messages): self
    {
        $this->messageCount += $messages;
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }
}
