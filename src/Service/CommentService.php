<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Document;
use App\Entity\DocumentComment;
use App\Entity\User;
use App\Repository\DocumentCommentRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Обсуждение документов: вопросы сотрудников и ответы ответственных прямо в карточке.
 *
 * Правка и удаление: автор — свою реплику, модератор раздела и администратор — любую.
 * Удалённая реплика остаётся в базе с отметкой «удалено»: переписка не рассыпается,
 * а факт удаления виден в журнале аудита.
 */
final class CommentService
{
    /** Сколько времени автор может править свою реплику. */
    public const EDIT_WINDOW_MINUTES = 60;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly DocumentCommentRepository $comments,
        private readonly PortalSettings $settings,
        private readonly LoggerInterface $auditLogger,
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->settings->commentsEnabled();
    }

    /**
     * @return list<DocumentComment>
     */
    public function forDocument(Document $document): array
    {
        return $this->comments->findForDocument($document);
    }

    public function count(Document $document): int
    {
        return $this->comments->countForDocument($document);
    }

    /**
     * Добавляет реплику (или ответ на неё).
     *
     * @throws \DomainException если обсуждение выключено или текст пуст
     */
    public function add(Document $document, User $author, ?string $body, ?DocumentComment $parent = null): DocumentComment
    {
        if (!$this->isEnabled()) {
            throw new \DomainException('Обсуждение документов выключено администратором.');
        }
        if ('' === DocumentComment::clean($body)) {
            throw new \DomainException('Сообщение пустое.');
        }
        if (null !== $parent && $parent->getDocument()->getId() !== $document->getId()) {
            throw new \DomainException('Ответ относится к другому документу.');
        }
        $comment = new DocumentComment($document, (string) $body, $author, $parent);
        $this->em->persist($comment);
        $this->em->flush();
        $this->auditLogger->info('Сообщение в обсуждении документа', [
            'document' => $document->getId(),
            'title' => $document->getTitle(),
            'comment' => $comment->getId(),
            'user' => $author->getUsername(),
        ]);

        return $comment;
    }

    /** Может ли сотрудник править реплику: автор в течение часа, модератор — нет (только удалять). */
    public function canEdit(DocumentComment $comment, ?User $user): bool
    {
        if ($comment->isDeleted() || !$comment->isOwnedBy($user)) {
            return false;
        }

        return $comment->getCreatedAt() > new \DateTimeImmutable(\sprintf('-%d minutes', self::EDIT_WINDOW_MINUTES));
    }

    public function canDelete(DocumentComment $comment, ?User $user, bool $canManageDocument): bool
    {
        return !$comment->isDeleted() && ($canManageDocument || $comment->isOwnedBy($user));
    }

    public function edit(DocumentComment $comment, string $body, User $by): void
    {
        if ('' === DocumentComment::clean($body)) {
            throw new \DomainException('Сообщение пустое.');
        }
        $comment->setBody($body)->markEdited();
        $this->em->flush();
        $this->auditLogger->info('Изменено сообщение в обсуждении', ['document' => $comment->getDocument()->getId(), 'comment' => $comment->getId(), 'user' => $by->getUsername()]);
    }

    public function delete(DocumentComment $comment, User $by): void
    {
        $comment->delete($by);
        $this->em->flush();
        $this->auditLogger->info('Удалено сообщение в обсуждении', [
            'document' => $comment->getDocument()->getId(),
            'comment' => $comment->getId(),
            'author' => $comment->getAuthorName(),
            'user' => $by->getUsername(),
        ]);
    }
}
