<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\DocumentTextRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Извлечённый текст текущей версии документа — для полнотекстового поиска по содержимому файлов.
 * Одна запись на документ (текст предыдущих версий не хранится); заполняется при загрузке версии
 * и командой app:search:reindex. Индекс FULLTEXT — по колонке content.
 */
#[ORM\Entity(repositoryClass: DocumentTextRepository::class)]
#[ORM\Table(name: 'document_text')]
#[ORM\Index(name: 'ft_document_text', columns: ['content'], flags: ['fulltext'])]
class DocumentText
{
    /** Сколько символов текста попадает в индекс (для поиска этого достаточно, база не разрастается). */
    public const MAX_CHARS = 200_000;

    #[ORM\Id]
    #[ORM\OneToOne(targetEntity: Document::class)]
    #[ORM\JoinColumn(name: 'document_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    private Document $document;

    #[ORM\Column(name: 'version_number', type: Types::SMALLINT)]
    private int $versionNumber = 0;

    /** Статус извлечения: ok | empty | unsupported | missing | error (см. TextExtractor). */
    #[ORM\Column(length: 12)]
    private string $status;

    #[ORM\Column(type: Types::TEXT, length: 4_294_967_295)]
    private string $content = '';

    #[ORM\Column]
    private int $chars = 0;

    #[ORM\Column(name: 'indexed_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $indexedAt;

    public function __construct(Document $document)
    {
        $this->document = $document;
        $this->status = 'empty';
        $this->indexedAt = new \DateTimeImmutable();
    }

    public function getDocument(): Document
    {
        return $this->document;
    }

    public function getVersionNumber(): int
    {
        return $this->versionNumber;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getContent(): string
    {
        return $this->content;
    }

    public function getChars(): int
    {
        return $this->chars;
    }

    public function getIndexedAt(): \DateTimeImmutable
    {
        return $this->indexedAt;
    }

    /** Записывает извлечённый текст (обрезая до MAX_CHARS). */
    public function fill(int $versionNumber, string $status, ?string $text): static
    {
        $text = (string) $text;
        if (mb_strlen($text) > self::MAX_CHARS) {
            $text = mb_substr($text, 0, self::MAX_CHARS);
        }
        $this->versionNumber = $versionNumber;
        $this->status = $status;
        $this->content = $text;
        $this->chars = mb_strlen($text);
        $this->indexedAt = new \DateTimeImmutable();

        return $this;
    }
}
