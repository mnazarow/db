<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Repository\SectionRepository;
use App\Service\StatsService;
use App\Service\Validity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Панель администратора: полная статистика по документам.
 */
#[Route('/admin/statistics')]
final class StatisticsController extends AbstractController
{
    private const PERIODS = [7 => '7 дней', 30 => '30 дней', 90 => '90 дней', 365 => 'Год', 0 => 'Всё время'];

    public function __construct(
        private readonly StatsService $stats,
        private readonly SectionRepository $sections,
        private readonly Validity $validity,
    ) {
    }

    #[Route('', name: 'admin_statistics', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $period = (int) ($request->query->get('period') ?: 30);
        if (!isset(self::PERIODS[$period])) {
            $period = 30;
        }
        $days = 0 === $period ? null : $period;

        return $this->render('admin/statistics/index.html.twig', [
            'period' => $period,
            'periods' => self::PERIODS,
            'overview' => $this->stats->overview(),
            'chart_days' => StatsService::chartSeries($this->stats->activityByDay(min(max($period, 14), 90) ?: 90)),
            'chart_months' => StatsService::chartSeries($this->stats->activityByMonth(12)),
            'top' => $this->stats->topDocuments($days, 15),
            'per_section' => $this->stats->perSection($days),
            'users' => $this->stats->userActivity($days, 30),
            'expiring' => $this->stats->expiring(null, 50),
            'stale' => $this->stats->stale(180, 15),
        ]);
    }

    /**
     * Актуальность по дереву разделов: просроченные и истекающие документы в каждом разделе
     * (с подразделами), список устаревших документов и сводка по ответственным.
     */
    #[Route('/validity', name: 'admin_statistics_validity', methods: ['GET'])]
    public function validity(Request $request): Response
    {
        $rootId = (int) $request->query->get('section');
        $root = $rootId > 0 ? $this->sections->find($rootId) : null;
        $onlyProblems = filter_var($request->query->get('only_problems'), \FILTER_VALIDATE_BOOL);
        $tree = $this->stats->validityBySection($root, $onlyProblems);
        $outdated = $this->stats->outdatedDocuments($root);

        return $this->render('admin/statistics/validity.html.twig', [
            'tree' => $tree['rows'],
            'totals' => $tree['totals'],
            'today' => $tree['today'],
            'soon_days' => $tree['soon_days'],
            'root' => $root,
            'only_problems' => $onlyProblems,
            'all_sections' => $this->sections->findAllTree(),
            'documents' => $outdated['documents'],
            'owners' => $outdated['owners'],
        ]);
    }

    #[Route('/validity.csv', name: 'admin_statistics_validity_csv', methods: ['GET'])]
    public function validityCsv(Request $request): Response
    {
        $rootId = (int) $request->query->get('section');
        $root = $rootId > 0 ? $this->sections->find($rootId) : null;
        $today = $this->validity->today();
        $rows = [];
        foreach ($this->stats->outdatedDocuments($root)['documents'] as $d) {
            $days = $d->getDaysLeft($today) ?? 0;
            $rows[] = [
                $d->getId(), $d->getCode(), $d->getTitle(), $d->getSection()->getFullName(), $d->getValidUntil()?->format('d.m.Y'),
                $days < 0 ? 'просрочен' : 'истекает', abs($days), $d->getOwner()?->getDisplayName(),
                implode(', ', array_map(static fn ($u) => $u->getDisplayName(), $d->getSection()->getModeratorUsers())),
                $d->getCurrentVersion()?->getNumber(), $d->getUpdatedAt()->format('d.m.Y'), $d->getLastViewedAt()?->format('d.m.Y'), $d->getViewCount(),
            ];
        }

        return $this->csv('validity-'.date('Y-m-d').'.csv', ['ID', 'Обозначение', 'Название', 'Раздел', 'Актуален до', 'Состояние', 'Дней', 'Ответственный', 'Модераторы раздела', 'Версия', 'Обновлён', 'Последний просмотр', 'Просмотры'], $rows);
    }

    #[Route('/sections.csv', name: 'admin_statistics_sections_csv', methods: ['GET'])]
    public function sectionsCsv(Request $request): Response
    {
        $period = (int) ($request->query->get('period') ?: 30);
        $rows = $this->stats->perSection(0 === $period ? null : $period);

        return $this->csv('statistics-sections-'.date('Y-m-d').'.csv', ['Раздел', 'Уровень', 'Документов (с подразделами)', 'Опубликовано', 'Черновиков', 'В архиве', 'Просмотры', 'Скачивания', 'Просрочено', 'Истекает', 'Модераторов'], array_map(static fn (array $r) => [
            $r['section']->getFullName(), $r['section']->getDepth() + 1, array_sum($r['subtree']), $r['subtree']['published'], $r['subtree']['draft'], $r['subtree']['archived'],
            $r['views'], $r['downloads'], $r['expired'], $r['soon'], $r['moderators'],
        ], $rows));
    }

    #[Route('/documents.csv', name: 'admin_statistics_documents_csv', methods: ['GET'])]
    public function documentsCsv(Request $request): Response
    {
        $period = (int) ($request->query->get('period') ?: 30);
        $rows = $this->stats->topDocuments(0 === $period ? null : $period, 1000);

        return $this->csv('statistics-documents-'.date('Y-m-d').'.csv', ['ID', 'Обозначение', 'Название', 'Раздел', 'Просмотры за период', 'Скачивания за период', 'Просмотры всего', 'Скачивания всего', 'Версий', 'Актуален до'], array_map(static fn (array $r) => [
            $r['document']->getId(), $r['document']->getCode(), $r['document']->getTitle(), $r['document']->getSection()->getFullName(),
            $r['views'], $r['downloads'], $r['document']->getViewCount(), $r['document']->getDownloadCount(), $r['document']->getVersionCount(), $r['document']->getValidUntil()?->format('d.m.Y'),
        ], $rows));
    }

    #[Route('/users.csv', name: 'admin_statistics_users_csv', methods: ['GET'])]
    public function usersCsv(Request $request): Response
    {
        $period = (int) ($request->query->get('period') ?: 30);
        $rows = $this->stats->userActivity(0 === $period ? null : $period, 1000);

        return $this->csv('statistics-users-'.date('Y-m-d').'.csv', ['Пользователь', 'Логин', 'Просмотры', 'Скачивания', 'Изменения', 'Последняя активность'], array_map(static fn (array $r) => [
            $r['name'], $r['username'], $r['views'], $r['downloads'], $r['changes'], $r['last']->format('d.m.Y H:i'),
        ], $rows));
    }

    /** @param list<list<mixed>> $rows */
    private function csv(string $filename, array $header, array $rows): StreamedResponse
    {
        $response = new StreamedResponse(static function () use ($header, $rows): void {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $header, ';');
            foreach ($rows as $row) {
                fputcsv($out, $row, ';');
            }
            fclose($out);
        });
        $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $response->headers->set('Content-Disposition', 'attachment; filename="'.$filename.'"');

        return $response;
    }
}
