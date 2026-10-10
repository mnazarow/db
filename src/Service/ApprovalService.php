<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Document;
use App\Entity\DocumentApproval;
use App\Entity\DocumentEvent;
use App\Entity\DocumentVersion;
use App\Entity\User;
use App\Repository\DocumentApprovalRepository;
use App\Repository\SectionModeratorRepository;
use App\Repository\UserRepository;
use App\Security\Access;
use App\Service\Notification\TelegramNotifier;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;

/**
 * Согласование документа перед публикацией.
 *
 * Модератор отправляет черновик согласующему (обычно владельцу процесса или руководителю),
 * тот согласует или отклоняет с комментарием. Решение привязано к редакции: после новой
 * версии согласование запрашивается заново. При обязательном согласовании новая редакция
 * опубликованного документа ждёт решения, а сотрудники до него видят прежнюю.
 *
 * Модерация — тот же механизм без конкретного согласующего: помощник маркетолога отправляет материал
 * в общую очередь, решает любой маркетолог (или модератор раздела); одобренный материал сразу
 * публикуется (новая редакция опубликованного документа — вступает в силу).
 */
final class ApprovalService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly DocumentApprovalRepository $approvals,
        private readonly UserRepository $users,
        private readonly PortalSettings $settings,
        private readonly DocumentManager $documents,
        private readonly MailerInterface $mailer,
        private readonly LoggerInterface $auditLogger,
        private readonly string $mailFrom,
        private readonly string $appName,
        private readonly PortalUrl $portalUrl,
        private readonly ?Access $access = null,
        private readonly ?SectionModeratorRepository $moderators = null,
        private readonly ?TelegramNotifier $telegram = null,
    ) {
    }

    /** Обязательно ли согласование перед публикацией. */
    public function isRequired(): bool
    {
        return $this->settings->approval()['required'];
    }

    /** Публиковать ли документ сразу после согласования. */
    public function isAutoPublish(): bool
    {
        return $this->settings->approval()['auto_publish'];
    }

    /**
     * Можно ли публиковать документ: без обязательного согласования — всегда,
     * иначе — только если текущая редакция согласована.
     */
    public function canPublish(Document $document): bool
    {
        if (!$this->isRequired()) {
            return true;
        }
        $version = $document->getCurrentVersion();

        return null !== $version && $this->approvals->isApproved($document, $version->getNumber());
    }

    /**
     * Можно ли сделать новую редакцию текущей: у неопубликованного документа — всегда (сотрудники его не видят),
     * у опубликованного — без обязательного согласования или если редакция согласована.
     */
    public function canPromote(Document $document, DocumentVersion $version): bool
    {
        return !$document->isPublished() || !$this->isRequired() || $this->approvals->isApproved($document, $version->getNumber());
    }

    /** Какую редакцию отправлять на согласование: новую (ждущую), а если её нет — текущую. */
    public function versionToApprove(Document $document): ?DocumentVersion
    {
        return $document->getPendingVersion() ?? $document->getCurrentVersion();
    }

    /**
     * Отправляет документ (или новую редакцию опубликованного документа) на согласование.
     *
     * @throws \DomainException если документ нельзя отправить
     */
    public function request(Document $document, ?User $approver, ?string $note, User $by): DocumentApproval
    {
        $version = $this->versionToApprove($document);
        if (null === $version) {
            throw new \DomainException('У документа нет ни одной версии — согласовывать нечего.');
        }
        if ($document->isArchived()) {
            throw new \DomainException('Документ в архиве: снимите его из архива, прежде чем согласовывать.');
        }
        if ($document->isPublished() && null === $document->getPendingVersion()) {
            throw new \DomainException(null === $approver
                ? 'Документ уже опубликован. На модерацию отправляется новая редакция: загрузите её — до решения сотрудники видят прежнюю.'
                : 'Документ уже опубликован. Согласовывать нужно новую редакцию: загрузите её — она будет ждать согласования, а сотрудники до решения увидят прежнюю.');
        }
        if (null !== $approver && !$approver->isActive()) {
            throw new \DomainException('Учётная запись согласующего заблокирована.');
        }
        if (null !== $approver && $approver->getId() === $by->getId()) {
            throw new \DomainException('Нельзя отправить документ на согласование самому себе.');
        }
        // Строка документа блокируется до конца записи: двойной щелчок или два модератора одновременно
        // не создадут два запроса.
        $connection = $this->em->getConnection();
        $connection->beginTransaction();
        try {
            $this->em->lock($document, LockMode::PESSIMISTIC_WRITE);
            if (null !== $this->approvals->findPending($document)) {
                throw new \DomainException('Документ уже на согласовании или модерации — дождитесь решения или отзовите запрос.');
            }
            $approval = new DocumentApproval($document, $version->getNumber(), $approver, $by, $note);
            $this->em->persist($approval);
            // Черновик на время согласования получает статус «на согласовании»; опубликованный документ
            // остаётся опубликованным — согласуется его новая редакция.
            if (!$document->isPublished()) {
                $document->setStatus(Document::STATUS_REVIEW)->touch();
            }
            $this->em->persist(new DocumentEvent($document, DocumentEvent::APPROVAL, $by, $version, null, [
                'approval' => 'requested',
                'approver' => $approver?->getDisplayName() ?? 'модерация',
                'moderation' => null === $approver,
                'version' => $version->getNumber(),
            ]));
            $this->em->flush();
            $connection->commit();
        } catch (\Throwable $e) {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
            throw $e;
        }
        $this->auditLogger->info(null === $approver ? 'Материал отправлен на модерацию' : 'Документ отправлен на согласование', ['document' => $document->getId(), 'version' => $version->getNumber(), 'approver' => $approver?->getUsername(), 'by' => $by->getUsername()]);
        $this->notify($approval, 'requested');

        return $approval;
    }

    /** Незакрытый запрос согласования или модерации по документу. */
    public function pendingFor(Document $document): ?DocumentApproval
    {
        return $this->approvals->findPending($document);
    }

    /** Отправка материала помощника маркетолога в общую очередь модерации. */
    public function submitForModeration(Document $document, ?string $note, User $by): DocumentApproval
    {
        return $this->request($document, null, $note, $by);
    }

    /**
     * Может ли сотрудник решить по запросу: назначенный согласующий; по модерации — тот, кто управляет
     * разделом документа (маркетолог, модератор раздела), но не автор запроса; администратор — всегда.
     */
    public function canDecide(DocumentApproval $approval, ?User $user): bool
    {
        if (null === $user || !$approval->isPending()) {
            return false;
        }
        if ($user->isAdmin()) {
            return true;
        }
        if (!$approval->isModeration()) {
            return $approval->getApprover()?->getId() === $user->getId();
        }
        if ($approval->getRequestedBy()?->getId() === $user->getId()) {
            return false;
        }

        return null !== $this->access && $this->access->canManageSection($user, $approval->getDocument()->getSection());
    }

    /**
     * Кто получает материалы на модерацию из раздела: модераторы раздела (по наследованию) и маркетологи;
     * если таких нет — администраторы.
     *
     * @return list<User>
     */
    public function moderatorsFor(Document $document): array
    {
        $out = [];
        foreach ($this->moderators?->findResponsibleUsers($document->getSection()) ?? [] as $user) {
            $out[(int) $user->getId()] = $user;
        }
        foreach ($this->users->findActiveMarketers() as $user) {
            $out[(int) $user->getId()] = $user;
        }
        if ([] === $out) {
            foreach ($this->users->findActiveAdmins() as $user) {
                $out[(int) $user->getId()] = $user;
            }
        }

        return array_values($out);
    }

    /**
     * Решение согласующего. Возвращает согласование с решением.
     *
     * @throws \DomainException если решение принимает не тот сотрудник или запрос уже закрыт
     */
    public function decide(DocumentApproval $approval, bool $approved, ?string $note, User $by): DocumentApproval
    {
        if (!$approval->isPending()) {
            throw new \DomainException('Решение по этому запросу уже принято.');
        }
        if (!$this->canDecide($approval, $by)) {
            throw new \DomainException($approval->isModeration()
                ? 'Решение по материалу на модерации принимает маркетолог или модератор раздела.'
                : 'Решение принимает назначенный согласующий.');
        }
        $document = $approval->getDocument();
        $version = $document->findVersion($approval->getVersionNumber());
        if ($document->isArchived() || null === $version) {
            $approval->decide(DocumentApproval::CANCELLED, 'Запрос закрыт автоматически: '.($document->isArchived() ? 'документ в архиве.' : 'редакция удалена.'));
            $this->em->flush();
            throw new \DomainException('Запрос согласования больше не действует: '.($document->isArchived() ? 'документ перенесён в архив.' : 'редакция удалена.'));
        }
        $approval->decide($approved ? DocumentApproval::APPROVED : DocumentApproval::REJECTED, $note, $by);
        if ($document->isOnReview()) {
            $document->setStatus(Document::STATUS_DRAFT)->touch();
        }
        $this->em->persist(new DocumentEvent($document, DocumentEvent::APPROVAL, $by, $version, null, [
            'approval' => $approved ? 'approved' : 'rejected',
            'moderation' => $approval->isModeration(),
            'version' => $approval->getVersionNumber(),
        ]));
        $this->em->flush();
        $this->auditLogger->info($approved ? 'Документ согласован' : 'Документ отклонён при согласовании', [
            'document' => $document->getId(), 'version' => $approval->getVersionNumber(), 'by' => $by->getUsername(),
        ]);
        // Автопубликация: согласованная редакция сразу становится доступной сотрудникам — именно она,
        // а не та, что окажется текущей к моменту решения. Одобренный на модерации материал публикуется всегда:
        // в этом смысл модерации.
        if ($approved && ($this->isAutoPublish() || $approval->isModeration())) {
            if (!$document->isVersionReleased($version)) {
                $this->documents->promoteVersion($document, $version, $by);
            }
            if (!$document->isPublished() && $document->getCurrentVersion()?->getNumber() === $version->getNumber()) {
                $this->documents->publish($document, $by);
            }
        }
        $this->notify($approval, $approved ? 'approved' : 'rejected');

        return $approval;
    }

    /** Отзыв запроса автором или администратором. */
    public function cancel(DocumentApproval $approval, User $by): void
    {
        if (!$approval->isPending()) {
            return;
        }
        $approval->decide(DocumentApproval::CANCELLED, null);
        $document = $approval->getDocument();
        if ($document->isOnReview()) {
            $document->setStatus(Document::STATUS_DRAFT)->touch();
        }
        $this->em->persist(new DocumentEvent($document, DocumentEvent::APPROVAL, $by, null, null, [
            'approval' => 'cancelled',
            'version' => $approval->getVersionNumber(),
        ]));
        $this->em->flush();
        $this->auditLogger->info('Запрос согласования отозван', ['document' => $document->getId(), 'by' => $by->getUsername()]);
    }

    /**
     * Кандидаты в согласующие: активные пользователи по подразделениям (кроме самого отправителя).
     *
     * @return array<string, list<User>>
     */
    public function candidatesByDepartment(User $except): array
    {
        $out = [];
        foreach ($this->users->findActiveOrdered() as $user) {
            if ($user->getId() === $except->getId()) {
                continue;
            }
            $department = trim((string) $user->getDepartment());
            $out['' !== $department ? $department : 'Без подразделения'][] = $user;
        }
        ksort($out);

        return $out;
    }

    private function notify(DocumentApproval $approval, string $kind): void
    {
        $document = $approval->getDocument();
        if ('requested' === $kind) {
            $recipients = $approval->isModeration() ? $this->moderatorsFor($document) : [$approval->getApprover()];
        } else {
            $recipients = [$approval->getRequestedBy()];
        }
        $by = 'requested' === $kind ? $approval->getRequestedBy() : $approval->getDecidedBy();
        $moderation = $approval->isModeration();
        $subject = match ($kind) {
            'requested' => $moderation
                ? \sprintf('%s: материал на модерации — «%s»', $this->appName, $document->getTitle())
                : \sprintf('%s: согласуйте документ «%s»', $this->appName, $document->getTitle()),
            'approved' => $moderation
                ? \sprintf('%s: материал «%s» одобрен и опубликован', $this->appName, $document->getTitle())
                : \sprintf('%s: документ «%s» согласован', $this->appName, $document->getTitle()),
            default => $moderation
                ? \sprintf('%s: материал «%s» возвращён на доработку', $this->appName, $document->getTitle())
                : \sprintf('%s: документ «%s» отклонён', $this->appName, $document->getTitle()),
        };
        $url = $this->portalUrl->absolute('app_document_show', ['id' => $document->getId()]);
        foreach ($recipients as $recipient) {
            if (null === $recipient || !$recipient->isActive() || $recipient->getId() === $by?->getId()) {
                continue;
            }
            if (null !== $recipient->getEmail()) {
                try {
                    $this->mailer->send((new TemplatedEmail())
                        ->from(Address::create($this->mailFrom))
                        ->to(new Address($recipient->getEmail(), $recipient->getDisplayName()))
                        ->subject($subject)
                        ->htmlTemplate('emails/approval.html.twig')
                        ->textTemplate('emails/approval.txt.twig')
                        ->context([
                            'user' => $recipient,
                            'approval' => $approval,
                            'document' => $document,
                            'kind' => $kind,
                            'moderation' => $moderation,
                            'url' => $url,
                            'app_name' => $this->appName,
                        ]));
                } catch (\Throwable $e) {
                    $this->auditLogger->warning('Не удалось отправить письмо о согласовании', ['document' => $document->getId(), 'error' => $e->getMessage()]);
                }
            }
            // В Telegram — короткое сообщение тем, кто привязал чат (о модерации и о решении по своему материалу).
            if ($moderation && null !== $this->telegram) {
                try {
                    $this->telegram->notify($recipient, \sprintf('<b>%s</b>%s<a href="%s">%s</a>',
                        htmlspecialchars($subject, \ENT_QUOTES),
                        'rejected' === $kind && null !== $approval->getDecisionNote() ? "\n".htmlspecialchars($approval->getDecisionNote(), \ENT_QUOTES)."\n" : "\n",
                        htmlspecialchars($url, \ENT_QUOTES),
                        htmlspecialchars($url, \ENT_QUOTES),
                    ));
                } catch (\Throwable $e) {
                    $this->auditLogger->warning('Не удалось отправить сообщение Telegram о модерации', ['document' => $document->getId(), 'error' => $e->getMessage()]);
                }
            }
        }
    }
}
