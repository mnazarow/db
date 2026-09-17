<?php

declare(strict_types=1);

namespace App\Service\Notification;

use App\Entity\Document;
use App\Entity\DocumentComment;
use App\Entity\DocumentEvent;
use App\Entity\User;
use App\Repository\DocumentCommentRepository;
use App\Repository\DocumentSubscriptionRepository;
use App\Security\Access;
use App\Service\PortalSettings;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Events;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Уведомления подписчикам: что изменилось в документе и что написали в обсуждении.
 *
 * Изменения копятся за время запроса (или консольной команды) и отправляются один раз после ответа:
 * при загрузке новой версии с публикацией сотрудник получает одно письмо, а не три. Адресаты —
 * подписчики документа и разделов над ним, автор документа и участники обсуждения; каждому
 * проверяется право видеть документ, чтобы уведомление не раскрывало документ с ограниченным доступом.
 */
#[AsDoctrineListener(event: Events::postPersist)]
final class SubscriptionNotifier implements EventSubscriberInterface
{
    /** События, о которых стоит сообщать подписчикам. */
    private const NOTIFY_TYPES = [
        DocumentEvent::PUBLISH => 'документ опубликован',
        DocumentEvent::NEW_VERSION => 'загружена новая редакция',
        DocumentEvent::RESTORE => 'восстановлена прежняя редакция',
        DocumentEvent::UPDATE => 'изменена карточка документа',
        DocumentEvent::ARCHIVE => 'документ перенесён в архив',
        DocumentEvent::UNPUBLISH => 'документ снят с публикации',
    ];

    /** Защита от лавины писем при массовом импорте. */
    private const MAX_DOCUMENTS = 50;

    /** @var array<int, array{document: Document, changes: array<string, string>, comments: list<DocumentComment>, actors: array<int, true>}> */
    private array $pending = [];

    private bool $sending = false;

