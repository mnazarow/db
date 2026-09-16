<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Document;
use App\Repository\DocumentRepository;
use App\Repository\SectionRepository;
use App\Repository\UserRepository;
use App\Service\Validity;
use App\Twig\AppExtension;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Панель администратора: реестр всех документов с фильтрами и выгрузкой в CSV.
 */
#[Route('/admin/documents')]
final class DocumentAdminController extends AbstractController
{
    public function __construct(
        private readonly DocumentRepository $documents,
        private readonly SectionRepository $sections,
        private readonly UserRepository $users,
        private readonly Validity $validity,
    ) {
    }

    #[Route('', name: 'admin_documents', methods: ['GET'])]
    public function index(Request $request): Response
    {
        [$filters, $documents] = $this->query($request);

        return $this->render('admin/documents/index.html.twig', [
            'documents' => $documents,
            'filters' => $filters,
            'tree' => $this->sections->findAllTree(),
            'owners' => $this->users->findAllOrdered(),
            'sort' => (string) $request->query->get('sort', 'updated'),
        ]);
    }

    #[Route('/export.csv', name: 'admin_documents_export', methods: ['GET'])]
    public function export(Request $request): Response
    {
        [, $documents] = $this->query($request, 10000);
        $response = new StreamedResponse(function () use ($documents): void {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // BOM для Excel
            fputcsv($out, ['ID', 'Обозначение', 'Название', 'Раздел', 'Вид', 'Статус', 'Доступ', 'Версия', 'Актуален до', 'Состояние', 'Владелец', 'Создан', 'Обновлён', 'Просмотры', 'Скачивания', 'Теги'], ';');
            foreach ($documents as $d) {
                fputcsv($out, [
                    $d->getId(), $d->getCode(), $d->getTitle(), $d->getSection()->getFullName(),
                    AppExtension::TYPE_LABELS[$d->getType()] ?? $d->getType(), AppExtension::STATUS_LABELS[$d->getStatus()] ?? $d->getStatus(),
                    $d->isPublic() ? 'открытый' : 'внутренний',
                    $d->getCurrentVersion()?->getNumber(), $d->getValidUntil()?->format('d.m.Y'),
                    Validity::LABELS[$this->validity->stateOf($d)], $d->getOwner()?->getDisplayName(),
                    $d->getCreatedAt()->format('d.m.Y H:i'), $d->getUpdatedAt()->format('d.m.Y H:i'),
                    $d->getViewCount(), $d->getDownloadCount(), $d->getTagsString(),
                ], ';');
            }
            fclose($out);
        });
        $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $response->headers->set('Content-Disposition', 'attachment; filename="documents-'.date('Y-m-d').'.csv"');

        return $response;
    }

    /** @return array{0: array<string, mixed>, 1: list<Document>} */
    private function query(Request $request, int $limit = 500): array
    {
        $sectionId = $request->query->getInt('section');
        $ownerId = $request->query->getInt('owner');
        $filters = [
            'section' => $sectionId > 0 ? $this->sections->find($sectionId) : null,
            'status' => $request->query->get('status') ?: null,
            'type' => $request->query->get('type') ?: null,
            'validity' => $request->query->get('validity') ?: null,
            'owner' => $ownerId > 0 ? $this->users->find($ownerId) : null,
            'access' => \in_array($request->query->get('access'), ['public', 'internal'], true) ? $request->query->get('access') : null,
            'q' => $request->query->get('q') ?: null,
        ];
        $sort = (string) $request->query->get('sort', 'updated');

        return [$filters, $this->documents->findForRegistry($filters, $this->validity->today(), $this->validity->getSoonDays(), $sort, $limit)];
    }
}
