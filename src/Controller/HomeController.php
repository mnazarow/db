<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Document;
use App\Entity\Section;
use App\Entity\User;
use App\Repository\DocumentAcknowledgementRepository;
use App\Repository\DocumentRepository;
use App\Repository\PriceItemRepository;
use App\Repository\SectionRepository;
use App\Security\Access;
use App\Service\Llm\AnswerService;
use App\Service\Material\MaterialSummary;
use App\Service\StatsService;
use App\Service\Text\SearchQuery;
use App\Service\Video\VideoApi;
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
        private readonly DocumentAcknowledgementRepository $acknowledgements,
        private readonly Access $access,
        private readonly StatsService $stats,
        private readonly ?VideoApi $videoApi = null,
        private readonly ?AnswerService $answers = null,
        private readonly ?PriceItemRepository $priceItems = null,
    ) {
    }

    /**
     * Главная: дерево разделов и недавние документы. Гость (без входа) видит только открытые
     * опубликованные документы; доступ гостей регулируется настройками (GuestAccessSubscriber).
     */
    #[Route('/', name: 'app_home', methods: ['GET'])]
    public function index(#[CurrentUser] ?User $user, MaterialSummary $materialSummary): Response
    {
        $guest = null === $user;
        $tree = $this->sections->findAllTree();
        $viewer = $this->access->viewer($user);
        $counts = self::subtreeCounts($tree, $this->documents->countPerSection($guest, $viewer));
        $managedIds = $guest ? [] : $this->access->managedSectionIds($user);
        $isModerator = !$guest && $this->access->isModerator($user);
        // Помощник маркетолога видит черновики всех разделов: он их готовит к модерации.
        $isAssistant = !$guest && !$isModerator && $this->access->isAssistant($user);

        // Подразделы берутся из уже загруженного дерева: обращение к $section->getChildren() в шаблоне
        // догружало бы каждую коллекцию отдельным запросом.
        $children = [];
        foreach ($tree as $section) {
            if (null !== $section->getParent()) {
                $children[$section->getParent()->getId()][] = $section;
            }
        }

        return $this->render('home/index.html.twig', [
            'roots' => array_values(array_filter($tree, static fn (Section $s) => null === $s->getParent())),
            'children' => $children,
            'tree' => $tree,
            'counts' => $counts,
            'recent' => $this->documents->findRecentPublished(8, $guest, $viewer),
            'expiring' => $isModerator ? $this->stats->expiring($managedIds, 8) : [],
            'drafts' => $isModerator ? $this->documents->findDrafts($managedIds, 6) : ($isAssistant ? $this->documents->findDrafts(null, 6) : []),
            'is_moderator' => $isModerator,
            'is_assistant' => $isAssistant,
            'total_published' => $this->documents->countByStatus(null, $guest, $viewer)[Document::STATUS_PUBLISHED],
            // Что сотруднику нужно прочитать под подпись.
            'to_acknowledge' => $guest ? [] : $this->acknowledgements->findPendingForUser($user, 6, $viewer),
            'today' => new \DateTimeImmutable('today'),
            // Счётчики таблиц материалов на карточках разделов — для тех, кто ведёт материалы этих разделов.
            'materials' => $guest ? [] : $this->materialCounters($user, $tree, $materialSummary),
        ]);
    }

    /**
     * @param list<Section> $tree
     *
     * @return array<int, array<string, mixed>> id раздела верхнего уровня => сводная его таблицы материалов
     */
    private function materialCounters(User $user, array $tree, MaterialSummary $summary): array
    {
        if (!$this->access->isContributor($user)) {
            return [];
        }
        $visible = [];
        foreach ($tree as $section) {
            if (null === $section->getParent() && $section->isMaterialsEnabled()
                && ($this->access->isAssistant($user) || $this->access->canManageSection($user, $section))) {
                $visible[(int) $section->getId()] = true;
            }
        }

        return [] === $visible ? [] : array_intersect_key($summary->bySection(), $visible);
    }

    #[Route('/search', name: 'app_search', methods: ['GET'])]
    public function search(Request $request, #[CurrentUser] ?User $user): Response
    {
        $q = trim((string) $request->query->get('q', ''));
        $sectionId = (int) $request->query->get('section');
        $section = $sectionId > 0 ? $this->sections->find($sectionId) : null;
        $results = [];
        $content = [];
        $videos = null;
        $prices = [];
        $query = SearchQuery::parse($q);
        if (mb_strlen($q) >= 2) {
            $statuses = null !== $user && $this->access->isContributor($user) ? Document::STATUSES : [Document::STATUS_PUBLISHED];
            $viewer = $this->access->viewer($user);
            $results = $this->documents->search($q, $statuses, $section, 100, null === $user, $viewer);
            // Черновики и архив видны только тем, кто управляет разделом; гостям — только открытые документы.
            $results = array_values(array_filter($results, fn (Document $d) => $this->access->canViewDocument($user, $d)));
            // Дополнительно — поиск по содержимому файлов (индекс document_text): документы, которые
            // не нашлись по названию и реквизитам, показываем отдельным блоком с фрагментом текста.
            $found = array_map(static fn (Document $d): int => (int) $d->getId(), $results);
            foreach ($this->documents->searchContent($query, $statuses, $section, 30, null === $user, $viewer) as $hit) {
                if (\in_array((int) $hit['document']->getId(), $found, true) || !$this->access->canViewDocument($user, $hit['document'])) {
                    continue;
                }
                $content[] = ['document' => $hit['document'], 'snippet' => $query->snippet($hit['text'])];
            }
            // Раздел «Видео»: видео с этими словами в названии, описании, тегах или расшифровке речи (у видео
            // нет разделов портала — при фильтре по разделу не ищем). Видеопортал не ответил — блока нет.
            if (null === $section && null !== $this->videoApi) {
                $videos = $this->videoApi->search($q, null !== $user && $user->isActive(), 6);
            }
            // Раздел «Прайс-листы»: позиции действующих прайс-листов с этими словами в артикуле или названии.
            if (null === $section && null !== $this->priceItems) {
                try {
                    $prices = $this->priceItems->searchCurrent($q, null === $user, 11);
                } catch (\Throwable) {
                    $prices = []; // таблиц прайс-листов ещё нет (миграция не применена)
                }
            }
        }

        return $this->render('home/search.html.twig', [
            'q' => $q,
            'section' => $section,
            'tree' => $this->sections->findAllTree(),
            'results' => $results,
            'content_results' => $content,
            'video_results' => $videos,
            'price_results' => $prices,
            // Ответ ИИ — только вошедшим сотрудникам и только если ответы включены в «Интеграциях».
            'ai_answers' => null !== $user && null !== $this->answers && $this->answers->isAvailable(),
            'ai_model' => null !== $this->answers && $this->answers->isEnabled(),
            'ai_auto' => (bool) preg_match('/\?\s*$|^(как|что|где|когда|кто|почему|зачем|сколько|можно ли|нужно ли|какой|какая|какие)\b/iu', $q),
            // «Похоже по смыслу» — документы, близкие к запросу по смыслу (если включён смысловой индекс).
            'semantic_search' => null !== $user && mb_strlen($q) >= 3 && null !== $this->answers && $this->answers->isSemanticReady(),
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
            $out[$s->getId()] = ['published' => 0, 'total' => 0, 'children' => 0];
        }
        // Число подразделов — по уже загруженному дереву (getChildren()->count() делал бы запрос на каждый раздел).
        foreach ($tree as $s) {
            $parentId = $s->getParent()?->getId();
            if (null !== $parentId && isset($out[$parentId])) {
                ++$out[$parentId]['children'];
            }
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
