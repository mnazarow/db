<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Document;
use App\Entity\DocumentAcknowledgement;
use App\Entity\DocumentEvent;
use App\Entity\User;
use App\Message\NotifyAcknowledgementAssigned;
use App\Repository\DocumentAcknowledgementRepository;
use App\Repository\UserRepository;
use App\Security\Access;
use App\Service\Notification\PushNotifier;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;

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
        private readonly LoggerInterface $auditLogger,
        private readonly string $mailFrom,
        private readonly string $appName,
        private readonly PortalUrl $portalUrl,
        private readonly ?Access $access = null,
        private readonly ?PushNotifier $push = null,
        private readonly ?BackgroundJobs $background = null,
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
            $this->em->persist(new DocumentEvent($document, DocumentEvent::ACKNOWLEDGE, $by, $version, null, [
                'acknowledgement' => 'assigned',
                'users' => $created,
                'due' => $dueAt?->format('Y-m-d'),
            ]));
        }
        $this->em->flush();
        if ($created > 0) {
            $this->auditLogger->info('Назначено ознакомление с документом', ['document' => $document->getId(), 'version' => $number, 'users' => $created, 'due' => $dueAt?->format('Y-m-d'), 'by' => $by->getUsername()]);
        }
        // Письма сотрудникам (их могут быть сотни) — фоновой задачей, если обработчик очереди работает.
        if ($notify && [] !== $recipients && !$this->background?->dispatch(new NotifyAcknowledgementAssigned(
            (int) $document->getId(), array_map(static fn (User $u): int => (int) $u->getId(), $recipients), $dueAt?->format('Y-m-d'),
        ))) {
            $this->notifyAssignedUsers($document, $recipients, $dueAt);
        }

        return ['created' => $created, 'existing' => $existing, 'skipped' => $skipped, 'version' => $number];
    }

    /**
     * Подтверждение ознакомления сотрудником. Возвращает запись или null, если ознакомление не назначалось.
     *
     * @param array{correct: int, total: int}|null $quiz результат проверки знаний, если она настроена
     */
    public function confirm(Document $document, User $user, ?string $ip = null, ?array $quiz = null): ?DocumentAcknowledgement
    {
        $acknowledgement = $this->acknowledgements->findPending($document, $user);
        if (null === $acknowledgement) {
            return null;
        }
        $acknowledgement->confirm($ip);
        if (null !== $quiz) {
            $acknowledgement->setQuizResult((int) ($quiz['correct'] ?? 0), (int) ($quiz['total'] ?? 0));
        }
        $this->em->persist(new DocumentEvent($document, DocumentEvent::ACKNOWLEDGE, $user, null, $ip, [
            'acknowledgement' => 'confirmed',
            'version' => $acknowledgement->getVersionNumber(),
        ] + (null !== $quiz ? ['quiz' => \sprintf('%d/%d', $quiz['correct'] ?? 0, $quiz['total'] ?? 0), 'attempts' => $acknowledgement->getQuizAttempts()] : [])));
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

    /** Подпись строки сотрудников без подразделения в сводке. */
    public const NO_DEPARTMENT = 'Без подразделения';

    /**
     * Сводка «подразделения × документы» для панели администратора: доля сотрудников подразделения,
     * подтвердивших ознакомление с последней назначенной редакцией документа. Подразделения объединяются
     * без учёта регистра и «е/ё» («Отдел кадров» и «отдел кадров» из AD — одно подразделение).
     *
     * @param list<int>|null $sectionIds  раздел с подразделами (null — все разделы)
     * @param int            $maxDocuments сколько документов показать столбцами (последние по дате назначения)
     * @param string         $sort         name — по названию подразделения, pct — сначала отстающие
     *
     * @return array{documents: list<array{document: Document, assigned: int, confirmed: int, overdue: int, pct: int}>, departments: list<array{name: string, assigned: int, confirmed: int, overdue: int, pct: int, cells: array<int, array{assigned: int, confirmed: int, overdue: int, pct: int}>}>, hidden_documents: int, totals: array{assigned: int, confirmed: int, overdue: int, pct: int}, all_documents: list<array{document: Document, assigned: int, confirmed: int, overdue: int, pct: int}>}
     */
    public function departmentMatrix(?array $sectionIds = null, int $maxDocuments = 30, string $sort = 'name'): array
    {
        $rows = $this->acknowledgements->departmentRows($sectionIds);
        $empty = ['assigned' => 0, 'confirmed' => 0, 'overdue' => 0];
        $byDocument = [];
        $lastAssigned = [];
        $departments = [];
        foreach ($rows as $row) {
            $docId = $row['document_id'];
            $name = trim((string) preg_replace('/\s+/u', ' ', $row['department']));
            $key = '' === $name ? '' : Document::departmentKey($name);
            $departments[$key] ??= ['names' => [], 'cells' => []] + $empty;
            // Название — самое частое написание (по числу назначений).
            if ('' !== $name) {
                $departments[$key]['names'][$name] = ($departments[$key]['names'][$name] ?? 0) + $row['assigned'];
            }
            $cell = $departments[$key]['cells'][$docId] ?? $empty;
            $byDocument[$docId] ??= $empty;
            foreach (['assigned', 'confirmed', 'overdue'] as $field) {
                $cell[$field] += $row[$field];
                $departments[$key][$field] += $row[$field];
                $byDocument[$docId][$field] += $row[$field];
            }
            $departments[$key]['cells'][$docId] = $cell;
            $lastAssigned[$docId] = max($lastAssigned[$docId] ?? '', $row['last_assigned']);
        }
        if ([] === $byDocument) {
            return ['documents' => [], 'departments' => [], 'hidden_documents' => 0, 'totals' => $empty + ['pct' => 0], 'all_documents' => []];
        }

        $documents = [];
        foreach ($this->em->getRepository(Document::class)->createQueryBuilder('d')->leftJoin('d.section', 's')->addSelect('s')
            ->andWhere('d.id IN (:ids)')->setParameter('ids', array_keys($byDocument))->getQuery()->getResult() as $document) {
            $documents[(int) $document->getId()] = $document;
        }
        // Столбцы — документы с последними назначениями.
        uksort($byDocument, static fn (int $a, int $b): int => [$lastAssigned[$b], $b] <=> [$lastAssigned[$a], $a]);
        $all = [];
        foreach ($byDocument as $docId => $counts) {
            if (isset($documents[$docId])) {
                $all[] = ['document' => $documents[$docId]] + $counts + ['pct' => self::percent($counts)];
            }
        }
        $shown = \array_slice($all, 0, max(1, $maxDocuments));

        $out = [];
        $totals = $empty;
        foreach ($departments as $key => $department) {
            // Название — самое частое написание; при равенстве — с заглавной буквы, затем по алфавиту.
            $names = $department['names'];
            uksort($names, static fn (string|int $a, string|int $b): int => [$names[$b], self::startsUpper((string) $b), (string) $a] <=> [$names[$a], self::startsUpper((string) $a), (string) $b]);
            $department['names'] = $names;
            $cells = [];
            foreach ($department['cells'] as $docId => $cell) {
                $cells[$docId] = $cell + ['pct' => self::percent($cell)];
            }
            $out[] = [
                'name' => '' === $key ? self::NO_DEPARTMENT : (string) array_key_first($department['names']),
                'no_department' => '' === $key,
                'assigned' => $department['assigned'],
                'confirmed' => $department['confirmed'],
                'overdue' => $department['overdue'],
                'pct' => self::percent($department),
                'cells' => $cells,
            ];
            foreach (['assigned', 'confirmed', 'overdue'] as $field) {
                $totals[$field] += $department[$field];
            }
        }
        usort($out, static function (array $a, array $b) use ($sort): int {
            // «Без подразделения» — в конце списка при любой сортировке.
            if ($a['no_department'] !== $b['no_department']) {
                return $a['no_department'] <=> $b['no_department'];
            }
            if ('pct' === $sort && $a['pct'] !== $b['pct']) {
                return $a['pct'] <=> $b['pct'];
            }

            return strcmp(mb_strtolower($a['name']), mb_strtolower($b['name']));
        });

        return [
            'documents' => $shown,
            'departments' => $out,
            'hidden_documents' => \count($all) - \count($shown),
            'totals' => $totals + ['pct' => self::percent($totals)],
            'all_documents' => $all,
        ];
    }

    /**
     * Единый отчёт «Документы и видео» по сотрудникам, сгруппированный по подразделениям: ознакомления с
     * документами (последняя назначенная редакция опубликованных документов) и обязательные просмотры
     * раздела «Видео» ($video — id сотрудника → назначено, выполнено, просрочено; null — сведений нет).
     * В отчёт попадают активные сотрудники, которым назначено хоть что-то.
     *
     * @param list<int>|null                                                    $sectionIds документы только этих разделов
     * @param array<int, array{assigned: int, completed: int, overdue: int}>|null $video
     *
     * @return array{departments: list<array{name: string, no_department: bool, people: list<array<string, mixed>>, docs: array{assigned: int, confirmed: int, overdue: int}, video: array{assigned: int, completed: int, overdue: int}, pending: int}>, totals: array<string, int>, department_names: list<string>, video_available: bool}
     */
    public function peopleReport(?array $sectionIds, ?array $video, ?string $department = null, bool $onlyPending = false): array
    {
        $docs = $this->acknowledgements->userRows($sectionIds);
        $videoAvailable = null !== $video;
        $video ??= [];
        $zeroDocs = ['assigned' => 0, 'confirmed' => 0, 'overdue' => 0];
        $zeroVideo = ['assigned' => 0, 'completed' => 0, 'overdue' => 0];
        $filterKey = null !== $department && '' !== trim($department) ? ('-' === $department ? '' : Document::departmentKey($department)) : null;
        $groups = [];
        $names = [];
        foreach ($this->users->findActiveOrdered() as $user) {
            $id = (int) $user->getId();
            if (!isset($docs[$id]) && !isset($video[$id])) {
                continue;
            }
            $d = $docs[$id] ?? $zeroDocs;
            $v = $video[$id] ?? $zeroVideo;
            $pending = ($d['assigned'] - $d['confirmed']) + ($v['assigned'] - $v['completed']);
            $name = trim((string) preg_replace('/\s+/u', ' ', (string) $user->getDepartment()));
            $key = '' === $name ? '' : Document::departmentKey($name);
            if ('' !== $name) {
                $names[$key][$name] = ($names[$key][$name] ?? 0) + 1;
            }
            if (null !== $filterKey && $filterKey !== $key) {
                continue;
            }
            if ($onlyPending && 0 === $pending) {
                continue;
            }
            $groups[$key] ??= ['people' => [], 'docs' => $zeroDocs, 'video' => $zeroVideo, 'pending' => 0];
            $groups[$key]['people'][] = ['user' => $user, 'docs' => $d, 'video' => $v, 'pending' => $pending, 'overdue' => $d['overdue'] + $v['overdue']];
            foreach ($zeroDocs as $field => $_) {
                $groups[$key]['docs'][$field] += $d[$field];
            }
            foreach ($zeroVideo as $field => $_) {
                $groups[$key]['video'][$field] += $v[$field];
            }
            $groups[$key]['pending'] += $pending;
        }
        // Название подразделения — самое частое написание (как в сводке по подразделениям).
        $label = static function (string $key) use ($names): string {
            if ('' === $key || !isset($names[$key])) {
                return self::NO_DEPARTMENT;
            }
            $variants = $names[$key];
            arsort($variants);

            return (string) array_key_first($variants);
        };
        $out = [];
        $totals = ['people' => 0, 'pending' => 0] + array_combine(['docs_assigned', 'docs_confirmed', 'docs_overdue'], [0, 0, 0]) + ['video_assigned' => 0, 'video_completed' => 0, 'video_overdue' => 0];
        foreach ($groups as $key => $group) {
            // Сначала те, у кого больше просрочено и не выполнено.
            usort($group['people'], static fn (array $a, array $b): int => [$b['overdue'], $b['pending'], $a['user']->getDisplayName()] <=> [$a['overdue'], $a['pending'], $b['user']->getDisplayName()]);
            $out[] = ['name' => $label((string) $key), 'no_department' => '' === $key] + $group;
            $totals['people'] += \count($group['people']);
            $totals['pending'] += $group['pending'];
            $totals['docs_assigned'] += $group['docs']['assigned'];
            $totals['docs_confirmed'] += $group['docs']['confirmed'];
            $totals['docs_overdue'] += $group['docs']['overdue'];
            $totals['video_assigned'] += $group['video']['assigned'];
            $totals['video_completed'] += $group['video']['completed'];
            $totals['video_overdue'] += $group['video']['overdue'];
        }
        usort($out, static fn (array $a, array $b): int => [$a['no_department'], mb_strtolower($a['name'])] <=> [$b['no_department'], mb_strtolower($b['name'])]);
        $allNames = array_map(static fn (string $key): string => $label($key), array_keys($names));
        sort($allNames);

        return ['departments' => $out, 'totals' => $totals, 'department_names' => $allNames, 'video_available' => $videoAvailable];
    }

    /**
     * «Мои сотрудники»: подчинённые руководителя (прямые и их подчинённые) с ознакомлениями и обязательными
     * просмотрами; у кого не выполнено — список документов (названия документов, которые руководителю не открыты,
     * скрыты). $video — как в peopleReport (null — видео недоступно).
     *
     * @param array<int, array{assigned: int, completed: int, overdue: int}>|null $video
     *
     * @return array{people: list<array{user: User, depth: int, docs: array{assigned: int, confirmed: int, overdue: int}, video: array{assigned: int, completed: int, overdue: int}, pending: int, overdue: int, documents: list<array{title: ?string, url_id: ?int, due: ?\DateTimeImmutable, overdue: bool}>}>, totals: array<string, int>, video_available: bool}
     */
    public function teamReport(User $manager, ?array $video, bool $details = true): array
    {
        $subordinates = $this->users->findSubordinates($manager);
        $docs = $this->acknowledgements->userRows(null, array_map(static fn (array $s): int => (int) $s['user']->getId(), $subordinates));
        $videoAvailable = null !== $video;
        $video ??= [];
        $people = [];
        $totals = ['people' => 0, 'pending' => 0, 'overdue' => 0, 'docs_assigned' => 0, 'docs_confirmed' => 0, 'video_assigned' => 0, 'video_completed' => 0];
        foreach ($subordinates as ['user' => $user, 'depth' => $depth]) {
            $id = (int) $user->getId();
            $d = $docs[$id] ?? ['assigned' => 0, 'confirmed' => 0, 'overdue' => 0];
            $v = $video[$id] ?? ['assigned' => 0, 'completed' => 0, 'overdue' => 0];
            $pending = ($d['assigned'] - $d['confirmed']) + ($v['assigned'] - $v['completed']);
            $documents = $details && $d['assigned'] > $d['confirmed'] ? $this->pendingDocumentsFor($manager, $user) : [];
            $people[] = ['user' => $user, 'depth' => $depth, 'docs' => $d, 'video' => $v, 'pending' => $pending, 'overdue' => $d['overdue'] + $v['overdue'], 'documents' => $documents];
            ++$totals['people'];
            $totals['pending'] += $pending;
            $totals['overdue'] += $d['overdue'] + $v['overdue'];
            $totals['docs_assigned'] += $d['assigned'];
            $totals['docs_confirmed'] += $d['confirmed'];
            $totals['video_assigned'] += $v['assigned'];
            $totals['video_completed'] += $v['completed'];
        }

        return ['people' => $people, 'totals' => $totals, 'video_available' => $videoAvailable];
    }

    /**
     * Неподтверждённые ознакомления сотрудника (не больше $limit, ближайшие сроки первыми) так, как их видит
     * руководитель: названия документов, которые ему не открыты, скрыты.
     *
     * @return list<array{title: ?string, url_id: ?int, due: ?\DateTimeImmutable, overdue: bool}>
     */
    public function pendingDocumentsFor(User $viewer, User $user, int $limit = 30): array
    {
        $today = new \DateTimeImmutable('today');
        $documents = [];
        foreach ($this->acknowledgements->findPendingForUser($user, $limit) as $ack) {
            $document = $ack->getDocument();
            $visible = null === $this->access || $this->access->canViewDocument($viewer, $document);
            $due = $ack->getDueAt();
            $documents[] = [
                'title' => $visible ? $document->getTitle() : null,
                'url_id' => $visible ? $document->getId() : null,
                'due' => $due,
                'overdue' => null !== $due && $due < $today,
            ];
        }

        return $documents;
    }

    private static function startsUpper(string $name): bool
    {
        $first = mb_substr($name, 0, 1);

        return '' !== $first && mb_strtoupper($first) === $first && mb_strtolower($first) !== $first;
    }

    /** Доля подтвердивших, с округлением вниз: 99,6 % — ещё не «все» (100 % — только когда ознакомились все). */
    private static function percent(array $counts): int
    {
        return $counts['assigned'] > 0 ? intdiv($counts['confirmed'] * 100, $counts['assigned']) : 0;
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
        if (null !== $this->access) {
            // Напоминаем только о документах, которые сотрудник может открыть (как и в его списке «Ознакомиться»).
            $pending = array_values(array_filter($pending, fn (DocumentAcknowledgement $a): bool => $this->access->canViewDocument($a->getUser(), $a->getDocument())));
        }
        $byUser = [];
        foreach ($pending as $item) {
            $byUser[$item->getUser()->getId()][] = $item;
        }
        $emails = 0;
        foreach ($byUser as $items) {
            $user = $items[0]->getUser();
            if (!$dryRun && null !== $this->push) {
                $titles = array_map(static fn (DocumentAcknowledgement $a): string => '• '.$a->getDocument()->getTitle(), \array_slice($items, 0, 3));
                $this->push->notify($user, \count($items) > 1 ? \sprintf('Ждут ознакомления: %d %s', \count($items), Validity::plural(\count($items), ['документ', 'документа', 'документов'])) : 'Ждёт ознакомления документ',
                    implode("\n", $titles).(\count($items) > 3 ? "\n…" : ''), $this->portalUrl->absolute('app_profile_acknowledgements'), 'ack-reminder');
            }
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

    /**
     * Письма и уведомления о назначенном ознакомлении.
     *
     * @param iterable<User> $users
     */
    public function notifyAssignedUsers(Document $document, iterable $users, ?\DateTimeImmutable $dueAt): void
    {
        foreach ($users as $user) {
            $this->notifyAssigned($user, $document, $dueAt);
        }
    }

    private function notifyAssigned(User $user, Document $document, ?\DateTimeImmutable $dueAt): void
    {
        $this->push?->notify($user, 'Ознакомьтесь с документом', $document->getTitle().(null !== $dueAt ? "\nСрок — до ".$dueAt->format('d.m.Y') : ''),
            $this->portalUrl->absolute('app_document_show', ['id' => $document->getId()]), 'ack-'.$document->getId());
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
                    'urls' => [$document->getId() => $this->portalUrl->absolute('app_document_show', ['id' => $document->getId()])],
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
            $urls[$item->getDocument()->getId()] = $this->portalUrl->absolute('app_document_show', ['id' => $item->getDocument()->getId()]);
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
