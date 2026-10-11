<?php

declare(strict_types=1);

namespace App\Service\Org;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Security\Ldap\LdapClient;
use App\Service\UserManager;
use App\Service\Xlsx\XlsxReader;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Оргструктура из кадровой системы (1С:ЗУП и др.): подразделение, должность, табельный номер и непосредственный
 * руководитель сотрудников — из выгрузки CSV или Excel.
 *
 * Файл — таблица со строкой заголовков (она может быть не первой: над ней в отчётах 1С бывают название и дата).
 * Столбцы узнаются по названию: «Логин», «Почта», «ФИО» / «Сотрудник», «Подразделение», «Должность»,
 * «Руководитель», «Табельный номер», «Дата увольнения» / «Уволен» / «Состояние» (полный список — COLUMNS).
 * Сотрудник в портале находится по логину, затем по почте, табельному номеру и ФИО (если такое ФИО одно).
 * Руководитель в ячейке — логин, почта, табельный номер, ФИО или «Фамилия И.О.»; ищется среди всех
 * сотрудников портала, в том числе найденных в этом же файле.
 *
 * Пробный запуск (dryRun) ничего не меняет и возвращает тот же отчёт: что изменится и какие строки не разобраны.
 */
final class OrgImporter
{
    public const MAX_ROWS = 20000;
    /** Сколько изменений и проблем показывать в отчёте (подсчёт — по всем). */
    public const REPORT_LIMIT = 500;

    /** Поле → варианты названия столбца (после нормализации: строчные, «ё» → «е», без знаков препинания). */
    public const COLUMNS = [
        'login' => ['логин', 'учетная запись', 'учетная запись ad', 'имя входа', 'пользователь ad', 'имя пользователя', 'login', 'username', 'user', 'samaccountname', 'account', 'учетная запись пользователя', 'пользователь информационной базы'],
        'email' => ['почта', 'email', 'e mail', 'электронная почта', 'адрес электронной почты', 'mail', 'эл почта', 'рабочая почта', 'e mail рабочий'],
        'name' => ['фио', 'сотрудник', 'физическое лицо', 'работник', 'фамилия имя отчество', 'name', 'full name', 'fullname', 'displayname', 'display name'],
        'department' => ['подразделение', 'отдел', 'департамент', 'структурное подразделение', 'подразделение организации', 'department'],
        'title' => ['должность', 'должность по штатному расписанию', 'позиция штатного расписания', 'title', 'job title', 'jobtitle', 'position'],
        'manager' => ['руководитель', 'непосредственный руководитель', 'начальник', 'руководитель подразделения', 'логин руководителя', 'руководитель логин', 'табельный номер руководителя', 'почта руководителя', 'email руководителя', 'manager'],
        'number' => ['табельный номер', 'таб номер', 'таб', 'табельный', 'табельный номер сотрудника', 'employee id', 'employeeid', 'employee number', 'personnel number'],
        'dismissed' => ['уволен', 'дата увольнения', 'состояние', 'состояние сотрудника', 'статус', 'status', 'dismissed', 'увольнение'],
    ];

    public const COLUMN_LABELS = [
        'login' => 'Логин',
        'email' => 'Почта',
        'name' => 'ФИО',
        'department' => 'Подразделение',
        'title' => 'Должность',
        'manager' => 'Руководитель',
        'number' => 'Табельный номер',
        'dismissed' => 'Увольнение',
    ];

    private const FIELD_LABELS = [
        'name' => 'ФИО',
        'email' => 'Почта',
        'department' => 'Подразделение',
        'title' => 'Должность',
        'number' => 'Табельный номер',
        'manager' => 'Руководитель',
        'active' => 'Активна',
    ];

    /** @var array<string, User> */
    private array $byLogin = [];
    /** @var array<string, User> */
    private array $byEmail = [];
    /** @var array<string, list<User>> */
    private array $byNumber = [];
    /** @var array<string, list<User>> */
    private array $byName = [];
    /** @var array<string, list<User>> «фамилия и о» → сотрудники */
    private array $byInitials = [];

    public function __construct(
        private readonly UserRepository $users,
        private readonly UserManager $userManager,
        private readonly EntityManagerInterface $em,
        private readonly XlsxReader $xlsx,
        private readonly LdapClient $ldap,
        private readonly LoggerInterface $auditLogger,
    ) {
    }

