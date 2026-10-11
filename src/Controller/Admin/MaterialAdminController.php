<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\DocumentKind;
use App\Entity\Section;
use App\Entity\User;
use App\Repository\DocumentKindRepository;
use App\Service\DocumentKindService;
use App\Service\Material\MaterialExporter;
use App\Service\Material\MaterialImporter;
use App\Service\Material\MaterialReminder;
use App\Service\Material\MaterialService;
use App\Service\Material\MaterialState;
use App\Service\Material\MaterialSummary;
use App\Service\PortalSettings;
use App\Service\Xlsx\XlsxReader;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Панель администратора → «Материалы»: сводная по категориям (как лист «Сводная по категориям»),
 * справочник типов файлов, импорт и выгрузка Excel.
 */
#[Route('/admin/materials')]
#[IsGranted('ROLE_ADMIN')]
final class MaterialAdminController extends AbstractController
{
    private const MAX_IMPORT_MB = 20;

    public function __construct(
        private readonly MaterialSummary $summary,
        private readonly MaterialService $materials,
        private readonly DocumentKindService $kinds,
        private readonly DocumentKindRepository $kindRepository,
        private readonly XlsxReader $reader,
        private readonly MaterialImporter $importer,
        private readonly MaterialExporter $exporter,
        private readonly LoggerInterface $auditLogger,
        #[Autowire('%kernel.cache_dir%/materials-import')]
        private readonly string $importDir,
        private readonly MaterialReminder $reminder,
        private readonly PortalSettings $settings,
    ) {
    }

    /** Сводная по категориям. */
    #[Route('', name: 'admin_materials', methods: ['GET'])]
    public function index(): Response
    {
        $overview = $this->summary->overview();
        $donuts = [];
        foreach ($overview['categories'] as $category) {
            $donuts[(int) $category['section']->getId()] = MaterialSummary::donut($category['summary']);
        }

        return $this->render('admin/materials/index.html.twig', [
            'overview' => $overview,
            'donuts' => $donuts,
            'total_donut' => MaterialSummary::donut($overview['totals']),
            'states' => MaterialState::SUMMARY,
            'state_css' => MaterialState::CSS,
            'candidates' => $this->summary->candidates(),
            'remind' => $this->settings->materialsRemind(),
            'remind_states' => MaterialReminder::STATES,
        ]);
    }

