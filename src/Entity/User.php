<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\UserRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\EquatableInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Пользователь портала.
 *
 * Роли (одна на учётную запись, действуют во всём портале — в документах и в видео):
 * - ROLE_USER — пользователь: видит опубликованные документы и одобренные видео;
 * - ROLE_ASSISTANT — помощник маркетолога: добавляет документы, новые редакции и видео, но они
 *   публикуются только после модерации;
 * - ROLE_MARKETER — маркетолог (модератор): ведёт все разделы, публикует сразу, решает по материалам
 *   на модерации;
 * - ROLE_ADMIN — администратор: всё, включая панель администратора.
 * Кроме того, права модератора можно выдать на отдельные разделы (см. SectionModerator) — они
 * распространяются на все вложенные подразделы.
 *
 * Источник учётной записи: local (пароль хранится в портале) или ldap (проверка пароля в Active Directory).
 */
#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: '`user`')]
#[ORM\UniqueConstraint(name: 'uniq_user_username', columns: ['username'])]
#[ORM\UniqueConstraint(name: 'uniq_user_sso_subject', columns: ['sso_subject'])]
#[ORM\Index(name: 'idx_user_source', columns: ['auth_source'])]
#[UniqueEntity(fields: ['username'], message: 'Пользователь с таким логином уже существует.')]
class User implements UserInterface, PasswordAuthenticatedUserInterface, EquatableInterface
{
    public const ROLE_USER = 'ROLE_USER';
    public const ROLE_ASSISTANT = 'ROLE_ASSISTANT';
    public const ROLE_MARKETER = 'ROLE_MARKETER';
    public const ROLE_ADMIN = 'ROLE_ADMIN';

    /** Роль пользователя одним значением (форма пользователя, синхронизация с каталогом, видео). */
    public const POSITION_USER = 'user';
    public const POSITION_ASSISTANT = 'assistant';
    public const POSITION_MARKETER = 'marketer';
    public const POSITION_ADMIN = 'admin';

    public const POSITIONS = [self::POSITION_USER, self::POSITION_ASSISTANT, self::POSITION_MARKETER, self::POSITION_ADMIN];

    public const POSITION_LABELS = [
        self::POSITION_USER => 'Пользователь',
        self::POSITION_ASSISTANT => 'Помощник маркетолога',
        self::POSITION_MARKETER => 'Маркетолог',
        self::POSITION_ADMIN => 'Администратор',
    ];

    private const POSITION_ROLES = [self::ROLE_ASSISTANT, self::ROLE_MARKETER, self::ROLE_ADMIN];

    public const SOURCE_LOCAL = 'local';
    public const SOURCE_LDAP = 'ldap';
    public const SOURCE_SSO = 'sso';

