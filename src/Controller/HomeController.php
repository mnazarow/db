<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Document;
use App\Entity\Section;
use App\Entity\User;
use App\Repository\DocumentRepository;
use App\Repository\SectionRepository;
use App\Security\Access;
use App\Service\StatsService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

final class HomeController extends AbstractController
{
    public function __construct(
        private readonly SectionRepository $sections,
        private readonly DocumentRepository $documents,
        private readonly Access $access,
        private readonly StatsService $stats,
    ) {
    }

    /**
     * Главная: дерево разделов и недавние документы. Гость (без входа) видит только открытые
     * опубликованные документы; доступ гостей регулируется настройками (GuestAccessSubscriber).
     */
    #[Route('/', name: 'app_home', methods: ['GET'])]
    public function index(#[CurrentUser] ?User $user): Response
    {
        $guest = null === $user;
        $tree = $this->sections->findAllTree();
        $counts = self::subtreeCounts($tree, $this->documents->countPerSection($guest));
        $managedIds = $guest ? [] : $this->access->managedSectionIds($user);
        $isModerator = !$guest && $this->access->isModerator($user);

        return $this->render('home/index.html.twig', [
            'roots' => array_values(array_filter($tree, static fn (Section $s) => null === $s->getParent())),
            'tree' => $tree,
            'counts' => $counts,
            'recent' => $this->documents->findRecentPublished(8, $guest),
            'expiring' => $isModerator ? $this->stats->expiring($managedIds, 8) : [],
            'drafts' => $isModerator ? $this->documents->findDrafts($managedIds, 6) : [],
            'is_moderator' => $isModerator,
            'total_published' => $this->documents->countByStatus(null, $guest)[Document::STATUS_PUBLISHED],
        ]);
    }

    #[Route('/search', name: 'app_search', methods: ['GET'])]
    public function search(Request $request, #[CurrentUser] ?User $user): Response
    {
        $q = trim((string) $request->query->get('q', ''));
        $sectionId = (int) $request->query->get('section');
        $section = $sectionId > 0 ? $this->sections->find($sectionId) : null;
        $results = [];
        if (mb_strlen($q) >= 2) {
            $statuses = null !== $user && $this->access->isModerator($user) ? Document::STATUSES : [Document::STATUS_PUBLISHED];
            $results = $this->documents->search($q, $statuses, $section, 100, null === $user);
            // Черновики и архив видны только тем, кто управляет разделом; гостям — только открытые документы.
            $results = array_values(array_filter($results, fn (Document $d) => $this->access->canViewDocument($user, $d)));
        }

        return $this->render('home/search.html.twig', [
            'q' => $q,
            'section' => $section,
            'tree' => $this->sections->findAllTree(),
            'results' => $results,
        ]);
    }

    /**
     * Суммирует число опубликованных документов и всех документов по поддеревьям.
     *
     * @param list<Section>                    $tree
     * @param array<int, array<string, int>>   $perSection
     *
     * @return array<int, array{published: int, total: int, children: int}>
     */
    public static function subtreeCounts(array $tree, array $perSection): array
    {
        $out = [];
        foreach ($tree as $s) {
            $out[$s->getId()] = ['published' => 0, 'total' => 0, 'children' => $s->getChildren()->count()];
        }
        foreach ($tree as $s) {
            $own = $perSection[$s->getId()] ?? [];
            $published = $own[Document::STATUS_PUBLISHED] ?? 0;
            $total = array_sum($own);
            foreach ($s->getPathIds() as $ancestorId) {
                if (isset($out[$ancestorId])) {
                    $out[$ancestorId]['published'] += $published;
                    $out[$ancestorId]['total'] += $total;
                }
            }
        }

        return $out;
    }
}
