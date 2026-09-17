<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Setting;
use App\Entity\User;
use App\Repository\SettingRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * Настройки портала, изменяемые в панели администратора (хранятся в таблице setting, кэшируются).
 *
 * Гостевой доступ (просмотр открытых документов без входа):
 *  - all — разрешён с любых адресов;
 *  - ip  — разрешён только с указанных IP-адресов и подсетей;
 *  - off — выключен, для просмотра нужно войти.
 */
final class PortalSettings
{
    public const GUEST_MODE = 'guest_access.mode';
    public const GUEST_NETWORKS = 'guest_access.networks';

    public const GUEST_ALL = 'all';
    public const GUEST_IP = 'ip';
    public const GUEST_OFF = 'off';

    public const GUEST_MODES = [self::GUEST_ALL, self::GUEST_IP, self::GUEST_OFF];

    public const GUEST_MODE_LABELS = [
        self::GUEST_ALL => 'разрешён с любых адресов',
        self::GUEST_IP => 'только с указанных IP-адресов и подсетей',
        self::GUEST_OFF => 'выключен — для просмотра нужно войти',
    ];

    // Интеграции: webhook об изменениях документов и описания через внешнюю LLM.
    public const WEBHOOK_URL = 'webhook.url';
    public const WEBHOOK_SECRET = 'webhook.secret';
    public const WEBHOOK_ENABLED = 'webhook.enabled';

    public const LLM_ENABLED = 'llm.enabled';
    public const LLM_BASE_URL = 'llm.base_url';
    public const LLM_API_KEY = 'llm.api_key';
    public const LLM_MODEL = 'llm.model';
    public const LLM_PROMPT = 'llm.prompt';
    public const LLM_MAX_INPUT_CHARS = 'llm.max_input_chars';
    public const LLM_TIMEOUT = 'llm.timeout';
    public const LLM_AUTO_DESCRIBE = 'llm.auto_describe';
    public const LLM_TEMPERATURE = 'llm.temperature';

    public const LLM_DEFAULT_BASE_URL = 'https://api.openai.com/v1';
    public const LLM_DEFAULT_MODEL = 'gpt-4o-mini';
    public const LLM_DEFAULT_MAX_INPUT_CHARS = 12000;
    public const LLM_DEFAULT_TIMEOUT = 60;
    public const LLM_DEFAULT_TEMPERATURE = 0.2;
    public const LLM_DEFAULT_PROMPT = <<<'PROMPT'
        Ты помогаешь вести корпоративный портал документации. По названию, реквизитам и фрагменту текста документа составь его краткое описание на русском языке: 2–4 предложения, деловой стиль, без вступлений и оценок. Укажи, о чём документ, для кого он и какие ключевые вопросы охватывает. Не придумывай сведений, которых нет в тексте. Ответь только текстом описания.
        PROMPT;

    // Распознавание текста на сканах (OCR): включается администратором, работает в фоновой индексации.
    public const OCR_ENABLED = 'ocr.enabled';
    public const OCR_LANGUAGES = 'ocr.languages';
    public const OCR_MAX_PAGES = 'ocr.max_pages';
    public const OCR_DPI = 'ocr.dpi';

    // Вход через внешнего провайдера (OpenID Connect): Keycloak, Blitz Identity, AD FS и т. п.
    public const SSO_ENABLED = 'sso.enabled';
    public const SSO_ISSUER = 'sso.issuer';
    public const SSO_CLIENT_ID = 'sso.client_id';
    public const SSO_CLIENT_SECRET = 'sso.client_secret';
    public const SSO_SCOPES = 'sso.scopes';
    public const SSO_BUTTON = 'sso.button';
    public const SSO_CREATE_USERS = 'sso.create_users';
    public const SSO_USERNAME_CLAIM = 'sso.username_claim';
    public const SSO_NAME_CLAIM = 'sso.name_claim';
    public const SSO_EMAIL_CLAIM = 'sso.email_claim';
    public const SSO_DEPARTMENT_CLAIM = 'sso.department_claim';
    public const SSO_ADMIN_CLAIM = 'sso.admin_claim';
    public const SSO_ADMIN_VALUE = 'sso.admin_value';

    public const SSO_DEFAULT_SCOPES = 'openid profile email';
    public const SSO_DEFAULT_BUTTON = 'Войти через SSO';

