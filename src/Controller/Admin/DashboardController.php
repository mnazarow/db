<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Repository\DocumentEventRepository;
use App\Repository\SectionRepository;
use App\Service\StatsService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin')]
final class DashboardController extends AbstractController
{
    #[Route('', name: 'admin_dashboard', methods: ['GET'])]
    public function index(StatsService $stats, DocumentEventRepository $events, SectionRepository $sections): Response
    {
        $overview = $stats->overview();
        $byDay = $stats->activityByDay(30);

        return $this->render('admin/dashboard.html.twig', [
            'overview' => $overview,
            'chart' => StatsService::chartSeries($byDay),
            'top' => $stats->topDocuments(30, 8),
            'expiring' => $stats->expiring(null, 10),
            'recent_events' => $events->findRecent(12),
            'roots' => $sections->findRoots(),
        ]);
    }
}
