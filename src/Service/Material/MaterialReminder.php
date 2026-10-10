<?php

declare(strict_types=1);

namespace App\Service\Material;

use App\Entity\MaterialParameter;
use App\Entity\MaterialRow;
use App\Entity\Section;
use App\Entity\User;
use App\Repository\SectionModeratorRepository;
use App\Repository\SectionRepository;
use App\Repository\UserRepository;
use App\Service\Notification\TelegramNotifier;
use App\Service\PortalSettings;
use App\Service\PortalUrl;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;

/**
 * Еженедельная сводка по таблицам материалов: модераторам разделов (а где их нет — администраторам)
 * приходит список материалов в выбранных состояниях («Не соответствует», «Отсутствует»…) — по почте и,
 * если привязан, в Telegram. Включается в панели администратора → Материалы.
 */
final class MaterialReminder
{
    /** Подпись для строк с пустым «Состоянием». */
    public const NO_STATE = 'Без оценки';

    /** Состояния, по которым можно получать сводку (в порядке формы настроек). */
    public const STATES = [MaterialState::MISMATCH, MaterialState::ABSENT, MaterialState::UNKNOWN, MaterialState::ACCEPTABLE, self::NO_STATE];

    /** Сколько строк раздела перечислять в письме (остальные — по ссылке на таблицу). */
    public const MAX_ROWS_PER_SECTION = 50;

    /** Повторный запуск раньше этого срока ничего не отправляет (защита от двойного запуска cron). */
    public const MIN_INTERVAL_HOURS = 6 * 24;

    public function __construct(
        private readonly SectionRepository $sections,
        private readonly SectionModeratorRepository $moderators,
        private readonly UserRepository $users,
        private readonly MaterialService $materials,
        private readonly PortalSettings $settings,
        private readonly PortalUrl $portalUrl,
        private readonly MailerInterface $mailer,
        private readonly TelegramNotifier $telegram,
        private readonly LoggerInterface $auditLogger,
        private readonly string $mailFrom,
        private readonly string $appName,
        private readonly string $projectDir = '',
    ) {
    }

    /**
     * Что попадёт в сводку: разделы с таблицей, их строки в выбранных состояниях и получатели.
     *
     * @param list<string>|null $states состояния (по умолчанию — из настроек)
     *
     * @return list<array{section: Section, rows: list<array{row: MaterialRow, state: string, comment: string}>, counts: array<string, int>, recipients: list<User>, url: string}>
     */
    public function collect(?array $states = null): array
    {
        $states ??= $this->settings->materialsRemind()['states'];
        $wanted = array_flip($states);
        $out = [];
        foreach ($this->sections->findRoots() as $section) {
            if (!$section->isMaterialsEnabled()) {
                continue;
            }
            $rows = [];
            $counts = [];
            $comment = $this->materials->roleParameter($section, MaterialParameter::ROLE_COMMENT);
            foreach ($this->materials->rows($section) as $row) {
                $state = $this->materials->state($row);
                $label = '' === $state ? self::NO_STATE : $state;
                if (!isset($wanted[$label])) {
                    continue;
                }
                $rows[] = ['row' => $row, 'state' => $label, 'comment' => null !== $comment ? $row->getValue($comment) : ''];
                $counts[$label] = ($counts[$label] ?? 0) + 1;
            }
            if ([] === $rows) {
                continue;
            }
            // Порядок строк — как в выбранных состояниях (сначала «Не соответствует»), затем как в таблице.
            $order = array_flip(self::STATES);
            usort($rows, static fn (array $a, array $b): int => [$order[$a['state']] ?? 99, $a['row']->getPosition()] <=> [$order[$b['state']] ?? 99, $b['row']->getPosition()]);
            $recipients = $this->moderators->findResponsibleUsers($section);
            if ([] === $recipients) {
                $recipients = $this->users->findActiveAdmins();
            }
            $out[] = [
                'section' => $section,
                'rows' => $rows,
                'counts' => $counts,
                'recipients' => $recipients,
                'url' => $this->portalUrl->absolute('app_section_materials', ['id' => $section->getId()]),
            ];
        }

        return $out;
    }

