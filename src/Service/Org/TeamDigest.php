<?php

declare(strict_types=1);

namespace App\Service\Org;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\AcknowledgementService;
use App\Service\Notification\TelegramNotifier;
use App\Service\PortalSettings;
use App\Service\PortalUrl;
use App\Service\ProcessLock;
use App\Service\Validity;
use App\Service\Video\VideoApi;
use App\Service\Video\VideoIntegration;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;

/**
 * Еженедельная сводка руководителям («Мои сотрудники»): кто из подчинённых (по оргструктуре, на всех уровнях)
 * не ознакомился с документами и не посмотрел обязательные видео, что просрочено. Письмо — если руководитель не
 * отключил письма в профиле; то же коротко — в Telegram и уведомлением в браузер. Включается в панели
 * администратора → «Ознакомление»; рассылку выполняет задание cron app:team:digest (по понедельникам).
 *
 * Названия документов, которые руководитель не может открыть (ограниченный доступ), в сводку не попадают —
 * только их число.
 */
final class TeamDigest
{
    /** Сколько сотрудников перечислять в письме (остальные — на странице «Мои сотрудники»). */
    public const MAX_PEOPLE = 15;

    /** Сколько документов перечислять у сотрудника. */
    public const MAX_DOCUMENTS = 3;

    /** Повторный запуск раньше этого срока ничего не отправляет (защита от двойного запуска cron). */
    public const MIN_INTERVAL_HOURS = 6 * 24;

    public function __construct(
        private readonly UserRepository $users,
        private readonly AcknowledgementService $acknowledgements,
        private readonly PortalSettings $settings,
        private readonly PortalUrl $portalUrl,
        private readonly MailerInterface $mailer,
        private readonly TelegramNotifier $telegram,
        private readonly LoggerInterface $auditLogger,
        private readonly string $mailFrom,
        private readonly string $appName,
        private readonly ProcessLock $locks,
        private readonly ?VideoApi $videoApi = null,
        private readonly ?VideoIntegration $video = null,
    ) {
    }

    /**
     * Сводки по руководителям.
     *
     * @return list<array{manager: User, totals: array<string, int>, people: list<array<string, mixed>>, more: int, video: bool, url: string}>
     */
    public function collect(?User $only = null, ?bool $onlyPending = null, bool $details = true): array
    {
        $onlyPending ??= $this->settings->teamDigest()['only_pending'];
        $video = null;
        if (null !== $this->videoApi && ($this->video?->isEnabled() ?? false)) {
            try {
                $video = $this->videoApi->assignmentsByUser();
            } catch (\Throwable $e) {
                // Видеопортал недоступен — сводка уходит по документам.
                $this->auditLogger->warning('Сводка руководителям: видеопортал не сообщил обязательные просмотры', ['error' => $e->getMessage()]);
            }
        }
        $out = [];
        foreach (null !== $only ? [$only] : $this->users->findManagers() as $manager) {
            if (!$manager->isActive()) {
                continue;
            }
            // Списки документов нужны только тем, кто попадёт в сводку (до MAX_PEOPLE), — их и загружаем.
            $report = $this->acknowledgements->teamReport($manager, $video, false);
            if (0 === $report['totals']['people'] || ($onlyPending && 0 === $report['totals']['pending'])) {
                continue;
            }
            $people = array_values(array_filter($report['people'], static fn (array $p): bool => $p['pending'] > 0));
            usort($people, static fn (array $a, array $b): int => [$b['overdue'], $b['pending'], $a['user']->getDisplayName()] <=> [$a['overdue'], $a['pending'], $b['user']->getDisplayName()]);
            $more = max(0, \count($people) - self::MAX_PEOPLE);
            $people = \array_slice($people, 0, self::MAX_PEOPLE);
            foreach ($people as &$person) {
                if ($details && $person['docs']['assigned'] > $person['docs']['confirmed']) {
                    $person['documents'] = $this->acknowledgements->pendingDocumentsFor($manager, $person['user']);
                }
                $visible = array_values(array_filter($person['documents'], static fn (array $d): bool => null !== $d['title']));
                $person['shown'] = \array_slice($visible, 0, self::MAX_DOCUMENTS);
                // Список документов в отчёте ограничен, поэтому «и ещё N» — от числа непрочитанных, а не от длины списка.
                $person['hidden'] = max(\count($person['documents']), $person['docs']['assigned'] - $person['docs']['confirmed']) - \count($person['shown']);
            }
            unset($person);
            $out[] = [
                'manager' => $manager,
                'totals' => $report['totals'],
                'people' => $people,
                'more' => $more,
                'video' => $report['video_available'],
                'url' => $this->portalUrl->absolute('app_profile_team'),
            ];
        }

        return $out;
    }