    // Безопасность: обязательный второй фактор для администраторов.
    public const SECURITY_REQUIRE_2FA_ADMINS = 'security.require_2fa_admins';

    // Согласование документов перед публикацией.
    public const APPROVAL_REQUIRED = 'approval.required';
    public const APPROVAL_AUTO_PUBLISH = 'approval.auto_publish';

    // Обсуждение документов и подписка на изменения.
    public const COMMENTS_ENABLED = 'comments.enabled';
    public const SUBSCRIPTIONS_ENABLED = 'subscriptions.enabled';

    // Уведомления в Telegram (бот компании; портал обращается к нему исходящими запросами).
    public const TELEGRAM_ENABLED = 'telegram.enabled';
    public const TELEGRAM_TOKEN = 'telegram.token';
    public const TELEGRAM_BOT_NAME = 'telegram.bot_name';
    public const TELEGRAM_API_URL = 'telegram.api_url';
    public const TELEGRAM_ADMIN_CHAT = 'telegram.admin_chat';
    /** Служебное значение: смещение getUpdates, чтобы не разбирать одни и те же сообщения дважды. */
    public const TELEGRAM_OFFSET = 'telegram.offset';

    public const TELEGRAM_DEFAULT_API_URL = 'https://api.telegram.org';

    public const OCR_DEFAULT_MAX_PAGES = 20;
    public const OCR_DEFAULT_DPI = 200;

    /** Настройки, значения которых не должны попадать в журнал. */
    private const SECRET_NAMES = [self::WEBHOOK_SECRET, self::LLM_API_KEY, self::SSO_CLIENT_SECRET, self::TELEGRAM_TOKEN];

    private const CACHE_KEY = 'portal_settings';

    /** @var array<string, mixed>|null */
    private ?array $values = null;

    public function __construct(
        private readonly SettingRepository $settings,
        private readonly EntityManagerInterface $em,
        private readonly CacheInterface $cache,
        private readonly LoggerInterface $auditLogger,
        private readonly string $defaultGuestMode = self::GUEST_ALL,
    ) {
    }

    public function get(string $name, mixed $default = null): mixed
    {
        $values = $this->all();

        return \array_key_exists($name, $values) ? $values[$name] : $default;
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        if (null === $this->values) {
            $this->values = $this->cache->get(self::CACHE_KEY, fn (): array => $this->settings->findAllValues());
        }

        return $this->values;
    }

    public function set(string $name, mixed $value, ?User $by = null): void
    {
        $setting = $this->settings->find($name);
        if (null === $setting) {
            $setting = new Setting($name, $value);
            $setting->setValue($value, $by);
            $this->em->persist($setting);
        } else {
            $setting->setValue($value, $by);
        }
        $this->em->flush();
        $this->cache->delete(self::CACHE_KEY);
        $this->values = null;
        $this->auditLogger->info('Изменена настройка портала', ['name' => $name, 'value' => \in_array($name, self::SECRET_NAMES, true) ? self::mask((string) $value) : $value, 'by' => $by?->getUsername()]);
    }

    /** Сохраняет несколько настроек, сбрасывая кэш один раз. */
    public function setMany(array $values, ?User $by = null): void
    {
        foreach ($values as $name => $value) {
            $this->set((string) $name, $value, $by);
        }
    }

    /** Скрывает секрет, оставляя первые и последние символы: sk-ab…wxyz. */
    public static function mask(?string $secret): string
    {
        $secret = (string) $secret;
        if ('' === $secret) {
            return '';
        }
        if (mb_strlen($secret) <= 8) {
            return str_repeat('•', mb_strlen($secret));
        }

        return mb_substr($secret, 0, 4).'…'.mb_substr($secret, -4);
    }

    // ---- Webhook -----------------------------------------------------------------------------------------

    /** @return array{enabled: bool, url: string, secret: string} */
    public function webhook(): array
    {
        $url = trim((string) $this->get(self::WEBHOOK_URL, ''));

        return [
            'enabled' => (bool) $this->get(self::WEBHOOK_ENABLED, false) && '' !== $url,
            'url' => $url,
            'secret' => (string) $this->get(self::WEBHOOK_SECRET, ''),
        ];
    }

