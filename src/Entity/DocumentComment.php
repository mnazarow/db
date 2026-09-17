<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\DocumentCommentRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Обсуждение документа: вопросы сотрудников и ответы ответственных.
 *
 * Комментарии хранятся в одном уровне вложенности (реплика и ответы на неё): этого достаточно
 * для рабочих вопросов и не превращает карточку документа в форум. Удалённый комментарий
 * не стирается из базы — остаётся отметка «удалено», чтобы переписка не теряла смысл, а
 * журнал сохранял доказательство.
 */
#[ORM\Entity(repositoryClass: DocumentCommentRepository::class)]
#[ORM\Table(name: 'document_comment')]
#[ORM\Index(name: 'idx_comment_document', columns: ['document_id', 'created_at'])]
#[ORM\Index(name: 'idx_comment_author', columns: ['author_id'])]
class DocumentComment
{
    /** Ограничение длины: обсуждение в карточке, а не переписка. */
    public const MAX_LENGTH = 4000;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Document::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Document $document;

    /** Ответ на другой комментарий (один уровень вложенности). */
    #[ORM\ManyToOne(targetEntity: self::class, inversedBy: 'replies')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?self $parent = null;

    /** @var Collection<int, self> */
    #[ORM\OneToMany(mappedBy: 'parent', targetEntity: self::class)]
    #[ORM\OrderBy(['createdAt' => 'ASC'])]
    private Collection $replies;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $author = null;

    /** Имя автора на момент отправки — сохраняется и после удаления учётной записи. */
    #[ORM\Column(length: 128)]
    private string $authorName = '';

    #[ORM\Column(type: Types::TEXT)]
    private string $body = '';

    /** Номер редакции, к которой относится комментарий (для истории обсуждения). */
    #[ORM\Column(nullable: true)]
    private ?int $versionNumber = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $editedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $deletedAt = null;

    #[ORM\Column(length: 128, nullable: true)]
    private ?string $deletedByName = null;

    public function __construct(Document $document, string $body, ?User $author, ?self $parent = null)
    {
        $this->document = $document;
        $this->replies = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
        $this->author = $author;
        $this->authorName = $author?->getDisplayName() ?? DocumentEvent::GUEST_NAME;
        $this->versionNumber = $document->getCurrentVersion()?->getNumber();
        $this->setParent($parent);
        $this->setBody($body);
    }

    /** Приводит текст к хранимому виду: без управляющих символов, с ограничением длины. */
    public static function clean(?string $body): string
    {
        $body = str_replace(["\r\n", "\r"], "\n", (string) $body);
        $body = preg_replace('/[^\P{C}\n]+/u', '', $body) ?? '';
        $body = preg_replace('/\n{3,}/', "\n\n", $body) ?? '';

        return mb_substr(trim($body), 0, self::MAX_LENGTH);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDocument(): Document
    {
        return $this->document;
    }

    public function getParent(): ?self
    {
        return $this->parent;
    }

    public function setParent(?self $parent): static
    {
        // Вложенность не глубже одного уровня: ответ на ответ крепится к исходной реплике.
        $this->parent = $parent?->getParent() ?? $parent;

        return $this;
    }

    /** @return Collection<int, self> */
    public function getReplies(): Collection
    {
        return $this->replies;
    }

    public function isReply(): bool
    {
        return null !== $this->parent;
    }

    public function getAuthor(): ?User
    {
        return $this->author;
    }

    public function getAuthorName(): string
    {
        return $this->authorName;
    }

    public function getBody(): string
    {
        return $this->isDeleted() ? '' : $this->body;
    }

    public function setBody(string $body): static
    {
        $this->body = self::clean($body);

        return $this;
    }

    /** Короткая выдержка для писем и уведомлений. */
    public function excerpt(int $length = 200): string
    {
        $text = preg_replace('/\s+/u', ' ', $this->getBody()) ?? '';

        return mb_strlen($text) > $length ? mb_substr($text, 0, $length - 1).'…' : $text;
    }

    public function getVersionNumber(): ?int
    {
        return $this->versionNumber;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getEditedAt(): ?\DateTimeImmutable
    {
        return $this->editedAt;
    }

    public function markEdited(): static
    {
        $this->editedAt = new \DateTimeImmutable();

        return $this;
    }

    public function isDeleted(): bool
    {
        return null !== $this->deletedAt;
    }

    public function getDeletedAt(): ?\DateTimeImmutable
    {
        return $this->deletedAt;
    }

    public function getDeletedByName(): ?string
    {
        return $this->deletedByName;
    }

    public function delete(?User $by): static
    {
        $this->deletedAt = new \DateTimeImmutable();
        $this->deletedByName = $by?->getDisplayName();

        return $this;
    }

    /** Может ли сотрудник править или удалять эту реплику (автор — свою, модератор — любую). */
    public function isOwnedBy(?User $user): bool
    {
        return null !== $user && null !== $this->author && $this->author->getId() === $user->getId();
    }
}