    public const SOURCES = [self::SOURCE_LOCAL, self::SOURCE_LDAP, self::SOURCE_SSO];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 64)]
    #[Assert\NotBlank(message: 'Укажите логин.')]
    #[Assert\Length(min: 2, max: 64, minMessage: 'Логин слишком короткий.', maxMessage: 'Логин слишком длинный.')]
    #[Assert\Regex(pattern: '/^[a-zA-Z0-9._@\\\\-]+$/', message: 'Логин может содержать латинские буквы, цифры и символы . _ - @')]
    private string $username = '';

    #[ORM\Column(length: 128)]
    #[Assert\NotBlank(message: 'Укажите имя пользователя.')]
    #[Assert\Length(max: 128)]
    private string $displayName = '';

    #[ORM\Column(length: 180, nullable: true)]
    #[Assert\Length(max: 180)]
    #[Assert\Email(message: 'Некорректный адрес электронной почты.')]
    private ?string $email = null;

    #[ORM\Column(length: 128, nullable: true)]
    #[Assert\Length(max: 128)]
    private ?string $department = null;

    /** @var list<string> */
    #[ORM\Column(type: Types::JSON)]
    private array $roles = [];

    /** Хэш пароля; для учётных записей из LDAP — пустая строка. */
    #[ORM\Column(length: 255)]
    private string $password = '';

    #[ORM\Column(name: 'auth_source', length: 16, options: ['default' => self::SOURCE_LOCAL])]
    private string $authSource = self::SOURCE_LOCAL;

    #[ORM\Column(length: 512, nullable: true)]
    private ?string $ldapDn = null;

    /**
     * Постоянный идентификатор у провайдера SSO («issuer|sub»): учётная запись привязывается к нему
     * при первом входе через SSO, и дальше войти в неё может только тот же пользователь провайдера.
     */
    #[ORM\Column(name: 'sso_subject', length: 255, nullable: true)]
    private ?string $ssoSubject = null;

    #[ORM\Column(options: ['default' => true])]
    private bool $active = true;

    #[ORM\Column(options: ['default' => false])]
    private bool $mustChangePassword = false;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastLoginAt = null;

    /** Двухфакторная аутентификация: секрет TOTP, дата подтверждения и хэши резервных кодов. */
    #[ORM\Column(name: 'totp_secret', length: 64, nullable: true)]
    private ?string $totpSecret = null;

    #[ORM\Column(name: 'totp_confirmed_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $totpConfirmedAt = null;

    /** @var list<string> хэши резервных кодов (сами коды показываются один раз) */
    #[ORM\Column(name: 'recovery_codes', type: Types::JSON)]
    private array $recoveryCodes = [];

    /** Уведомления об изменениях: почта включена по умолчанию, Telegram — после привязки чата. */
    #[ORM\Column(name: 'notify_email', options: ['default' => true])]
    private bool $notifyEmail = true;

    #[ORM\Column(name: 'notify_telegram', options: ['default' => true])]
    private bool $notifyTelegram = true;

    /** Идентификатор чата с ботом: заполняется, когда сотрудник отправил боту код привязки. */
    #[ORM\Column(name: 'telegram_chat_id', length: 32, nullable: true)]
    private ?string $telegramChatId = null;

    #[ORM\Column(name: 'telegram_name', length: 64, nullable: true)]
    private ?string $telegramName = null;

    /** Одноразовый код привязки Telegram (действует ограниченное время). */
    #[ORM\Column(name: 'telegram_code', length: 16, nullable: true)]
    private ?string $telegramCode = null;

    #[ORM\Column(name: 'telegram_code_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $telegramCodeAt = null;

    /** @var Collection<int, SectionModerator> */
    #[ORM\OneToMany(targetEntity: SectionModerator::class, mappedBy: 'user', orphanRemoval: true)]
    private Collection $moderatedSections;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->moderatedSections = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUsername(): string
    {
        return $this->username;
    }

    public function setUsername(string $username): static
    {
        $this->username = self::normalizeUsername($username);

        return $this;
    }

    /** Логин хранится в нижнем регистре; префикс домена (DOMAIN\user) и суффикс UPN (user@domain) отбрасываются. */
    public static function normalizeUsername(string $username): string
    {
        $username = mb_strtolower(trim($username));
        if (false !== ($pos = strrpos($username, '\\'))) {
            $username = substr($username, $pos + 1);
        }

        return $username;
    }

    public function getUserIdentifier(): string
    {
        return $this->username;
    }

    public function getDisplayName(): string
    {
        return $this->displayName;
    }

    public function setDisplayName(string $displayName): static
    {
        $this->displayName = trim($displayName);

        return $this;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(?string $email): static
    {
        $email = null === $email ? null : trim($email);
        $this->email = '' === $email ? null : $email;

        return $this;
    }

    public function getDepartment(): ?string
    {
        return $this->department;
    }

    public function setDepartment(?string $department): static
    {
        $department = null === $department ? '' : trim($department);
        $this->department = '' === $department ? null : mb_substr($department, 0, 128);

        return $this;
    }

    /** @return list<string> */
    public function getRoles(): array
    {
        $roles = $this->roles;
        $roles[] = self::ROLE_USER;

        return array_values(array_unique($roles));
    }

    /** @param list<string> $roles */
    public function setRoles(array $roles): static
    {
        $this->roles = array_values(array_unique(array_filter($roles, static fn ($r) => \is_string($r) && '' !== $r)));

        return $this;
    }

    public function isAdmin(): bool
    {
        return \in_array(self::ROLE_ADMIN, $this->roles, true);
    }

    public function setAdmin(bool $admin): static
    {
        $roles = array_values(array_diff($this->roles, [self::ROLE_ADMIN]));
        if ($admin) {
            $roles[] = self::ROLE_ADMIN;
        }

        return $this->setRoles($roles);
    }

    /** Маркетолог (модератор всех разделов). Администратор маркетологом не считается, хотя может то же самое. */
    public function isMarketer(): bool
    {
        return \in_array(self::ROLE_MARKETER, $this->roles, true);
    }

    /** Помощник маркетолога: добавляет материалы, публикация — после модерации. */
    public function isAssistant(): bool
    {
        return \in_array(self::ROLE_ASSISTANT, $this->roles, true) && !$this->isMarketer() && !$this->isAdmin();
    }

    /** Роль одним значением: admin, marketer, assistant или user. */
    public function getPosition(): string
    {
        return match (true) {
            $this->isAdmin() => self::POSITION_ADMIN,
            $this->isMarketer() => self::POSITION_MARKETER,
            \in_array(self::ROLE_ASSISTANT, $this->roles, true) => self::POSITION_ASSISTANT,
            default => self::POSITION_USER,
        };
    }

    public function getPositionLabel(): string
    {
        return self::POSITION_LABELS[$this->getPosition()];
    }

    /** Назначает роль одним значением; прочие роли (если появятся) сохраняются. */
    public function setPosition(string $position): static
    {
        if (!\in_array($position, self::POSITIONS, true)) {
            throw new \InvalidArgumentException(\sprintf('Неизвестная роль «%s».', $position));
        }
        $roles = array_values(array_diff($this->roles, self::POSITION_ROLES));
        $roles[] = match ($position) {
            self::POSITION_ADMIN => self::ROLE_ADMIN,
            self::POSITION_MARKETER => self::ROLE_MARKETER,
            self::POSITION_ASSISTANT => self::ROLE_ASSISTANT,
            default => self::ROLE_USER,
        };

        return $this->setRoles(array_values(array_diff($roles, [self::ROLE_USER])));
    }

    public function getPassword(): string
    {
        return $this->password;
    }

    public function setPassword(string $hashedPassword): static
    {
        $this->password = $hashedPassword;

        return $this;
    }

    public function getAuthSource(): string
    {
        return $this->authSource;
    }

    public function setAuthSource(string $authSource): static
    {
        $this->authSource = \in_array($authSource, self::SOURCES, true) ? $authSource : self::SOURCE_LOCAL;

        return $this;
    }

    public function isLdap(): bool
    {
        return self::SOURCE_LDAP === $this->authSource;
    }

    public function isLocal(): bool
    {
        return self::SOURCE_LOCAL === $this->authSource;
    }

    public function getLdapDn(): ?string
    {
        return $this->ldapDn;
    }

    public function setLdapDn(?string $ldapDn): static
    {
        $this->ldapDn = null === $ldapDn ? null : mb_substr($ldapDn, 0, 512);

        return $this;
    }

    public function getSsoSubject(): ?string
    {
        return $this->ssoSubject;
    }

    public function setSsoSubject(?string $ssoSubject): static
    {
        $this->ssoSubject = null === $ssoSubject || '' === $ssoSubject ? null : mb_substr($ssoSubject, 0, 255);

        return $this;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function setActive(bool $active): static
    {
        $this->active = $active;

        return $this;
    }

    public function isMustChangePassword(): bool
    {
        return $this->mustChangePassword;
    }

    public function setMustChangePassword(bool $mustChangePassword): static
    {
        $this->mustChangePassword = $mustChangePassword;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getLastLoginAt(): ?\DateTimeImmutable
    {
        return $this->lastLoginAt;
    }

    public function setLastLoginAt(?\DateTimeImmutable $lastLoginAt): static
    {
        $this->lastLoginAt = $lastLoginAt;

        return $this;
    }

    // --- Двухфакторная аутентификация -------------------------------------------------

    public function getTotpSecret(): ?string
    {
        return $this->totpSecret;
    }

    public function setTotpSecret(?string $secret): static
    {
        $this->totpSecret = $secret;
        if (null === $secret) {
            $this->totpConfirmedAt = null;
            $this->recoveryCodes = [];
        }

        return $this;
    }

    /** Включена ли двухфакторная аутентификация (секрет задан и подтверждён кодом). */
    public function isTotpEnabled(): bool
    {
        return null !== $this->totpSecret && null !== $this->totpConfirmedAt;
    }

    public function getTotpConfirmedAt(): ?\DateTimeImmutable
    {
        return $this->totpConfirmedAt;
    }

    public function confirmTotp(): static
    {
        $this->totpConfirmedAt = new \DateTimeImmutable();

        return $this;
    }

    /** @return list<string> */
    public function getRecoveryCodes(): array
    {
        return $this->recoveryCodes;
    }

    /** @param list<string> $codes открытые коды — хранятся только их хэши */
    public function setRecoveryCodes(array $codes): static
    {
        $this->recoveryCodes = array_values(array_map(static fn (string $code): string => hash('sha256', $code), $codes));

        return $this;
    }

    public function countRecoveryCodes(): int
    {
        return \count($this->recoveryCodes);
    }

    /** Сверяет резервный код и гасит его (каждый код одноразовый). */
    public function useRecoveryCode(string $code): bool
    {
        $hash = hash('sha256', trim(mb_strtolower($code)));
        foreach ($this->recoveryCodes as $index => $stored) {
            if (hash_equals($stored, $hash)) {
                unset($this->recoveryCodes[$index]);
                $this->recoveryCodes = array_values($this->recoveryCodes);

                return true;
            }
        }

        return false;
    }

    /** @return Collection<int, SectionModerator> */
    public function getModeratedSections(): Collection
    {
        return $this->moderatedSections;
    }

    public function isModerator(): bool
    {
        return $this->moderatedSections->count() > 0;
    }

    /** Инициалы для аватара: «Иванов Иван» → «ИИ». */
    public function getInitials(): string
    {
        $parts = preg_split('/\s+/u', trim($this->displayName)) ?: [];
        $initials = '';
        foreach (\array_slice($parts, 0, 2) as $part) {
            $initials .= mb_strtoupper(mb_substr($part, 0, 1));
        }

        return '' !== $initials ? $initials : mb_strtoupper(mb_substr($this->username, 0, 1));
    }

    public function isNotifyEmail(): bool
    {
        return $this->notifyEmail;
    }

    public function setNotifyEmail(bool $notifyEmail): static
    {
        $this->notifyEmail = $notifyEmail;

        return $this;
    }

    public function isNotifyTelegram(): bool
    {
        return $this->notifyTelegram;
    }

    public function setNotifyTelegram(bool $notifyTelegram): static
    {
        $this->notifyTelegram = $notifyTelegram;

        return $this;
    }

    public function getTelegramChatId(): ?string
    {
        return $this->telegramChatId;
    }

    public function hasTelegram(): bool
    {
        return null !== $this->telegramChatId && '' !== $this->telegramChatId;
    }

    public function getTelegramName(): ?string
    {
        return $this->telegramName;
    }

    /** Привязывает чат с ботом и гасит код привязки. */
    public function linkTelegram(string $chatId, ?string $name): static
    {
        $this->telegramChatId = mb_substr(trim($chatId), 0, 32);
        $this->telegramName = null !== $name ? mb_substr(trim($name), 0, 64) : null;
        $this->telegramCode = null;
        $this->telegramCodeAt = null;

        return $this;
    }

    public function unlinkTelegram(): static
    {
        $this->telegramChatId = null;
        $this->telegramName = null;
        $this->telegramCode = null;
        $this->telegramCodeAt = null;

        return $this;
    }

    public function getTelegramCode(): ?string
    {
        return $this->telegramCode;
    }

    public function getTelegramCodeAt(): ?\DateTimeImmutable
    {
        return $this->telegramCodeAt;
    }

    public function setTelegramCode(?string $code): static
    {
        $this->telegramCode = null !== $code ? mb_substr($code, 0, 16) : null;
        $this->telegramCodeAt = null !== $code ? new \DateTimeImmutable() : null;

        return $this;
    }

    #[\Deprecated] // метод пустой: временные учётные данные в объекте не хранятся (требование Symfony 7.3+)
    public function eraseCredentials(): void
    {
    }

    /**
     * Сравнение пользователя из сессии с актуальной записью из базы при каждом запросе: если учётную запись
     * заблокировали, сменили пароль, права или способ входа — сессия завершается сразу, а не по истечении срока.
     */
    public function isEqualTo(UserInterface $user): bool
    {
        if (!$user instanceof self || !$user->isActive()) {
            return false;
        }
        $roles = $this->getRoles();
        $other = $user->getRoles();
        sort($roles);
        sort($other);

        return $this->getUserIdentifier() === $user->getUserIdentifier()
            && $this->getPassword() === $user->getPassword()
            && $this->getAuthSource() === $user->getAuthSource()
            && $roles === $other;
    }

    public function __toString(): string
    {
        return $this->displayName;
    }
}