    /** Еженедельная сводка по таблицам материалов: включение и состояния. */
    #[Route('/remind', name: 'admin_materials_remind', methods: ['POST'])]
    public function remind(Request $request, #[CurrentUser] User $user): Response
    {
        $this->checkToken($request);
        $states = array_values(array_intersect(MaterialReminder::STATES, array_map('strval', $request->request->all('states'))));
        $enabled = $request->request->getBoolean('enabled');
        if ($enabled && [] === $states) {
            $this->addFlash('error', 'Выберите хотя бы одно состояние, по которому присылать сводку.');

            return $this->redirectToRoute('admin_materials', ['_fragment' => 'remind']);
        }
        $this->settings->setMaterialsRemind($enabled, [] !== $states ? $states : PortalSettings::MATERIALS_REMIND_DEFAULT_STATES, $user);
        $this->addFlash('success', $enabled
            ? 'Сводка включена: по понедельникам модераторы разделов получат список материалов в состояниях «'.implode('», «', $states).'».'
            : 'Еженедельная сводка по таблицам материалов выключена.');

        return $this->redirectToRoute('admin_materials', ['_fragment' => 'remind']);
    }

    /** Отправить сводку сейчас (или показать, кому она уйдёт). */
    #[Route('/remind/send', name: 'admin_materials_remind_send', methods: ['POST'])]
    public function remindSend(Request $request): Response
    {
        $this->checkToken($request);
        $dryRun = 'preview' === $request->request->get('mode');
        $result = $this->reminder->run($dryRun, true);
        if ($dryRun && [] !== $result['plan']) {
            $lines = array_map(static fn (array $p): string => \sprintf('%s — %s (%d)', $p['user']->getDisplayName(), implode(', ', $p['sections']), $p['rows']), $result['plan']);
            $this->addFlash('info', $result['message'].' Получатели: '.implode('; ', \array_slice($lines, 0, 20)).(\count($lines) > 20 ? '; …' : '').'.');
        } else {
            $this->addFlash(0 === $result['rows'] ? 'info' : 'success', $result['message']);
        }

        return $this->redirectToRoute('admin_materials', ['_fragment' => 'remind']);
    }

    /** Включить таблицу материалов для раздела верхнего уровня прямо из сводной. */
    #[Route('/sections/{id}/enable', name: 'admin_materials_enable', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function enable(Section $section, Request $request, #[CurrentUser] User $user): Response
    {
        $this->checkToken($request);
        try {
            $this->materials->enable($section, $user);
            $this->addFlash('success', \sprintf('Таблица материалов раздела «%s» включена.', $section->getName()));
        } catch (\DomainException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('app_section_materials', ['id' => $section->getId()]);
    }

    /** Выгрузка всех таблиц в Excel: сводная и лист на каждый раздел. */
    #[Route('/export.xlsx', name: 'admin_materials_export', methods: ['GET'])]
    public function export(#[CurrentUser] User $user): Response
    {
        return $this->exporter->response($this->summary->categories(), 'Продуктовые материалы', true, $user);
    }

    // ---- Типы файлов ----------------------------------------------------------------------------------

    #[Route('/kinds', name: 'admin_material_kinds', methods: ['GET'])]
    public function kinds(): Response
    {
        return $this->render('admin/materials/kinds.html.twig', [
            'kinds' => $this->kinds->all(),
            'documents' => $this->kindRepository->countDocuments(),
            'rows' => $this->kindRepository->countMaterials(),
        ]);
    }

    #[Route('/kinds/add', name: 'admin_material_kinds_add', methods: ['POST'])]
    public function addKind(Request $request, #[CurrentUser] User $user): Response
    {
        $this->checkToken($request);
        try {
            $kind = $this->kinds->create((string) $request->request->get('name'), $user);
            $this->addFlash('success', \sprintf('Тип «%s» добавлен.', $kind->getName()));
        } catch (\DomainException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('admin_material_kinds');
    }

    #[Route('/kinds/{id}/rename', name: 'admin_material_kinds_rename', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function renameKind(DocumentKind $kind, Request $request, #[CurrentUser] User $user): Response
    {
        $this->checkToken($request);
        try {
            $this->kinds->rename($kind, (string) $request->request->get('name'), $user);
            $this->addFlash('success', 'Тип переименован.');
        } catch (\DomainException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('admin_material_kinds');
    }

    #[Route('/kinds/{id}/move', name: 'admin_material_kinds_move', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function moveKind(DocumentKind $kind, Request $request): Response
    {
        $this->checkToken($request);
        $this->kinds->move($kind, (int) $request->request->get('direction', 1));

        return $this->redirectToRoute('admin_material_kinds');
    }

    #[Route('/kinds/{id}/delete', name: 'admin_material_kinds_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function deleteKind(DocumentKind $kind, Request $request, #[CurrentUser] User $user): Response
    {
        $this->checkToken($request);
        try {
            $replacement = $this->kinds->find((int) $request->request->get('replacement'));
            $moved = $this->kinds->delete($kind, $replacement, $user);
            $this->addFlash('success', \sprintf('Тип удалён. Документов переведено в «%s»: %d, строк таблиц материалов: %d.', $replacement?->getName() ?? DocumentKind::FALLBACK, $moved['documents'], $moved['materials']));
        } catch (\DomainException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('admin_material_kinds');
    }

    // ---- Импорт из Excel ------------------------------------------------------------------------------

    #[Route('/import', name: 'admin_materials_import', methods: ['GET'])]
    public function importForm(): Response
    {
        return $this->render('admin/materials/import.html.twig', ['max_mb' => self::MAX_IMPORT_MB]);
    }

    /** Шаг 1: файл сохраняется, книга разбирается, показывается план изменений. */
    #[Route('/import', name: 'admin_materials_import_upload', methods: ['POST'])]
    public function importUpload(Request $request): Response
    {
        $this->checkToken($request);
        $file = $request->files->get('file');
        if (!$file instanceof UploadedFile || !$file->isValid()) {
            $this->addFlash('error', 'Выберите файл Excel (.xlsx).');

            return $this->redirectToRoute('admin_materials_import');
        }
        if ('xlsx' !== mb_strtolower($file->getClientOriginalExtension()) || $file->getSize() > self::MAX_IMPORT_MB * 1048576) {
            $this->addFlash('error', \sprintf('Нужен файл .xlsx размером до %d МБ. Файлы .xls сначала сохраните в Excel как «Книга Excel (.xlsx)».', self::MAX_IMPORT_MB));

            return $this->redirectToRoute('admin_materials_import');
        }
        $this->cleanup();
        if (!is_dir($this->importDir) && !@mkdir($this->importDir, 0775, true) && !is_dir($this->importDir)) {
            throw new \RuntimeException('Не удалось создать каталог '.$this->importDir);
        }
        $token = bin2hex(random_bytes(16));
        $file->move($this->importDir, $token.'.xlsx');
        $deleteMissing = $request->request->getBoolean('delete_missing');
        try {
            $plan = $this->importer->plan($this->reader->read($this->path($token)), $deleteMissing);
        } catch (\RuntimeException|\DomainException $e) {
            @unlink($this->path($token));
            $this->addFlash('error', 'Не удалось прочитать файл: '.$e->getMessage());

            return $this->redirectToRoute('admin_materials_import');
        }

        return $this->render('admin/materials/import_preview.html.twig', [
            'plan' => $plan,
            'token' => $token,
            'file_name' => $file->getClientOriginalName(),
            'delete_missing' => $deleteMissing,
        ]);
    }

    /** Шаг 2: импорт по сохранённому файлу. */
    #[Route('/import/{token}', name: 'admin_materials_import_apply', requirements: ['token' => '[a-f0-9]{32}'], methods: ['POST'])]
    public function importApply(string $token, Request $request, #[CurrentUser] User $user): Response
    {
        $this->checkToken($request);
        $path = $this->path($token);
        if (!is_file($path)) {
            $this->addFlash('error', 'Файл импорта не найден (прошло больше суток?) — загрузите его ещё раз.');

            return $this->redirectToRoute('admin_materials_import');
        }
        try {
            $report = $this->importer->apply($this->reader->read($path), $request->request->getBoolean('delete_missing'), $user);
        } catch (\Throwable $e) {
            // Импорт идёт одной транзакцией: при ошибке ничего не сохранено.
            $this->auditLogger->error('Импорт таблиц материалов не выполнен', ['error' => $e->getMessage(), 'user' => $user->getUsername()]);
            $this->addFlash('error', 'Импорт не выполнен, изменения отменены: '.$e->getMessage());

            return $this->redirectToRoute('admin_materials_import');
        } finally {
            @unlink($path);
        }
        $totals = $report['totals'];
        $this->addFlash('success', \sprintf(
            'Импорт выполнен: разделов создано %d, строк добавлено %d, обновлено %d, без изменений %d%s%s.',
            $totals['sectionsCreated'], $totals['created'], $totals['updated'], $totals['unchanged'],
            $totals['deleted'] > 0 ? ', удалено '.$totals['deleted'] : '',
            [] !== $report['kindsCreated'] ? '; новые типы файлов: '.implode(', ', $report['kindsCreated']) : '',
        ));
        foreach (\array_slice($report['warnings'], 0, 5) as $warning) {
            $this->addFlash('warning', $warning);
        }

        return $this->redirectToRoute('admin_materials');
    }

    private function path(string $token): string
    {
        return $this->importDir.'/'.$token.'.xlsx';
    }

    /** Убирает брошенные файлы импорта старше суток. */
    private function cleanup(): void
    {
        foreach (glob($this->importDir.'/*.xlsx') ?: [] as $file) {
            if (filemtime($file) < time() - 86400) {
                @unlink($file);
            }
        }
    }

    private function checkToken(Request $request): void
    {
        if (!$this->isCsrfTokenValid('materials_admin', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Неверный CSRF-токен.');
        }
    }
}
