<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\DocumentEventRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Событие по документу — основа статистики и журнала: просмотр, скачивание,
 * создание, изменение, публикация, новая версия и т.д.
 */
#[ORM\Entity(repositoryClass: DocumentEventRepository::class)]
#[ORM\Table(name: 'document_event')]
#[ORM\Index(name: 'idx_event_document_type', columns: ['document_id', 'type'])]
#[ORM\Index(name: 'idx_event_created', columns: ['created_at'])]
#[ORM\Index(name: 'idx_event_type_created', columns: ['type', 'created_at'])]
#[ORM\Index(name: 'idx_event_user', columns: ['user_id'])]
class DocumentEvent
{
    public const VIEW = 'view';
    public const DOWNLOAD = 'download';
    public const CREATE = 'create';
    public const UPDATE = 'update';
    public const NEW_VERSION = 'new_version';
    public const RESTORE = 'restore';
    public const PUBLISH = 'publish';
    public const UNPUBLISH = 'unpublish';
    public const ARCHIVE = 'archive';
    public const MOVE = 'move';
    public const DELETE = 'delete';
    public const EXPIRY_NOTICE = 'expiry_notice';

    public const LABELS = [
        self::VIEW => 'Просмотр',
        self::DOWNLOAD => 'Скачивание',
        self::CREATE => 'Создание',
        self::UPDATE => 'Изменение карточки',
        self::NEW_VERSION => 'Новая версия',
        self::RESTORE => 'Восстановление версии',
        self::PUBLISH => 'Публикация',
        self::UNPUBLISH => 'Снятие с публикации',
        self::ARCHIVE => 'Перенос в архив',
        self::MOVE => 'Перемещение',
        self::DELETE => 'Удаление',
        self::EXPIRY_NOTICE => 'Уведомление о сроке',
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Document::class, inversedBy: 'events')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Document $document;

    #[ORM\ManyToOne(targetEntity: DocumentVersion::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?DocumentVersion $version = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $user = null;

    /** Имя пользователя на момент события (сохраняется и после удаления учётной записи). */
    #[ORM\Column(length: 128, nullable: true)]
    private ?string $actorName = null;

    #[ORM\Column(length: 24)]
    private string $type;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(length: 45, nullable: true)]
    private ?string $ip = null;

    /** @var array<string, mixed>|null */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $details = null;

    /**
     * @param array<string, mixed>|null $details
     */
    public function __construct(Document $document, string $type, ?User $user = null, ?DocumentVersion $version = null, ?string $ip = null, ?array $details = null)
    {
        $this->document = $document;
        $this->type = $type;
        $this->user = $user;
        $this->actorName = $user?->getDisplayName();
        $this->version = $version;
        $this->ip = null === $ip ? null : mb_substr($ip, 0, 45);
        $this->details = $details;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDocument(): Document
    {
        return $this->document;
    }

    public function getVersion(): ?DocumentVersion
    {
        return $this->version;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function getActorName(): ?string
    {
        return $this->actorName;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getLabel(): string
    {
        return self::LABELS[$this->type] ?? $this->type;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): static
    {
        $this->createdAt = $createdAt;

        return $this;
    }

    public function getIp(): ?string
    {
        return $this->ip;
    }

    /** @return array<string, mixed>|null */
    public function getDetails(): ?array
    {
        return $this->details;
    }
}
