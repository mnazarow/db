<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * HTML-текст версии-страницы. Хранится отдельно от самой версии: списки документов загружают версии
 * (номер, размер, имя файла) вместе с документами, и мегабайты текста страниц со вставленными картинками
 * при этом не читались бы из базы. Текст загружается только при обращении к нему.
 */
#[ORM\Entity]
#[ORM\Table(name: 'document_version_content')]
#[ORM\UniqueConstraint(name: 'uniq_document_version_content', columns: ['version_id'])]
class DocumentVersionContent
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: DocumentVersion::class, inversedBy: 'bodies')]
    #[ORM\JoinColumn(name: 'version_id', nullable: false, onDelete: 'CASCADE')]
    private DocumentVersion $version;

    /** HTML страницы, уже очищенный от опасной разметки (до 16 МБ). */
    #[ORM\Column(type: Types::TEXT, length: 16777215)]
    private string $html;

    public function __construct(DocumentVersion $version, string $html)
    {
        $this->version = $version;
        $this->html = $html;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getVersion(): DocumentVersion
    {
        return $this->version;
    }

    public function getHtml(): string
    {
        return $this->html;
    }

    public function setHtml(string $html): static
    {
        $this->html = $html;

        return $this;
    }
}
