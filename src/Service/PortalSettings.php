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

    /** Настройки, значения которых не должны попадать в журнал. */
    private const SECRET_NAMES = [self::WEBHOOK_SECRET, self::LLM_API_KEY];

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

    /** Формировать ли описания автоматически при создании и импорте документов. */
    public function isLlmAutoDescribe(): bool
    {
        $llm = $this->llm();

        return $llm['enabled'] && $llm['auto_describe'];
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
}
