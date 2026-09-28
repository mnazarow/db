<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\MaterialParameter;
use App\Entity\MaterialRow;
use App\Entity\Section;
use App\Entity\User;
use App\Security\Access;
use App\Service\DocumentKindService;
use App\Service\FileStorage;
use App\Service\Material\MaterialExporter;
use App\Service\Material\MaterialService;
use App\Service\Material\MaterialState;
use App\Service\Material\MaterialSummary;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Таблица материалов раздела верхнего уровня: просмотр и правка «как в Excel».
 * Доступна администраторам и модераторам раздела; правки уходят запросами JSON.
 */
#[Route('/sections/{id}/materials', requirements: ['id' => '\d+'])]
#[IsGranted('ROLE_USER')]
final class MaterialController extends AbstractController
{
    public function __construct(
        private readonly MaterialService $materials,
        private readonly MaterialSummary $summary,
        private readonly DocumentKindService $kinds,
        private readonly Access $access,
        private readonly FileStorage $storage,
    ) {
    }

    #[Route('', name: 'app_section_materials', methods: ['GET'])]
    public function table(Section $section, #[CurrentUser] User $user): Response
    {
        $this->checkAccess($section, $user);
        if (!$this->materials->supports($section)) {
            $this->addFlash('info', 'Таблица материалов ведётся для разделов верхнего уровня.');

            return $this->redirectToRoute('app_section_show', ['id' => $section->getId()]);
        }
        $parameters = $this->materials->parameters($section);
        $rows = $this->materials->rows($section);

        return $this->render('materials/table.html.twig', [
            'section' => $section,
            'enabled' => $section->isMaterialsEnabled(),
            'parameters' => $parameters,
            'rows' => array_map($this->materials->serializeRow(...), $rows),
            'kinds' => $this->kinds->all(),
            'summary' => $this->summary->forSection($section),
            'states' => MaterialState::SUMMARY,
            'kind_labels' => MaterialParameter::KIND_LABELS,
            'accept' => implode(',', array_map(static fn (string $e): string => '.'.$e, $this->storage->getAllowedExtensions())),
            'max_mb' => $this->storage->getUploadMaxMb(),
            'config' => [
                'section' => $section->getId(),
                'token' => $this->container->get('security.csrf.token_manager')->getToken('materials_'.$section->getId())->getValue(),
                'base' => $this->generateUrl('app_section_materials', ['id' => $section->getId()]),
                'parameters' => array_map($this->materials->serializeParameter(...), $parameters),
                'kinds' => array_map(static fn ($k): array => ['id' => $k->getId(), 'name' => $k->getName()], $this->kinds->all()),
                'states' => MaterialState::CSS,
                'maxBytes' => $this->storage->getUploadMaxBytes(),
            ],
        ]);
    }

    /** Включает таблицу для раздела верхнего уровня (создаёт столбцы как в Excel). */
    #[Route('/enable', name: 'app_section_materials_enable', methods: ['POST'])]
    public function enable(Section $section, Request $request, #[CurrentUser] User $user): Response
    {
        $this->checkAccess($section, $user);
        $this->checkFormToken($request, $section);
        try {
            $this->materials->enable($section, $user);
            $this->addFlash('success', 'Таблица материалов включена: добавьте строки или загрузите таблицу из Excel.');
        } catch (\DomainException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('app_section_materials', ['id' => $section->getId()]);
    }

    #[Route('/disable', name: 'app_section_materials_disable', methods: ['POST'])]
    public function disable(Section $section, Request $request, #[CurrentUser] User $user): Response
    {
        $this->checkAccess($section, $user);
        $this->checkFormToken($request, $section);
        $this->materials->disable($section, $user);
        $this->addFlash('success', 'Таблица материалов скрыта. Строки и столбцы сохранены — при повторном включении они вернутся.');

        return $this->redirectToRoute('app_section_show', ['id' => $section->getId()]);
    }

    // ---- Строки ---------------------------------------------------------------------------------------

    #[Route('/rows', name: 'app_section_materials_row_add', methods: ['POST'])]
    public function addRow(Section $section, Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $this->checkApi($request, $section, $user);
        $after = null;
        $afterId = (int) $request->request->get('after', 0);
        if ($afterId > 0) {
            $after = $this->row($section, $afterId);
        }
        $row = $this->materials->addRow($section, $user, (string) $request->request->get('title', ''), $after);

        return $this->json(['ok' => true, 'row' => $this->materials->serializeRow($row), 'summary' => $this->summary->forSection($section)]);
    }

    #[Route('/rows/{row}', name: 'app_section_materials_row_update', requirements: ['row' => '\d+'], methods: ['POST'])]
    public function updateRow(Section $section, int $row, Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $this->checkApi($request, $section, $user);
        $material = $this->row($section, $row);
        try {
            $this->materials->updateCell($material, (string) $request->request->get('field'), (string) $request->request->get('value'), $user);
        } catch (\DomainException $e) {
            return $this->json(['ok' => false, 'error' => $e->getMessage(), 'row' => $this->materials->serializeRow($material)], 422);
        }

        return $this->json(['ok' => true, 'row' => $this->materials->serializeRow($material), 'summary' => $this->summary->forSection($section)]);
    }

    #[Route('/rows/{row}/delete', name: 'app_section_materials_row_delete', requirements: ['row' => '\d+'], methods: ['POST'])]
    public function deleteRow(Section $section, int $row, Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $this->checkApi($request, $section, $user);
        $this->materials->deleteRow($this->row($section, $row), $user);

        return $this->json(['ok' => true, 'summary' => $this->summary->forSection($section)]);
    }

    #[Route('/rows/{row}/move', name: 'app_section_materials_row_move', requirements: ['row' => '\d+'], methods: ['POST'])]
    public function moveRow(Section $section, int $row, Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $this->checkApi($request, $section, $user);
        $this->materials->moveRow($this->row($section, $row), (int) $request->request->get('direction', 1));

        return $this->json(['ok' => true]);
    }

    /** Загрузка файла прямо в строку: документ получает название и тип из строки. */
    #[Route('/rows/{row}/upload', name: 'app_section_materials_row_upload', requirements: ['row' => '\d+'], methods: ['POST'])]
    public function upload(Section $section, int $row, Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $this->checkApi($request, $section, $user);
        $material = $this->row($section, $row);
        $file = $request->files->get('file');
        if (!$file instanceof UploadedFile) {
            return $this->json(['ok' => false, 'error' => 'Файл не получен: возможно, он больше допустимого размера.'], 422);
        }
        try {
            $document = $this->materials->upload($material, $file, $user, $request->getClientIp());
        } catch (\InvalidArgumentException|\DomainException|\RuntimeException $e) {
            return $this->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }

        return $this->json([
            'ok' => true,
            'message' => \sprintf('Файл загружен: «%s», версия %d%s.', $document->getTitle(), (int) $document->getCurrentVersion()?->getNumber(), $document->isPublished() ? '' : ' (черновик — опубликуйте после проверки)'),
            'row' => $this->materials->serializeRow($material),
            'summary' => $this->summary->forSection($section),
        ]);
    }

    #[Route('/rows/{row}/detach', name: 'app_section_materials_row_detach', requirements: ['row' => '\d+'], methods: ['POST'])]
    public function detach(Section $section, int $row, Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $this->checkApi($request, $section, $user);
        $material = $this->row($section, $row);
        $this->materials->detach($material, $user);

        return $this->json(['ok' => true, 'row' => $this->materials->serializeRow($material), 'summary' => $this->summary->forSection($section)]);
    }

    // ---- Столбцы --------------------------------------------------------------------------------------

    #[Route('/parameters', name: 'app_section_materials_parameter_add', methods: ['POST'])]
    public function addParameter(Section $section, Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $this->checkApi($request, $section, $user);
        try {
            $parameter = $this->materials->addParameter($section, (string) $request->request->get('name'), (string) $request->request->get('kind', MaterialParameter::KIND_TEXT), (string) $request->request->get('options', ''), $user);
        } catch (\DomainException $e) {
            return $this->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }

        return $this->json(['ok' => true, 'parameter' => $this->materials->serializeParameter($parameter)]);
    }

    #[Route('/parameters/{parameter}', name: 'app_section_materials_parameter_update', requirements: ['parameter' => '\d+'], methods: ['POST'])]
    public function updateParameter(Section $section, int $parameter, Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $this->checkApi($request, $section, $user);
        $column = $this->parameter($section, $parameter);
        $width = $request->request->get('width');
        try {
            $this->materials->updateParameter(
                $column,
                (string) $request->request->get('name', $column->getName()),
                $request->request->has('options') ? (string) $request->request->get('options') : null,
                null !== $width && '' !== $width ? (int) $width : null,
                $user,
            );
        } catch (\DomainException $e) {
            return $this->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }

        return $this->json(['ok' => true, 'parameter' => $this->materials->serializeParameter($column)]);
    }

    #[Route('/parameters/{parameter}/delete', name: 'app_section_materials_parameter_delete', requirements: ['parameter' => '\d+'], methods: ['POST'])]
    public function deleteParameter(Section $section, int $parameter, Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $this->checkApi($request, $section, $user);
        $this->materials->deleteParameter($this->parameter($section, $parameter), $user);

        return $this->json(['ok' => true]);
    }

    #[Route('/parameters/{parameter}/move', name: 'app_section_materials_parameter_move', requirements: ['parameter' => '\d+'], methods: ['POST'])]
    public function moveParameter(Section $section, int $parameter, Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $this->checkApi($request, $section, $user);
        $this->materials->moveParameter($this->parameter($section, $parameter), (int) $request->request->get('direction', 1));

        return $this->json(['ok' => true]);
    }

    /** Выгрузка таблицы раздела в Excel (тот же вид, что у исходного файла). */
    #[Route('.xlsx', name: 'app_section_materials_export', methods: ['GET'])]
    public function export(Section $section, #[CurrentUser] User $user, MaterialExporter $exporter): Response
    {
        $this->checkAccess($section, $user);

        return $exporter->response([$section], \sprintf('Материалы — %s', $section->getName()));
    }

    // ---- Служебное ------------------------------------------------------------------------------------

    private function checkAccess(Section $section, User $user): void
    {
        if (!$this->access->canManageSection($user, $section)) {
            throw $this->createAccessDeniedException('Таблица материалов доступна администраторам и модераторам раздела.');
        }
    }

    private function checkFormToken(Request $request, Section $section): void
    {
        if (!$this->isCsrfTokenValid('materials_'.$section->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Неверный CSRF-токен.');
        }
    }

    private function checkApi(Request $request, Section $section, User $user): void
    {
        $this->checkAccess($section, $user);
        $token = (string) ($request->headers->get('X-CSRF-Token') ?? $request->request->get('_token'));
        if (!$this->isCsrfTokenValid('materials_'.$section->getId(), $token)) {
            throw $this->createAccessDeniedException('Неверный CSRF-токен — обновите страницу.');
        }
        if (!$section->isMaterialsEnabled()) {
            throw $this->createNotFoundException('Таблица материалов выключена.');
        }
    }

    private function row(Section $section, int $id): MaterialRow
    {
        foreach ($this->materials->rows($section) as $row) {
            if ($row->getId() === $id) {
                return $row;
            }
        }
        throw $this->createNotFoundException('Строка не найдена — возможно, её уже удалили.');
    }

    private function parameter(Section $section, int $id): MaterialParameter
    {
        foreach ($this->materials->parameters($section) as $parameter) {
            if ($parameter->getId() === $id) {
                return $parameter;
            }
        }
        throw $this->createNotFoundException('Столбец не найден — возможно, его уже удалили.');
    }
}
