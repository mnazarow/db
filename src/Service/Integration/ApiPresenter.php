<?php

declare(strict_types=1);

namespace App\Service\Integration;

use App\Entity\Document;
use App\Entity\DocumentDeletion;
use App\Entity\DocumentVersion;
use App\Entity\Section;
use App\Service\Validity;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Представление сущностей портала в JSON для REST API.
 */
final class ApiPresenter
{
    public function __construct(
        private readonly UrlGeneratorInterface $urls,
        private readonly Validity $validity,
    ) {
    }

    /** @return array<string, mixed> */
    public function section(Section $section, ?int $documentCount = null): array
    {
        $data = [
            'id' => $section->getId(),
            'parent_id' => $section->getParent()?->getId(),
            'name' => $section->getName(),
            'slug' => $section->getSlug(),
            'full_name' => $section->getFullName(),
            'path' => array_map(static fn (Section $s): array => ['id' => $s->getId(), 'name' => $s->getName()], $section->getBreadcrumbs()),
            'depth' => $section->getDepth(),
            'position' => $section->getPosition(),
            'description' => $section->getDescription(),
            'created_at' => $section->getCreatedAt()->format(\DATE_ATOM),
            'updated_at' => $section->getUpdatedAt()->format(\DATE_ATOM),
            'links' => [
                'self' => $this->urls->generate('api_section', ['id' => $section->getId()], UrlGeneratorInterface::ABSOLUTE_URL),
                'documents' => $this->urls->generate('api_documents', ['section' => $section->getId()], UrlGeneratorInterface::ABSOLUTE_URL),
                'web' => $this->urls->generate('app_section_show', ['id' => $section->getId()], UrlGeneratorInterface::ABSOLUTE_URL),
            ],
        ];
        if (null !== $documentCount) {
            $data['document_count'] = $documentCount;
        }

        return $data;
    }

    /** @return array<string, mixed> */
    public function document(Document $document): array
    {
        $section = $document->getSection();
        $version = $document->getCurrentVersion();

        return [
            'id' => $document->getId(),
            'title' => $document->getTitle(),
            'code' => $document->getCode(),
            'description' => $document->getDescription(),
            'description_source' => $document->getDescriptionSource(),
            'type' => $document->getType(),
            'status' => $document->getStatus(),
            'is_public' => $document->isPublic(),
            'tags' => $document->getTags(),
            'section' => [
                'id' => $section->getId(),
                'name' => $section->getName(),
                'full_name' => $section->getFullName(),
                'path' => array_map(static fn (Section $s): array => ['id' => $s->getId(), 'name' => $s->getName()], $section->getBreadcrumbs()),
            ],
            'owner' => $document->getOwner()?->getDisplayName(),
            'valid_until' => $document->getValidUntil()?->format('Y-m-d'),
            'validity' => $this->validity->stateOf($document),
            'created_at' => $document->getCreatedAt()->format(\DATE_ATOM),
            'updated_at' => $document->getUpdatedAt()->format(\DATE_ATOM),
            'published_at' => $document->getPublishedAt()?->format(\DATE_ATOM),
            'archived_at' => $document->getArchivedAt()?->format(\DATE_ATOM),
            'version' => null !== $version ? $this->version($version) : null,
            'links' => [
                'self' => $this->urls->generate('api_document', ['id' => $document->getId()], UrlGeneratorInterface::ABSOLUTE_URL),
                'content' => $this->urls->generate('api_document_content', ['id' => $document->getId()], UrlGeneratorInterface::ABSOLUTE_URL),
                'text' => $this->urls->generate('api_document_text', ['id' => $document->getId()], UrlGeneratorInterface::ABSOLUTE_URL),
                'web' => $this->urls->generate('app_document_show', ['id' => $document->getId()], UrlGeneratorInterface::ABSOLUTE_URL),
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function version(DocumentVersion $version): array
    {
        return [
            'number' => $version->getNumber(),
            'kind' => $version->getKind(),
            'file_name' => $version->getOriginalName(),
            'extension' => $version->isFile() ? strtolower($version->getExtension()) : null,
            'mime_type' => $version->getMimeType(),
            'size' => $version->getSize(),
            'checksum' => $version->getChecksum(),
            'change_note' => $version->getChangeNote(),
            'created_at' => $version->getCreatedAt()->format(\DATE_ATOM),
            'created_by' => $version->getCreatedBy()?->getDisplayName(),
            'is_current' => $version->isCurrent(),
            'links' => [
                'content' => $this->urls->generate('api_document_content', ['id' => $version->getDocument()->getId(), 'version' => $version->getNumber()], UrlGeneratorInterface::ABSOLUTE_URL),
                'text' => $this->urls->generate('api_document_text', ['id' => $version->getDocument()->getId(), 'version' => $version->getNumber()], UrlGeneratorInterface::ABSOLUTE_URL),
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function deletion(DocumentDeletion $deletion): array
    {
        return [
            'id' => $deletion->getDocumentId(),
            'title' => $deletion->getTitle(),
            'section_path' => $deletion->getSectionPath(),
            'deleted_at' => $deletion->getDeletedAt()->format(\DATE_ATOM),
        ];
    }
}
