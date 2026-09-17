<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Document;
use App\Entity\Section;
use App\Entity\User;
use App\Repository\DocumentRepository;
use App\Security\Access;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Массовые операции над документами в реестре администратора: публикация, снятие с публикации,
 * перенос в архив и в другой раздел, срок актуальности, теги, доступ и удаление.
 *
 * Права проверяются для каждого документа отдельно: модератор меняет только свои разделы,
 * а при переносе должен управлять и разделом-приёмником. Что не получилось — попадает в отчёт,
 * операция не прерывается на первом отказе.
 */
final class BulkDocumentService
{
    public const ACTIONS = [
        'publish' => 'Опубликовать',
        'unpublish' => 'Снять с публикации',
        'archive' => 'Перенести в архив',
        'move' => 'Перенести в раздел',
        'validity' => 'Изменить срок актуальности',
        'tags' => 'Добавить теги',
        'access' => 'Изменить доступ',
        'delete' => 'Удалить',
    ];

    /** Больше документов за одну операцию не берём: защита от случайного «выделить всё». */
    public const MAX_DOCUMENTS = 200;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly DocumentRepository $documents,
        private readonly DocumentManager $manager,
        private readonly Access $access,
        private readonly LoggerInterface $auditLogger,
    ) {
    }

    /**
     * @param list<int>            $ids
     * @param array<string, mixed> $options раздел (move), срок (validity), теги (tags), доступ (access)
     *
     * @return array{done: int, skipped: int, denied: int, messages: list<string>}
     */
    public function run(string $action, array $ids, array $options, User $by, ?string $ip = null): array
    {
        $result = ['done' => 0, 'skipped' => 0, 'denied' => 0, 'messages' => []];
        if (!isset(self::ACTIONS[$action])) {
            $result['messages'][] = 'Неизвестное действие.';

            return $result;
        }
        $ids = \array_slice(array_values(array_unique(array_filter($ids))), 0, self::MAX_DOCUMENTS);
        if ([] === $ids) {
            $result['messages'][] = 'Не отмечен ни один документ.';

            return $result;
        }
        $target = $options['section'] ?? null;
        if ('move' === $action) {
            if (!$target instanceof Section) {
                $result['messages'][] = 'Не выбран раздел, в который переносить.';

                return $result;
            }
            if (!$this->access->canManageSection($by, $target)) {
                $result['messages'][] = 'Нет прав на раздел «'.$target->getFullName().'».';

                return $result;
            }
        }

        /** @var list<Document> $documents */
        $documents = $this->documents->findBy(['id' => $ids]);
        foreach ($documents as $document) {
            if (!$this->access->canEditDocument($by, $document)) {
                ++$result['denied'];
                continue;
            }
            try {
                $this->apply($action, $document, $options, $by, $ip) ? ++$result['done'] : ++$result['skipped'];
            } catch (\DomainException $e) {
                ++$result['skipped'];
                $result['messages'][] = \sprintf('«%s»: %s', $document->getTitle(), $e->getMessage());
            }
        }
        $this->em->flush();
        if ($result['denied'] > 0) {
            $result['messages'][] = \sprintf('Пропущено по правам доступа: %d.', $result['denied']);
        }
        $this->auditLogger->info('Массовая операция с документами', [
            'action' => self::ACTIONS[$action],
            'documents' => \count($ids),
            'done' => $result['done'],
            'user' => $by->getUsername(),
        ]);

        return $result;
    }

    /** @param array<string, mixed> $options */
    private function apply(string $action, Document $document, array $options, User $by, ?string $ip): bool
    {
        switch ($action) {
            case 'publish':
                if ($document->isPublished()) {
                    return false;
                }
                $this->manager->publish($document, $by, $ip);

                return true;
            case 'unpublish':
                if ($document->isDraft()) {
                    return false;
                }
                $this->manager->unpublish($document, $by, $ip);

                return true;
            case 'archive':
                if ($document->isArchived()) {
                    return false;
                }
                $this->manager->archive($document, $by, $ip);

                return true;
            case 'move':
                $section = $options['section'];
                if (!$section instanceof Section || $section->getId() === $document->getSection()->getId()) {
                    return false;
                }
                $previous = $document->getSection();
                $document->setSection($section);
                $this->manager->updateMetadata($document, $previous, $by, $ip);

                return true;
            case 'validity':
                $until = $options['valid_until'] ?? null;
                $document->setValidUntil($until instanceof \DateTimeImmutable ? $until->setTime(0, 0) : null);
                $this->manager->updateMetadata($document, null, $by, $ip);

                return true;
            case 'tags':
                $tags = array_merge($document->getTags(), (array) ($options['tags'] ?? []));
                $before = $document->getTagsString();
                $document->setTags(array_values($tags));
                if ($before === $document->getTagsString()) {
                    return false;
                }
                $this->manager->updateMetadata($document, null, $by, $ip);

                return true;
            case 'access':
                $public = (bool) ($options['public'] ?? false);
                if ($document->isPublic() === $public) {
                    return false;
                }
                $document->setPublic($public);
                $this->manager->updateMetadata($document, null, $by, $ip);

                return true;
            case 'delete':
                $this->manager->delete($document, $by);

                return true;
        }

        return false;
    }
}
