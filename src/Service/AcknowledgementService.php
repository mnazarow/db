<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Document;
use App\Entity\DocumentAcknowledgement;
use App\Entity\DocumentEvent;
use App\Entity\User;
use App\Repository\DocumentAcknowledgementRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Ознакомление сотрудников с документами «под подпись»: назначение, подтверждение, напоминания.
 *
 * Подтверждение всегда привязано к номеру версии: после выпуска новой редакции ознакомление
 * назначается заново, а прежние записи сохраняются как история (доказательство по прежней редакции).
 */
final class AcknowledgementService
{
    /** Через сколько дней после последнего напоминания можно напомнить снова. */
    public const REMINDER_INTERVAL_DAYS = 3;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly DocumentAcknowledgementRepository $acknowledgements,
        private readonly UserRepository $users,
        private readonly MailerInterface $mailer,
        private readonly UrlGeneratorInterface $urls,
        private readonly LoggerInterface $auditLogger,
        private readonly string $mailFrom,
        private readonly string $appName,
    ) {
    }

    /**
     * Назначает ознакомление с текущей версией документа списку сотрудников.
     * Уже назначенным (с той же версией) повторно не назначается.
     *
     * @param iterable<User> $users
     *
     * @return array{created: int, existing: int, skipped: int, version: int}
     *
     * @throws \DomainException если у документа нет версий или он не опубликован
     */
    public function assign(Document $document, iterable $users, ?\DateTimeImmutable $dueAt, User $by, bool $notify = true): array
    {
        $version = $document->getCurrentVersion();
        if (null === $version) {
            throw new \DomainException('У документа нет ни одной версии — знакомить не с чем.');
        }
        if (!$document->isPublished()) {
            throw new \DomainException('Ознакомление назначается только для опубликованных документов.');
        }
        $number = $version->getNumber();
        $created = 0;
        $existing = 0;
        $skipped = 0;
        $recipients = [];
        foreach ($users as $user) {
            if (!$user->isActive()) {
                ++$skipped;
                continue;
            }
            if (null !== $this->acknowledgements->findOneFor($document, $user, $number)) {
                ++$existing;
                continue;
            }
            $this->em->persist(new DocumentAcknowledgement($document, $user, $number, $by, $dueAt));
            $recipients[] = $user;
            ++$created;
        }
        if ($created > 0) {
            $this->em->persist(new DocumentEvent($document, DocumentEvent::UPDATE, $by, $version, null, [
                'acknowledgement' => 'assigned',
                'users' => $created,
                'due' => $dueAt?->format('Y-m-d'),
            ]));
        }
        $this->em->flush();
        if ($created > 0) {
            $this->auditLogger->info('Назначено ознакомление с документом', ['document' => $document->getId(), 'version' => $number, 'users' => $created, 'due' => $dueAt?->format('Y-m-d'), 'by' => $by->getUsername()]);
        }
        if ($notify) {
            foreach ($recipients as $user) {
                $this->notifyAssigned($user, $document, $dueAt);
            }
        }

        return ['created' => $created, 'existing' => $existing, 'skipped' => $skipped, 'version' => $number];
    }

    /**
     * Подтверждение ознакомления сотрудником. Возвращает запись или null, если ознакомление не назначалось.
     */
    public function confirm(Document $document, User $user, ?string $ip = null): ?DocumentAcknowledgement
    {
        $acknowledgement = $this->acknowledgements->findPending($document, $user);
        if (null === $acknowledgement) {
            return null;
        }
        $acknowledgement->confirm($ip);
        $this->em->persist(new DocumentEvent($document, DocumentEvent::UPDATE, $user, null, $ip, [
            'acknowledgement' => 'confirmed',
            'version' => $acknowledgement->getVersionNumber(),
        ]));
        $this->em->flush();
        $this->auditLogger->info('Сотрудник ознакомился с документом', ['document' => $document->getId(), 'version' => $acknowledgement->getVersionNumber(), 'user' => $user->getUsername(), 'ip' => $ip]);

        return $acknowledgement;
    }

    /**
     * Кандидаты для назначения: активные пользователи, сгруппированные по подразделению.
     *
     * @return array<string, list<User>>
     */
    public function candidatesByDepartment(): array
    {
        $out = [];
        foreach ($this->users->findActiveOrdered() as $user) {
            $department = trim((string) $user->getDepartment());
            $out['' !== $department ? $department : 'Без подразделения'][] = $user;
        }
        ksort($out);

        return $out;
    }

    /**
     * Отчёт по документу: строки по сотрудникам и сводка (по указанной версии, по умолчанию — текущей).
     *
     * @return array{version: int, rows: list<DocumentAcknowledgement>, summary: array{assigned: int, confirmed: int, overdue: int}, versions: list<int>}
     */
    public function report(Document $document, ?int $versionNumber = null): array
    {
        $all = $this->acknowledgements->findForDocument($document);
        $versions = array_values(array_unique(array_map(static fn (DocumentAcknowledgement $a): int => $a->getVersionNumber(), $all)));
        rsort($versions);
        $version = $versionNumber ?? ($versions[0] ?? ($document->getCurrentVersion()?->getNumber() ?? 1));
        $rows = array_values(array_filter($all, static fn (DocumentAcknowledgement $a): bool => $a->getVersionNumber() === $version));

        return [
            'version' => $version,
            'rows' => $rows,
            'summary' => $this->acknowledgements->summaryForDocument($document, $version),
            'versions' => $versions,
        ];
    }

    /**
     * Рассылает напоминания о неподтверждённых ознакомлениях (по одному письму на сотрудника).
     *
     * @return array{users: int, items: int, emails: int}
     */
    public function remind(int $soonDays = 3, bool $dryRun = false): array
    {
        $today = new \DateTimeImmutable('today');
        $before = $today->modify('-'.self::REMINDER_INTERVAL_DAYS.' days');
        $pending = $this->acknowledgements->findForReminder($today, $soonDays, $before);
        $byUser = [];
        foreach ($pending as $item) {
            $byUser[$item->getUser()->getId()][] = $item;
        }
        $emails = 0;
        foreach ($byUser as $items) {
            $user = $items[0]->getUser();
            if ($dryRun || null === $user->getEmail()) {
                continue;
            }
            try {
                $this->mailer->send($this->buildReminder($user, $items));
                ++$emails;
                foreach ($items as $item) {
                    $item->markReminded();
                }
            } catch (\Throwable $e) {
                $this->auditLogger->warning('Не удалось отправить напоминание об ознакомлении', ['user' => $user->getUsername(), 'error' => $e->getMessage()]);
            }
        }
        if (!$dryRun) {
            $this->em->flush();
        }

        return ['users' => \count($byUser), 'items' => \count($pending), 'emails' => $emails];
    }

    private function notifyAssigned(User $user, Document $document, ?\DateTimeImmutable $dueAt): void
    {
        if (null === $user->getEmail()) {
            return;
        }
        try {
            $this->mailer->send((new TemplatedEmail())
                ->from(Address::create($this->mailFrom))
                ->to(new Address($user->getEmail(), $user->getDisplayName()))
                ->subject(\sprintf('%s: ознакомьтесь с документом «%s»', $this->appName, $document->getTitle()))
                ->htmlTemplate('emails/acknowledgement.html.twig')
                ->textTemplate('emails/acknowledgement.txt.twig')
                ->context([
                    'user' => $user,
                    'documents' => [$document],
                    'due' => $dueAt,
                    'urls' => [$document->getId() => $this->urls->generate('app_document_show', ['id' => $document->getId()], UrlGeneratorInterface::ABSOLUTE_URL)],
                    'app_name' => $this->appName,
                    'reminder' => false,
                ]));
        } catch (\Throwable $e) {
            $this->auditLogger->warning('Не удалось отправить уведомление об ознакомлении', ['user' => $user->getUsername(), 'document' => $document->getId(), 'error' => $e->getMessage()]);
        }
    }

    /** @param list<DocumentAcknowledgement> $items */
    private function buildReminder(User $user, array $items): TemplatedEmail
    {
        $documents = [];
        $urls = [];
        $due = null;
        foreach ($items as $item) {
            $documents[] = $item->getDocument();
            $urls[$item->getDocument()->getId()] = $this->urls->generate('app_document_show', ['id' => $item->getDocument()->getId()], UrlGeneratorInterface::ABSOLUTE_URL);
            $itemDue = $item->getDueAt();
            if (null !== $itemDue && (null === $due || $itemDue < $due)) {
                $due = $itemDue;
            }
        }

        return (new TemplatedEmail())
            ->from(Address::create($this->mailFrom))
            ->to(new Address((string) $user->getEmail(), $user->getDisplayName()))
            ->subject(\sprintf('%s: напоминание об ознакомлении с документами (%d)', $this->appName, \count($documents)))
            ->htmlTemplate('emails/acknowledgement.html.twig')
            ->textTemplate('emails/acknowledgement.txt.twig')
            ->context([
                'user' => $user,
                'documents' => $documents,
                'items' => $items,
                'due' => $due,
                'urls' => $urls,
                'app_name' => $this->appName,
                'reminder' => true,
            ]);
    }
}
