<?php

declare(strict_types=1);

namespace App\Service\Llm;

use App\Entity\Document;
use App\Repository\DocumentRepository;
use App\Repository\DocumentTextRepository;
use App\Service\PortalSettings;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Смысловой индекс документов — поиск по смыслу без отдельной векторной базы.
 *
 * Текст опубликованного документа (название, описание, теги, содержимое файла или страницы) режется на фрагменты
 * около тысячи символов с перекрытием; модель эмбеддингов (OpenAI-совместимый /embeddings) превращает каждый в
 * вектор. Векторы хранятся в базе портала (document_chunk, упакованные float32), вектор документа — нормированное
 * среднее его фрагментов (document_embedding).
 *
 * Поиск в два шага, чтобы не перебирать все фрагменты: по векторам документов выбираются ближайшие документы,
 * затем среди их фрагментов — самые близкие к вопросу. Сходство — скалярное произведение нормированных векторов
 * (косинус). Индекс обновляет команда app:ai:index (cron раз в час): новые и изменившиеся документы, смена модели.
 */
final class SemanticIndex
{
    /** Длина фрагмента и перекрытие соседних фрагментов, символов. */
    public const CHUNK_CHARS = 1000;
    public const CHUNK_OVERLAP = 150;
    /** Фрагментов на документ (≈ 60 тыс. символов): дальше документ в индекс не попадает. */
    public const MAX_CHUNKS = 60;
    /** Текстов в одном запросе к модели. */
    private const BATCH = 32;

    public function __construct(
        private readonly LlmClient $llm,
        private readonly PortalSettings $settings,
        private readonly Connection $connection,
        private readonly DocumentRepository $documents,
        private readonly DocumentTextRepository $texts,
        private readonly LoggerInterface $logger,
        private readonly ?EntityManagerInterface $em = null,
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->settings->embeddings()['enabled'];
    }

    /** Модель и длина вектора: при их смене индекс строится заново (векторы разных моделей несравнимы). */
    public function signature(): string
    {
        $cfg = $this->settings->embeddings();

        return mb_substr($cfg['model'].'|'.$cfg['dimensions'], 0, 190);
    }

    /** Есть ли в индексе хоть один документ для текущей модели (иначе искать по смыслу не в чем). */
    public function hasData(): bool
    {
        return false !== $this->connection->fetchOne("SELECT 1 FROM document_embedding WHERE status = 'ok' AND model = :sig LIMIT 1", ['sig' => $this->signature()]);
    }

    /**
     * Фрагменты текста: по абзацам и предложениям, не длиннее CHUNK_CHARS, соседние перекрываются на CHUNK_OVERLAP.
     *
     * @return list<string>
     */
    public static function chunks(string $text, int $max = self::MAX_CHUNKS): array
    {
        $text = trim((string) preg_replace("/[ \t\u{A0}]+/u", ' ', str_replace("\r", '', $text)));
        $text = (string) preg_replace("/\n{3,}/", "\n\n", $text);
        if ('' === $text) {
            return [];
        }
        $out = [];
        $length = mb_strlen($text);
        $start = 0;
        while ($start < $length && \count($out) < $max) {
            $end = min($length, $start + self::CHUNK_CHARS);
            if ($end < $length) {
                // Граница — конец абзаца или предложения во второй половине окна, иначе пробел.
                $window = mb_substr($text, $start, $end - $start);
                $cut = null;
                foreach (["\n\n", "\n", '. ', '! ', '? ', '; ', ' '] as $separator) {
                    $at = mb_strrpos($window, $separator);
                    if (false !== $at && $at > self::CHUNK_CHARS / 2) {
                        $cut = $at + mb_strlen($separator);
                        break;
                    }
                }
                $end = $start + ($cut ?? ($end - $start));
            }
            $piece = trim(mb_substr($text, $start, $end - $start));
            if ('' !== $piece) {
                $out[] = $piece;
            }
            if ($end >= $length) {
                break;
            }
            $start = max($start + 1, $end - self::CHUNK_OVERLAP);
            // Начало следующего фрагмента — с начала слова.
            $space = mb_strpos($text, ' ', $start);
            if (false !== $space && $space < $end) {
                $start = $space + 1;
            }
        }

        return $out;
    }