    /**
     * Таблица из файла: найденные столбцы и строки данных.
     *
     * @return array{columns: array<string, int>, rows: list<array{line: int, values: array<string, string>}>}
     *
     * @throws \RuntimeException файл не прочитан или в нём нет строки заголовков
     */
    public function read(string $path, string $originalName = ''): array
    {
        $extension = strtolower(pathinfo('' !== $originalName ? $originalName : $path, \PATHINFO_EXTENSION));
        if ('xlsx' === $extension) {
            $sheets = $this->xlsx->read($path);
            foreach ($sheets as $sheet) {
                if ($sheet['hidden'] ?? false) {
                    continue;
                }
                $rows = [];
                foreach ($sheet['rows'] as $number => $cells) {
                    $row = [];
                    foreach ($cells as $col => $value) {
                        $row[$col - 1] = (string) $value;
                    }
                    if ([] !== $row) {
                        ksort($row);
                        $max = max(array_keys($row));
                        $rows[$number] = array_replace(array_fill(0, $max + 1, ''), $row);
                    }
                }
                $table = self::table($rows);
                if (null !== $table) {
                    return $table;
                }
            }
            throw new \RuntimeException('В книге не найдена строка заголовков: нужны столбцы «Логин», «Почта» или «ФИО» и хотя бы «Подразделение», «Должность» или «Руководитель».');
        }
        if (!\in_array($extension, ['csv', 'txt', ''], true)) {
            throw new \RuntimeException('Поддерживаются файлы CSV и Excel (.xlsx). Из 1С сохраните отчёт как «Лист Excel 2007» или CSV.');
        }
        $content = @file_get_contents($path);
        if (false === $content) {
            throw new \RuntimeException('Не удалось прочитать файл.');
        }

        return self::table(self::csvRows($content)) ?? throw new \RuntimeException('В файле не найдена строка заголовков: нужны столбцы «Логин», «Почта» или «ФИО» и хотя бы «Подразделение», «Должность» или «Руководитель».');
    }

    /**
     * Строки CSV: кодировка (UTF-8 или Windows-1251 — так сохраняет 1С и Excel) и разделитель («;», «,» или
     * табуляция) определяются сами.
     *
     * @return array<int, list<string>> номер строки файла → значения
     */
    public static function csvRows(string $content): array
    {
        if (str_starts_with($content, "\xEF\xBB\xBF")) {
            $content = substr($content, 3);
        } elseif (str_starts_with($content, "\xFF\xFE") || str_starts_with($content, "\xFE\xFF")) {
            $content = (string) mb_convert_encoding(substr($content, 2), 'UTF-8', str_starts_with($content, "\xFF\xFE") ? 'UTF-16LE' : 'UTF-16BE');
        } elseif (!mb_check_encoding($content, 'UTF-8')) {
            $content = (string) mb_convert_encoding($content, 'UTF-8', 'Windows-1251');
        }
        $sample = implode("\n", \array_slice(preg_split('/\R/u', $content, 12) ?: [], 0, 11));
        $counts = [';' => substr_count($sample, ';'), "\t" => substr_count($sample, "\t"), ',' => substr_count($sample, ',')];
        arsort($counts);
        $delimiter = (string) array_key_first($counts);
        $stream = fopen('php://temp', 'w+');
        fwrite($stream, $content);
        rewind($stream);
        $rows = [];
        $line = 0;
        while (false !== ($row = fgetcsv($stream, null, $delimiter, '"', ''))) {
            ++$line;
            if ([null] === $row) {
                continue;
            }
            $rows[$line] = array_map(static fn ($v): string => (string) $v, $row);
            if (\count($rows) > self::MAX_ROWS + 20) {
                break;
            }
        }
        fclose($stream);

        return $rows;
    }

    /**
     * Строка заголовков среди первых 15 строк и данные под ней.
     *
     * @param array<int, list<string>> $rows
     *
     * @return array{columns: array<string, int>, rows: list<array{line: int, values: array<string, string>}>}|null
     */
    public static function table(array $rows): ?array
    {
        $checked = 0;
        foreach ($rows as $headerLine => $cells) {
            if (++$checked > 15) {
                break;
            }
            $columns = self::columns($cells);
            $hasKey = isset($columns['login']) || isset($columns['email']) || isset($columns['name']) || isset($columns['number']);
            $hasData = isset($columns['department']) || isset($columns['title']) || isset($columns['manager']) || isset($columns['dismissed']);
            if (!$hasKey || !$hasData) {
                continue;
            }
            $data = [];
            foreach ($rows as $line => $row) {
                if ($line <= $headerLine) {
                    continue;
                }
                $values = [];
                foreach ($columns as $field => $index) {
                    $values[$field] = trim((string) preg_replace('/\s+/u', ' ', str_replace("\u{A0}", ' ', (string) ($row[$index] ?? ''))));
                }
                if ('' === implode('', $values)) {
                    continue;
                }
                $data[] = ['line' => (int) $line, 'values' => $values];
                if (\count($data) >= self::MAX_ROWS) {
                    break;
                }
            }

            return ['columns' => $columns, 'rows' => $data];
        }

        return null;
    }

