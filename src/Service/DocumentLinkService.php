<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Document;
use App\Entity\DocumentLink;
use App\Entity\User;
use App\Repository\DocumentLinkRepository;
use App\Repository\DocumentRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Связи между документами: «заменяет», «отменяет», «приложение к», «см. также».
 */
final class DocumentLinkService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly DocumentLinkRepository $links,
        private readonly DocumentRepository $documents,
        private readonly DocumentManager $manager,
        private readonly LoggerInterface $auditLogger,
    ) {
    }

    /**
     * Связи документа, разложенные по виду и направлению: ключ — подпись,
     * значение — список документов на другом конце связи.
     *
     * @return list<array{label: string, link: DocumentLink, document: Document}>
     */
    public function forDocument(Document $document): array
    {
        $rows = [];
        foreach ($this->links->findForDocument($document) as $link) {
            $rows[] = ['label' => $link->labelFor($document), 'link' => $link, 'document' => $link->other($document)];
        }
        usort($rows, static fn (array $a, array $b): int => [$a['label'], $a['document']->getTitle()] <=> [$b['label'], $b['document']->getTitle()]);

        return $rows;
    }

    /**
     * Находит документ по обозначению, идентификатору или названию — как их вводит модератор.
     */
    public function resolve(string $query): ?Document
    {
        $query = trim($query);
        if ('' === $query) {
            return null;
        }
        if (ctype_digit($query)) {
            $byId = $this->documents->find((int) $query);
            if (null !== $byId) {
                return $byId;
            }
        }
        $byCode = $this->documents->findOneBy(['code' => $query]);
        if (null !== $byCode) {
            return $byCode;
        }
        $found = $this->documents->search($query, Document::STATUSES, null, 2);

        return 1 === \count($found) ? $found[0] : null;
    }

    /**
     * Создаёт связь. Возвращает её или выбрасывает исключение с понятным сообщением.
     *
     * @throws \DomainException
     */
    public function link(Document $source, Document $target, string $type, User $by, ?string $note = null): DocumentLink
    {
        if ($source->getId() === $target->getId()) {
            throw new \DomainException('Документ нельзя связать с самим собой.');
        }
        if (!\in_array($type, DocumentLink::TYPES, true)) {
            throw new \DomainException('Неизвестный вид связи.');
        }
        if (null !== $this->links->findBetween($source, $target, $type)) {
            throw new \DomainException('Такая связь уже есть.');
        }
        if (null !== $this->links->findBetween($target, $source, $type) && DocumentLink::RELATED !== $type) {
            throw new \DomainException('Обратная связь уже заведена в карточке второго документа.');
        }
        $link = new DocumentLink($source, $target, $type, $by, $note);
        $this->em->persist($link);
        $this->em->flush();
        $this->auditLogger->info('Добавлена связь документов', [
            'document' => $source->getId(),
            'target' => $target->getId(),
            'type' => DocumentLink::label($type),
            'user' => $by->getUsername(),
        ]);

        return $link;
    }

    public function unlink(DocumentLink $link, User $by): void
    {
        $this->auditLogger->info('Удалена связь документов', [
            'document' => $link->getSource()->getId(),
            'target' => $link->getTarget()->getId(),
            'type' => DocumentLink::label($link->getType()),
            'user' => $by->getUsername(),
        ]);
        $this->em->remove($link);
        $this->em->flush();
    }

    /**
     * Переносит в архив документы, которые заменены или отменены этим документом.
     *
     * @return list<string> названия перенесённых документов
     */
    public function archiveSuperseded(Document $document, User $by, ?string $ip = null): array
    {
        $archived = [];
        foreach ($this->links->supersededBy($document) as $old) {
            if (!$old->isArchived()) {
                $this->manager->archive($old, $by, $ip);
                $archived[] = $old->getTitle();
            }
        }

        return $archived;
    }
}