    /**
     * @throws \InvalidArgumentException при некорректном адресе
     */
    public function setWebhook(bool $enabled, string $url, ?string $secret, ?User $by = null): void
    {
        $url = trim($url);
        if ('' !== $url && (false === filter_var($url, \FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $url))) {
            throw new \InvalidArgumentException('Адрес webhook должен начинаться с http:// или https://.');
        }
        if ($enabled && '' === $url) {
            throw new \InvalidArgumentException('Укажите адрес webhook.');
        }
        $values = [self::WEBHOOK_ENABLED => $enabled, self::WEBHOOK_URL => $url];
        if (null !== $secret) { // null — оставить прежний секрет
            $values[self::WEBHOOK_SECRET] = trim($secret);
        }
        $this->setMany($values, $by);
    }

    // ---- LLM ---------------------------------------------------------------------------------------------

    /**
     * @return array{enabled: bool, base_url: string, api_key: string, model: string, prompt: string, max_input_chars: int, timeout: int, auto_describe: bool, temperature: float}
     */
    public function llm(): array
    {
        $baseUrl = rtrim(trim((string) $this->get(self::LLM_BASE_URL, self::LLM_DEFAULT_BASE_URL)), '/');
        $prompt = trim((string) $this->get(self::LLM_PROMPT, ''));

        return [
            'enabled' => (bool) $this->get(self::LLM_ENABLED, false),
            'base_url' => '' !== $baseUrl ? $baseUrl : self::LLM_DEFAULT_BASE_URL,
            'api_key' => (string) $this->get(self::LLM_API_KEY, ''),
            'model' => trim((string) $this->get(self::LLM_MODEL, self::LLM_DEFAULT_MODEL)) ?: self::LLM_DEFAULT_MODEL,
            'prompt' => '' !== $prompt ? $prompt : self::LLM_DEFAULT_PROMPT,
            'max_input_chars' => max(500, (int) $this->get(self::LLM_MAX_INPUT_CHARS, self::LLM_DEFAULT_MAX_INPUT_CHARS)),
            'timeout' => max(5, min(600, (int) $this->get(self::LLM_TIMEOUT, self::LLM_DEFAULT_TIMEOUT))),
            'auto_describe' => (bool) $this->get(self::LLM_AUTO_DESCRIBE, false),
            'temperature' => (float) $this->get(self::LLM_TEMPERATURE, self::LLM_DEFAULT_TEMPERATURE),
        ];
    }

    public function isLlmEnabled(): bool
    {
        return $this->llm()['enabled'];
    }

    /**
     * Распознавание текста на сканах.
     *
     * @return array{enabled: bool, languages: string, max_pages: int, dpi: int}
     */
    public function ocr(string $defaultLanguages = 'rus+eng'): array
    {
        $languages = trim((string) $this->get(self::OCR_LANGUAGES, ''));

        return [
            'enabled' => (bool) $this->get(self::OCR_ENABLED, false),
            'languages' => '' !== $languages ? $languages : $defaultLanguages,
            'max_pages' => max(1, min(200, (int) $this->get(self::OCR_MAX_PAGES, self::OCR_DEFAULT_MAX_PAGES))),
            'dpi' => max(72, min(600, (int) $this->get(self::OCR_DPI, self::OCR_DEFAULT_DPI))),
        ];
    }

    /**
     * Языки tesseract: «rus+eng». Разделителем принимаем плюс, запятую, пробел — как напишет администратор.
     *
     * @return string нормализованный список («rus+eng») или пустая строка
     */
    public static function normalizeLanguages(string $raw): string
    {
        $parts = preg_split('/[^a-zA-Z0-9_]+/', trim($raw)) ?: [];
        $parts = array_values(array_unique(array_filter($parts, static fn (string $p): bool => '' !== $p)));

        return implode('+', $parts);
    }

