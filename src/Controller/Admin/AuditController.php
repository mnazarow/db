<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\AuditEvent;
use App\Repository\AuditEventRepository;
use App\Service\Audit\AuditExporter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Журнал аудита: кто входил, что менял в настройках и правах, какие документы удалял.
 * Нужен службе информационной безопасности и при проверках по 152-ФЗ.
 */
#[Route('/admin/audit')]
#[IsGranted('ROLE_ADMIN')]
final class AuditController extends AbstractController
{
    private const PER_PAGE = 100;

    public function __construct(
        private readonly AuditEventRepository $events,
        private readonly AuditExporter $exporter,
    ) {
    }

    #[Route('', name: 'admin_audit', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $filters = self::filters($request);
        $page = max(1, (int) $request->query->get('page', 1));
        $total = $this->events->countFiltered($filters);

        return $this->render('admin/audit/index.html.twig', [
            'events' => $this->events->findFiltered($filters, self::PER_PAGE, ($page - 1) * self::PER_PAGE),
            'total' => $total,
            'page' => $page,
            'pages' => max(1, (int) ceil($total / self::PER_PAGE)),
            'filters' => $request->query->all(),
            'actors' => $this->events->actors(),
            'levels' => AuditEvent::LEVEL_LABELS,
            'summary' => $this->events->summary(),
        ]);
    }

    #[Route('.csv', name: 'admin_audit_csv', methods: ['GET'])]
    public function csv(Request $request): StreamedResponse
    {
        $events = $this->events->findFiltered(self::filters($request), 20000);
        $response = new StreamedResponse(function () use ($events): void {
            $out = fopen('php://output', 'wb');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Дата и время', 'Уровень', 'Действие', 'Сотрудник', 'Адрес', 'Подробности'], ';', '"', '\\');
            foreach ($events as $event) {
                fputcsv($out, [
                    $event->getOccurredAt()->format('d.m.Y H:i:s'),
                    $event->getLevelLabel(),
                    $event->getAction(),
                    (string) $event->getActorName(),
                    (string) $event->getIp(),
                    $event->detailsLine(),
                ], ';', '"', '\\');
            }
            fclose($out);
        });
        $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $response->headers->set('Content-Disposition', \sprintf('attachment; filename="audit-%s.csv"', date('Y-m-d')));

        return $response;
    }

    /** Выгрузка для SIEM: JSON Lines или CEF. */
    #[Route('/export', name: 'admin_audit_export', methods: ['GET'])]
    public function export(Request $request): StreamedResponse
    {
        $format = 'cef' === $request->query->get('format') ? AuditExporter::FORMAT_CEF : AuditExporter::FORMAT_JSONL;
        $events = $this->events->findFiltered(self::filters($request), 20000);
        $exporter = $this->exporter;
        $response = new StreamedResponse(static function () use ($events, $format, $exporter): void {
            $out = fopen('php://output', 'wb');
            foreach ($events as $event) {
                fwrite($out, $exporter->line($event, $format)."\n");
            }
            fclose($out);
        });
        $response->headers->set('Content-Type', 'text/plain; charset=UTF-8');
        $response->headers->set('Content-Disposition', \sprintf('attachment; filename="audit-%s.%s"', date('Y-m-d'), AuditExporter::FORMAT_CEF === $format ? 'cef' : 'jsonl'));

        return $response;
    }

    /**
     * @return array{q: ?string, level: ?string, actor: ?string, from: ?\DateTimeImmutable, to: ?\DateTimeImmutable}
     */
    private static function filters(Request $request): array
    {
        $date = static function (string $value, bool $endOfDay): ?\DateTimeImmutable {
            $date = '' === $value ? false : \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

            return false === $date ? null : ($endOfDay ? $date->setTime(23, 59, 59) : $date);
        };

        return [
            'q' => trim((string) $request->query->get('q')) ?: null,
            'level' => \in_array($request->query->get('level'), AuditEvent::LEVELS, true) ? (string) $request->query->get('level') : null,
            'actor' => trim((string) $request->query->get('actor')) ?: null,
            'from' => $date(trim((string) $request->query->get('from')), false),
            'to' => $date(trim((string) $request->query->get('to')), true),
        ];
    }
}
