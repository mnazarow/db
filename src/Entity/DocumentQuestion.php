<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\DocumentQuestionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Вопрос для проверки знаний после ознакомления с документом.
 *
 * Один вопрос — один верный вариант из нескольких. Проверка нужна там, где важно не нажатие
 * кнопки, а понимание: охрана труда, пожарная безопасность, обязательные инструкции.
 */
#[ORM\Entity(repositoryClass: DocumentQuestionRepository::class)]
#[ORM\Table(name: 'document_question')]
#[ORM\Index(name: 'idx_question_document', columns: ['document_id', 'position'])]
class DocumentQuestion
{
    public const MIN_OPTIONS = 2;
    public const MAX_OPTIONS = 6;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Document::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Document $document;

    #[ORM\Column(type: Types::SMALLINT)]
    private int $position = 0;

    #[ORM\Column(length: 500)]
    private string $text = '';

    /** @var list<string> варианты ответа */
    #[ORM\Column(type: Types::JSON)]
    private array $options = [];

    /** Номер верного варианта (с нуля). */
    #[ORM\Column(name: 'correct_option', type: Types::SMALLINT)]
    private int $correctOption = 0;

    public function __construct(Document $document)
    {
        $this->document = $document;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDocument(): Document
    {
        return $this->document;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(int $position): static
    {
        $this->position = max(0, $position);

        return $this;
    }

    public function getText(): string
    {
        return $this->text;
    }

    public function setText(string $text): static
    {
        $text = trim($text);
        if ('' === $text) {
            throw new \InvalidArgumentException('Текст вопроса не может быть пустым.');
        }
        $this->text = mb_substr($text, 0, 500);

        return $this;
    }

    /** @return list<string> */
    public function getOptions(): array
    {
        return $this->options;
    }

    /**
     * @param list<string> $options
     *
     * @throws \InvalidArgumentException если вариантов слишком мало или верный номер вне списка
     */
    public function setOptions(array $options, int $correctOption): static
    {
        $clean = [];
        foreach ($options as $option) {
            $option = trim((string) $option);
            if ('' !== $option) {
                $clean[] = mb_substr($option, 0, 300);
            }
        }
        if (\count($clean) < self::MIN_OPTIONS) {
            throw new \InvalidArgumentException('Нужно не меньше двух вариантов ответа.');
        }
        $clean = \array_slice($clean, 0, self::MAX_OPTIONS);
        if (!isset($clean[$correctOption])) {
            throw new \InvalidArgumentException('Отметьте верный вариант ответа.');
        }
        $this->options = $clean;
        $this->correctOption = $correctOption;

        return $this;
    }

    public function getCorrectOption(): int
    {
        return $this->correctOption;
    }

    public function isCorrect(?int $answer): bool
    {
        return null !== $answer && $answer === $this->correctOption;
    }
}
