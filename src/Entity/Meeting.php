<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\MeetingRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Пятничное собрание: «Собрание №131, 16 октября 2026». Сотрудники готовят к нему темы (презентации
 * KIND_TOPIC), маркетологи одобряют их и задают порядок; общая презентация собирается из одобренных тем
 * с титульным слайдом, содержанием и финальным слайдом (App\Service\Presentation\MeetingAssembler).
 * После собрания маркетолог публикует его — презентацию видят все сотрудники (архив собраний).
 */
#[ORM\Entity(repositoryClass: MeetingRepository::class)]
#[ORM\Table(name: 'meeting')]
#[ORM\UniqueConstraint(name: 'uniq_meeting_number', columns: ['number'])]
#[ORM\Index(name: 'idx_meeting_date', columns: ['meeting_date'])]
class Meeting
{
    public const STATUS_PREPARING = 'preparing';
    public const STATUS_PUBLISHED = 'published';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: Types::INTEGER)]
    private int $number;

    #[ORM\Column(name: 'meeting_date', type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $date;

    /** Своё название вместо «Собрание №N» (например, «Итоги года»). */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $title = null;

    /** Срок подачи тем (обычно четверг); после него тему ещё можно отправить, но список подсвечивает опоздание. */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $deadline = null;

    #[ORM\Column(length: 16)]
    private string $status = self::STATUS_PREPARING;

    #[ORM\Column(name: 'published_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $publishedAt = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'created_by_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $createdBy = null;

    public function __construct(int $number, \DateTimeImmutable $date, ?User $createdBy = null)
    {
        $this->number = max(1, $number);
        $this->date = $date->setTime(0, 0);
        $this->createdBy = $createdBy;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getNumber(): int
    {
        return $this->number;
    }

    public function setNumber(int $number): static
    {
        $this->number = max(1, $number);

        return $this;
    }

    public function getDate(): \DateTimeImmutable
    {
        return $this->date;
    }

    public function setDate(\DateTimeImmutable $date): static
    {
        $this->date = $date->setTime(0, 0);

        return $this;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function setTitle(?string $title): static
    {
        $title = null !== $title ? trim($title) : null;
        $this->title = null === $title || '' === $title ? null : mb_substr($title, 0, 255);

        return $this;
    }

    /** Название для титульного слайда: «Собрание №131» или своё. */
    public function getDisplayTitle(): string
    {
        return $this->title ?? 'Собрание №'.$this->number;
    }

    /** Дата словами: «16 октября 2026». */
    public function getDateLabel(): string
    {
        return self::formatDate($this->date);
    }

    public static function formatDate(\DateTimeInterface $date): string
    {
        $months = [1 => 'января', 'февраля', 'марта', 'апреля', 'мая', 'июня', 'июля', 'августа', 'сентября', 'октября', 'ноября', 'декабря'];

        return (int) $date->format('j').' '.$months[(int) $date->format('n')].' '.$date->format('Y');
    }

    public function getDeadline(): ?\DateTimeImmutable
    {
        return $this->deadline;
    }

    public function setDeadline(?\DateTimeImmutable $deadline): static
    {
        $this->deadline = $deadline;

        return $this;
    }

    public function isDeadlinePassed(?\DateTimeImmutable $now = null): bool
    {
        return null !== $this->deadline && ($now ?? new \DateTimeImmutable()) > $this->deadline;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function isPublished(): bool
    {
        return self::STATUS_PUBLISHED === $this->status;
    }

    public function publish(): static
    {
        $this->status = self::STATUS_PUBLISHED;
        $this->publishedAt = new \DateTimeImmutable();

        return $this;
    }

    public function unpublish(): static
    {
        $this->status = self::STATUS_PREPARING;
        $this->publishedAt = null;

        return $this;
    }

    public function getPublishedAt(): ?\DateTimeImmutable
    {
        return $this->publishedAt;
    }

    /** Собрание уже прошло (его день закончился). */
    public function isPast(?\DateTimeImmutable $today = null): bool
    {
        return $this->date < ($today ?? new \DateTimeImmutable('today'));
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getCreatedBy(): ?User
    {
        return $this->createdBy;
    }
}
