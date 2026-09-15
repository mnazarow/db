<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Document;
use App\Entity\DocumentEvent;
use App\Entity\User;
use App\Repository\DocumentRepository;
use App\Repository\SectionModeratorRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Контроль сроков актуальности: находит документы, срок которых истекает или истёк,
 * и рассылает уведомления ответственным (владельцу, модераторам раздела, администраторам).
 * Каждый документ уведомляется один раз на стадии «истекает» и один раз на стадии «просрочен»
 * (стадия сбрасывается при изменении срока).
 */
final class ExpiryNotifier
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly DocumentRepository $documents,
        private readonly SectionModeratorRepository $moderators,
        private readonly UserRepository $users,
        private readonly Validity $validity,
        private readonly MailerInterface $mailer,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly LoggerInterface $auditLogger,
        private readonly string $mailFrom,
        private readonly bool $notifyAdmins,
        private readonly string $appName,
    ) {
    }

    /**
     * Проверяет сроки и отправляет уведомления.
     *
     * @return array{checked: int, soon: int, expired: int, notified: int, emails: int, skipped: int, recipients: list<string>}
     */
    public function run(bool $force = false, bool $dryRun = false): array
    {
        $today = $this->validity->today();
        $candidates = $this->documents->findExpiring($today, $this->validity->getSoonDays());
        $stats = ['checked' => \count($candidates), 'soon' => 0, 'expired' => 0, 'notified' => 0, 'emails' => 0, 'skipped' => 0, 'recipients' => []];

        /** @var array<string, array{user: User, soon: list<Document>, expired: list<Document>}> $byRecipient */
        $byRecipient = [];
        $admins = $this->notifyAdmins ? $this->users->findActiveAdmins() : [];

        foreach ($candidates as $document) {
            $expired = $document->isExpired($today);
            $stage = $expired ? 2 : 1;
            $expired ? ++$stats['expired'] : ++$stats['soon'];
            if (!$force && $document->getExpiryNoticeStage() >= $stage) {
                ++$stats['skipped'];
                continue;
            }
            $recipients = $this->recipientsFor($document, $admins);
            foreach ($recipients as $user) {
                $key = $user->getEmail() ?? ('#'.$user->getId());
                $byRecipient[$key] ??= ['user' => $user, 'soon' => [], 'expired' => []];
                $byRecipient[$key][$expired ? 'expired' : 'soon'][] = $document;
            }
            ++$stats['notified'];
            if (!$dryRun) {
                $document->setExpiryNoticeStage($stage);
                $this->em->persist(new DocumentEvent($document, DocumentEvent::EXPIRY_NOTICE, null, null, null, [
                    'stage' => $expired ? 'expired' : 'soon',
                    'valid_until' => $document->getValidUntil()?->format('Y-m-d'),
                    'recipients' => array_map(static fn (User $u) => $u->getUsername(), $recipients),
                ]));
            }
        }

        foreach ($byRecipient as $entry) {
            $user = $entry['user'];
            if (null === $user->getEmail()) {
                $this->auditLogger->warning('Уведомление о сроке не отправлено: у пользователя нет e-mail', ['user' => $user->getUsername()]);
                continue;
            }
            $stats['recipients'][] = $user->getEmail();
            if ($dryRun) {
                continue;
            }
            try {
                $this->mailer->send($this->buildEmail($user, $entry['soon'], $entry['expired']));
                ++$stats['emails'];
            } catch (\Throwable $e) {
                $this->auditLogger->error('Ошибка отправки уведомления о сроках', ['user' => $user->getUsername(), 'error' => $e->getMessage()]);
            }
        }

        if (!$dryRun) {
            $this->em->flush();
        }
        $this->auditLogger->info('Проверка сроков актуальности', $stats);

        return $stats;
    }

    /**
     * @param list<User> $admins
     *
     * @return list<User>
     */
    private function recipientsFor(Document $document, array $admins): array
    {
        $recipients = [];
        $owner = $document->getOwner();
        if (null !== $owner && $owner->isActive()) {
            $recipients[$owner->getId()] = $owner;
        }
        foreach ($this->moderators->findResponsibleUsers($document->getSection()) as $user) {
            $recipients[$user->getId()] = $user;
        }
        foreach ($admins as $admin) {
            $recipients[$admin->getId()] = $admin;
        }

        return array_values($recipients);
    }

    /**
     * @param list<Document> $soon
     * @param list<Document> $expired
     */
    private function buildEmail(User $user, array $soon, array $expired): TemplatedEmail
    {
        $subject = \sprintf('%s: %s', $this->appName, [] !== $expired ? 'документы с истёкшим сроком актуальности' : 'документы с истекающим сроком актуальности');
        $link = static fn (Document $d): string => $d->getTitle();
        $urls = [];
        foreach (array_merge($soon, $expired) as $d) {
            $urls[$d->getId()] = $this->urlGenerator->generate('app_document_show', ['id' => $d->getId()], UrlGeneratorInterface::ABSOLUTE_URL);
        }

        return (new TemplatedEmail())
            ->from(Address::create($this->mailFrom))
            ->to(new Address($user->getEmail(), $user->getDisplayName()))
            ->subject($subject)
            ->htmlTemplate('emails/expiry.html.twig')
            ->textTemplate('emails/expiry.txt.twig')
            ->context([
                'user' => $user,
                'soon' => $soon,
                'expired' => $expired,
                'urls' => $urls,
                'today' => $this->validity->today(),
                'soon_days' => $this->validity->getSoonDays(),
                'app_name' => $this->appName,
            ]);
    }

    /** Тестовое письмо (страница настроек). */
    public function sendTest(User $user): void
    {
        if (null === $user->getEmail()) {
            throw new \DomainException('У вашей учётной записи не указан e-mail.');
        }
        $this->mailer->send((new TemplatedEmail())
            ->from(Address::create($this->mailFrom))
            ->to(new Address($user->getEmail(), $user->getDisplayName()))
            ->subject($this->appName.': проверка почты')
            ->text(\sprintf("Здравствуйте, %s!\n\nЭто тестовое письмо портала «%s». Если вы его получили, отправка почты настроена правильно.", $user->getDisplayName(), $this->appName)));
    }
}
