<?php

declare(strict_types=1);

namespace App\Service\Pdf\Bundle;

use App\Entity\Section;
use App\Entity\User;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Задания PDF-сборников: var/bundle-jobs/<id>.json и рабочий каталог var/bundle-jobs/<id>/ с частями
 * и готовым файлом. Сборка идёт порциями (каждый запрос со страницы задания — несколько документов),
 * поэтому большой раздел не упирается в ограничение времени запроса. Готовые сборники хранятся сутки.
 */
final class BundleJobStore
{
    private const KEEP_SECONDS = 86400;

    private readonly string $dir;

    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        string $projectDir,
        private readonly SectionBundleBuilder $builder,
        private readonly LoggerInterface $auditLogger,
    ) {
        $this->dir = rtrim($projectDir, '/').'/var/bundle-jobs';
    }

    /** @param list<array<string, mixed>> $items */
    public function create(Section $section, User $actor, array $items, bool $titlePages): BundleJob
    {
        $this->cleanup();
        $id = date('Ymd-His').'-'.bin2hex(random_bytes(4));
        $job = new BundleJob($id, (int) $section->getId(), $section->getFullName(), (int) $actor->getId(), $actor->getDisplayName(), $titlePages, array_values($items));
        if (!is_dir($this->workDir($id)) && !@mkdir($this->workDir($id), 0750, true) && !is_dir($this->workDir($id))) {
            throw new \RuntimeException('Не удалось создать каталог для сборника: '.$this->workDir($id));
        }
        $this->save($job);

        return $job;
    }

    public function load(string $id): ?BundleJob
    {
        if (1 !== preg_match('/^\d{8}-\d{6}-[0-9a-f]{8}$/', $id) || !is_file($this->path($id)) || $this->cancelled($id)) {
            return null;
        }
        $data = json_decode((string) file_get_contents($this->path($id)), true);

        return \is_array($data) ? BundleJob::fromArray($data) : null;
    }

    public function save(BundleJob $job): void
    {
        if (!is_dir($this->dir) && !@mkdir($this->dir, 0750, true) && !is_dir($this->dir)) {
            throw new \RuntimeException('Не удалось создать каталог заданий сборников: '.$this->dir);
        }
        $job->updatedAt = new \DateTimeImmutable();
        $json = json_encode($job->toArray(), \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR);
        $path = $this->path($job->id);
        if (false === @file_put_contents($path.'.tmp', $json, \LOCK_EX) || !@rename($path.'.tmp', $path)) {
            throw new \RuntimeException('Не удалось сохранить задание сборника: '.$path);
        }
    }

    /** Сколько раз пробовать документ (и объединение): запрос, прерванный на нём, не повторяется бесконечно. */
    private const MAX_ATTEMPTS = 2;

    /**
     * Очередная порция: документы, пока не прошло $maxSeconds (хотя бы один), затем — объединение частей.
     * Возвращает false, если задание сейчас выполняется другим запросом.
     */
    public function runBatch(BundleJob $job, float $maxSeconds = 8.0): bool
    {
        if ($job->isFinished()) {
            return true;
        }
        $lock = @fopen($this->path($job->id).'.lock', 'c');
        if (false === $lock || !flock($lock, \LOCK_EX | \LOCK_NB)) {
            if (false !== $lock) {
                fclose($lock);
            }

            return false;
        }
        $done = false;
        try {
            if ($this->cancelled($job->id)) {
                $this->purge($job->id);

                return true;
            }
            // Состояние перечитывается под блокировкой: параллельный запрос мог уже продвинуть задание.
            $fresh = $this->load($job->id);
            if (null === $fresh) {
                return true;
            }
            $job->items = $fresh->items;
            $job->position = $fresh->position;
            $job->phase = $fresh->phase;
            $job->error = $fresh->error;
            $job->finishedAt = $fresh->finishedAt;
            $job->attempts = $fresh->attempts;
            $job->background = $fresh->background;
            if ($job->isFinished()) {
                return true;
            }
            $started = microtime(true);
            $dir = $this->workDir($job->id);
            try {
                if (BundleJob::PHASE_PARTS === $job->phase) {
                    while ($job->position < $job->total()) {
                        if ($this->cancelled($job->id)) {
                            $this->purge($job->id);

                            return true;
                        }
                        // Попытка записывается до работы: если запрос прервётся (время, память), следующий
                        // не будет повторять этот документ без конца.
                        ++$job->attempts;
                        $this->save($job);
                        if ($job->attempts > self::MAX_ATTEMPTS) {
                            $this->builder->failPart($job, $job->position, $dir, 'обработка прерывалась — файл слишком велик или сложен для сборника');
                        } else {
                            try {
                                $this->builder->buildPart($job, $job->position, $dir);
                            } catch (\Throwable $e) {
                                $this->builder->failPart($job, $job->position, $dir, $e->getMessage());
                            }
                        }
                        ++$job->position;
                        $job->attempts = 0;
                        $this->save($job);
                        if (microtime(true) - $started >= $maxSeconds) {
                            break;
                        }
                    }
                    if ($job->position >= $job->total()) {
                        $job->phase = BundleJob::PHASE_MERGE;
                    }
                } elseif (BundleJob::PHASE_MERGE === $job->phase) {
                    ++$job->attempts;
                    $this->save($job);
                    if ($job->attempts > self::MAX_ATTEMPTS) {
                        throw new \RuntimeException('Объединение файлов прерывалось — сборник слишком велик. Соберите несколько сборников по подразделам.');
                    }
                    $this->builder->finish($job, $dir);
                    $job->phase = BundleJob::PHASE_DONE;
                    $job->finishedAt = new \DateTimeImmutable();
                    $job->attempts = 0;
                    $done = true;
                    $this->auditLogger->info('Сформирован PDF-сборник раздела', ['section' => $job->sectionId, 'documents' => $job->total(), 'pages' => $job->pages, 'bytes' => $job->bytes, 'user' => $job->actorName]);
                }
            } catch (\Throwable $e) {
                $job->error = $e->getMessage();
                $job->finishedAt = new \DateTimeImmutable();
            }
            $this->save($job);
            if ($done) {
                // Части удаляются только после того, как готовность сохранена: прерванное объединение можно повторить.
                $this->builder->removeParts($job, $dir);
            }
        } finally {
            flock($lock, \LOCK_UN);
            fclose($lock);
        }

        return true;
    }

    /** Незавершённых сборников пользователя (по всем разделам). */
    public function activeCount(User $user): int
    {
        $count = 0;
        foreach (glob($this->dir.'/*.json') ?: [] as $file) {
            $job = $this->load(basename($file, '.json'));
            if (null !== $job && $job->actorId === $user->getId() && !$job->isFinished()) {
                ++$count;
            }
        }

        return $count;
    }

    /** Свободное место в каталоге сборников, байт (null — не удалось узнать). */
    public function freeBytes(): ?int
    {
        $dir = is_dir($this->dir) ? $this->dir : \dirname($this->dir);
        $free = @disk_free_space($dir);

        return false === $free ? null : (int) $free;
    }

    /** Готовый файл сборника или null. */
    public function result(BundleJob $job): ?string
    {
        $path = $this->workDir($job->id).'/bundle.pdf';

        return $job->isReady() && is_file($path) ? $path : null;
    }

    /** Последние задания пользователя (новые первыми). @return list<BundleJob> */
    public function recentFor(User $user, ?int $sectionId = null, int $limit = 5): array
    {
        $jobs = [];
        $files = glob($this->dir.'/*.json') ?: [];
        rsort($files);
        foreach ($files as $file) {
            $job = $this->load(basename($file, '.json'));
            if (null !== $job && $job->actorId === $user->getId() && (null === $sectionId || $job->sectionId === $sectionId)) {
                $jobs[] = $job;
                if (\count($jobs) >= $limit) {
                    break;
                }
            }
        }

        return $jobs;
    }

    /**
     * Удаляет задание и его файлы. Если задание сейчас выполняется, оно помечается отменённым —
     * выполняющий запрос удалит его сам, как только закончит текущий документ.
     */
    public function delete(BundleJob $job): void
    {
        $lock = @fopen($this->path($job->id).'.lock', 'c');
        if (false !== $lock && flock($lock, \LOCK_EX | \LOCK_NB)) {
            $this->purge($job->id);
            flock($lock, \LOCK_UN);
            fclose($lock);

            return;
        }
        if (false !== $lock) {
            fclose($lock);
        }
        @touch($this->path($job->id).'.cancel');
    }

    /** Удаляет задания старше суток вместе с файлами (выполняющиеся сейчас — пропускает). */
    public function cleanup(): void
    {
        if (!is_dir($this->dir)) {
            return;
        }
        $threshold = time() - self::KEEP_SECONDS;
        foreach (glob($this->dir.'/*.json') ?: [] as $file) {
            $id = basename($file, '.json');
            if ((int) @filemtime($file) >= $threshold && !$this->cancelled($id)) {
                continue;
            }
            $lock = @fopen($file.'.lock', 'c');
            if (false === $lock) {
                continue;
            }
            if (flock($lock, \LOCK_EX | \LOCK_NB)) {
                $this->purge($id);
                flock($lock, \LOCK_UN);
            }
            fclose($lock);
        }
        // Каталоги без задания (сбой при создании) и одинокие отметки.
        foreach (glob($this->dir.'/*', \GLOB_ONLYDIR) ?: [] as $dir) {
            if (!is_file($dir.'.json') && (int) @filemtime($dir) < $threshold) {
                $this->removeTree($dir);
            }
        }
    }

    private function cancelled(string $id): bool
    {
        return is_file($this->path($id).'.cancel');
    }

    private function purge(string $id): void
    {
        $this->removeTree($this->workDir($id));
        @unlink($this->path($id));
        @unlink($this->path($id).'.cancel');
        @unlink($this->path($id).'.lock');
    }

    /** @return array{jobs: int, bytes: int} */
    public function usage(): array
    {
        $jobs = \count(glob($this->dir.'/*.json') ?: []);
        $bytes = 0;
        foreach (glob($this->dir.'/*/*') ?: [] as $file) {
            $bytes += (int) @filesize($file);
        }

        return ['jobs' => $jobs, 'bytes' => $bytes];
    }

    public function workDir(string $id): string
    {
        return $this->dir.'/'.$id;
    }

    private function path(string $id): string
    {
        return $this->dir.'/'.$id.'.json';
    }

    private function removeTree(string $dir): void
    {
        if (!is_dir($dir) || is_link($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ('.' === $entry || '..' === $entry) {
                continue;
            }
            $path = $dir.'/'.$entry;
            is_dir($path) && !is_link($path) ? $this->removeTree($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
