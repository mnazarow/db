<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\User;
use App\Repository\ConfluencePageRepository;
use App\Repository\SectionRepository;
use App\Service\Import\Confluence\ConfluenceException;
use App\Service\Import\Confluence\ConfluenceImporter;
use App\Service\Import\Confluence\ConfluenceJob;
use App\Service\PortalSettings;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Панель администратора → Импорт → «Из Confluence»: подключение (Cloud или Server/Data Center), выбор пространства
 * и раздела, импорт порциями с показом хода. Повторный импорт того же пространства обновляет изменившиеся страницы.
 */
#[Route('/admin/import/confluence')]
final class ConfluenceImportController extends AbstractController
{
    private const LOG_ROWS = 300;

    public function __construct(
        private readonly ConfluenceImporter $importer,
        private readonly PortalSettings $settings,
        private readonly SectionRepository $sections,
        private readonly ConfluencePageRepository $links,
    ) {
    }

    #[Route('', name: 'admin_import_confluence', methods: ['GET'])]
    public function index(): Response
    {
        $config = $this->settings->confluence();
        $spaces = [];
        $spacesError = null;
        if ($this->importer->isConfigured()) {
            try {
                $spaces = $this->importer->api()->spaces();
            } catch (ConfluenceException $e) {
                $spacesError = $e->getMessage();
            }
        }

        return $this->render('admin/import/confluence.html.twig', [
            'config' => ['token_set' => '' !== $config['token'], 'token_masked' => PortalSettings::mask($config['token'])] + $config,
            'configured' => $this->importer->isConfigured(),
            'spaces' => $spaces,
            'spaces_error' => $spacesError,
            'imported' => $this->links->countBySpace(),
            'tree' => $this->sections->findAllTree(),
        ]);
    }

    #[Route('/settings', name: 'admin_import_confluence_settings', methods: ['POST'])]
    public function settings(Request $request, #[CurrentUser] User $user): Response
    {
        $this->checkToken($request);
        $token = (string) $request->request->get('token', '');
        $url = trim((string) $request->request->get('url', ''));
        $this->settings->setConfluence([
            'url' => $url,
            'auth' => (string) $request->request->get('auth', 'cloud'),
            'user' => (string) $request->request->get('user', ''),
            'token' => '' !== $token ? $token : null,
        ], $user);
        if ('' === $url) {
            $this->addFlash('success', 'Подключение к Confluence удалено.');

            return $this->redirectToRoute('admin_import_confluence');
        }
        try {
            $spaces = $this->importer->api()->spaces();
            $this->addFlash('success', \sprintf('Подключение к Confluence работает: доступно пространств — %d.', \count($spaces)));
        } catch (ConfluenceException $e) {
            $this->addFlash('danger', 'Настройки сохранены, но Confluence не отвечает: '.$e->getMessage());
        }

        return $this->redirectToRoute('admin_import_confluence');
    }

    #[Route('/start', name: 'admin_import_confluence_start', methods: ['POST'])]
    public function start(Request $request, #[CurrentUser] User $user): Response
    {
        $this->checkToken($request);
        @set_time_limit(300);
        $section = (int) $request->request->get('section', 0);
        try {
            $job = $this->importer->start([
                'space' => trim((string) $request->request->get('space', '')),
                'root' => trim((string) $request->request->get('root', '')),
                'section' => $section > 0 ? $section : null,
                'attachments' => $request->request->has('attachments'),
                'publish' => 'draft' !== $request->request->get('status'),
                'flat' => $request->request->has('flat'),
            ], $user);
        } catch (ConfluenceException|\DomainException $e) {
            $this->addFlash('danger', 'Импорт не начат: '.$e->getMessage());

            return $this->redirectToRoute('admin_import_confluence');
        }

        return $this->redirectToRoute('admin_import_confluence_job', ['id' => $job->id]);
    }

    #[Route('/jobs/{id}', name: 'admin_import_confluence_job', requirements: ['id' => 'confluence-[0-9]{8}-[0-9]{6}-[0-9a-f]{6}'], methods: ['GET'])]
    public function job(string $id): Response
    {
        $job = $this->importer->load($id) ?? throw $this->createNotFoundException('Задание импорта не найдено.');

        return $this->render('admin/import/confluence_job.html.twig', [
            'job' => $job,
            'counters' => ConfluenceJob::COUNTER_LABELS,
            'log' => \array_slice($job->log, -self::LOG_ROWS),
            'labels' => self::labels(),
            'root_section' => $this->sections->find($job->rootSectionId),
        ]);
    }

    /** Очередная порция задания (JSON — для страницы задания). */
    #[Route('/jobs/{id}/run', name: 'admin_import_confluence_job_run', requirements: ['id' => 'confluence-[0-9]{8}-[0-9]{6}-[0-9a-f]{6}'], methods: ['POST'])]
    public function run(string $id, Request $request): JsonResponse
    {
        if (!$this->isCsrfTokenValid('import', (string) $request->request->get('_token'))) {
            return new JsonResponse(['error' => 'Неверный CSRF-токен — обновите страницу.'], Response::HTTP_FORBIDDEN);
        }
        $job = $this->importer->load($id);
        if (null === $job) {
            return new JsonResponse(['error' => 'Задание импорта не найдено.'], Response::HTTP_NOT_FOUND);
        }
        @set_time_limit(120);
        if (!$this->importer->runBatch($job)) {
            return new JsonResponse(['busy' => true, 'position' => $job->position, 'total' => $job->total()], Response::HTTP_CONFLICT);
        }
        $labels = self::labels();
        $root = $this->sections->find($job->rootSectionId);

        return new JsonResponse([
            'position' => $job->position,
            'total' => $job->total(),
            'percent' => $job->progressPercent(),
            'finished' => $job->isFinished(),
            'error' => $job->error,
            'counters' => $job->counters,
            'log' => array_map(static fn (array $l): array => $l + ['label' => $labels[$l['result']] ?? $l['result']], \array_slice($job->log, -self::LOG_ROWS)),
            'root_section' => null !== $root ? ['id' => $root->getId(), 'name' => $root->getFullName(), 'url' => $this->generateUrl('app_section_show', ['id' => $root->getId()])] : null,
        ]);
    }

    /** @return array<string, string> */
    private static function labels(): array
    {
        return [
            'section_new' => 'Раздел создан', 'section_exists' => 'Раздел есть', 'doc_new' => 'Импортировано', 'version_new' => 'Обновлено',
            'unchanged' => 'Без изменений', 'skipped' => 'Пропущено', 'error' => 'Ошибка',
        ];
    }

    private function checkToken(Request $request): void
    {
        if (!$this->isCsrfTokenValid('import', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Неверный CSRF-токен.');
        }
    }
}
