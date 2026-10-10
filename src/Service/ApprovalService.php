<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Document;
use App\Entity\DocumentApproval;
use App\Entity\DocumentEvent;
use App\Entity\DocumentVersion;
use App\Entity\User;
use App\Repository\DocumentApprovalRepository;
use App\Repository\UserRepository;
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
    public function request(Document $document, User $approver, ?string $note, User $by): DocumentApproval
    {
        $version = $this->versionToApprove($document);
        if (null === $version) {
            throw new \DomainException('У документа нет ни одной версии — согласовывать нечего.');
        }
        if ($document->isArchived()) {
            throw new \DomainException('Документ в архиве: снимите его из архива, прежде чем согласовывать.');
        }
        if ($document->isPublished() && null === $document->getPendingVersion()) {
            throw new \DomainException('Документ уже опубликован. Согласовывать нужно новую редакцию: загрузите её — она будет ждать согласования, а сотрудники до решения увидят прежнюю.');
        }
        if (!$approver->isActive()) {
            throw new \DomainException('Учётная запись согласующего заблокирована.');
        }
        if ($approver->getId() === $by->getId()) {
            throw new \DomainException('Нельзя отправить документ на согласование самому себе.');
        }
        // Строка документа блокируется до конца записи: двойной щелчок или два модератора одновременно
        // не создадут два запроса.
        $connection = $this->em->getConnection();
        $connection->beginTransaction();
        try {
            $this->em->lock($document, LockMode::PESSIMISTIC_WRITE);
            if (null !== $this->approvals->findPending($document)) {
                throw new \DomainException('Документ уже на согласовании — дождитесь решения или отзовите запрос.');
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
                'approver' => $approver->getDisplayName(),
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
        $this->auditLogger->info('Документ отправлен на согласование', ['document' => $document->getId(), 'version' => $version->getNumber(), 'approver' => $approver->getUsername(), 'by' => $by->getUsername()]);
        $this->notify($approval, 'requested');

        return $approval;
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
        if ($approval->getApprover()->getId() !== $by->getId() && !$by->isAdmin()) {
            throw new \DomainException('Решение принимает назначенный согласующий.');
        }
        $document = $approval->getDocument();
        $version = $document->findVersion($approval->getVersionNumber());
        if ($document->isArchived() || null === $version) {
            $approval->decide(DocumentApproval::CANCELLED, 'Запрос закрыт автоматически: '.($document->isArchived() ? 'документ в архиве.' : 'редакция удалена.'));
            $this->em->flush();
            throw new \DomainException('Запрос согласования больше не действует: '.($document->isArchived() ? 'документ перенесён в архив.' : 'редакция удалена.'));
        }
        $approval->decide($approved ? DocumentApproval::APPROVED : DocumentApproval::REJECTED, $note);
        if ($document->isOnReview()) {
            $document->setStatus(Document::STATUS_DRAFT)->touch();
        }
        $this->em->persist(new DocumentEvent($document, DocumentEvent::APPROVAL, $by, $version, null, [
            'approval' => $approved ? 'approved' : 'rejected',
            'version' => $approval->getVersionNumber(),
        ]));
        $this->em->flush();
        $this->auditLogger->info($approved ? 'Документ согласован' : 'Документ отклонён при согласовании', [
            'document' => $document->getId(), 'version' => $approval->getVersionNumber(), 'by' => $by->getUsername(),
        ]);
        // Автопубликация: согласованная редакция сразу становится доступной сотрудникам — именно она,
        // а не та, что окажется текущей к моменту решения.
        if ($approved && $this->isAutoPublish()) {
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
        $recipient = 'requested' === $kind ? $approval->getApprover() : $approval->getRequestedBy();
        if (null === $recipient || null === $recipient->getEmail()) {
            return;
        }
        $document = $approval->getDocument();
        $subject = match ($kind) {
            'requested' => \sprintf('%s: согласуйте документ «%s»', $this->appName, $document->getTitle()),
            'approved' => \sprintf('%s: документ «%s» согласован', $this->appName, $document->getTitle()),
            default => \sprintf('%s: документ «%s» отклонён', $this->appName, $document->getTitle()),
        };
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
                    'url' => $this->portalUrl->absolute('app_document_show', ['id' => $document->getId()]),
                    'app_name' => $this->appName,
                ]));
        } catch (\Throwable $e) {
            $this->auditLogger->warning('Не удалось отправить письмо о согласовании', ['document' => $document->getId(), 'error' => $e->getMessage()]);
        }
    }
}
