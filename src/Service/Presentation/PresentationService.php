<?php

declare(strict_types=1);

namespace App\Service\Presentation;

use App\Entity\Meeting;
use App\Entity\Presentation;
use App\Entity\PresentationVersion;
use App\Entity\User;
use App\Repository\PresentationRepository;
use App\Repository\PresentationVersionRepository;
use App\Repository\UserRepository;
use App\Service\Notification\TelegramNotifier;
use App\Service\PortalUrl;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;

/**
 * Жизненный цикл презентации: создание из макетов, сохранение слайдов из редактора (с проверкой формата
 * и защитой от одновременной правки), история версий, отправка на модерацию, решение маркетолога или
 * помощника маркетолога, копирование и удаление. Уведомления — письмом, в Telegram и в браузер.
 */
final class PresentationService
{
    /** Автосохранения попадают в историю не чаще раза в столько секунд. */
    public const VERSION_INTERVAL = 600;
    public const KEEP_AUTO_VERSIONS = 40;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly DeckValidator $validator,
        private readonly PresentationRepository $presentations,
        private readonly PresentationVersionRepository $versions,
        private readonly UserRepository $users,
        private readonly PresentationAccess $access,
        private readonly AssetStore $assets,
        private readonly MailerInterface $mailer,
        private readonly LoggerInterface $auditLogger,
        private readonly string $mailFrom,
        private readonly string $appName,
        private readonly PortalUrl $portalUrl,
        private readonly ?TelegramNotifier $telegram = null,
    ) {
    }

    /**
     * Новая презентация: обычная — титульный слайд и «Заголовок и текст», тема собрания — слайд «Заголовок
     * и текст» с шапкой, привязанной к названию темы (титул, содержание и финал добавит сборка собрания).
     */
    public function create(string $kind, string $title, ?string $responsible, User $author, ?Meeting $meeting = null): Presentation
    {
        $p = new Presentation($kind, $title, $author);
        $p->setResponsible($responsible);
        if (Presentation::KIND_TOPIC === $kind) {
            if (null === $meeting) {
                throw new \InvalidArgumentException('Тема готовится к конкретному собранию.');
            }
            $p->setMeeting($meeting)->setPosition($this->presentations->maxPosition($meeting) + 10);
            $slides = [SlideLayouts::slide('title-text', ['bound' => true])];
        } else {
            $slides = [
                SlideLayouts::slide('cover', ['bindTitle' => 'title', 'date' => Meeting::formatDate(new \DateTimeImmutable())]),
                SlideLayouts::slide('title-text'),
                SlideLayouts::slide('closing'),
            ];
        }
        $deck = $this->validator->emptyDeck($p->getTitle(), $p->getResponsibleLabel(), $slides);
        $p->setContent(DeckValidator::encode($deck), DeckValidator::preview($deck), \count($deck['slides']), $author);
        $this->em->persist($p);
        $this->em->flush();
        $this->auditLogger->info('Создана презентация', ['presentation' => $p->getId(), 'kind' => $kind, 'by' => $author->getUsername()]);

        return $p;
    }

    /**
     * Рабочая редакция как массив (или одобренная — для тех, кто видит только её).
     *
     * @return array<string, mixed>
     */
    public function deck(Presentation $p, bool $approved = false): array
    {
        $json = $approved ? $p->getApprovedContent() : $p->getContent();
        $deck = DeckValidator::decode($json);

        return $deck ?? $this->validator->emptyDeck($p->getTitle(), $p->getResponsibleLabel());
    }

    /** Какую редакцию показывать сотруднику: рабочую (автор, модераторы) или одобренную. */
    public function deckFor(Presentation $p, ?User $user): array
    {
        return $this->deck($p, !$this->access->canSeeWorking($user, $p) && $p->hasApprovedVersion());
    }

    /**
     * Сохранить слайды из редактора.
     *
     * @param array<string, mixed> $deck
     *
     * @throws \InvalidArgumentException неверный формат
     * @throws ConflictException         слайды уже сохранил кто-то другой (рабочая редакция новее)
     */
    public function save(Presentation $p, array $deck, ?int $baseRevision, User $by, bool $force = false): Presentation
    {
        if (!$force && null !== $baseRevision && $baseRevision !== $p->getRevision()) {
            throw new ConflictException($p);
        }
        $meta = \is_array($deck['meta'] ?? null) ? $deck['meta'] : [];
        $title = trim((string) ($meta['title'] ?? ''));
        if ('' !== $title) {
            $p->setTitle($title);
        }
        if (\array_key_exists('responsible', $meta)) {
            $resp = trim((string) $meta['responsible']);
            $p->setResponsible('' === $resp || $resp === $p->getResponsibleLabel() && null === $p->getResponsible() ? null : $resp);
        }
        $normalized = $this->validator->normalize($deck, ['title' => $p->getTitle(), 'responsible' => $p->getResponsibleLabel()]);
        $json = DeckValidator::encode($normalized);
        if (\strlen($json) > DeckValidator::MAX_JSON_BYTES) {
            throw new \InvalidArgumentException('Презентация слишком большая (больше 8 МБ данных слайдов) — разделите её на несколько.');
        }
        if ($json === $p->getContent()) {
            return $p;
        }
        $this->snapshotIfDue($p, $by);
        $p->setContent($json, DeckValidator::preview($normalized), \count($normalized['slides']), $by);
        $this->em->flush();

        return $p;
    }

    /** В историю — предыдущая редакция, если последняя сохранённая версия старше десяти минут. */
    private function snapshotIfDue(Presentation $p, ?User $by): void
    {
        if ('' === $p->getContent()) {
            return;
        }
        $last = $this->versions->findLatest($p);
        if (null !== $last && $last->getRevision() === $p->getRevision()) {
            return;
        }
        if (null === $last || $last->getCreatedAt() < new \DateTimeImmutable('-'.self::VERSION_INTERVAL.' seconds')) {
            $this->em->persist(new PresentationVersion($p, PresentationVersion::KIND_AUTO, $p->getUpdatedBy() ?? $by));
            $this->em->flush();
            $this->versions->pruneAuto($p, self::KEEP_AUTO_VERSIONS);
        }
    }

    private function snapshot(Presentation $p, string $kind, ?User $by): void
    {
        $this->em->persist(new PresentationVersion($p, $kind, $by));
    }

    public function submit(Presentation $p, ?string $note, User $by): void
    {
        if (!$this->access->canSubmit($by, $p)) {
            throw new \DomainException(0 === $p->getSlideCount() ? 'В презентации нет слайдов.' : 'Эту презентацию сейчас нельзя отправить на модерацию.');
        }
        $p->submit($note);
        $this->snapshot($p, PresentationVersion::KIND_SUBMIT, $by);
        $this->em->flush();
        $this->auditLogger->info('Презентация отправлена на модерацию', ['presentation' => $p->getId(), 'by' => $by->getUsername()]);
        $this->notify($p, 'submitted', $by);
    }

    public function withdraw(Presentation $p, User $by): void
    {
        if (!$this->access->canWithdraw($by, $p)) {
            throw new \DomainException('Презентация не на модерации.');
        }
        $p->withdraw();
        $this->em->flush();
        $this->auditLogger->info('Презентация снята с модерации', ['presentation' => $p->getId(), 'by' => $by->getUsername()]);
    }

    public function approve(Presentation $p, User $by, ?string $note): void
    {
        if (!$this->access->canDecide($by, $p)) {
            throw new \DomainException($p->isAuthor($by) ? 'Свою презентацию одобряет маркетолог или другой помощник маркетолога.' : 'Решение по презентации принимает маркетолог или помощник маркетолога.');
        }
        $p->approve($by, $note);
        $this->snapshot($p, PresentationVersion::KIND_APPROVE, $by);
        $this->em->flush();
        $this->auditLogger->info('Презентация одобрена', ['presentation' => $p->getId(), 'revision' => $p->getRevision(), 'by' => $by->getUsername()]);
        $this->notify($p, 'approved', $by);
    }

    public function reject(Presentation $p, User $by, string $note): void
    {
        if ('' === trim($note)) {
            throw new \InvalidArgumentException('Напишите, что нужно доработать.');
        }
        if (!$this->access->canDecide($by, $p)) {
            throw new \DomainException('Решение по презентации принимает маркетолог или помощник маркетолога.');
        }
        $p->reject($by, $note);
        $this->snapshot($p, PresentationVersion::KIND_REJECT, $by);
        $this->em->flush();
        $this->auditLogger->info('Презентация возвращена на доработку', ['presentation' => $p->getId(), 'by' => $by->getUsername()]);
        $this->notify($p, 'rejected', $by);
    }

    /** Снять одобрение: сотрудники и собрание больше не видят одобренную редакцию. */
    public function revoke(Presentation $p, User $by): void
    {
        if (!$this->access->isModerator($by)) {
            throw new \DomainException('Снять одобрение может маркетолог или помощник маркетолога.');
        }
        $p->revokeApproval();
        $this->em->flush();
        $this->auditLogger->info('С презентации снято одобрение', ['presentation' => $p->getId(), 'by' => $by->getUsername()]);
    }

    public function restore(Presentation $p, PresentationVersion $version, User $by): void
    {
        if ($version->getPresentation()->getId() !== $p->getId()) {
            throw new \InvalidArgumentException('Версия от другой презентации.');
        }
        $this->snapshot($p, PresentationVersion::KIND_RESTORE, $by);
        $deck = DeckValidator::decode($version->getContent()) ?? [];
        $this->save($p, $deck, null, $by, true);
    }

    /** Снимок перед импортом слайдов из PowerPoint — чтобы импорт можно было отменить в истории версий. */
    public function snapshotBeforeImport(Presentation $p, User $by): void
    {
        $this->snapshot($p, PresentationVersion::KIND_IMPORT, $by);
        $this->em->flush();
    }

    /** Копия (рабочей редакции — для автора и модераторов, иначе одобренной) — новый черновик сотрудника. */
    public function duplicate(Presentation $source, User $by, ?Meeting $meeting = null): Presentation
    {
        $deck = $this->deckFor($source, $by);
        $kind = null !== $meeting ? Presentation::KIND_TOPIC : Presentation::KIND_GENERAL;
        $copy = new Presentation($kind, $source->getTitle().' (копия)', $by);
        $copy->setDescription($source->getDescription());
        if (null !== $meeting) {
            $copy->setMeeting($meeting)->setPosition($this->presentations->maxPosition($meeting) + 10);
        }
        $deck['meta'] = ['title' => $copy->getTitle(), 'responsible' => $copy->getResponsibleLabel()];
        $normalized = $this->validator->normalize($deck, $deck['meta']);
        $copy->setContent(DeckValidator::encode($normalized), DeckValidator::preview($normalized), \count($normalized['slides']), $by);
        $this->em->persist($copy);
        $this->em->flush();

        return $copy;
    }

    public function delete(Presentation $p, User $by): void
    {
        if (!$this->access->canDelete($by, $p)) {
            throw new \DomainException('Удалить эту презентацию нельзя.');
        }
        $id = $p->getId();
        $title = $p->getTitle();
        $this->assets->cleanupFor($p);
        $this->em->remove($p);
        $this->em->flush();
        $this->auditLogger->info('Презентация удалена', ['presentation' => $id, 'title' => $title, 'by' => $by->getUsername()]);
    }

    /**
     * Кто модерирует презентации: маркетологи и помощники маркетолога; если таких нет — администраторы.
     *
     * @return list<User>
     */
    public function moderators(): array
    {
        $out = [];
        foreach ($this->users->findActiveOrdered() as $u) {
            if (!$u->isAdmin() && ($u->isMarketer() || \in_array(User::ROLE_ASSISTANT, $u->getRoles(), true))) {
                $out[] = $u;
            }
        }
        if ([] === $out) {
            $out = $this->users->findActiveAdmins();
        }

        return $out;
    }

    private function notify(Presentation $p, string $kind, User $by): void
    {
        $recipients = 'submitted' === $kind ? $this->moderators() : array_filter([$p->getAuthor()]);
        $what = $p->isTopic() ? 'тема собрания' : 'презентация';
        $meeting = $p->isTopic() && null !== $p->getMeeting() ? ' ('.$p->getMeeting()->getDisplayTitle().', '.$p->getMeeting()->getDateLabel().')' : '';
        $subject = match ($kind) {
            'submitted' => \sprintf('%s: на модерации %s «%s»%s', $this->appName, $what, $p->getTitle(), $meeting),
            'approved' => \sprintf('%s: %s «%s» одобрена', $this->appName, $what, $p->getTitle()),
            default => \sprintf('%s: %s «%s» возвращена на доработку', $this->appName, $what, $p->getTitle()),
        };
        $route = 'submitted' === $kind || 'rejected' === $kind ? 'app_presentation_edit' : 'app_presentation_show';
        $url = $this->portalUrl->absolute($route, ['id' => $p->getId()]);
        foreach ($recipients as $recipient) {
            if (!$recipient instanceof User || !$recipient->isActive() || $recipient->getId() === $by->getId()) {
                continue;
            }
            if (null !== $recipient->getEmail() && '' !== $recipient->getEmail()) {
                try {
                    $this->mailer->send((new TemplatedEmail())
                        ->from(Address::create($this->mailFrom))
                        ->to(new Address($recipient->getEmail(), $recipient->getDisplayName()))
                        ->subject($subject)
                        ->htmlTemplate('emails/presentation.html.twig')
                        ->textTemplate('emails/presentation.txt.twig')
                        ->context([
                            'user' => $recipient,
                            'presentation' => $p,
                            'kind' => $kind,
                            'by' => $by,
                            'url' => $url,
                            'app_name' => $this->appName,
                        ]));
                } catch (\Throwable $e) {
                    $this->auditLogger->warning('Не удалось отправить письмо о презентации', ['presentation' => $p->getId(), 'error' => $e->getMessage()]);
                }
            }
            if (null !== $this->telegram) {
                try {
                    $note = 'rejected' === $kind && null !== $p->getDecisionNote() ? "\n".htmlspecialchars($p->getDecisionNote(), \ENT_QUOTES)."\n" : "\n";
                    $this->telegram->notify($recipient, \sprintf('<b>%s</b>%s<a href="%s">%s</a>', htmlspecialchars($subject, \ENT_QUOTES), $note, htmlspecialchars($url, \ENT_QUOTES), htmlspecialchars($url, \ENT_QUOTES)));
                } catch (\Throwable $e) {
                    $this->auditLogger->warning('Не удалось отправить сообщение Telegram о презентации', ['presentation' => $p->getId(), 'error' => $e->getMessage()]);
                }
            }
        }
    }
}