    /**
     * Настройки входа через внешнего провайдера.
     *
     * @return array{enabled: bool, issuer: string, client_id: string, client_secret: string, scopes: string, button: string, create_users: bool, username_claim: string, name_claim: string, email_claim: string, department_claim: string, admin_claim: string, admin_value: string}
     */
    public function sso(): array
    {
        $issuer = rtrim(trim((string) $this->get(self::SSO_ISSUER, '')), '/');
        $scopes = trim((string) $this->get(self::SSO_SCOPES, ''));
        $button = trim((string) $this->get(self::SSO_BUTTON, ''));

        return [
            'enabled' => (bool) $this->get(self::SSO_ENABLED, false),
            'issuer' => $issuer,
            'client_id' => trim((string) $this->get(self::SSO_CLIENT_ID, '')),
            'client_secret' => (string) $this->get(self::SSO_CLIENT_SECRET, ''),
            'scopes' => '' !== $scopes ? $scopes : self::SSO_DEFAULT_SCOPES,
            'button' => '' !== $button ? $button : self::SSO_DEFAULT_BUTTON,
            'create_users' => (bool) $this->get(self::SSO_CREATE_USERS, true),
            'username_claim' => trim((string) $this->get(self::SSO_USERNAME_CLAIM, '')) ?: 'preferred_username',
            'name_claim' => trim((string) $this->get(self::SSO_NAME_CLAIM, '')) ?: 'name',
            'email_claim' => trim((string) $this->get(self::SSO_EMAIL_CLAIM, '')) ?: 'email',
            'department_claim' => trim((string) $this->get(self::SSO_DEPARTMENT_CLAIM, '')),
            'admin_claim' => trim((string) $this->get(self::SSO_ADMIN_CLAIM, '')),
            'admin_value' => trim((string) $this->get(self::SSO_ADMIN_VALUE, '')),
        ];
    }

    public function isSsoEnabled(): bool
    {
        $sso = $this->sso();

        return $sso['enabled'] && '' !== $sso['issuer'] && '' !== $sso['client_id'];
    }

    /** @param array<string, mixed> $values ключ client_secret = null — не менять */
    public function setSso(array $values, ?User $by = null): void
    {
        $update = [
            self::SSO_ENABLED => (bool) ($values['enabled'] ?? false),
            self::SSO_ISSUER => rtrim(trim((string) ($values['issuer'] ?? '')), '/'),
            self::SSO_CLIENT_ID => trim((string) ($values['client_id'] ?? '')),
            self::SSO_SCOPES => trim((string) ($values['scopes'] ?? '')),
            self::SSO_BUTTON => mb_substr(trim((string) ($values['button'] ?? '')), 0, 64),
            self::SSO_CREATE_USERS => (bool) ($values['create_users'] ?? false),
            self::SSO_USERNAME_CLAIM => trim((string) ($values['username_claim'] ?? '')),
            self::SSO_NAME_CLAIM => trim((string) ($values['name_claim'] ?? '')),
            self::SSO_EMAIL_CLAIM => trim((string) ($values['email_claim'] ?? '')),
            self::SSO_DEPARTMENT_CLAIM => trim((string) ($values['department_claim'] ?? '')),
            self::SSO_ADMIN_CLAIM => trim((string) ($values['admin_claim'] ?? '')),
            self::SSO_ADMIN_VALUE => trim((string) ($values['admin_value'] ?? '')),
        ];
        if (null !== ($values['client_secret'] ?? null)) {
            $update[self::SSO_CLIENT_SECRET] = (string) $values['client_secret'];
        }
        $this->setMany($update, $by);
    }

    /** Обязателен ли второй фактор для администраторов. */
    public function requireAdminTwoFactor(): bool
    {
        return (bool) $this->get(self::SECURITY_REQUIRE_2FA_ADMINS, false);
    }

    public function setRequireAdminTwoFactor(bool $required, ?User $by = null): void
    {
        $this->set(self::SECURITY_REQUIRE_2FA_ADMINS, $required, $by);
    }

    /**
     * Согласование перед публикацией.
     *
     * @return array{required: bool, auto_publish: bool}
     */
    public function approval(): array
    {
        return [
            'required' => (bool) $this->get(self::APPROVAL_REQUIRED, false),
            'auto_publish' => (bool) $this->get(self::APPROVAL_AUTO_PUBLISH, true),
        ];
    }

    public function setApproval(bool $required, bool $autoPublish, ?User $by = null): void
    {
        $this->setMany([
            self::APPROVAL_REQUIRED => $required,
            self::APPROVAL_AUTO_PUBLISH => $autoPublish,
        ], $by);
    }

    /** @param array{enabled?: bool, languages?: string, max_pages?: int, dpi?: int} $values */
    public function setOcr(array $values, ?User $by = null): void
    {
        $this->setMany([
            self::OCR_ENABLED => (bool) ($values['enabled'] ?? false),
            self::OCR_LANGUAGES => self::normalizeLanguages((string) ($values['languages'] ?? '')),
            self::OCR_MAX_PAGES => max(1, min(200, (int) ($values['max_pages'] ?? self::OCR_DEFAULT_MAX_PAGES))),
            self::OCR_DPI => max(72, min(600, (int) ($values['dpi'] ?? self::OCR_DEFAULT_DPI))),
        ], $by);
    }

