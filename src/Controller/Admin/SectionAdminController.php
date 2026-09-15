<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Controller\HomeController;
use App\Entity\Section;
use App\Entity\SectionModerator;
use App\Entity\User;
use App\Form\SectionType;
use App\Repository\DocumentRepository;
use App\Repository\SectionModeratorRepository;
use App\Repository\SectionRepository;
use App\Repository\UserRepository;
use App\Service\SectionManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Панель администратора: дерево разделов и модераторы.
 */
#[Route('/admin/sections')]
final class SectionAdminController extends AbstractController
{
    public function __construct(
        private readonly SectionRepository $sections,
        private readonly DocumentRepository $documents,
        private readonly SectionModeratorRepository $moderators,
        private readonly UserRepository $users,
        private readonly SectionManager $manager,
    ) {
    }

    #[Route('', name: 'admin_sections', methods: ['GET'])]
    public function index(): Response
    {
        $tree = $this->sections->findAllTree();

        return $this->render('admin/sections/index.html.twig', [
            'tree' => $tree,
            'counts' => HomeController::subtreeCounts($tree, $this->documents->countPerSection()),
            'per_section' => $this->documents->countPerSection(),
        ]);
    }

    #[Route('/new', name: 'admin_section_new', methods: ['GET', 'POST'])]
    public function new(Request $request, #[CurrentUser] User $user): Response
    {
        $parentId = $request->query->getInt('parent');
        $parent = $parentId > 0 ? $this->sections->find($parentId) : null;
        $section = (new Section())->setParent($parent);
        $form = $this->createForm(SectionType::class, $section, ['parent_choices' => $this->sections->findAllTree(), 'allow_root' => true]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $created = $this->manager->create($section->getName(), $section->getParent(), $section->getDescription(), $user);
                $this->addFlash('success', \sprintf('Раздел «%s» создан.', $created->getName()));

                return $this->redirectToRoute('admin_sections');
            } catch (\DomainException $e) {
                $this->addFlash('danger', $e->getMessage());
            }
        }

        return $this->render('admin/sections/form.html.twig', ['form' => $form, 'section' => $section, 'is_new' => true]);
    }

    #[Route('/{id}/edit', name: 'admin_section_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(Section $section, Request $request, #[CurrentUser] User $user): Response
    {
        $oldParent = $section->getParent();
        $choices = array_values(array_filter($this->sections->findAllTree(), static fn (Section $s) => $s->getId() !== $section->getId() && !$s->isDescendantOf($section)));
        $form = $this->createForm(SectionType::class, $section, ['parent_choices' => $choices, 'allow_root' => true]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $newParent = $section->getParent();
            try {
                $section->setParent($oldParent);
                $this->manager->update($section, $newParent, $user);
                $this->addFlash('success', 'Раздел сохранён.');

                return $this->redirectToRoute('admin_sections');
            } catch (\DomainException $e) {
                $this->addFlash('danger', $e->getMessage());
            }
        }

        return $this->render('admin/sections/form.html.twig', ['form' => $form, 'section' => $section, 'is_new' => false]);
    }

    #[Route('/{id}/delete', name: 'admin_section_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(Section $section, Request $request, #[CurrentUser] User $user): Response
    {
        $this->checkToken($request, $section);
        try {
            $name = $section->getName();
            $this->manager->delete($section, $user);
            $this->addFlash('success', \sprintf('Раздел «%s» удалён.', $name));
        } catch (\DomainException $e) {
            $this->addFlash('danger', $e->getMessage());
        }

        return $this->redirectToRoute('admin_sections');
    }

    #[Route('/{id}/move/{direction}', name: 'admin_section_move', requirements: ['id' => '\d+', 'direction' => 'up|down'], methods: ['POST'])]
    public function move(Section $section, string $direction, Request $request): Response
    {
        $this->checkToken($request, $section);
        $this->manager->move($section, 'up' === $direction ? -1 : 1);

        return $this->redirectToRoute('admin_sections', ['_fragment' => 'section-'.$section->getId()]);
    }

    #[Route('/{id}/moderators', name: 'admin_section_moderators', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function moderators(Section $section, Request $request, #[CurrentUser] User $user): Response
    {
        if ($request->isMethod('POST')) {
            $this->checkToken($request, $section);
            $userId = $request->request->getInt('user_id');
            $target = $userId > 0 ? $this->users->find($userId) : null;
            if (null === $target) {
                $this->addFlash('danger', 'Выберите пользователя.');
            } else {
                try {
                    $this->manager->addModerator($section, $target, $user);
                    $this->addFlash('success', \sprintf('%s назначен(а) модератором раздела «%s».', $target->getDisplayName(), $section->getName()));
                } catch (\DomainException $e) {
                    $this->addFlash('danger', $e->getMessage());
                }
            }

            return $this->redirectToRoute('admin_section_moderators', ['id' => $section->getId()]);
        }

        $assignedIds = array_map(static fn (SectionModerator $m) => $m->getUser()->getId(), $section->getModerators()->toArray());
        $candidates = array_values(array_filter($this->users->findActiveOrdered(), static fn (User $u) => !\in_array($u->getId(), $assignedIds, true)));

        // Модераторы, унаследованные от родительских разделов.
        $inherited = [];
        foreach ($section->getBreadcrumbs() as $ancestor) {
            if ($ancestor->getId() === $section->getId()) {
                continue;
            }
            foreach ($ancestor->getModerators() as $m) {
                $inherited[] = ['moderator' => $m, 'section' => $ancestor];
            }
        }

        return $this->render('admin/sections/moderators.html.twig', [
            'section' => $section,
            'assignments' => $section->getModerators(),
            'inherited' => $inherited,
            'candidates' => $candidates,
        ]);
    }

    #[Route('/{id}/moderators/{moderatorId}/remove', name: 'admin_section_moderator_remove', requirements: ['id' => '\d+', 'moderatorId' => '\d+'], methods: ['POST'])]
    public function removeModerator(Section $section, int $moderatorId, Request $request, #[CurrentUser] User $user): Response
    {
        $this->checkToken($request, $section);
        $moderator = $this->moderators->find($moderatorId);
        if (null === $moderator || $moderator->getSection()->getId() !== $section->getId()) {
            throw $this->createNotFoundException();
        }
        $name = $moderator->getUser()->getDisplayName();
        $this->manager->removeModerator($moderator, $user);
        $this->addFlash('success', \sprintf('%s больше не модерирует раздел «%s».', $name, $section->getName()));

        return $this->redirectToRoute('admin_section_moderators', ['id' => $section->getId()]);
    }

    #[Route('/moderators', name: 'admin_moderators', methods: ['GET'])]
    public function allModerators(): Response
    {
        $byUser = [];
        foreach ($this->moderators->findAllWithRelations() as $m) {
            $byUser[$m->getUser()->getId()]['user'] = $m->getUser();
            $byUser[$m->getUser()->getId()]['sections'][] = $m;
        }
        usort($byUser, static fn ($a, $b) => strcmp(mb_strtolower($a['user']->getDisplayName()), mb_strtolower($b['user']->getDisplayName())));

        return $this->render('admin/sections/moderators_all.html.twig', ['by_user' => $byUser]);
    }

    private function checkToken(Request $request, Section $section): void
    {
        if (!$this->isCsrfTokenValid('section_'.$section->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Неверный CSRF-токен.');
        }
    }
}
