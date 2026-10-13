<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\AiVerifiedAnswerRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Проверенный ответ ИИ: ответ на частый вопрос, который написал или утвердил администратор («Вопросы к ИИ →
 * Проверенные ответы»). На тот же вопрос (и его другие формулировки) сотрудник получает его сразу, с пометкой «Проверенный ответ»,
 * без обращения к языковой модели. Тема сравнивается так же, как в отчёте о вопросах без ответа (основы
 * значимых слов, см. AiQuestionRepository::similar()); другие формулировки расширяют совпадение.
 */
#[ORM\Entity(repositoryClass: AiVerifiedAnswerRepository::class)]
#[ORM\Table(name: 'ai_verified_answer')]
#[ORM\Index(name: 'idx_ai_verified_active', columns: ['active'])]
class AiVerifiedAnswer
{
    public const MAX_SOURCES = 5;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 500)]
    private string $question = '';

    /** Другие формулировки того же вопроса — по одной в строке. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $alternatives = null;

    /** Ответ: обычный текст, «**жирный**», списки строками «- …», ссылки на источники — [1], [2]. */
    #[ORM\Column(type: Types::TEXT)]
    private string $answer = '';

    /** @var list<int> документы-источники (по порядку номеров [1], [2]…) */
    #[ORM\Column(type: Types::JSON)]
    private array $documentIds = [];

    #[ORM\Column(options: ['default' => true])]
    private bool $active = true;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'updated_by_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $updatedBy = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    /** Сколько раз ответ был выдан сотрудникам и когда в последний раз. */
    #[ORM\Column(options: ['default' => 0])]
    private int $uses = 0;

    #[ORM\Column(name: 'last_used_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastUsedAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getQuestion(): string
    {
        return $this->question;
    }

    public function setQuestion(string $question): static
    {
        $this->question = mb_substr(trim((string) preg_replace('/\s+/u', ' ', $question)), 0, AiQuestion::MAX_QUESTION);

        return $this;
    }

    public function getAlternatives(): ?string
    {
        return $this->alternatives;
    }

    /** @return list<string> */
    public function getAlternativeList(): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/\R/u', (string) $this->alternatives) ?: []), static fn (string $s): bool => '' !== $s));
    }

    public function setAlternatives(?string $alternatives): static
    {
        $lines = array_values(array_unique(array_filter(array_map(
            static fn (string $s): string => mb_substr(trim((string) preg_replace('/\s+/u', ' ', $s)), 0, AiQuestion::MAX_QUESTION),
            preg_split('/\R/u', (string) $alternatives) ?: [],
        ), static fn (string $s): bool => '' !== $s)));
        $this->alternatives = [] === $lines ? null : implode("\n", \array_slice($lines, 0, 30));

        return $this;
    }

    public function getAnswer(): string
    {
        return $this->answer;
    }

    public function setAnswer(string $answer): static
    {
        $this->answer = trim(str_replace("\r\n", "\n", $answer));

        return $this;
    }

    /** @return list<int> */
    public function getDocumentIds(): array
    {
        return array_values(array_map('intval', $this->documentIds));
    }

    /** @param list<int> $ids */
    public function setDocumentIds(array $ids): static
    {
        $this->documentIds = \array_slice(array_values(array_unique(array_map('intval', array_filter($ids, static fn ($id): bool => (int) $id > 0)))), 0, self::MAX_SOURCES);

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

    public function getUpdatedBy(): ?User
    {
        return $this->updatedBy;
    }

    public function touch(?User $by): static
    {
        $this->updatedBy = $by;
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function getUses(): int
    {
        return $this->uses;
    }

    public function getLastUsedAt(): ?\DateTimeImmutable
    {
        return $this->lastUsedAt;
    }

    public function markUsed(): static
    {
        ++$this->uses;
        $this->lastUsedAt = new \DateTimeImmutable();

        return $this;
    }
}
