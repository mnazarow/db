<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Смысловой индекс документа (поиск по смыслу для ответов ИИ): вектор документа целиком — нормированное среднее
 * векторов его фрагментов (DocumentChunk) — и сведения, по какой редакции и какой моделью он построен.
 * Одна запись на опубликованный документ; заполняет и обновляет App\Service\Llm\SemanticIndex
 * (команда app:ai:index по cron). Векторы читаются и пишутся напрямую через DBAL (упакованные float32).
 */
#[ORM\Entity]
#[ORM\Table(name: 'document_embedding')]
#[ORM\Index(name: 'idx_embedding_model', columns: ['model'])]
class DocumentEmbedding
{
    #[ORM\Id]
    #[ORM\OneToOne(targetEntity: Document::class)]
    #[ORM\JoinColumn(name: 'document_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    private Document $document;

    #[ORM\Column(name: 'version_number', type: Types::SMALLINT)]
    private int $versionNumber = 0;

    /** Модель и длина вектора («text-embedding-3-small|512»): поменялись в настройках — индекс строится заново. */
    #[ORM\Column(length: 190)]
    private string $model = '';

    #[ORM\Column(type: Types::SMALLINT)]
    private int $dims = 0;

    #[ORM\Column(type: Types::SMALLINT)]
    private int $chunks = 0;

    /** ok — есть вектор; empty — нет текста; error — модель не ответила (повтор при следующем запуске). */
    #[ORM\Column(length: 12)]
    private string $status = 'ok';

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $error = null;

    /**
     * Вектор документа (упакованные float32). Колонка не «vector»: это зарезервированное слово MariaDB 11.7+.
     *
     * @var resource|string|null
     */
    #[ORM\Column(name: 'embedding', type: Types::BLOB, length: 65535, nullable: true)]
    private $embedding = null;

    /** Отпечаток текста и модели: текст не изменился — модель повторно не вызывается. */
    #[ORM\Column(name: 'content_hash', length: 40, nullable: true)]
    private ?string $contentHash = null;

    #[ORM\Column(name: 'embedded_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $embeddedAt;

    public function __construct(Document $document)
    {
        $this->document = $document;
        $this->embeddedAt = new \DateTimeImmutable();
    }

    public function getDocument(): Document
    {
        return $this->document;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getEmbeddedAt(): \DateTimeImmutable
    {
        return $this->embeddedAt;
    }
}
