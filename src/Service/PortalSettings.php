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
        $this->auditLogger->info('Изменена настройка портала', ['name' => $name, 'value' => $value, 'by' => $by?->getUsername()]);
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
