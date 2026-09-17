<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Document;
use App\Entity\DocumentTemplate;
use App\Entity\User;
use App\Repository\DocumentTemplateRepository;
use App\Repository\SectionRepository;
use App\Service\DocumentTemplateService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Шаблоны документов: заготовки карточки и текста с автонумерацией обозначений.
 */
#[Route('/admin/templates')]
#[IsGranted('ROLE_ADMIN')]
final class TemplateController extends AbstractController
{
    public function __construct(
        private readonly DocumentTemplateRepository $templates,
        private readonly DocumentTemplateService $service,
        private readonly SectionRepository $sections,
    ) {
    }

    #[Route('', name: 'admin_templates', methods: ['GET'])]
    public function index(): Response
    {
        $templates = $this->templates->findAllOrdered();
        $previews = [];
        foreach ($templates as $template) {
            $previews[(int) $template->getId()] = $this->service->preview($template);
        }

        return $this->render('admin/templates/index.html.twig', [
            'templates' => $templates,
            'previews' => $previews,
            'placeholders' => DocumentTemplate::PLACEHOLDERS,
        ]);
    }

    #[Route('/new', name: 'admin_template_new', methods: ['GET', 'POST'])]
    public function create(Request $request, #[CurrentUser] User $user): Response
    {
        return $this->form(new DocumentTemplate(), $request, $user);
    }

    #[Route('/{id}', name: 'admin_template_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(DocumentTemplate $template, Request $request, #[CurrentUser] User $user): Response
    {
        return $this->form($template, $request, $user);
    }

    #[Route('/{id}/delete', name: 'admin_template_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(DocumentTemplate $template, Request $request, #[CurrentUser] User $user): Response
    {
        if (!$this->isCsrfTokenValid('templates', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Неверный CSRF-токен.');
        }
        $this->service->delete($template, $user);
        $this->addFlash('success', 'Шаблон удалён.');

        return $this->redirectToRoute('admin_templates');
    }

    private function form(DocumentTemplate $template, Request $request, User $user): Response
    {
        $isNew = null === $template->getId();
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('templates', (string) $request->request->get('_token'))) {
                throw $this->createAccessDeniedException('Неверный CSRF-токен.');
            }
            $sectionId = (int) $request->request->get('section');
            $months = trim((string) $request->request->get('validity_months'));
            $template->setName((string) $request->request->get('name'))
                ->setDescription((string) $request->request->get('description'))
                ->setSection($sectionId > 0 ? $this->sections->find($sectionId) : null)
                ->setType((string) $request->request->get('type', Document::TYPE_FILE))
                ->setTitlePattern((string) $request->request->get('title_pattern'))
                ->setCodePattern((string) $request->request->get('code_pattern'))
                ->setBody((string) $request->request->get('body'))
                ->setTagsString((string) $request->request->get('tags'))
                ->setValidityMonths('' !== $months ? (int) $months : null)
                ->setPublic($request->request->getBoolean('public'))
                ->setActive($request->request->getBoolean('active'));
            $counter = trim((string) $request->request->get('counter'));
            if ('' !== $counter) {
                $template->setCounter((int) $counter, (int) date('Y'));
            }
            if ('' === $template->getName()) {
                $this->addFlash('error', 'Укажите название шаблона.');
            } else {
                $this->service->save($template, $user);
                $this->addFlash('success', $isNew ? 'Шаблон создан.' : 'Шаблон сохранён.');

                return $this->redirectToRoute('admin_templates');
            }
        }

        return $this->render('admin/templates/form.html.twig', [
            'template' => $template,
            'is_new' => $isNew,
            'tree' => $this->sections->findAllTree(),
            'placeholders' => DocumentTemplate::PLACEHOLDERS,
            'preview' => $this->service->preview($template),
        ]);
    }
}
