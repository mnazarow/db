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
        $wasPending = TextExtractor::STATUS_PENDING === $text->getStatus() && $text->getVersionNumber() === $version->getNumber();
        $text->fill($version->getNumber(), $extracted['status'], $extracted['text']);
        if ($wasPending && TextExtractor::STATUS_PENDING !== $extracted['status']) {
            // Скан распознан в фоне: для внешних систем (API для RAG) это новое содержимое — документ
            // снова попадает в ленту изменений /api/v1/changes, и индексатор заберёт готовый текст.
            $document->touch();
        }
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
     * Переиндексация: обновляет документы, у которых индекса нет, он отстал от текущей версии или ждёт
     * распознавания (pending). $all — переиндексировать все документы заново: партиями по $limit, пока
     * не будут пройдены все (раньше повторный запуск снова брал первые $limit документов).
     *
     * @param callable(Document, string): void|null $onProgress
     *
     * @return array{processed: int, indexed: int, empty: int, skipped: int}
     */
    public function reindex(int $limit = 500, bool $all = false, ?callable $onProgress = null): array
    {
        $limit = max(1, $limit);
        $stats = ['processed' => 0, 'indexed' => 0, 'empty' => 0, 'skipped' => 0];
        if (!$all) {
            $this->process($this->texts->findOutdatedDocumentIds($limit), $stats, $onProgress);

            return $stats;
        }
        $lastId = 0;
        do {
            $ids = array_map(static fn (array $r): int => (int) $r['id'], $this->documents->createQueryBuilder('d')->select('d.id')
                ->andWhere('d.currentVersion IS NOT NULL')->andWhere('d.id > :last')->setParameter('last', $lastId)
                ->orderBy('d.id', 'ASC')->setMaxResults($limit)->getQuery()->getArrayResult());
            $this->process($ids, $stats, $onProgress);
            if ([] !== $ids) {
                $lastId = max($ids);
            }
            // Память между партиями освобождается: переиндексация тысяч документов не должна её исчерпать.
            $this->em->clear();
        } while (\count($ids) === $limit);

        return $stats;
    }

    /**
     * Помечает весь индекс устаревшим: текст остаётся (поиск продолжает работать), а документы
     * переиндексируются порциями — кнопкой в панели администратора или заданием cron. Так
     * «Переиндексировать всё» не упирается в ограничение времени веб-запроса. Возвращает число записей.
     */
    public function invalidateAll(): int
    {
        return (int) $this->em->getConnection()->executeStatement('UPDATE document_text SET version_number = 0');
    }

    /**
     * @param list<int>                             $ids
     * @param array{processed: int, indexed: int, empty: int, skipped: int} $stats
     * @param callable(Document, string): void|null $onProgress
     */
    private function process(array $ids, array &$stats, ?callable $onProgress): void
    {
        $done = 0;
        foreach ($ids as $id) {
            $document = $this->documents->find($id);
            if (null === $document) {
                continue;
            }
            ++$stats['processed'];
            ++$done;
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
            if (0 === $done % 25) {
                $this->em->flush();
            }
        }
        $this->em->flush();
    }
}