    /**
     * Узнанные столбцы строки заголовков: поле → номер столбца (с 0). Первый подходящий столбец побеждает.
     *
     * @param list<string> $cells
     *
     * @return array<string, int>
     */
    public static function columns(array $cells): array
    {
        $found = [];
        foreach ($cells as $index => $cell) {
            $name = self::normalize((string) $cell);
            if ('' === $name) {
                continue;
            }
            foreach (self::COLUMNS as $field => $variants) {
                if (!isset($found[$field]) && \in_array($name, $variants, true)) {
                    $found[$field] = (int) $index;
                    continue 2;
                }
            }
        }

        return $found;
    }

    /** Строчные буквы, «ё» → «е», без знаков препинания и лишних пробелов. */
    public static function normalize(string $value): string
    {
        $value = mb_strtolower(str_replace(['ё', 'Ё'], 'е', $value));
        $value = (string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', $value);

        return trim($value);
    }

    /** «Иванов Иван Иванович» и «Иванов И.И.» → «иванов и и»; null — не похоже на ФИО. */
    public static function initials(string $name): ?string
    {
        $parts = preg_split('/\s+/u', self::normalize($name)) ?: [];
        if (\count($parts) < 2) {
            return null;
        }
        $surname = array_shift($parts);
        if (1 === \count($parts) && mb_strlen($parts[0]) <= 2 && preg_match('/^\p{L}{2}$/u', $parts[0])) {
            // «Иванов ИИ» (точки без пробела потерялись при нормализации).
            $parts = [mb_substr($parts[0], 0, 1), mb_substr($parts[0], 1, 1)];
        }

        return $surname.' '.implode(' ', array_map(static fn (string $p): string => mb_substr($p, 0, 1), \array_slice($parts, 0, 2)));
    }

    /**
     * Загрузка оргструктуры.
     *
     * Параметры: create — создать учётные записи для строк с логином, которых нет в портале (доменные, если
     * настроен LDAP, иначе локальные со сменой пароля при первом входе); deactivate — заблокировать уволенных;
     * names — заменить ФИО из файла (иначе ФИО заполняется, только если его не было).
     *
     * @param array{columns: array<string, int>, rows: list<array{line: int, values: array<string, string>}>} $table
     * @param array{create?: bool, deactivate?: bool, names?: bool}                                                $options
     *
     * @return array{rows: int, matched: int, created: int, updated: int, unchanged: int, deactivated: int, managers: int, skipped: int, changes: list<array{line: int, login: string, name: string, created: bool, fields: array<string, array{0: string, 1: string}>}>, problems: list<array{line: int, message: string}>, columns: list<string>, dry_run: bool}
     */
    public function import(array $table, array $options, ?User $actor, bool $dryRun): array
    {
        $create = (bool) ($options['create'] ?? false);
        $deactivate = (bool) ($options['deactivate'] ?? true);
        $replaceNames = (bool) ($options['names'] ?? false);
        $report = ['rows' => \count($table['rows']), 'matched' => 0, 'created' => 0, 'updated' => 0, 'unchanged' => 0, 'deactivated' => 0, 'managers' => 0, 'skipped' => 0,
            'changes' => [], 'problems' => [], 'columns' => array_values(array_map(static fn (string $f): string => self::COLUMN_LABELS[$f], array_keys($table['columns']))), 'dry_run' => $dryRun];
        $this->index();
        $problem = static function (int $line, string $message) use (&$report): void {
            if (\count($report['problems']) < self::REPORT_LIMIT) {
                $report['problems'][] = ['line' => $line, 'message' => $message];
            }
        };

        $connection = $this->em->getConnection();
        if (!$dryRun) {
            $connection->beginTransaction();
        }
        /** @var list<array{user: User, line: int, values: array<string, string>, created: bool, fields: array<string, array{0: string, 1: string}>}> $matched */
        $matched = [];
        try {
            $seenUsers = [];
            foreach ($table['rows'] as $row) {
                $line = $row['line'];
                $values = $row['values'];
                [$user, $why] = $this->find($values);
                $created = false;
                if (null === $user && null !== $why) {
                    $problem($line, $why);
                    ++$report['skipped'];
                    continue;
                }
                if (null === $user) {
                    $login = User::normalizeUsername($values['login'] ?? '');
                    if (!$create || '' === $login) {
                        $problem($line, \sprintf('Сотрудник не найден в портале: %s.', self::describe($values)).($create && '' === $login ? ' Чтобы создать учётную запись, нужен логин.' : ''));
                        ++$report['skipped'];
                        continue;
                    }
                    if (self::isDismissed($values)) {
                        ++$report['skipped'];
                        continue;
                    }
                    if (!preg_match('/^[\p{L}\p{N}._@-]{1,64}$/u', $login)) {
                        $problem($line, \sprintf('Некорректный логин «%s».', $login));
                        ++$report['skipped'];
                        continue;
                    }
                    $user = $this->createUser($login, $values, $actor, $dryRun);
                    $created = true;
                    ++$report['created'];
                } else {
                    ++$report['matched'];
                }
                $uid = spl_object_id($user);
                if (isset($seenUsers[$uid])) {
                    $problem($line, \sprintf('Сотрудник «%s» уже был в строке %d — строка пропущена.', $user->getUsername(), $seenUsers[$uid]));
                    ++$report['skipped'];
                    continue;
                }
                $seenUsers[$uid] = $line;
                $fields = $this->applyFields($user, $values, $replaceNames, $created);
                if ($deactivate && !$created && self::isDismissed($values) && $user->isActive()) {
                    if ($user->isAdmin()) {
                        $problem($line, \sprintf('«%s» уволен по данным файла, но это администратор портала — учётная запись не заблокирована, проверьте вручную.', $user->getUsername()));
                    } elseif (null !== $actor && $actor->getId() === $user->getId()) {
                        $problem($line, 'Свою учётную запись заблокировать нельзя.');
                    } else {
                        $user->setActive(false);
                        $fields[self::FIELD_LABELS['active']] = ['да', 'нет — уволен'];
                        ++$report['deactivated'];
                    }
                }
                $matched[] = ['user' => $user, 'line' => $line, 'values' => $values, 'created' => $created, 'fields' => $fields];
            }

            // Руководители — вторым проходом: руководитель может стоять в файле ниже своих сотрудников.
            if (isset($table['columns']['manager'])) {
                foreach ($matched as $i => $entry) {
                    $value = $entry['values']['manager'] ?? '';
                    if ('' === $value) {
                        continue;
                    }
                    [$manager, $why] = $this->findManager($value);
                    if (null === $manager) {
                        $problem($entry['line'], \sprintf('Руководитель «%s» для «%s» не найден%s.', $value, $entry['user']->getUsername(), null !== $why ? ': '.$why : ''));
                        continue;
                    }
                    if ($manager === $entry['user']) {
                        continue; // руководитель подразделения сам себе руководитель в выгрузке — пропускаем молча
                    }
                    $old = $entry['user']->getManager();
                    if ($old === $manager) {
                        continue;
                    }
                    try {
                        $entry['user']->setManager($manager);
                    } catch (\DomainException $e) {
                        $problem($entry['line'], $e->getMessage());
                        continue;
                    }
                    $matched[$i]['fields'][self::FIELD_LABELS['manager']] = [null !== $old ? self::who($old) : '', self::who($manager)];
                    ++$report['managers'];
                }
            }

            foreach ($matched as $entry) {
                if ([] === $entry['fields'] && !$entry['created']) {
                    ++$report['unchanged'];
                    continue;
                }
                if (!$entry['created']) {
                    ++$report['updated'];
                }
                if (\count($report['changes']) < self::REPORT_LIMIT) {
                    $report['changes'][] = ['line' => $entry['line'], 'login' => $entry['user']->getUsername(), 'name' => $entry['user']->getDisplayName(), 'created' => $entry['created'], 'fields' => $entry['fields']];
                }
            }

            if (!$dryRun) {
                $this->em->flush();
                $connection->commit();
                $this->auditLogger->info('Загружена оргструктура', [
                    'rows' => $report['rows'], 'created' => $report['created'], 'updated' => $report['updated'], 'deactivated' => $report['deactivated'],
                    'managers' => $report['managers'], 'skipped' => $report['skipped'], 'by' => $actor?->getUsername(),
                ]);
            }
        } catch (\Throwable $e) {
            if (!$dryRun && $connection->isTransactionActive()) {
                $connection->rollBack();
            }
            throw $e;
        } finally {
            if ($dryRun) {
                // Пробный запуск: всё, что изменилось в памяти, возвращается к сохранённому в базе.
                foreach ($matched as $entry) {
                    if (!$entry['created'] && $this->em->contains($entry['user'])) {
                        $this->em->refresh($entry['user']);
                    }
                }
            }
        }

        return $report;
    }

    /**
     * Поиск сотрудника по строке: логин → почта → табельный номер → ФИО. [сотрудник, null] — найден;
     * [null, причина] — неоднозначно; [null, null] — не найден.
     *
     * @param array<string, string> $values
     *
     * @return array{0: ?User, 1: ?string}
     */
    private function find(array $values): array
    {
        $login = User::normalizeUsername($values['login'] ?? '');
        if ('' !== $login) {
            $user = $this->byLogin[$login] ?? (str_contains($login, '@') ? ($this->byLogin[strstr($login, '@', true)] ?? null) : null);
            if (null !== $user) {
                return [$user, null];
            }
        }
        $email = mb_strtolower(trim($values['email'] ?? ''));
        if ('' !== $email && isset($this->byEmail[$email])) {
            return [$this->byEmail[$email], null];
        }
        $number = trim($values['number'] ?? '');
        if ('' !== $number && isset($this->byNumber[$number])) {
            if (\count($this->byNumber[$number]) > 1) {
                return [null, \sprintf('Табельный номер %s есть у нескольких сотрудников портала.', $number)];
            }

            return [$this->byNumber[$number][0], null];
        }
        if ('' === $login) {
            $name = self::normalize($values['name'] ?? '');
            if ('' !== $name && isset($this->byName[$name])) {
                if (\count($this->byName[$name]) > 1) {
                    return [null, \sprintf('В портале несколько сотрудников «%s» — добавьте в файл логин, почту или табельный номер.', $values['name'])];
                }

                return [$this->byName[$name][0], null];
            }
        }

        return [null, null];
    }

    /** @return array{0: ?User, 1: ?string} */
    private function findManager(string $value): array
    {
        $login = User::normalizeUsername($value);
        if (isset($this->byLogin[$login])) {
            return [$this->byLogin[$login], null];
        }
        $email = mb_strtolower(trim($value));
        if (isset($this->byEmail[$email])) {
            return [$this->byEmail[$email], null];
        }
        if (isset($this->byNumber[trim($value)])) {
            return 1 === \count($this->byNumber[trim($value)]) ? [$this->byNumber[trim($value)][0], null] : [null, 'табельный номер не уникален'];
        }
        $name = self::normalize($value);
        if (isset($this->byName[$name])) {
            return 1 === \count($this->byName[$name]) ? [$this->byName[$name][0], null] : [null, 'в портале несколько сотрудников с таким ФИО'];
        }
        $initials = self::initials($value);
        if (null !== $initials && isset($this->byInitials[$initials])) {
            return 1 === \count($this->byInitials[$initials]) ? [$this->byInitials[$initials][0], null] : [null, 'по фамилии и инициалам подходят несколько сотрудников'];
        }

        return [null, null];
    }

    /**
     * Изменения полей сотрудника по строке файла: поле → [было, стало].
     *
     * @param array<string, string> $values
     *
     * @return array<string, array{0: string, 1: string}>
     */
    private function applyFields(User $user, array $values, bool $replaceNames, bool $created): array
    {
        $changes = [];
        $set = function (string $field, ?string $old, string $new, callable $apply) use (&$changes, $created): void {
            if ('' === $new || $new === (string) $old) {
                return;
            }
            $apply($new);
            if (!$created) {
                $changes[$field] = [(string) $old, $new];
            }
        };
        $name = $values['name'] ?? '';
        if ('' !== $name && ($replaceNames || $created || '' === trim($user->getDisplayName()) || $user->getDisplayName() === $user->getUsername())) {
            $set('name', $user->getDisplayName(), mb_substr($name, 0, 128), fn (string $v) => $user->setDisplayName($v));
        }
        $email = mb_strtolower($values['email'] ?? '');
        if ('' !== $email && null === $user->getEmail() && false !== filter_var($email, \FILTER_VALIDATE_EMAIL) && !isset($this->byEmail[$email])) {
            $set('email', $user->getEmail(), $email, function (string $v) use ($user): void {
                $user->setEmail($v);
                $this->byEmail[$v] = $user;
            });
        }
        $set('department', $user->getDepartment(), mb_substr($values['department'] ?? '', 0, 128), fn (string $v) => $user->setDepartment($v));
        $set('title', $user->getJobTitle(), mb_substr($values['title'] ?? '', 0, 128), fn (string $v) => $user->setJobTitle($v));
        $number = mb_substr($values['number'] ?? '', 0, 32);
        $set('number', $user->getEmployeeNumber(), $number, function (string $v) use ($user): void {
            $user->setEmployeeNumber($v);
            if (!\in_array($user, $this->byNumber[$v] ?? [], true)) {
                $this->byNumber[$v][] = $user;
            }
        });

        return array_combine(array_map(static fn (string $f): string => self::FIELD_LABELS[$f] ?? $f, array_keys($changes)), array_values($changes)) ?: [];
    }

    /** @param array<string, string> $values */
    private function createUser(string $login, array $values, ?User $actor, bool $dryRun): User
    {
        $name = '' !== ($values['name'] ?? '') ? mb_substr($values['name'], 0, 128) : $login;
        $email = mb_strtolower($values['email'] ?? '');
        $email = '' !== $email && false !== filter_var($email, \FILTER_VALIDATE_EMAIL) && !isset($this->byEmail[$email]) ? $email : null;
        if ($dryRun) {
            $user = (new User())->setUsername($login)->setDisplayName($name)->setEmail($email)->setActive(true);
        } elseif ($this->ldap->isEnabled()) {
            $user = $this->userManager->createLdapUser($login, $name, false, $email, $actor);
        } else {
            // Локальная учётная запись со случайным паролем: администратор выдаёт пароль, при входе его нужно сменить.
            $user = $this->userManager->create($login, $name, bin2hex(random_bytes(12)).'Aa1!', false, $email, true, $actor);
        }
        $this->remember($user);

        return $user;
    }

    private function index(): void
    {
        $this->byLogin = $this->byEmail = $this->byNumber = $this->byName = $this->byInitials = [];
        foreach ($this->users->findAll() as $user) {
            $this->remember($user);
        }
    }

    private function remember(User $user): void
    {
        $this->byLogin[$user->getUsername()] = $user;
        if (null !== $user->getEmail()) {
            $this->byEmail[mb_strtolower($user->getEmail())] ??= $user;
        }
        if (null !== $user->getEmployeeNumber()) {
            $this->byNumber[$user->getEmployeeNumber()][] = $user;
        }
        $name = self::normalize($user->getDisplayName());
        if ('' !== $name && $name !== $user->getUsername()) {
            $this->byName[$name][] = $user;
            $initials = self::initials($user->getDisplayName());
            if (null !== $initials) {
                $this->byInitials[$initials][] = $user;
            }
        }
    }

    /** Уволен ли сотрудник по данным строки: «да» в «Уволен», прошедшая дата увольнения, «уволен» в состоянии. */
    public static function isDismissed(array $values): bool
    {
        $value = mb_strtolower(trim($values['dismissed'] ?? ''));
        if ('' === $value || \in_array($value, ['нет', 'no', 'false', '0', '-', 'работает', 'работа', 'active', 'активен'], true)) {
            return false;
        }
        if (\in_array($value, ['да', 'yes', 'true', '1', 'x', 'х', '+', 'уволен', 'уволена', 'dismissed', 'inactive'], true) || str_contains($value, 'уволен')) {
            return true;
        }
        // Дата увольнения: ДД.ММ.ГГГГ или ГГГГ-ММ-ДД (в том числе с временем) — уволен, если дата уже наступила.
        if (preg_match('/^(\d{1,2})\.(\d{1,2})\.(\d{4})/', $value, $m)) {
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d', \sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]));
        } elseif (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $value, $m)) {
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $m[1].'-'.$m[2].'-'.$m[3]);
        } else {
            return false;
        }

        return false !== $date && $date <= new \DateTimeImmutable('today');
    }

    /** @param array<string, string> $values */
    private static function describe(array $values): string
    {
        $parts = array_filter([$values['name'] ?? '', $values['login'] ?? '', $values['email'] ?? '', '' !== ($values['number'] ?? '') ? 'таб. № '.$values['number'] : '']);

        return [] !== $parts ? implode(', ', $parts) : 'пустая строка';
    }

    private static function who(User $user): string
    {
        return $user->getDisplayName().' ('.$user->getUsername().')';
    }
}
