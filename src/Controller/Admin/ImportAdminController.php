<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Section;
use App\Entity\User;
use App\Repository\SectionRepository;
use App\Service\FileStorage;
use App\Service\Import\DirectoryImporter;
use App\Service\Import\ImportJobStore;
use App\Service\Import\ImportOptions;
use App\Service\Import\ImportPlan;
use App\Service\Import\ImportState;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Панель администратора: импорт документов из каталога на диске сервера (IMPORT_DIR).
 * Папки становятся разделами, файлы — документами. Импорт выполняется порциями:
 * страница задания по очереди запрашивает обработку следующих элементов, показывая ход выполнения.
 */
#[Route('/admin/import')]
final class ImportAdminController extends AbstractController
{
    /** Сколько строк плана показывать в предпросмотре и в журнале задания. */
    private const PREVIEW_ROWS = 400;
    private const LOG_ROWS = 300;

    public function __construct(
        private readonly DirectoryImporter $importer,
        private readonly ImportJobStore $jobs,
        private readonly SectionRepository $sections,
        private readonly FileStorage $storage,
        private readonly string $importDir,
        private readonly int $defaultValidityMonths,
    ) {
    }

    #[Route('', name: 'admin_import', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $dir = $this->importRoot();
        $exists = null !== $dir;
        $scanner = $this->importer->getScanner();