    /** Текст документа для индекса: название, обозначение, описание, теги, содержимое. */
    public function textFor(Document $document): string
    {
        $parts = [(string) $document->getTitle()];
        if ('' !== (string) $document->getCode()) {
            $parts[] = 'Обозначение: '.$document->getCode();
        }
        if ('' !== trim((string) $document->getDescription())) {
            $parts[] = trim((string) $document->getDescription());
        }
        if ([] !== $document->getTags()) {
            $parts[] = 'Теги: '.implode(', ', $document->getTags());
        }
        $indexed = $this->texts->findForDocument($document);
        if (null !== $indexed && '' !== trim($indexed->getContent())) {
            $parts[] = $indexed->getContent();
        } else {
            $html = $document->getCurrentVersion()?->getContent();
            if (null !== $html && '' !== trim($html)) {
                $parts[] = html_entity_decode(strip_tags(str_replace(['</p>', '<br>', '<br/>', '</li>', '</h1>', '</h2>', '</h3>', '</tr>'], "\n", $html)), \ENT_QUOTES | \ENT_HTML5, 'UTF-8');
            }
        }

        return implode("\n\n", $parts);
    }

    /**
     * Обновляет индекс документа. Возвращает ok (построен), unchanged (текст и модель те же — модель не вызывалась),
     * empty (нечего индексировать), removed (документ не опубликован) или error (модель не ответила: прежние
     * векторы остаются в поиске, документ повторится при следующем запуске).
     */
    public function index(Document $document): string
    {
        $id = (int) $document->getId();
        $version = $document->getCurrentVersion();
        if (!$document->isPublished() || null === $version) {
            $this->remove($id);

            return 'removed';
        }
        $signature = $this->signature();
        $title = (string) $document->getTitle();
        $text = $this->textFor($document);
        $hash = sha1($signature."\n".$text);
        $existing = $this->connection->fetchAssociative('SELECT status, model, content_hash FROM document_embedding WHERE document_id = :id', ['id' => $id]) ?: null;
        if (null !== $existing && \in_array($existing['status'], ['ok', 'empty'], true) && $existing['model'] === $signature && $existing['content_hash'] === $hash) {
            // Текст не изменился (например, документ «тронули» или заново распознан тот же скан) — только отметка времени.
            $this->connection->executeStatement('UPDATE document_embedding SET version_number = :v, embedded_at = :at, error = NULL WHERE document_id = :id',
                ['v' => $version->getNumber(), 'at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'), 'id' => $id]);

            return 'unchanged';
        }
        $chunks = self::chunks($text);
        if ([] === $chunks) {
            $this->store($id, $version->getNumber(), $signature, $hash, 'empty', [], []);

            return 'empty';
        }
        $vectors = [];
        try {
            foreach (array_chunk($chunks, self::BATCH) as $batch) {
                // Фрагмент получает название документа: так вектор знает, о чём документ в целом.
                $vectors = [...$vectors, ...$this->llm->embed(array_map(static fn (string $c): string => '«'.$title.'». '.$c, $batch))];
            }
        } catch (LlmException $e) {
            $error = mb_substr($e->getMessage(), 0, 255);
            if (null !== $existing) {
                // Прежние векторы остаются в поиске; время не меняется — документ останется в очереди.
                $this->connection->executeStatement('UPDATE document_embedding SET error = :error WHERE document_id = :id', ['error' => $error, 'id' => $id]);
            } else {
                $this->store($id, $version->getNumber(), $signature, null, 'error', [], [], $error);
            }
            $this->logger->warning('Смысловой индекс: документ не обработан', ['document' => $id, 'error' => $e->getMessage()]);

            return 'error';
        }
        $this->store($id, $version->getNumber(), $signature, $hash, 'ok', $chunks, $vectors);

