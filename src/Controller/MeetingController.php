<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Meeting;
use App\Entity\Presentation;
use App\Entity\User;
use App\Repository\MeetingRepository;
use App\Repository\PresentationRepository;
use App\Security\Voter\PresentationVoter;
use App\Service\Presentation\BrandAssets;
use App\Service\Presentation\MeetingService;
use App\Service\Presentation\PresentationAccess;
use App\Service\Presentation\PresentationService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Раздел «Подготовка пятничной презентации»: каждую неделю — собрание (№N, пятница). Сотрудники готовят
 * к нему темы (слайды в корпоративном стиле) и отправляют на модерацию; маркетологи и помощники
 * одобряют темы, задают порядок, публикуют собрание. Общая презентация собирается автоматически:
 * титульный слайд, «Содержание», слайды тем и финальный слайд; её можно показать, выгрузить в
 * PowerPoint или распечатать в PDF.
 */
#[Route('/meetings')]
#[IsGranted('ROLE_USER')]
final class MeetingController extends AbstractController
{
    public function __construct(
        private readonly MeetingRepository $meetings,
        private readonly PresentationRepository $presentations,
        private readonly MeetingService $service,
        private readonly PresentationService $presentationService,
        private readonly PresentationAccess $access,
        private readonly PresentationController $presentationController,
    ) {
    }