        return $this->render('admin/import/index.html.twig', [
            'import_dir' => $this->importDir,
            'dir_exists' => $exists,
            'dir_writable' => $exists && is_writable($dir),
            'folders' => $exists ? $scanner->listSubdirectories($dir) : [],
            'root_files' => $exists ? $scanner->countRootFiles($dir) : ['files' => 0, 'bytes' => 0],
            'tree' => $this->sections->findAllTree(),
            'values' => $this->valuesFromRequest($request, true),
            'default_validity' => max(0, $this->defaultValidityMonths),
            'extensions' => $this->storage->getAllowedExtensions(),
            'max_mb' => $this->storage->getUploadMaxMb(),
            'recent_jobs' => $this->jobs->recent(5),
            'can_describe' => $this->importer->canDescribe(),
        ]);
    }

    #[Route('/preview', name: 'admin_import_preview', methods: ['POST'])]
    public function preview(Request $request): Response
    {
        $this->checkToken($request);
        $values = $this->valuesFromRequest($request, false);
        try {
            [$path, $options] = $this->resolve($values);
            $plan = $this->importer->preview($this->importer->scan($path, $options), $options);
        } catch (\InvalidArgumentException $e) {
            $this->addFlash('danger', $e->getMessage());

            return $this->redirectToRoute('admin_import', array_filter($values, static fn ($v) => '' !== $v && null !== $v));
        }

        return $this->render('admin/import/preview.html.twig', [
            'plan' => $plan,
            'summary' => $plan->summary(),
            'expected' => self::expectedCounts($plan),
            'rows' => \array_slice($plan->entries, 0, self::PREVIEW_ROWS),
            'rows_limit' => self::PREVIEW_ROWS,
            'values' => $values,
            'options' => $options,
            'target' => null !== $options->targetSectionId ? $this->sections->find($options->targetSectionId) : null,
            'labels' => ImportPlan::RESULT_LABELS,
        ]);
    }

    #[Route('/start', name: 'admin_import_start', methods: ['POST'])]
    public function start(Request $request, #[CurrentUser] User $user): Response
    {
        $this->checkToken($request);
        $values = $this->valuesFromRequest($request, false);
        try {
            [$path, $options] = $this->resolve($values);
            $plan = $this->importer->scan($path, $options);
            if (0 === $plan->count()) {
                throw new \InvalidArgumentException('В выбранном каталоге нет ни папок, ни файлов.');
            }
            $job = $this->jobs->create($plan, $options, $user);
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            $this->addFlash('danger', $e->getMessage());

            return $this->redirectToRoute('admin_import');
        }

        return $this->redirectToRoute('admin_import_job', ['id' => $job->id]);
    }

    #[Route('/jobs/{id}', name: 'admin_import_job', methods: ['GET'])]
    public function job(string $id): Response
    {
        $job = $this->jobs->load($id);
        if (null === $job) {
            throw $this->createNotFoundException('Задание импорта не найдено.');
        }
        $rootId = $job->state->sections[''] ?? $job->options->targetSectionId;

        return $this->render('admin/import/job.html.twig', [
            'job' => $job,
            'summary' => $job->plan->summary(),
            'counters' => ImportState::COUNTER_LABELS,
            'log' => \array_slice($job->state->log, -self::LOG_ROWS),
            'labels' => ImportPlan::RESULT_LABELS,
            'root_section' => null !== $rootId ? $this->sections->find($rootId) : null,
            'target' => null !== $job->options->targetSectionId ? $this->sections->find($job->options->targetSectionId) : null,
        ]);
    }

    /** Обрабатывает очередную порцию элементов плана и возвращает состояние задания (JSON). */
    #[Route('/jobs/{id}/run', name: 'admin_import_job_run', methods: ['POST'])]
    public function run(string $id, Request $request): JsonResponse
    {
        if (!$this->isCsrfTokenValid('import', (string) $request->request->get('_token'))) {
            return new JsonResponse(['error' => 'Неверный CSRF-токен — обновите страницу.'], Response::HTTP_FORBIDDEN);
        }
        $job = $this->jobs->load($id);
        if (null === $job) {
            return new JsonResponse(['error' => 'Задание импорта не найдено.'], Response::HTTP_NOT_FOUND);
        }
        @set_time_limit(60);
        $ran = $this->jobs->runBatch($job);
        if (!$ran) {
            return new JsonResponse(['busy' => true, 'position' => $job->state->position, 'total' => $job->total()], Response::HTTP_CONFLICT);
        }
        $rootId = $job->state->sections[''] ?? $job->options->targetSectionId;
        $root = null !== $rootId ? $this->sections->find($rootId) : null;
        $log = [];
        foreach (\array_slice($job->state->log, -self::LOG_ROWS) as $line) {
            $log[] = $line + ['label' => ImportPlan::RESULT_LABELS[$line['result']] ?? $line['result']];
        }

        return new JsonResponse([
            'position' => $job->state->position,
            'total' => $job->total(),
            'percent' => $job->progressPercent(),
            'finished' => $job->isFinished(),
            'error' => $job->error,
            'counters' => $job->state->counters,
            'log' => $log,
            'root_section' => null !== $root ? ['id' => $root->getId(), 'name' => $root->getFullName(), 'url' => $this->generateUrl('app_section_show', ['id' => $root->getId()])] : null,
        ]);
    }

    /**
     * Значения формы (из POST или, для повторного показа формы после ошибки, из GET).
     *
     * @return array<string, mixed>
     */
    private function valuesFromRequest(Request $request, bool $defaults): array
    {
        $bag = $defaults ? $request->query : $request->request;

        return [
            'folder' => (string) $bag->get('folder', ''),
            'section' => (int) $bag->get('section', 0),
            'root_as_section' => $defaults ? (bool) $bag->get('root_as_section', false) : $bag->has('root_as_section'),
            'status' => 'draft' === $bag->get('status') ? 'draft' : 'published',
            'validity' => max(0, (int) $bag->get('validity', $defaults ? max(0, $this->defaultValidityMonths) : 0)),
            'update' => $defaults ? (bool) $bag->get('update', true) : $bag->has('update'),
            'delete_source' => $defaults ? (bool) $bag->get('delete_source', false) : $bag->has('delete_source'),
            'any_extension' => $defaults ? (bool) $bag->get('any_extension', false) : $bag->has('any_extension'),
            'public' => $defaults ? (bool) $bag->get('public', true) : $bag->has('public'),
            'describe' => $defaults ? (bool) $bag->get('describe', false) : $bag->has('describe'),
        ];
    }

    /**
     * Проверяет выбранный подкаталог (только внутри IMPORT_DIR) и собирает параметры импорта.
     *
     * @param array<string, mixed> $values
     *
     * @return array{0: string, 1: ImportOptions}
     */
    private function resolve(array $values): array
    {
        $root = $this->importRoot();
        if (null === $root) {
            throw new \InvalidArgumentException(\sprintf('Каталог импорта %s не существует. Создайте его на сервере (параметр IMPORT_DIR).', $this->importDir));
        }
        $folder = trim((string) $values['folder']);
        if ('' === $folder) {
            $path = $root;
        } else {
            if (str_contains($folder, '/') || str_contains($folder, '\\') || '.' === $folder || '..' === $folder || str_starts_with($folder, '.')) {
                throw new \InvalidArgumentException('Недопустимое имя подкаталога.');
            }
            $path = realpath($root.'/'.$folder);
            if (false === $path || !is_dir($path) || !str_starts_with($path, $root.'/')) {
                throw new \InvalidArgumentException(\sprintf('Подкаталог «%s» не найден в каталоге импорта.', $folder));
            }
        }
        $target = null;
        if ($values['section'] > 0) {
            $target = $this->sections->find((int) $values['section']);
            if (!$target instanceof Section) {
                throw new \InvalidArgumentException('Целевой раздел не найден.');
            }
        }
        return [$path, new ImportOptions(
            targetSectionId: $target?->getId(),
            rootAsSection: (bool) $values['root_as_section'],
            publish: 'draft' !== $values['status'],
            validityMonths: (int) $values['validity'],
            updateExisting: (bool) $values['update'],
            deleteSource: (bool) $values['delete_source'],
            anyExtension: (bool) $values['any_extension'],
            removeRootIfEmpty: (bool) $values['delete_source'] && '' !== $folder,
            publicAccess: (bool) $values['public'],
            describe: (bool) $values['describe'] && $this->importer->canDescribe(),
        )];
    }

    /** Реальный путь каталога импорта (создаётся при первом обращении); null — создать не удалось. */
    private function importRoot(): ?string
    {
        if (!is_dir($this->importDir)) {
            @mkdir($this->importDir, 0770, true);
        }
        $real = realpath($this->importDir);

        return false !== $real && is_dir($real) ? rtrim($real, '/') : null;
    }

    /** @return array<string, int> */
    public static function expectedCounts(ImportPlan $plan): array
    {
        $counts = array_fill_keys(array_keys(ImportPlan::RESULT_LABELS), 0);
        foreach ($plan->entries as $entry) {
            $result = $entry['result'] ?? ImportPlan::RESULT_SKIPPED;
            $counts[$result] = ($counts[$result] ?? 0) + 1;
        }

        return $counts;
    }

    private function checkToken(Request $request): void
    {
        if (!$this->isCsrfTokenValid('import', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Неверный CSRF-токен.');
        }
    }
}