    /**
     * Рассылка сводки.
     *
     * @return array{status: string, message: string, managers: int, pending: int, overdue: int, emails: int, telegram: int, plan: list<array{user: User, people: int, pending: int, overdue: int}>}
     */
    public function run(bool $dryRun = false, bool $force = false, ?User $only = null): array
    {
        $config = $this->settings->teamDigest();
        $result = ['status' => 'ok', 'message' => '', 'managers' => 0, 'pending' => 0, 'overdue' => 0, 'emails' => 0, 'telegram' => 0, 'plan' => []];
        if (!$config['enabled'] && !$force) {
            return ['status' => 'disabled', 'message' => 'Сводка руководителям выключена (панель администратора → Ознакомление).'] + $result;
        }
        $release = null;
        $lastRun = null;
        if (!$dryRun && null === $only) {
            // Одна рассылка за раз (блокировка в базе — общая для cron и веб-интерфейса и в Docker): запуск
            // по расписанию и «Отправить сейчас» не дублируют письма.
            $release = $this->locks->acquire('team-digest');
            if (null === $release) {
                return ['status' => 'skipped', 'message' => 'Сводка уже отправляется — дождитесь окончания.'] + $result;
            }
            $lastRun = $this->settings->fresh(PortalSettings::TEAM_DIGEST_LAST_RUN);
            $last = \is_string($lastRun) ? strtotime($lastRun) : false;
            if (false !== $last && !$force && time() - $last < self::MIN_INTERVAL_HOURS * 3600) {
                $release();

                return ['status' => 'skipped', 'message' => 'Сводка уже отправлялась '.date('d.m.Y H:i', $last).' — следующая не раньше чем через неделю (--force — отправить сейчас).'] + $result;
            }
            if (false !== $last && $force && time() - $last < 120) {
                $release();

                return ['status' => 'skipped', 'message' => 'Сводка только что отправлена ('.date('H:i', $last).').'] + $result;
            }
            // Запуск отмечается до рассылки: если он прервётся, повтор по расписанию не разошлёт письма второй раз.
            $this->settings->set(PortalSettings::TEAM_DIGEST_LAST_RUN, date('Y-m-d H:i:s'));
        }
        $attempted = 0;
        try {
            foreach ($this->collect($only, null, !$dryRun) as $digest) {
                $manager = $digest['manager'];
                ++$result['managers'];
                $result['pending'] += $digest['totals']['pending'];
                $result['overdue'] += $digest['totals']['overdue'];
                $result['plan'][] = ['user' => $manager, 'people' => $digest['totals']['people'], 'pending' => $digest['totals']['pending'], 'overdue' => $digest['totals']['overdue']];
                if ($dryRun) {
                    continue;
                }
                if (null !== $manager->getEmail() && '' !== $manager->getEmail() && $manager->isNotifyEmail()) {
                    ++$attempted;
                    try {
                        $this->mailer->send($this->email($digest));
                        ++$result['emails'];
                    } catch (\Throwable $e) {
                        $this->auditLogger->warning('Не удалось отправить сводку руководителю', ['user' => $manager->getUsername(), 'error' => $e->getMessage()]);
                    }
                }
                if ($this->telegram->notify($manager, $this->telegramText($digest))) {
                    ++$result['telegram'];
                }
            }
        } finally {
            if (null !== $release) {
                $release();
            }
        }
        if ($dryRun) {
            // Пробный запуск ничего не отправляет и в журнал не пишется.
        } elseif ($attempted > 0 && 0 === $result['emails'] && 0 === $result['telegram']) {
            // Почта не работает (и других каналов нет): неделя не считается «отправленной» — после исправления
            // почты сводку можно отправить кнопкой, а «Мониторинг» покажет ошибку задания.
            if (null === $only) {
                $this->settings->set(PortalSettings::TEAM_DIGEST_LAST_RUN, $lastRun);
            }
            $this->auditLogger->error('Сводка руководителям не отправлена: ни одно письмо не ушло', ['managers' => $result['managers'], 'emails' => $attempted]);

            return ['status' => 'error', 'message' => \sprintf('Сводка не отправлена: ни одно из %d писем не ушло — проверьте почту (подробности — в журнале аудита) и отправьте сводку снова.', $attempted)] + $result;
        } else {
            $this->auditLogger->info('Отправлена сводка руководителям', ['managers' => $result['managers'], 'emails' => $result['emails'], 'telegram' => $result['telegram'], 'email_failed' => $attempted - $result['emails']]);
        }
        $result['message'] = 0 === $result['managers']
            ? 'Руководителей с невыполненным у сотрудников нет — сводку отправлять некому (подчинённость задаётся оргструктурой: «Пользователи → Оргструктура из 1С» или поле «Руководитель»).'
            : \sprintf('%s: руководителей %d, не выполнено %d, просрочено %d; писем %d, сообщений в Telegram %d.',
                $dryRun ? 'Пробный запуск' : 'Сводка отправлена', $result['managers'], $result['pending'], $result['overdue'], $result['emails'], $result['telegram'])
                .($attempted > $result['emails'] ? \sprintf(' Не ушло писем: %d (подробности — в журнале аудита).', $attempted - $result['emails']) : '');

        return $result;
    }