        return 'ok';
    }

    /**
     * Обновляет индекс: документы без индекса, изменившиеся, с ошибкой прошлого раза и при смене модели;
     * убирает из индекса неопубликованные. $all — все опубликованные заново.
     *
     * @return array{processed: int, ok: int, unchanged: int, empty: int, errors: int, removed: int, left: int}
     */
    public function reindex(int $limit = 200, bool $all = false, ?callable $onProgress = null): array
    {
        $stats = ['processed' => 0, 'ok' => 0, 'unchanged' => 0, 'empty' => 0, 'errors' => 0, 'removed' => $this->removeUnpublished(), 'left' => 0];
        $ids = $all ? $this->publishedIds() : $this->outdatedIds($limit);
        foreach (\array_slice($ids, 0, $all ? \count($ids) : $limit) as $id) {
            $document = $this->documents->find($id);
            if (null === $document) {
                continue;
            }
            $status = $this->index($document);
            ++$stats['processed'];
            match ($status) {
                'ok' => ++$stats['ok'],
                'unchanged' => ++$stats['unchanged'],
                'empty' => ++$stats['empty'],
                'error' => ++$stats['errors'],
                default => ++$stats['removed'],
            };
            if (null !== $onProgress) {
                $onProgress($document, $status);
            }
            if ($stats['errors'] >= 5 && 0 === $stats['ok'] + $stats['unchanged']) {
                break; // модель недоступна — не тратим время на остальные документы
            }
            if (0 === $stats['processed'] % 50) {
                // Команда проходит тысячи документов — прочитанные сущности не держим в памяти.
                $this->em?->clear();
            }
        }
        $stats['left'] = \count($this->outdatedIds(100000));

        return $stats;
    }

    /**
     * Документы, которым нужно обновить индекс.
     *
     * @return list<int>
     */
    public function outdatedIds(int $limit = 500): array
    {
        $sql = 'SELECT d.id FROM document d
                JOIN document_version v ON v.id = d.current_version_id
                LEFT JOIN document_embedding e ON e.document_id = d.id
                LEFT JOIN document_text t ON t.document_id = d.id
                WHERE d.status = :published
                  AND (e.document_id IS NULL OR e.model <> :sig OR e.version_number <> v.number OR e.status = :error
                       OR e.embedded_at < d.updated_at OR (t.indexed_at IS NOT NULL AND e.embedded_at < t.indexed_at))
                ORDER BY CASE WHEN e.error IS NOT NULL THEN 1 ELSE 0 END, d.updated_at DESC';
        $ids = $this->connection->fetchFirstColumn($sql.' LIMIT '.max(1, $limit), ['published' => Document::STATUS_PUBLISHED, 'sig' => $this->signature(), 'error' => 'error']);

        return array_map('intval', $ids);
    }

    /**
     * Состояние индекса для «Интеграций», команды и мониторинга.
     *
     * @return array{published: int, indexed: int, empty: int, errors: int, outdated: int, chunks: int, bytes: int, model: string, last_error: ?string}
     */
    public function status(): array
    {
        $sig = $this->signature();
        $row = $this->connection->fetchAssociative(
            "SELECT SUM(CASE WHEN e.status = 'ok' THEN 1 ELSE 0 END) AS indexed,
                    SUM(CASE WHEN e.status = 'empty' THEN 1 ELSE 0 END) AS empty,
                    SUM(CASE WHEN e.error IS NOT NULL THEN 1 ELSE 0 END) AS errors
             FROM document_embedding e JOIN document d ON d.id = e.document_id
             WHERE d.status = :published AND e.model = :sig",
            ['published' => Document::STATUS_PUBLISHED, 'sig' => $sig]) ?: [];
        $chunks = $this->connection->fetchAssociative('SELECT COUNT(*) AS n, COALESCE(SUM(LENGTH(embedding)), 0) AS bytes FROM document_chunk') ?: [];

        return [
            'published' => (int) $this->connection->fetchOne('SELECT COUNT(*) FROM document WHERE status = :published', ['published' => Document::STATUS_PUBLISHED]),
            'indexed' => (int) ($row['indexed'] ?? 0),
            'empty' => (int) ($row['empty'] ?? 0),
            'errors' => (int) ($row['errors'] ?? 0),
            'outdated' => \count($this->outdatedIds(100000)),
            'chunks' => (int) ($chunks['n'] ?? 0),
            'bytes' => (int) ($chunks['bytes'] ?? 0),
            'model' => $sig,
            'last_error' => ($e = $this->connection->fetchOne("SELECT error FROM document_embedding WHERE error IS NOT NULL ORDER BY embedded_at DESC LIMIT 1")) ? (string) $e : null,
        ];
    }

    /**
     * Шаг 1: ближайшие к вопросу документы по их векторам (только опубликованные, текущая модель).
     *
     * @param list<float> $query нормированный вектор вопроса
     *
     * @return array<int, float> id документа → сходство, по убыванию
     */
    public function rankDocuments(array $query, int $limit = 40): array
    {
        $scores = [];
        $after = 0;
        // Порциями по 500 документов: в памяти не держится весь индекс (тысячи векторов по несколько килобайт).
        do {
            $rows = $this->connection->fetchAllAssociative(
                "SELECT e.document_id, e.embedding FROM document_embedding e JOIN document d ON d.id = e.document_id
                 WHERE e.status = 'ok' AND e.model = :sig AND d.status = :published AND e.document_id > :after
                 ORDER BY e.document_id LIMIT 500",
                ['sig' => $this->signature(), 'published' => Document::STATUS_PUBLISHED, 'after' => $after]);
            foreach ($rows as $row) {
                $after = (int) $row['document_id'];
                $vector = self::unpack($row['embedding']);
                if (\count($vector) !== \count($query)) {
                    continue;
                }
                $scores[$after] = self::dot($query, $vector);
            }
            if (\count($scores) > 4 * $limit) {
                arsort($scores);
                $scores = \array_slice($scores, 0, 2 * $limit, true);
            }
        } while (500 === \count($rows));
        arsort($scores);

        return \array_slice($scores, 0, $limit, true);
    }

    /**
     * Шаг 2: самые близкие к вопросу фрагменты указанных документов.
     *
     * @param list<float> $query
     * @param list<int>   $documentIds
     *
     * @return array<int, list<array{position: int, content: string, score: float}>> id документа → фрагменты по убыванию сходства
     */
    public function bestChunks(array $query, array $documentIds, int $perDocument = 3): array
    {
        if ([] === $documentIds) {
            return [];
        }
        $out = [];
        $result = $this->connection->executeQuery('SELECT document_id, position, content, embedding FROM document_chunk WHERE document_id IN (:ids)',
            ['ids' => array_values($documentIds)], ['ids' => ArrayParameterType::INTEGER]);
        while (false !== ($row = $result->fetchAssociative())) {
            $vector = self::unpack($row['embedding']);
            if (\count($vector) !== \count($query)) {
                continue;
            }
            $out[(int) $row['document_id']][] = ['position' => (int) $row['position'], 'content' => (string) $row['content'], 'score' => self::dot($query, $vector)];
        }
        foreach ($out as $id => $chunks) {
            usort($chunks, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);
            $out[$id] = \array_slice($chunks, 0, $perDocument);
        }

        return $out;
    }

    /** Убирает документ из индекса. */
    public function remove(int $documentId): void
    {
        $this->connection->executeStatement('DELETE FROM document_chunk WHERE document_id = :id', ['id' => $documentId]);
        $this->connection->executeStatement('DELETE FROM document_embedding WHERE document_id = :id', ['id' => $documentId]);
    }

    /** Весь индекс — заново (при смене модели это делается и само). */
    public function clear(): void
    {
        $this->connection->executeStatement('DELETE FROM document_chunk');
        $this->connection->executeStatement('DELETE FROM document_embedding');
    }

    /** @param list<float> $vector */
    public static function pack(array $vector): string
    {
        return pack('g*', ...$vector);
    }

    /**
     * @param string|resource|null $blob
     *
     * @return list<float>
     */
    public static function unpack($blob): array
    {
        if (\is_resource($blob)) {
            $blob = stream_get_contents($blob);
        }
        if (!\is_string($blob) || '' === $blob || 0 !== \strlen($blob) % 4) {
            return [];
        }

        return array_values(unpack('g*', $blob) ?: []);
    }

    /**
     * @param list<float> $a
     * @param list<float> $b
     */
    public static function dot(array $a, array $b): float
    {
        $sum = 0.0;
        foreach ($a as $i => $value) {
            $sum += $value * $b[$i];
        }

        return $sum;
    }

    /**
     * @param list<string>      $chunks
     * @param list<list<float>> $vectors
     */
    private function store(int $documentId, int $versionNumber, string $signature, ?string $hash, string $status, array $chunks, array $vectors, ?string $error = null): void
    {
        $this->connection->transactional(function (Connection $connection) use ($documentId, $versionNumber, $signature, $hash, $status, $error, $chunks, $vectors): void {
            $connection->executeStatement('DELETE FROM document_chunk WHERE document_id = :id', ['id' => $documentId]);
            $connection->executeStatement('DELETE FROM document_embedding WHERE document_id = :id', ['id' => $documentId]);
            $mean = [];
            foreach ($chunks as $position => $content) {
                $vector = $vectors[$position];
                $connection->insert('document_chunk', ['document_id' => $documentId, 'position' => $position, 'content' => $content, 'embedding' => self::pack($vector)],
                    ['document_id' => ParameterType::INTEGER, 'position' => ParameterType::INTEGER, 'content' => ParameterType::STRING, 'embedding' => ParameterType::LARGE_OBJECT]);
                foreach ($vector as $i => $value) {
                    $mean[$i] = ($mean[$i] ?? 0.0) + $value;
                }
            }
            $documentVector = [] !== $mean ? LlmClient::normalize(array_values($mean)) : [];
            $connection->insert('document_embedding', [
                'document_id' => $documentId,
                'version_number' => $versionNumber,
                'model' => $signature,
                'dims' => \count($documentVector),
                'chunks' => \count($chunks),
                'status' => $status,
                'error' => $error,
                'embedding' => [] !== $documentVector ? self::pack($documentVector) : null,
                'content_hash' => $hash,
                'embedded_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            ], ['document_id' => ParameterType::INTEGER, 'version_number' => ParameterType::INTEGER, 'dims' => ParameterType::INTEGER, 'chunks' => ParameterType::INTEGER,
                'embedding' => [] !== $documentVector ? ParameterType::LARGE_OBJECT : ParameterType::NULL]);
        });
    }

    /** @return list<int> */
    private function publishedIds(): array
    {
        return array_map('intval', $this->connection->fetchFirstColumn('SELECT id FROM document WHERE status = :published AND current_version_id IS NOT NULL ORDER BY updated_at DESC', ['published' => Document::STATUS_PUBLISHED]));
    }

    /** Убирает из индекса документы, снятые с публикации, ушедшие в архив и т. п. */
    private function removeUnpublished(): int
    {
        $ids = array_map('intval', $this->connection->fetchFirstColumn('SELECT e.document_id FROM document_embedding e JOIN document d ON d.id = e.document_id WHERE d.status <> :published', ['published' => Document::STATUS_PUBLISHED]));
        foreach ($ids as $id) {
            $this->remove($id);
        }

        return \count($ids);
    }
}