    /**
     * Рассылает сводку.
     *
     * @return array{status: string, message: string, sections: int, rows: int, recipients: int, emails: int, telegram: int, plan: list<array{user: User, sections: list<string>, rows: int}>}
     */
    public function run(bool $dryRun = false, bool $force = false): array
    {
        $config = $this->settings->materialsRemind();
        $result = ['status' => 'ok', 'message' => '', 'sections' => 0, 'rows' => 0, 'recipients' => 0, 'emails' => 0, 'telegram' => 0, 'plan' => []];
        if (!$config['enabled'] && !$force) {
            return ['status' => 'disabled', 'message' => 'Сводка по таблицам материалов выключена (панель администратора → Материалы).'] + $result;
        }
        $lock = null;
        if (!$dryRun) {
            // Одна рассылка за раз: запуск по расписанию и «Отправить сейчас» (или двойной щелчок) не дублируют письма.
            $lock = @fopen(rtrim('' !== $this->projectDir ? $this->projectDir : sys_get_temp_dir(), '/').'/var/materials-remind.lock', 'c');
            if (false === $lock || !flock($lock, \LOCK_EX | \LOCK_NB)) {
                return ['status' => 'skipped', 'message' => 'Сводка уже отправляется — дождитесь окончания.'] + $result;
            }
            $lastRun = $this->settings->fresh(PortalSettings::MATERIALS_REMIND_LAST_RUN);
            $last = \is_string($lastRun) ? strtotime($lastRun) : false;
            if (false !== $last && !$force && time() - $last < self::MIN_INTERVAL_HOURS * 3600) {
                return ['status' => 'skipped', 'message' => 'Сводка уже отправлялась '.date('d.m.Y H:i', $last).' — следующая не раньше чем через неделю (--force — отправить сейчас).'] + $result;
            }
            if (false !== $last && $force && time() - $last < 120) {
                return ['status' => 'skipped', 'message' => 'Сводка только что отправлена ('.date('H:i', $last).').'] + $result;
            }
            // Запуск отмечается до рассылки: если он прервётся, повтор по расписанию не разошлёт письма второй раз.
            $this->settings->set(PortalSettings::MATERIALS_REMIND_LAST_RUN, date('Y-m-d H:i:s'));
        }

        try {
            return $this->send($config, $result, $dryRun);
        } finally {
            if (\is_resource($lock)) {
                flock($lock, \LOCK_UN);
                fclose($lock);
            }
        }
    }

    /**
     * @param array{enabled: bool, states: list<string>, last_run: ?string} $config
     * @param array<string, mixed>                                         $result
     *
     * @return array{status: string, message: string, sections: int, rows: int, recipients: int, emails: int, telegram: int, plan: list<array{user: User, sections: list<string>, rows: int}>}
     */
    private function send(array $config, array $result, bool $dryRun): array
    {
        $collected = $this->collect($config['states']);
        $byUser = [];
        foreach ($collected as $entry) {
            ++$result['sections'];
            $result['rows'] += \count($entry['rows']);
            foreach ($entry['recipients'] as $user) {
                $byUser[(int) $user->getId()]['user'] = $user;
                $byUser[(int) $user->getId()]['entries'][] = $entry;
            }
        }
        $result['recipients'] = \count($byUser);
        foreach ($byUser as $item) {
            $user = $item['user'];
            $entries = $item['entries'];
            $result['plan'][] = [
                'user' => $user,
                'sections' => array_map(static fn (array $e): string => $e['section']->getName(), $entries),
                'rows' => array_sum(array_map(static fn (array $e): int => \count($e['rows']), $entries)),
            ];
            if ($dryRun) {
                continue;
            }
            if (null !== $user->getEmail() && '' !== $user->getEmail() && $user->isNotifyEmail()) {
                try {
                    $this->mailer->send($this->email($user, $entries, $config['states']));
                    ++$result['emails'];
                } catch (\Throwable $e) {
                    $this->auditLogger->warning('Не удалось отправить сводку по таблицам материалов', ['user' => $user->getUsername(), 'error' => $e->getMessage()]);
                }
            }
            if ($this->telegram->notify($user, $this->telegramText($entries))) {
                ++$result['telegram'];
            }
        }
        if (!$dryRun) {
            $this->auditLogger->info('Отправлена сводка по таблицам материалов', ['sections' => $result['sections'], 'rows' => $result['rows'], 'emails' => $result['emails'], 'telegram' => $result['telegram']]);
        }
        $result['message'] = 0 === $result['rows']
            ? 'Материалов в выбранных состояниях нет — сводку отправлять некому.'
            : \sprintf('%s: разделов %d, материалов %d, получателей %d; писем %d, сообщений в Telegram %d.',
                $dryRun ? 'Пробный запуск' : 'Сводка отправлена', $result['sections'], $result['rows'], $result['recipients'], $result['emails'], $result['telegram']);

        return $result;
    }

    /**
     * @param list<array<string, mixed>> $entries
     * @param list<string>               $states
     */
    private function email(User $user, array $entries, array $states): TemplatedEmail
    {
        $total = array_sum(array_map(static fn (array $e): int => \count($e['rows']), $entries));

        return (new TemplatedEmail())
            ->from(Address::create($this->mailFrom))
            ->to(new Address((string) $user->getEmail(), $user->getDisplayName()))
            ->subject(\sprintf('%s: материалы, требующие внимания (%d)', $this->appName, $total))
            ->htmlTemplate('emails/materials_remind.html.twig')
            ->textTemplate('emails/materials_remind.txt.twig')
            ->context([
                'user' => $user,
                'entries' => $entries,
                'states' => $states,
                'total' => $total,
                'max_rows' => self::MAX_ROWS_PER_SECTION,
                'app_name' => $this->appName,
                'settings_hint' => $this->portalUrl->absolute('admin_materials'),
            ]);
    }

    /** @param list<array<string, mixed>> $entries */
    private function telegramText(array $entries): string
    {
        $lines = ['<b>Материалы, требующие внимания</b>'];
        foreach ($entries as $entry) {
            $parts = [];
            foreach ($entry['counts'] as $state => $count) {
                $parts[] = mb_strtolower($state).' — '.$count;
            }
            $lines[] = \sprintf('• <a href="%s">%s</a>: %s', htmlspecialchars($entry['url'], \ENT_QUOTES), htmlspecialchars($entry['section']->getName(), \ENT_QUOTES), implode(', ', $parts));
        }

        return implode("\n", $lines);
    }
}