    /**
     * @param array<string, mixed> $values ключи: enabled, base_url, api_key (null — не менять), model, prompt, max_input_chars, timeout, auto_describe, temperature
     *
     * @throws \InvalidArgumentException
     */
    public function setLlm(array $values, ?User $by = null): void
    {
        $baseUrl = rtrim(trim((string) ($values['base_url'] ?? self::LLM_DEFAULT_BASE_URL)), '/');
        if ('' === $baseUrl) {
            $baseUrl = self::LLM_DEFAULT_BASE_URL;
        }
        if (false === filter_var($baseUrl, \FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $baseUrl)) {
            throw new \InvalidArgumentException('Адрес API LLM должен начинаться с http:// или https:// (например, https://api.openai.com/v1).');
        }
        $model = trim((string) ($values['model'] ?? ''));
        if ('' === $model) {
            throw new \InvalidArgumentException('Укажите название модели.');
        }
        $temperature = (float) str_replace(',', '.', (string) ($values['temperature'] ?? self::LLM_DEFAULT_TEMPERATURE));
        if ($temperature < 0 || $temperature > 2) {
            throw new \InvalidArgumentException('Температура должна быть в пределах от 0 до 2.');
        }
        $set = [
            self::LLM_ENABLED => (bool) ($values['enabled'] ?? false),
            self::LLM_BASE_URL => $baseUrl,
            self::LLM_MODEL => mb_substr($model, 0, 128),
            self::LLM_PROMPT => trim((string) ($values['prompt'] ?? '')),
            self::LLM_MAX_INPUT_CHARS => max(500, min(400000, (int) ($values['max_input_chars'] ?? self::LLM_DEFAULT_MAX_INPUT_CHARS))),
            self::LLM_TIMEOUT => max(5, min(600, (int) ($values['timeout'] ?? self::LLM_DEFAULT_TIMEOUT))),
            self::LLM_AUTO_DESCRIBE => (bool) ($values['auto_describe'] ?? false),
            self::LLM_TEMPERATURE => $temperature,
        ];
        if (\array_key_exists('api_key', $values) && null !== $values['api_key']) {
            $set[self::LLM_API_KEY] = trim((string) $values['api_key']);
        }
        $this->setMany($set, $by);
    }

    public function guestMode(): string
    {
        $mode = (string) $this->get(self::GUEST_MODE, $this->defaultGuestMode);

        return \in_array($mode, self::GUEST_MODES, true) ? $mode : self::GUEST_ALL;
    }

    /** @return list<string> IP-адреса и подсети (CIDR), с которых разрешён просмотр без входа */
    public function guestNetworks(): array
    {
        $list = $this->get(self::GUEST_NETWORKS, []);

        return \is_array($list) ? array_values(array_filter(array_map('strval', $list), static fn (string $s) => '' !== $s)) : [];
    }

    /** Разрешён ли просмотр открытых документов без входа с этого адреса. */
    public function isGuestAllowed(?string $ip): bool
    {
        return match ($this->guestMode()) {
            self::GUEST_ALL => true,
            self::GUEST_IP => null !== $ip && '' !== $ip && [] !== $this->guestNetworks() && IpUtils::checkIp($ip, $this->guestNetworks()),
            default => false,
        };
    }

    /**
     * Сохраняет режим гостевого доступа и список сетей.
     *
     * @param list<string> $networks
     *
     * @throws \InvalidArgumentException при недопустимом режиме или адресе
     */
    public function setGuestAccess(string $mode, array $networks, ?User $by = null): void
    {
        if (!\in_array($mode, self::GUEST_MODES, true)) {
            throw new \InvalidArgumentException('Неизвестный режим гостевого доступа.');
        }
        $clean = self::normalizeNetworks($networks);
        if (self::GUEST_IP === $mode && [] === $clean) {
            throw new \InvalidArgumentException('Укажите хотя бы один IP-адрес или подсеть.');
        }
        $this->set(self::GUEST_MODE, $mode, $by);
        $this->set(self::GUEST_NETWORKS, $clean, $by);
    }

