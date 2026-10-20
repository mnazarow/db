<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\SlideTemplate;
use App\Entity\User;
use App\Repository\SlideTemplateRepository;
use App\Service\Presentation\SlideLibrary;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * «Подготовка презентаций» → «Библиотека слайдов»: фирменные слайды-шаблоны. Смотрят и вставляют все
 * сотрудники (в редакторе — галерея «Новый слайд» → «Библиотека»), ведут маркетологи и помощники маркетолога.
 */
#[Route('/presentations/library')]
#[IsGranted('IS_AUTHENTICATED')]
final class SlideLibraryController extends AbstractController
{
    private const CSRF = 'slide_library';

    public function __construct(
        private readonly SlideLibrary $library,
        private readonly SlideTemplateRepository $templates,
    ) {
    }

    #[Route('', name: 'app_slide_library', methods: ['GET'])]
    public function index(#[CurrentUser] User $user): Response
    {
        $groups = [];
        foreach ($this->library->all() as $t) {
            $groups[$t->getCategory()][] = $t;
        }

        return $this->render('presentation/library.html.twig', [
            'groups' => $groups,
            'categories' => $this->library->categories(),
            'can_manage' => $this->library->canManage($user),
        ]);
    }

    /** Слайды библиотеки для редактора. */
    #[Route('.json', name: 'app_slide_library_json', methods: ['GET'])]
    public function list(#[CurrentUser] User $user): JsonResponse
    {
        return $this->json([
            'ok' => true,
            'templates' => $this->library->forEditor(),
            'categories' => $this->library->categories(),
            'canManage' => $this->library->canManage($user),
        ]);
    }

    /** Сохранить слайд в библиотеку (из редактора, JSON). */
    #[Route('', name: 'app_slide_library_create', methods: ['POST'])]
    public function create(Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $data = $this->payload($request);
        try {
            $t = $this->library->create($user, (string) ($data['title'] ?? ''), (string) ($data['category'] ?? ''), $data['slide'] ?? null, isset($data['description']) ? (string) $data['description'] : null);
        } catch (\DomainException $e) {
            return $this->json(['ok' => false, 'error' => $e->getMessage()], 403);
        } catch (\InvalidArgumentException $e) {
            return $this->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }

        return $this->json(['ok' => true, 'template' => $this->library->serialize($t), 'message' => \sprintf('Слайд «%s» сохранён в библиотеку («%s»).', $t->getTitle(), $t->getCategory())]);
    }

    /** Вставили слайд в презентацию — учёт для «часто используемых». */
    #[Route('/{id}/use', name: 'app_slide_library_use', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function use(SlideTemplate $template, Request $request): JsonResponse
    {
        $this->payload($request);
        $this->library->used($template);

        return $this->json(['ok' => true]);
    }

    /** Правка названия, категории и описания (страница библиотеки). */
    #[Route('/{id}', name: 'app_slide_library_update', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function update(SlideTemplate $template, Request $request, #[CurrentUser] User $user): Response
    {
        $this->checkForm($request);
        try {
            $this->library->update($user, $template, (string) $request->request->get('title', ''), (string) $request->request->get('category', ''), (string) $request->request->get('description', ''));
            $this->addFlash('success', \sprintf('Слайд «%s» сохранён.', $template->getTitle()));
        } catch (\DomainException $e) {
            throw $this->createAccessDeniedException($e->getMessage());
        } catch (\InvalidArgumentException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('app_slide_library', ['_fragment' => 'tpl-'.$template->getId()]);
    }

    #[Route('/{id}/move', name: 'app_slide_library_move', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function move(SlideTemplate $template, Request $request, #[CurrentUser] User $user): Response
    {
        $this->checkForm($request);
        try {
            $this->library->move($user, $template, (int) $request->request->get('direction', 1));
        } catch (\DomainException $e) {
            throw $this->createAccessDeniedException($e->getMessage());
        }

        return $this->redirectToRoute('app_slide_library', ['_fragment' => 'tpl-'.$template->getId()]);
    }

    #[Route('/{id}/delete', name: 'app_slide_library_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(SlideTemplate $template, Request $request, #[CurrentUser] User $user): Response
    {
        $this->checkForm($request);
        $title = $template->getTitle();
        try {
            $this->library->delete($user, $template);
        } catch (\DomainException $e) {
            throw $this->createAccessDeniedException($e->getMessage());
        }
        $this->addFlash('success', \sprintf('Слайд «%s» удалён из библиотеки. В презентациях, куда его вставили, он остаётся.', $title));

        return $this->redirectToRoute('app_slide_library');
    }

    private function checkForm(Request $request): void
    {
        if (!$this->isCsrfTokenValid(self::CSRF, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Неверный CSRF-токен — обновите страницу.');
        }
    }

    /** @return array<string, mixed> тело JSON-запроса редактора (токен — в заголовке X-CSRF-Token) */
    private function payload(Request $request): array
    {
        if (!$this->isCsrfTokenValid(self::CSRF, (string) $request->headers->get('X-CSRF-Token'))) {
            throw $this->createAccessDeniedException('Неверный CSRF-токен — обновите страницу.');
        }
        $data = json_decode($request->getContent(), true);

        return \is_array($data) ? $data : [];
    }
}
