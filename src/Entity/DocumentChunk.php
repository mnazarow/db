<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Фрагмент текста документа (около тысячи символов) с его вектором — по ним ищутся места, отвечающие на вопрос.
 * Пишется и читается через DBAL (App\Service\Llm\SemanticIndex); удаляется вместе с документом.
 */
#[ORM\Entity]
#[ORM\Table(name: 'document_chunk')]
#[ORM\Index(name: 'idx_chunk_document', columns: ['document_id', 'position'])]
class DocumentChunk
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Document::class)]
    #[ORM\JoinColumn(name: 'document_id', nullable: false, onDelete: 'CASCADE')]
    private Document $document;

    #[ORM\Column(type: Types::SMALLINT)]
    private int $position = 0;

    #[ORM\Column(type: Types::TEXT)]
    private string $content = '';

    /** @var resource|string вектор фрагмента (упакованные float32); имя колонки — не зарезервированное «vector» */
    #[ORM\Column(name: 'embedding', type: Types::BLOB, length: 65535)]
    private $embedding = '';

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

    public function getContent(): string
    {
        return $this->content;
    }
}