    #[Route('', name: 'app_meetings', methods: ['GET'])]
    public function index(#[CurrentUser] User $user): Response
    {
        $moderator = $this->access->isModerator($user);
        $upcoming = $this->service->upcoming($user);
        $topics = $this->presentations->findTopics($upcoming);

        return $this->render('meeting/index.html.twig', [
            'meeting' => $upcoming,
            'stats' => self::stats($topics),
            'my_topics' => $this->presentations->findByAuthor($user, Presentation::KIND_TOPIC, 30),
            'queue' => $moderator ? $this->presentations->findModerationQueue(Presentation::KIND_TOPIC) : [],
            'recent' => array_values(array_filter(
                $this->meetings->findRecent(30, !$moderator),
                static fn (Meeting $m): bool => $m->getId() !== $upcoming->getId(),
            )),
            'is_moderator' => $moderator,
            'my_presentations' => $this->presentations->findByAuthor($user, Presentation::KIND_GENERAL, 50),
        ]);
    }

    #[Route('/{id}', name: 'app_meeting_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(Meeting $meeting, #[CurrentUser] User $user): Response
    {
        $moderator = $this->access->isModerator($user);
        $this->denyUnlessCanSee($meeting, $user);
        $topics = $this->presentations->findTopics($meeting);
        $visible = $moderator ? $topics : array_values(array_filter(
            $topics,
            fn (Presentation $t): bool => $t->isAuthor($user) || ($meeting->isPublished() && $t->hasApprovedVersion()),
        ));
        $approved = array_values(array_filter($topics, static fn (Presentation $t): bool => $t->hasApprovedVersion()));
        $showDeck = $moderator || $meeting->isPublished();

        return $this->render('meeting/show.html.twig', [
            'meeting' => $meeting,
            'topics' => $visible,
            'stats' => self::stats($topics),
            'approved_count' => \count($approved),
            'is_moderator' => $moderator,
            'can_add' => $this->canAddTopic($meeting, $user),
            'deck' => $showDeck ? $this->service->assemble($meeting) : null,
            'deck_config' => [
                'brand' => BrandAssets::forEditor(),
                'assetBase' => $this->generateUrl('app_presentation_asset', ['token' => '_T_', 'ext' => 'img']),
                'qr' => $this->generateUrl('app_presentation_qr'),
                'present' => $this->generateUrl('app_meeting_present', ['id' => $meeting->getId()]),
            ],
            'my_presentations' => $this->presentations->findByAuthor($user, Presentation::KIND_GENERAL, 50),
            'next_meeting' => $this->meetings->findOneByDate($meeting->getDate()->modify('+7 days')),
        ]);
    }

    /** Новая тема собрания — сразу в редактор. */
    #[Route('/{id}/topics', name: 'app_meeting_topic_new', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function addTopic(Request $request, Meeting $meeting, #[CurrentUser] User $user): Response
    {
        $this->checkCsrf($request, 'meeting_topic_'.$meeting->getId());
        if (!$this->canAddTopic($meeting, $user)) {
            $this->addFlash('danger', $meeting->isPublished() ? 'Собрание уже опубликовано — новые темы добавляют маркетологи.' : 'Собрание прошло — подготовьте тему к следующему собранию.');

            return $this->redirectToRoute('app_meeting_show', ['id' => $meeting->getId()]);
        }
        $title = trim((string) $request->request->get('title', ''));
        if ('' === $title) {
            $this->addFlash('danger', 'Укажите название темы.');

            return $this->redirectToRoute('app_meeting_show', ['id' => $meeting->getId()]);
        }
        $source = (int) $request->request->get('source', 0);
        if ($source > 0) {
            $from = $this->presentations->find($source);
            if (null === $from || !$this->isGranted(PresentationVoter::VIEW, $from)) {
                throw $this->createNotFoundException();
            }
            $topic = $this->presentationService->duplicate($from, $user, $meeting);
            $topic->setTitle($title)->setResponsible(trim((string) $request->request->get('responsible', '')) ?: null);
            // Шапка слайдов подхватит новое название при первом сохранении в редакторе.
            $deck = $this->presentationService->deck($topic);
            $this->presentationService->save($topic, $deck, $topic->getRevision(), $user, true);
        } else {
            $topic = $this->presentationService->create(Presentation::KIND_TOPIC, $title, trim((string) $request->request->get('responsible', '')) ?: null, $user, $meeting);
            $file = $request->files->get('pptx');
            if ($file instanceof UploadedFile) {
                $this->presentationController->importInto($topic, $file, $user);
            }
        }
        if ($meeting->isDeadlinePassed() && !$this->access->isModerator($user)) {
            $this->addFlash('info', 'Срок подачи тем прошёл: маркетологи рассмотрят тему, если успеют до собрания.');
        }

        return $this->redirectToRoute('app_presentation_edit', ['id' => $topic->getId()]);
    }

    /** Порядок тем в общей презентации (список id через запятую — из перетаскивания или кнопок ↑↓). */
    #[Route('/{id}/order', name: 'app_meeting_order', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function order(Request $request, Meeting $meeting, #[CurrentUser] User $user): Response
    {
        $this->checkCsrf($request, 'meeting_'.$meeting->getId());
        $this->denyUnlessModerator($user);
        $ids = array_values(array_filter(array_map('intval', explode(',', (string) $request->request->get('order', ''))), static fn (int $i): bool => $i > 0));
        $move = (string) $request->request->get('move', '');
        if ('' !== $move && 1 === preg_match('/^(\d+):(up|down)$/', $move, $m)) {
            $ids = array_map(static fn (Presentation $t): int => (int) $t->getId(), $this->presentations->findTopics($meeting));
            $i = array_search((int) $m[1], $ids, true);
            $j = false === $i ? false : ('up' === $m[2] ? $i - 1 : $i + 1);
            if (false !== $i && false !== $j && isset($ids[$j])) {
                [$ids[$i], $ids[$j]] = [$ids[$j], $ids[$i]];
            }
        }
        $this->service->reorder($meeting, $ids);
        if ('json' === $request->getPreferredFormat() || $request->isXmlHttpRequest()) {
            return new JsonResponse(['ok' => true]);
        }
        $this->addFlash('success', 'Порядок тем сохранён.');

        return $this->redirectToRoute('app_meeting_show', ['id' => $meeting->getId(), '_fragment' => 'topics']);
    }

    #[Route('/{id}/settings', name: 'app_meeting_settings', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function settings(Request $request, Meeting $meeting, #[CurrentUser] User $user): Response
    {
        $this->checkCsrf($request, 'meeting_'.$meeting->getId());
        $this->denyUnlessModerator($user);
        $number = (int) $request->request->get('number', 0);
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $request->request->get('date', ''));
        $deadlineRaw = trim((string) $request->request->get('deadline', ''));
        $deadline = '' !== $deadlineRaw ? (\DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $deadlineRaw) ?: null) : null;
        if ($number < 1 || $number > 100000 || false === $date) {
            $this->addFlash('danger', 'Укажите номер собрания и дату.');
        } else {
            try {
                $this->service->update($meeting, $number, $date, trim((string) $request->request->get('title', '')) ?: null, $deadline);
                $this->addFlash('success', 'Собрание сохранено.');
            } catch (\InvalidArgumentException $e) {
                $this->addFlash('danger', $e->getMessage());
            }
        }