    public function __construct(
        private readonly DocumentSubscriptionRepository $subscriptions,
        private readonly DocumentCommentRepository $comments,
        private readonly TelegramNotifier $telegram,
        private readonly MailerInterface $mailer,
        private readonly UrlGeneratorInterface $urls,
        private readonly Access $access,
        private readonly PortalSettings $settings,
        private readonly LoggerInterface $auditLogger,
        private readonly string $mailFrom,
        private readonly string $appName,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::TERMINATE => ['flush', -110],
            ConsoleEvents::TERMINATE => ['flush', -110],
        ];
    }

    public function postPersist(PostPersistEventArgs $args): void
    {
        $entity = $args->getObject();
        if ($entity instanceof DocumentEvent && isset(self::NOTIFY_TYPES[$entity->getType()])) {
            $entry = &$this->bucket($entity->getDocument());
            $entry['changes'][$entity->getType()] = self::NOTIFY_TYPES[$entity->getType()];
            if (null !== $entity->getUser()) {
                $entry['actors'][(int) $entity->getUser()->getId()] = true;
            }
        } elseif ($entity instanceof DocumentComment) {
            $entry = &$this->bucket($entity->getDocument());
            $entry['comments'][] = $entity;
            if (null !== $entity->getAuthor()) {
                $entry['actors'][(int) $entity->getAuthor()->getId()] = true;
            }
        }
    }

    public function hasPending(): bool
    {
        return [] !== $this->pending;
    }

    /**
     * Рассылает накопленные уведомления.
     *
     * @return array{documents: int, recipients: int, emails: int, telegram: int}
     */
    public function flush(): array
    {
        $stats = ['documents' => 0, 'recipients' => 0, 'emails' => 0, 'telegram' => 0];
        if ([] === $this->pending || $this->sending) {
            return $stats;
        }
        $this->sending = true;
        $pending = \array_slice($this->pending, 0, self::MAX_DOCUMENTS, true);
        $this->pending = [];
        try {
            foreach ($pending as $entry) {
                $document = $entry['document'];
                // Документ, удалённый в этом же запросе, уже не существует — уведомлять о нём нечего.
                if (null === $document->getId()) {
                    continue;
                }
                // Черновики и документы на согласовании подписчикам не анонсируем: событие о них придёт при публикации.
                if (!$document->isPublished() && [] === $entry['comments']) {
                    continue;
                }
                $recipients = $this->recipients($document, $entry);
                if ([] === $recipients) {
                    continue;
                }
                ++$stats['documents'];
                foreach ($recipients as $user) {
                    ++$stats['recipients'];
                    $stats['emails'] += $this->email($user, $document, $entry) ? 1 : 0;
                    $stats['telegram'] += $this->message($user, $document, $entry) ? 1 : 0;
                }
            }
        } finally {
            $this->sending = false;
        }

        return $stats;
    }

    /**
     * @param array{document: Document, changes: array<string, string>, comments: list<DocumentComment>, actors: array<int, true>} $entry
     *
     * @return list<User>
     */
    private function recipients(Document $document, array $entry): array
    {
        if (!$this->settings->subscriptionsEnabled()) {
            return [];
        }
        $section = $document->getSection();
        $candidates = $this->subscriptions->subscribers($document, $section->getPathIds());
        if ([] !== $entry['comments']) {
            // Обсуждение касается и владельца документа, и тех, кто уже отвечал в этой ветке.
            $owner = $document->getOwner();
            if (null !== $owner && $owner->isActive()) {
                $candidates[] = $owner;
            }
            foreach ($this->comments->participants($document) as $participant) {
                $candidates[] = $participant;
            }
        }
        $unique = [];
        foreach ($candidates as $user) {
            $id = (int) $user->getId();
            if (isset($entry['actors'][$id]) || isset($unique[$id])) {
                continue;
            }
            if (!$this->access->canViewDocument($user, $document)) {
                continue;
            }
            $unique[$id] = $user;
        }

        return array_values($unique);
    }

    /**
     * @param array{document: Document, changes: array<string, string>, comments: list<DocumentComment>, actors: array<int, true>} $entry
     */
    private function email(User $user, Document $document, array $entry): bool
    {
        if (!$user->isNotifyEmail() || null === $user->getEmail()) {
            return false;
        }
        $comments = array_values(array_filter($entry['comments'], static fn (DocumentComment $c): bool => !$c->isDeleted()));
        $changes = array_values($entry['changes']);
        $subject = [] !== $comments && [] === $changes
            ? \sprintf('%s: новое сообщение в обсуждении «%s»', $this->appName, $document->getTitle())
            : \sprintf('%s: изменения в документе «%s»', $this->appName, $document->getTitle());
        try {
            $this->mailer->send((new TemplatedEmail())
                ->from(Address::create($this->mailFrom))
                ->to(new Address($user->getEmail(), $user->getDisplayName()))
                ->subject($subject)
                ->htmlTemplate('emails/subscription.html.twig')
                ->textTemplate('emails/subscription.txt.twig')
                ->context([
                    'user' => $user,
                    'document' => $document,
                    'changes' => $changes,
                    'comments' => $comments,
                    'url' => $this->url($document),
                    'settings_url' => $this->urls->generate('app_profile_subscriptions', [], UrlGeneratorInterface::ABSOLUTE_URL),
                    'app_name' => $this->appName,
                ]));

            return true;
        } catch (\Throwable $e) {
            $this->auditLogger->warning('Не удалось отправить уведомление подписчику', ['user' => $user->getUsername(), 'document' => $document->getId(), 'error' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * @param array{document: Document, changes: array<string, string>, comments: list<DocumentComment>, actors: array<int, true>} $entry
     */
    private function message(User $user, Document $document, array $entry): bool
    {
        if (!$user->hasTelegram() || !$user->isNotifyTelegram() || !$this->telegram->isEnabled()) {
            return false;
        }
        $escape = static fn (string $text): string => htmlspecialchars($text, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');
        $lines = [\sprintf('<b>%s</b>', $escape($document->getTitle()))];
        $lines[] = $escape($document->getSection()->getFullName());
        foreach ($entry['changes'] as $change) {
            $lines[] = '• '.$escape($change);
        }
        foreach ($entry['comments'] as $comment) {
            if (!$comment->isDeleted()) {
                $lines[] = \sprintf('💬 <b>%s</b>: %s', $escape($comment->getAuthorName()), $escape($comment->excerpt(300)));
            }
        }
        $lines[] = $this->url($document);

        return $this->telegram->notify($user, implode("\n", $lines));
    }

    private function url(Document $document): string
    {
        return $this->urls->generate('app_document_show', ['id' => $document->getId()], UrlGeneratorInterface::ABSOLUTE_URL);
    }

    /**
     * @return array{document: Document, changes: array<string, string>, comments: list<DocumentComment>, actors: array<int, true>}
     */
    private function &bucket(Document $document): array
    {
        $id = (int) $document->getId();
        $this->pending[$id] ??= ['document' => $document, 'changes' => [], 'comments' => [], 'actors' => []];

        return $this->pending[$id];
    }
}
