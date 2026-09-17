<?php

declare(strict_types=1);

namespace App\Service\Text;

use App\Entity\Document;
use App\Entity\DocumentText;
use App\Repository\DocumentRepository;
use App\Repository\DocumentTextRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Индекс содержимого документов для полнотекстового поиска: извлечённый текст текущей версии
 * складывается в таблицу document_text (индекс FULLTEXT). Обновляется при загрузке новой версии
 * и командой app:search:reindex.
 */
final class TextIndexer
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TextExtractor $extractor,
        private readonly DocumentTextRepository $texts,
        private readonly DocumentRepository $documents,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Обновляет индекс документа по его текущей версии.
     * Ошибки извлечения не мешают работе портала — они только пишутся в журнал.
     */
    public function index(Document $document, bool $flush = true): ?DocumentText
    {
        $version = $document->getCurrentVersion();
        if (null === $version) {
            return null;
        }
        try {
            $extracted = $this->extractor->extract($version);
        } catch (\Throwable $e) {
            $this->logger->warning('Не удалось проиндексировать документ', ['document' => $document->getId(), 'error' => $e->getMessage()]);

            return null;
        }
        $text = $this->texts->findForDocument($document);
        if (null === $text) {
            $text = new DocumentText($document);
            $this->em->persist($text);
        }
        $text->fill($version->getNumber(), $extracted['status'], $extracted['text']);
        if ($flush) {
            $this->em->flush();
        }

        return $text;
    }

    /** Убирает документ из индекса (при удалении документа строка исчезает и по внешнему ключу). */
    public function remove(Document $document): void
    {
        $text = $this->texts->findForDocument($document);
        if (null !== $text) {
            $this->em->remove($text);
            $this->em->flush();
        }
    }

    /**
     * Переиндексация: обновляет документы, у которых индекса нет или он отстал от текущей версии.
     * $all — переиндексировать все документы заново.
     *
     * @param callable(Document, string): void|null $onProgress
     *
     * @return array{processed: int, indexed: int, empty: int, skipped: int}
     */
    public function reindex(int $limit = 500, bool $all = false, ?callable $onProgress = null): array
    {
        $ids = $all
            ? array_map(static fn (array $r): int => (int) $r['id'], $this->documents->createQueryBuilder('d')->select('d.id')->andWhere('d.currentVersion IS NOT NULL')->orderBy('d.id', 'ASC')->setMaxResults($limit)->getQuery()->getArrayResult())
            : $this->texts->findOutdatedDocumentIds($limit);
        $stats = ['processed' => 0, 'indexed' => 0, 'empty' => 0, 'skipped' => 0];
        foreach ($ids as $id) {
            $document = $this->documents->find($id);
            if (null === $document) {
                continue;
            }
            ++$stats['processed'];
            $text = $this->index($document, false);
            if (null === $text) {
                ++$stats['skipped'];
                $status = 'пропущен';
            } elseif ($text->getChars() > 0) {
                ++$stats['indexed'];
                $status = \sprintf('%s, %d символов', $text->getStatus(), $text->getChars());
            } else {
                ++$stats['empty'];
                $status = $text->getStatus();
            }
            if (null !== $onProgress) {
                $onProgress($document, $status);
            }
            if (0 === $stats['processed'] % 25) {
                $this->em->flush();
            }
        }
        $this->em->flush();

        return $stats;
    }
}
