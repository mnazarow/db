<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\ConfluencePage;
use App\Repository\ConfluencePageRepository;
use App\Service\Import\Confluence\ConfluenceApi;
use App\Service\PortalSettings;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Ссылки из страниц, импортированных из Confluence: на другую страницу (в Confluence ссылка — по пространству и
 * названию) и на вложение. Ведут на импортированный документ, а если его ещё нет — на страницу в Confluence.
 * Права на документ проверяет уже страница документа.
 */
#[Route('/confluence')]
final class ConfluenceLinkController extends AbstractController
{
    public function __construct(
        private readonly ConfluencePageRepository $links,
        private readonly PortalSettings $settings,
    ) {
    }

    #[Route('/page', name: 'app_confluence_page', methods: ['GET'])]
    public function page(Request $request): Response
    {
        $space = (string) $request->query->get('space', '');
        $title = (string) $request->query->get('title', '');
        $mapping = '' !== $title ? $this->links->findPageByTitle($space, $title) : null;
        $target = $this->target($mapping);
        if (null !== $target) {
            return $target;
        }
        $base = $this->settings->confluence()['url'];
        if ('' !== $base && '' !== $space && '' !== $title) {
            try {
                [$url] = ConfluenceApi::normalizeUrl($base);

                return $this->redirect($url.'/display/'.rawurlencode($space).'/'.str_replace('%20', '+', rawurlencode($title)));
            } catch (\Throwable) {
            }
        }
        throw $this->createNotFoundException('Страница «'.$title.'» из Confluence в портал не импортирована.');
    }

    #[Route('/attachment', name: 'app_confluence_attachment', methods: ['GET'])]
    public function attachment(Request $request): Response
    {
        $page = (string) $request->query->get('page', '');
        $file = (string) $request->query->get('file', '');
        $target = $this->target('' !== $page && '' !== $file ? $this->links->findAttachment($page, $file) : null);
        if (null !== $target) {
            return $target;
        }
        // Вложение не импортировано (изображение, запрещённый тип) — страница, к которой оно приложено.
        foreach ($this->links->findBy(['kind' => ConfluencePage::KIND_PAGE, 'externalId' => $page]) as $mapping) {
            if (null !== ($target = $this->target($mapping))) {
                return $target;
            }
        }
        throw $this->createNotFoundException('Вложение «'.$file.'» из Confluence в портал не импортировано.');
    }

    private function target(?ConfluencePage $mapping): ?Response
    {
        if (null === $mapping) {
            return null;
        }
        if (null !== $mapping->getDocument()) {
            return $this->redirectToRoute('app_document_show', ['id' => $mapping->getDocument()->getId()]);
        }
        if (null !== $mapping->getSection()) {
            return $this->redirectToRoute('app_section_show', ['id' => $mapping->getSection()->getId()]);
        }

        return null !== $mapping->getWebUrl() && preg_match('~^https?://~i', $mapping->getWebUrl()) ? $this->redirect($mapping->getWebUrl()) : null;
    }
}
