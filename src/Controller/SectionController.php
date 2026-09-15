<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Document;
use App\Entity\Section;
use App\Entity\User;
use App\Form\SectionType;
use App\Repository\DocumentRepository;
use App\Repository\SectionRepository;
use App\Security\Access;
use App\Security\Voter\PortalVoter;
use App\Service\SectionManager;
use App\Service\StatsService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Разделы: просмотр, создание подразделов, изменение и удаление (для модераторов раздела и администраторов).
 */
#[Route('/sections')]
final class SectionController extends AbstractController
{
    public function __construct(
        private readonly SectionRepository $sections,
        private readonly DocumentRepository $documents,
        private readonly SectionManager $sectionManager,
        private readonly Access $access,
        private readonly StatsService $stats,
    ) {
    }

    #[Route('/{id}', name: 'app_section_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(Section $section, Request $request, #[CurrentUser] User $user): Response
    {
        $canManage = $this->access->canManageSection($user, $section);
        $filter = (string) $request->query->get('status', $canManage ? 'all' : 'published');
        $sort = (string) $request->query->get('sort', 'title');
        $statuses = match ($filter) {
            'draft' => [Document::STATUS_DRAFT],
            'archived' => [Document::STATUS_ARCHIVED],
            'all' => $canManage ? Document::STATUSES : [Document::STATUS_PUBLISHED],
            default => [Document::STATUS_PUBLISHED],
        };
        if (!$canManage) {
            $statuses = [Document::STATUS_PUBLISHED];
            $filter = 'published';
        }
        $tree = $this->sections->findAllTree();
        $counts = HomeController::subtreeCounts($tree, $this->documents->countPerSection());
        $byStatus = $this->documents->countByStatus([(int) $section->getId()]);

        return $this->render('section/show.html.twig', [
            'section' => $section,
            'children' => $section->getChildren(),
            'counts' => $counts,
            'documents' => $this->documents->findBySection($section, $statuses, $sort),
            'filter' => $filter,
            'sort' => $sort,
            'by_status' => $byStatus,
            'can_manage' => $canManage,
            'moderators' => $section->getModerators(),
            'expiring' => $canManage ? $this->stats->expiring($this->sections->findSubtreeIds($section), 5) : [],
        ]);
    }

    #[Route('/new', name: 'app_section_new_root', methods: ['GET', 'POST'])]
    #[IsGranted(User::ROLE_ADMIN)]
    public function newRoot(Request $request, #[CurrentUser] User $user): Response
    {
        return $this->createSection($request, $user, null);
    }

    #[Route('/{id}/new', name: 'app_section_new', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    #[IsGranted(PortalVoter::SECTION_MANAGE, subject: 'parent')]
    public function newChild(Section $parent, Request $request, #[CurrentUser] User $user): Response
    {
        return $this->createSection($request, $user, $parent);
    }

    private function createSection(Request $request, User $user, ?Section $parent): Response
    {
        $section = (new Section())->setParent($parent);
        $form = $this->createForm(SectionType::class, $section, [
            'parent_choices' => $this->access->managedSections($user),
            'allow_root' => $user->isAdmin(),
            'show_parent' => true,
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $chosenParent = $section->getParent();
            if (null === $chosenParent && !$user->isAdmin()) {
                $form->get('parent')->addError(new \Symfony\Component\Form\FormError('Выберите родительский раздел.'));
            } elseif (null !== $chosenParent && !$this->access->canManageSection($user, $chosenParent)) {
                $form->get('parent')->addError(new \Symfony\Component\Form\FormError('У вас нет прав на этот раздел.'));
            } else {
                try {
                    $created = $this->sectionManager->create($section->getName(), $chosenParent, $section->getDescription(), $user);
                    $this->addFlash('success', \sprintf('Раздел «%s» создан.', $created->getName()));

                    return $this->redirectToRoute('app_section_show', ['id' => $created->getId()]);
                } catch (\DomainException $e) {
                    $this->addFlash('danger', $e->getMessage());
                }
            }
        }

        return $this->render('section/form.html.twig', [
            'form' => $form,
            'section' => $section,
            'parent' => $parent,
            'is_new' => true,
        ]);
    }

    #[Route('/{id}/edit', name: 'app_section_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    #[IsGranted(PortalVoter::SECTION_MANAGE, subject: 'section')]
    public function edit(Section $section, Request $request, #[CurrentUser] User $user): Response
    {
        $oldParent = $section->getParent();
        $choices = array_values(array_filter(
            $this->access->managedSections($user),
            static fn (Section $s) => $s->getId() !== $section->getId() && !$s->isDescendantOf($section)
        ));
        // Модератор может менять родителя только в пределах своих разделов; корневой раздел может менять только администратор.
        $showParent = $user->isAdmin() || null !== $oldParent;
        $form = $this->createForm(SectionType::class, $section, [
            'parent_choices' => $choices,
            'allow_root' => $user->isAdmin(),
            'show_parent' => $showParent,
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $newParent = $section->getParent();
            if (null !== $newParent && !$this->access->canManageSection($user, $newParent)) {
                $form->get('parent')->addError(new \Symfony\Component\Form\FormError('У вас нет прав на выбранный родительский раздел.'));
            } else {
                try {
                    // update() сам сравнивает родителя с сохранённым, поэтому временно возвращаем старого.
                    $section->setParent($oldParent);
                    $this->sectionManager->update($section, $newParent, $user);
                    $this->addFlash('success', 'Раздел сохранён.');

                    return $this->redirectToRoute('app_section_show', ['id' => $section->getId()]);
                } catch (\DomainException $e) {
                    $this->addFlash('danger', $e->getMessage());
                }
            }
        }

        return $this->render('section/form.html.twig', [
            'form' => $form,
            'section' => $section,
            'parent' => $oldParent,
            'is_new' => false,
        ]);
    }

    #[Route('/{id}/delete', name: 'app_section_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted(PortalVoter::SECTION_MANAGE, subject: 'section')]
    public function delete(Section $section, Request $request, #[CurrentUser] User $user): Response
    {
        if (!$this->isCsrfTokenValid('section_'.$section->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Неверный CSRF-токен.');
        }
        $parent = $section->getParent();
        try {
            $name = $section->getName();
            $this->sectionManager->delete($section, $user);
            $this->addFlash('success', \sprintf('Раздел «%s» удалён.', $name));
        } catch (\DomainException $e) {
            $this->addFlash('danger', $e->getMessage());

            return $this->redirectToRoute('app_section_show', ['id' => $section->getId()]);
        }

        return null !== $parent ? $this->redirectToRoute('app_section_show', ['id' => $parent->getId()]) : $this->redirectToRoute('app_home');
    }

    #[Route('/{id}/move/{direction}', name: 'app_section_move', requirements: ['id' => '\d+', 'direction' => 'up|down'], methods: ['POST'])]
    #[IsGranted(PortalVoter::SECTION_MANAGE, subject: 'section')]
    public function move(Section $section, string $direction, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('section_'.$section->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Неверный CSRF-токен.');
        }
        $this->sectionManager->move($section, 'up' === $direction ? -1 : 1);
        $back = (string) $request->request->get('_back', '');

        return $this->redirect('' !== $back && str_starts_with($back, '/') ? $back : $this->generateUrl('app_section_show', ['id' => $section->getParent()?->getId() ?? $section->getId()]));
    }
}
