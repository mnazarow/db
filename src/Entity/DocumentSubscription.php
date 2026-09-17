<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use App\Repository\DocumentSubscriptionRepository;

/**
 * Подписка сотрудника на изменения: либо на отдельный документ, либо на раздел целиком
 * (вместе с подразделами). Уведомления уходят по почте и в Telegram — по настройкам сотрудника.
 */
#[ORM\Entity(repositoryClass: DocumentSubscriptionRepository::class)]
#[ORM\Table(name: 'document_subscription')]
#[ORM\UniqueConstraint(name: 'uniq_subscription_document', columns: ['user_id', 'document_id'])]
#[ORM\UniqueConstraint(name: 'uniq_subscription_section', columns: ['user_id', 'section_id'])]
#[ORM\Index(name: 'idx_subscription_document', columns: ['document_id'])]
#[ORM\Index(name: 'idx_subscription_section', columns: ['section_id'])]
class DocumentSubscription
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\ManyToOne(targetEntity: Document::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?Document $document = null;

    #[ORM\ManyToOne(targetEntity: Section::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?Section $section = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(User $user, ?Document $document = null, ?Section $section = null)
    {
        if (null === $document && null === $section) {
            throw new \InvalidArgumentException('Подписка оформляется на документ или на раздел.');
        }
        $this->user = $user;
        $this->document = $document;
        $this->section = $section;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getDocument(): ?Document
    {
        return $this->document;
    }

    public function getSection(): ?Section
    {
        return $this->section;
    }

    public function isForSection(): bool
    {
        return null !== $this->section;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getTitle(): string
    {
        return $this->section?->getFullName() ?? $this->document?->getTitle() ?? '';
    }
}