    /** @param array<string, mixed> $digest */
    private function email(array $digest): TemplatedEmail
    {
        $manager = $digest['manager'];
        $totals = $digest['totals'];

        return (new TemplatedEmail())
            ->from(Address::create($this->mailFrom))
            ->to(new Address((string) $manager->getEmail(), $manager->getDisplayName()))
            ->subject(\sprintf('%s: ваши сотрудники — не выполнено %d, просрочено %d', $this->appName, $totals['pending'], $totals['overdue']))
            ->htmlTemplate('emails/team_digest.html.twig')
            ->textTemplate('emails/team_digest.txt.twig')
            ->context($digest + ['app_name' => $this->appName, 'profile_url' => $this->portalUrl->absolute('app_profile_subscriptions')]);
    }

    /** @param array<string, mixed> $digest */
    private function telegramText(array $digest): string
    {
        $esc = static fn (string $s): string => htmlspecialchars($s, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');
        $totals = $digest['totals'];
        $lines = [\sprintf('<b>Мои сотрудники: не выполнено %d, просрочено %d</b>', $totals['pending'], $totals['overdue'])];
        foreach (\array_slice($digest['people'], 0, 10) as $p) {
            $docs = $p['docs']['assigned'] - $p['docs']['confirmed'];
            $parts = [];
            if ($docs > 0) {
                $parts[] = $docs.' '.Validity::plural($docs, ['документ', 'документа', 'документов']);
            }
            $videos = $p['video']['assigned'] - $p['video']['completed'];
            if ($videos > 0) {
                $parts[] = $videos.' видео';
            }
            $lines[] = '• '.$esc($p['user']->getDisplayName()).' — '.implode(', ', $parts).($p['overdue'] > 0 ? ' (просрочено '.$p['overdue'].')' : '');
        }
        if (\count($digest['people']) > 10 || $digest['more'] > 0) {
            $lines[] = '…';
        }
        $lines[] = \sprintf('<a href="%s">Мои сотрудники</a>', $esc($digest['url']));

        return implode("\n", $lines);
    }
}
