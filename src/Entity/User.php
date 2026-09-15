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
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Пользователь портала.
 *
 * Роли: ROLE_USER (чтение опубликованных документов) и ROLE_ADMIN (панель администратора, всё остальное).
 * Права модератора не хранятся в ролях — они выдаются на конкретные разделы (см. SectionModerator)
 * и распространяются на все вложенные подразделы.
 *
 * Источник учётной записи: local (пароль хранится в портале) или ldap (проверка пароля в Active Directory).
 */
#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: '`user`')]
#[ORM\UniqueConstraint(name: 'uniq_user_username', columns: ['username'])]
#[ORM\Index(name: 'idx_user_source', columns: ['auth_source'])]
#[UniqueEntity(fields: ['username'], message: 'Пользователь с таким логином уже существует.')]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    public const ROLE_USER = 'ROLE_USER';
    public const ROLE_ADMIN = 'ROLE_ADMIN';

    public const SOURCE_LOCAL = 'local';
    public const SOURCE_LDAP = 'ldap';

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

    #[ORM\Column(options: ['default' => true])]
    private bool $active = true;

    #[ORM\Column(options: ['default' => false])]
    private bool $mustChangePassword = false;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastLoginAt = null;

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
        $this->authSource = self::SOURCE_LDAP === $authSource ? self::SOURCE_LDAP : self::SOURCE_LOCAL;

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

    public function eraseCredentials(): void
    {
    }

    public function __toString(): string
    {
        return $this->displayName;
    }
}
