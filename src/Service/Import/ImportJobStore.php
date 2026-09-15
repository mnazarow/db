<?php

declare(strict_types=1);

namespace App\Service\Import;

use App\Entity\User;

/**
 * Хранилище заданий импорта (JSON-файлы в var/import-jobs). Задание выполняется порциями:
 * каждый запрос из панели администратора обрабатывает несколько элементов плана и сохраняет состояние.
 */
final class ImportJobStore
{
    /** Сколько дней хранить завершённые задания. */
    private const KEEP_DAYS = 14;

    private readonly string $dir;

    public function __construct(string $projectDir, private readonly DirectoryImporter $importer)
    {
        $this->dir = rtrim($projectDir, '/').'/var/import-jobs';
    }

    public function create(ImportPlan $plan, ImportOptions $options, User $actor): ImportJob
    {
        $this->cleanup();
        $id = date('Ymd-His').'-'.bin2hex(random_bytes(3));
        $job = new ImportJob($id, $plan, $options, ImportState::start($options), (int) $actor->getId(), $actor->getDisplayName(), new \DateTimeImmutable());
        $this->save($job);

        return $job;
    }

    public function load(string $id): ?ImportJob
    {
        if (!preg_match('/^[0-9]{8}-[0-9]{6}-[0-9a-f]{6}$/', $id)) {
            return null;
        }
        $path = $this->path($id);
        if (!is_file($path)) {
            return null;
        }
        $data = json_decode((string) file_get_contents($path), true);
        if (!\is_array($data)) {
            return null;
        }

        return ImportJob::fromArray($data);
    }

    public function save(ImportJob $job): void
    {
        if (!is_dir($this->dir) && !@mkdir($this->dir, 0750, true) && !is_dir($this->dir)) {
            throw new \RuntimeException('Не удалось создать каталог заданий импорта: '.$this->dir);
        }
        $job->updatedAt = new \DateTimeImmutable();
        $json = json_encode($job->toArray(), \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR);
        $path = $this->path($job->id);
        if (false === @file_put_contents($path.'.tmp', $json, \LOCK_EX) || !@rename($path.'.tmp', $path)) {
            throw new \RuntimeException('Не удалось сохранить задание импорта: '.$path);
        }
    }

    /**
     * Выполняет порцию плана: не больше $maxEntries элементов и не дольше $maxSeconds.
     * Возвращает false, если задание уже выполняется другим запросом.
     */
    public function runBatch(ImportJob $job, int $maxEntries = 25, float $maxSeconds = 8.0): bool
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
        try {
            $started = microtime(true);
            $done = 0;
            try {
                while ($done < $maxEntries && microtime(true) - $started < $maxSeconds) {
                    if (!$this->importer->step($job->plan, $job->options, $job->state, $job->actorId)) {
                        break;
                    }
                    ++$done;
                }
                if ($job->state->position >= $job->total()) {
                    $this->importer->finish($job->plan, $job->options, $job->state);
                    $job->finishedAt = new \DateTimeImmutable();
                }
            } catch (\Throwable $e) {
                $job->error = $e->getMessage();
                $job->finishedAt = new \DateTimeImmutable();
            }
            $this->save($job);
        } finally {
            flock($lock, \LOCK_UN);
            fclose($lock);
        }

        return true;
    }

    /** Последние задания (новые первыми). @return list<ImportJob> */
    public function recent(int $limit = 10): array
    {
        if (!is_dir($this->dir)) {
            return [];
        }
        $files = glob($this->dir.'/*.json') ?: [];
        rsort($files);
        $jobs = [];
        foreach (\array_slice($files, 0, $limit) as $file) {
            $job = $this->load(basename($file, '.json'));
            if (null !== $job) {
                $jobs[] = $job;
            }
        }

        return $jobs;
    }

    /** Удаляет задания старше KEEP_DAYS дней. */
    public function cleanup(): void
    {
        if (!is_dir($this->dir)) {
            return;
        }
        $threshold = time() - self::KEEP_DAYS * 86400;
        foreach (glob($this->dir.'/*.json*') ?: [] as $file) {
            if ((int) @filemtime($file) < $threshold) {
                @unlink($file);
            }
        }
        foreach (glob($this->dir.'/*.lock') ?: [] as $file) {
            if (!is_file(substr($file, 0, -5))) {
                @unlink($file);
            }
        }
    }

    private function path(string $id): string
    {
        return $this->dir.'/'.$id.'.json';
    }
}
