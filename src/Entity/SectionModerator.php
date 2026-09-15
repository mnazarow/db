<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\SectionModeratorRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Назначение модератора на раздел. Права модератора действуют на раздел
 * и на все вложенные в него подразделы любой глубины.
 */
#[ORM\Entity(repositoryClass: SectionModeratorRepository::class)]
#[ORM\Table(name: 'section_moderator')]
#[ORM\UniqueConstraint(name: 'uniq_section_moderator', columns: ['section_id', 'user_id'])]
#[ORM\Index(name: 'idx_section_moderator_user', columns: ['user_id'])]
class SectionModerator
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Section::class, inversedBy: 'moderators')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Section $section;

    #[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'moderatedSections')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $assignedBy = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $assignedAt;

    public function __construct(Section $section, User $user, ?User $assignedBy = null)
    {
        $this->section = $section;
        $this->user = $user;
        $this->assignedBy = $assignedBy;
        $this->assignedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSection(): Section
    {
        return $this->section;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getAssignedBy(): ?User
    {
        return $this->assignedBy;
    }

    public function getAssignedAt(): \DateTimeImmutable
    {
        return $this->assignedAt;
    }
}
