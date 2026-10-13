<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\AiQuestionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Вопрос сотрудника к ИИ на странице поиска и чем он закончился: ответ, «в документах ответа нет», ошибка, —
 * и оценка ответа («помог» / «не помог» с комментарием). По вопросам без ответа администратор видит, каких
 * документов не хватает. Хранится AI_LOG_DAYS дней (настройка «Интеграции → Поиск по смыслу»); в отчётах имена
 * сотрудников не показываются — автор нужен, только чтобы оценить ответ мог тот, кто спрашивал.
 */
#[ORM\Entity(repositoryClass: AiQuestionRepository::class)]
#[ORM\Table(name: 'ai_question')]
#[ORM\Index(name: 'idx_ai_question_created', columns: ['created_at'])]
#[ORM\Index(name: 'idx_ai_question_status', columns: ['status', 'created_at'])]
#[ORM\Index(name: 'idx_ai_question_topic', columns: ['topic'])]
class AiQuestion
{
    /** Модель ответила по найденным документам. */
    public const STATUS_ANSWERED = 'answered';
    /** Модель ответила, что в найденных документах ответа нет. */
    public const STATUS_NOT_FOUND = 'not_found';
    /** По вопросу не нашлось ни документов, ни видео — модель не вызывалась. */
    public const STATUS_NO_SOURCES = 'no_sources';
    /** Модель не ответила (ошибка, тайм-аут). */
    public const STATUS_ERROR = 'error';
    /** Выдан проверенный ответ администратора (модель не вызывалась). */
    public const STATUS_VERIFIED = 'verified';

    public const STATUS_LABELS = [
        self::STATUS_ANSWERED => 'ответ дан',
        self::STATUS_VERIFIED => 'проверенный ответ',
        self::STATUS_NOT_FOUND => 'в документах нет ответа',
        self::STATUS_NO_SOURCES => 'ничего не найдено',
        self::STATUS_ERROR => 'ошибка модели',
    ];

    public const MAX_QUESTION = 500;
    public const MAX_COMMENT = 500;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $user;

    #[ORM\Column(length: 500)]
    private string $question;

    /** Тема — основы значимых слов вопроса по алфавиту: похожие формулировки попадают в одну группу. */
    #[ORM\Column(length: 190)]
    private string $topic;

    #[ORM\Column(length: 12)]
    private string $status = self::STATUS_ANSWERED;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $answer = null;

    /** @var list<array{type: string, title: string, url: string}> */
    #[ORM\Column(type: Types::JSON)]
    private array $sources = [];

    /** Источники искались и по смыслу (эмбеддинги), а не только по словам. */
    #[ORM\Column(options: ['default' => false])]
    private bool $semantic = false;

    /** 1 — ответ помог, −1 — не помог, null — не оценён. */
    #[ORM\Column(type: Types::SMALLINT, nullable: true)]
    private ?int $rating = null;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $comment = null;

    #[ORM\Column(name: 'duration_ms', options: ['default' => 0])]
    private int $durationMs = 0;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'rated_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $ratedAt = null;

    public function __construct(?User $user, string $question, string $topic)
    {
        $this->user = $user;
        $this->question = mb_substr($question, 0, self::MAX_QUESTION);
        $this->topic = mb_substr($topic, 0, 190);
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function getQuestion(): string
    {
        return $this->question;
    }

    public function getTopic(): string
    {
        return $this->topic;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getStatusLabel(): string
    {
        return self::STATUS_LABELS[$this->status] ?? $this->status;
    }

    public function getAnswer(): ?string
    {
        return $this->answer;
    }

    /** @return list<array{type: string, title: string, url: string}> */
    public function getSources(): array
    {
        return $this->sources;
    }

    public function isSemantic(): bool
    {
        return $this->semantic;
    }

    public function getRating(): ?int
    {
        return $this->rating;
    }

    public function getComment(): ?string
    {
        return $this->comment;
    }

    public function getDurationMs(): int
    {
        return $this->durationMs;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getRatedAt(): ?\DateTimeImmutable
    {
        return $this->ratedAt;
    }

    /** @param list<array{type: string, title: string, url: string}> $sources */
    public function finish(string $status, ?string $answer, array $sources, bool $semantic, int $durationMs): static
    {
        $this->status = \array_key_exists($status, self::STATUS_LABELS) ? $status : self::STATUS_ERROR;
        $this->answer = null !== $answer ? mb_substr($answer, 0, 20000) : null;
        $this->sources = array_values(array_map(static fn (array $s): array => ['type' => (string) ($s['type'] ?? 'document'), 'title' => mb_substr((string) ($s['title'] ?? ''), 0, 255), 'url' => (string) ($s['url'] ?? '')], $sources));
        $this->semantic = $semantic;
        $this->durationMs = max(0, $durationMs);

        return $this;
    }

    public function rate(int $rating, ?string $comment): static
    {
        $this->rating = $rating > 0 ? 1 : -1;
        $comment = trim((string) preg_replace('/\s+/u', ' ', (string) $comment));
        $this->comment = '' !== $comment ? mb_substr($comment, 0, self::MAX_COMMENT) : null;
        $this->ratedAt = new \DateTimeImmutable();

        return $this;
    }
}