        return $this->redirectToRoute('app_meeting_show', ['id' => $meeting->getId()]);
    }

    #[Route('/{id}/publish', name: 'app_meeting_publish', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function publish(Request $request, Meeting $meeting, #[CurrentUser] User $user): Response
    {
        $this->checkCsrf($request, 'meeting_'.$meeting->getId());
        $this->denyUnlessModerator($user);
        if ([] === $this->presentations->findApprovedTopics($meeting)) {
            $this->addFlash('danger', 'Нет одобренных тем — публиковать нечего.');
        } else {
            $this->service->publish($meeting, $user);
            $this->addFlash('success', 'Собрание опубликовано: сотрудники видят общую презентацию.');
        }

        return $this->redirectToRoute('app_meeting_show', ['id' => $meeting->getId()]);
    }

    #[Route('/{id}/unpublish', name: 'app_meeting_unpublish', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function unpublish(Request $request, Meeting $meeting, #[CurrentUser] User $user): Response
    {
        $this->checkCsrf($request, 'meeting_'.$meeting->getId());
        $this->denyUnlessModerator($user);
        $this->service->unpublish($meeting, $user);
        $this->addFlash('info', 'Публикация снята — презентацию собрания снова видят только маркетологи.');

        return $this->redirectToRoute('app_meeting_show', ['id' => $meeting->getId()]);
    }

    /** Подготовить следующее собрание (через неделю). */
    #[Route('/{id}/next', name: 'app_meeting_next', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function next(Request $request, Meeting $meeting, #[CurrentUser] User $user): Response
    {
        $this->checkCsrf($request, 'meeting_'.$meeting->getId());
        $this->denyUnlessModerator($user);
        $next = $this->service->createNext($meeting, $user);

        return $this->redirectToRoute('app_meeting_show', ['id' => $next->getId()]);
    }

    #[Route('/{id}/present', name: 'app_meeting_present', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function present(Request $request, Meeting $meeting, #[CurrentUser] User $user): Response
    {
        $this->denyUnlessCanSeeDeck($meeting, $user);

        return $this->render('presentation/present.html.twig', [
            'title' => $meeting->getDisplayTitle().' — '.$meeting->getDateLabel(),
            'deck' => $this->service->assemble($meeting),
            'start' => max(0, (int) $request->query->get('slide', 1) - 1),
            'back' => $this->generateUrl('app_meeting_show', ['id' => $meeting->getId()]),
            'speaker' => $request->query->getBoolean('speaker'),
        ]);
    }

    #[Route('/{id}/print', name: 'app_meeting_print', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function print(Meeting $meeting, #[CurrentUser] User $user): Response
    {
        $this->denyUnlessCanSeeDeck($meeting, $user);

        return $this->render('presentation/print.html.twig', [
            'title' => $meeting->getDisplayTitle().' '.$meeting->getDateLabel(),
            'deck' => $this->service->assemble($meeting),
        ]);
    }

    #[Route('/{id}/export.pptx', name: 'app_meeting_export', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function export(Meeting $meeting, #[CurrentUser] User $user): Response
    {
        $this->denyUnlessCanSeeDeck($meeting, $user);

        return $this->presentationController->pptxResponse(
            $this->service->assemble($meeting),
            'Презентация к собранию №'.$meeting->getNumber().' '.$meeting->getDate()->format('d.m.Y'),
            $user->getDisplayName(),
        );
    }

    // ---- Помощники ------------------------------------------------------------------------------

    /**
     * @param list<Presentation> $topics
     *
     * @return array{total: int, approved: int, submitted: int, draft: int, rejected: int, changed: int}
     */
    public static function stats(array $topics): array
    {
        $s = ['total' => \count($topics), 'approved' => 0, 'submitted' => 0, 'draft' => 0, 'rejected' => 0, 'changed' => 0];
        foreach ($topics as $t) {
            if ($t->isSubmitted()) {
                ++$s['submitted'];
            } elseif ($t->isRejected()) {
                ++$s['rejected'];
            } elseif ($t->isApproved()) {
                ++$s['approved'];
            } else {
                ++$s['draft'];
            }
            if ($t->hasApprovedVersion() && !$t->isApproved()) {
                ++$s['changed'];
            }
        }

        return $s;
    }

    private function canAddTopic(Meeting $meeting, User $user): bool
    {
        if ($this->access->isModerator($user)) {
            return true;
        }

        return !$meeting->isPublished() && !$meeting->isPast();
    }

    private function denyUnlessCanSee(Meeting $meeting, User $user): void
    {
        if ($this->access->isModerator($user) || $meeting->isPublished() || !$meeting->isPast()) {
            return;
        }
        foreach ($this->presentations->findTopics($meeting) as $t) {
            if ($t->isAuthor($user)) {
                return;
            }
        }
        throw $this->createAccessDeniedException('Презентация этого собрания не опубликована.');
    }

    private function denyUnlessCanSeeDeck(Meeting $meeting, User $user): void
    {
        if (!$this->access->isModerator($user) && !$meeting->isPublished()) {
            throw $this->createAccessDeniedException('Презентация собрания ещё не опубликована — её видят маркетологи.');
        }
    }

    private function denyUnlessModerator(User $user): void
    {
        if (!$this->access->isModerator($user)) {
            throw $this->createAccessDeniedException('Собрания ведут маркетологи и помощники маркетолога.');
        }
    }

    private function checkCsrf(Request $request, string $id): void
    {
        $token = (string) ($request->request->get('_token') ?? $request->headers->get('X-CSRF-Token', ''));
        if (!$this->isCsrfTokenValid($id, $token)) {
            throw $this->createAccessDeniedException('Неверный CSRF-токен.');
        }
    }
}