    /**
     * Разбирает список адресов (IPv4/IPv6, при необходимости с префиксом /NN), убирая повторы.
     *
     * @param list<string> $networks
     *
     * @return list<string>
     *
     * @throws \InvalidArgumentException с указанием первой некорректной записи
     */
    public static function normalizeNetworks(array $networks): array
    {
        $out = [];
        foreach ($networks as $raw) {
            $item = trim((string) $raw);
            if ('' === $item || str_starts_with($item, '#')) {
                continue;
            }
            if (!self::isValidNetwork($item)) {
                throw new \InvalidArgumentException(\sprintf('Некорректный адрес или подсеть: «%s». Примеры: 192.168.1.10, 10.0.0.0/8, 2001:db8::/32.', $item));
            }
            if (!\in_array($item, $out, true)) {
                $out[] = $item;
            }
        }

        return $out;
    }

    public static function isValidNetwork(string $item): bool
    {
        [$address, $prefix] = array_pad(explode('/', $item, 2), 2, null);
        if (false !== filter_var($address, \FILTER_VALIDATE_IP, \FILTER_FLAG_IPV4)) {
            return null === $prefix || (ctype_digit($prefix) && (int) $prefix >= 0 && (int) $prefix <= 32);
        }
        if (false !== filter_var($address, \FILTER_VALIDATE_IP, \FILTER_FLAG_IPV6)) {
            return null === $prefix || (ctype_digit($prefix) && (int) $prefix >= 0 && (int) $prefix <= 128);
        }

        return false;
    }

    /**
     * Разбирает текст из формы (адреса через перевод строки, запятую или пробел).
     *
     * @return list<string>
     */
    public static function parseNetworkList(string $text): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/[\s,;]+/', $text) ?: []), static fn (string $s) => '' !== $s));
    }

    // ---- Обсуждение, подписки и Telegram -----------------------------------------------------------------

    public function commentsEnabled(): bool
    {
        return (bool) $this->get(self::COMMENTS_ENABLED, true);
    }

    public function subscriptionsEnabled(): bool
    {
        return (bool) $this->get(self::SUBSCRIPTIONS_ENABLED, true);
    }

    public function setDiscussion(bool $comments, bool $subscriptions, ?User $by = null): void
    {
        $this->setMany([self::COMMENTS_ENABLED => $comments, self::SUBSCRIPTIONS_ENABLED => $subscriptions], $by);
    }

    /** @return array{enabled: bool, token: string, bot_name: string, api_url: string, admin_chat: string} */
    public function telegram(): array
    {
        $api = rtrim(trim((string) $this->get(self::TELEGRAM_API_URL, '')), '/');

        return [
            'enabled' => (bool) $this->get(self::TELEGRAM_ENABLED, false),
            'token' => trim((string) $this->get(self::TELEGRAM_TOKEN, '')),
            'bot_name' => ltrim(trim((string) $this->get(self::TELEGRAM_BOT_NAME, '')), '@'),
            'api_url' => '' !== $api ? $api : self::TELEGRAM_DEFAULT_API_URL,
            'admin_chat' => trim((string) $this->get(self::TELEGRAM_ADMIN_CHAT, '')),
        ];
    }

    public function isTelegramEnabled(): bool
    {
        $telegram = $this->telegram();

        return $telegram['enabled'] && '' !== $telegram['token'];
    }

    /**
     * @param array{enabled?: bool, token?: ?string, bot_name?: ?string, api_url?: ?string, admin_chat?: ?string} $values
     */
    public function setTelegram(array $values, ?User $by = null): void
    {
        $update = [
            self::TELEGRAM_ENABLED => (bool) ($values['enabled'] ?? false),
            self::TELEGRAM_BOT_NAME => ltrim(mb_substr(trim((string) ($values['bot_name'] ?? '')), 0, 64), '@'),
            self::TELEGRAM_API_URL => rtrim(trim((string) ($values['api_url'] ?? '')), '/'),
            self::TELEGRAM_ADMIN_CHAT => mb_substr(trim((string) ($values['admin_chat'] ?? '')), 0, 32),
        ];
        // Пустое поле токена означает «оставить прежний» — иначе секрет стирался бы при каждом сохранении формы.
        if (null !== ($values['token'] ?? null)) {
            $update[self::TELEGRAM_TOKEN] = trim((string) $values['token']);
        }
        $this->setMany($update, $by);
    }
}
