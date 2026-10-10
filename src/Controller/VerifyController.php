<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\DocumentAcknowledgement;
use App\Repository\SignatureSheetRepository;
use App\Security\Voter\PortalVoter;
use App\Service\Pdf\SignatureSheetService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Проверка подлинности листа ознакомления по коду с листа (QR-код ведёт сюда же).
 *
 * Страница открыта без входа и при выключенном гостевом доступе: проверяющему (аудитору, инспектору)
 * учётная запись не нужна. Код из 16 знаков (80 бит случайности) не подобрать перебором. Без входа видна
 * сводка — документ, редакция, когда и кем сформирован лист, совпадают ли записи; список сотрудников
 * показывается только тем, кто может редактировать документ.
 */
final class VerifyController extends AbstractController
{
    public function __construct(
        private readonly SignatureSheetRepository $sheets,
        private readonly SignatureSheetService $service,
    ) {
    }

    #[Route('/verify', name: 'app_verify_form', methods: ['GET'])]
    public function form(Request $request): Response
    {
        $input = trim((string) $request->query->get('code', ''));
        if ('' !== $input) {
            $code = SignatureSheetService::normalizeCode($input);
            if (null !== $code) {
                return $this->redirectToRoute('app_verify', ['code' => $code]);
            }
        }

        return $this->page('verify/form.html.twig', ['input' => $input, 'invalid' => '' !== $input], '' !== $input ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK);
    }

    #[Route('/verify/{code}', name: 'app_verify', requirements: ['code' => '[^/]{1,64}'], methods: ['GET'])]
    public function show(string $code): Response
    {
        $normalized = SignatureSheetService::normalizeCode($code);
        if (null === $normalized) {
            return $this->page('verify/form.html.twig', ['input' => $code, 'invalid' => true], Response::HTTP_NOT_FOUND);
        }
        if ($normalized !== $code) {
            // Код набран вручную строчными буквами или без дефисов — ведём на каноническую запись.
            return $this->redirectToRoute('app_verify', ['code' => $normalized], Response::HTTP_MOVED_PERMANENTLY);
        }
        $sheet = $this->sheets->findOneByCode($normalized);
        if (null === $sheet) {
            return $this->page('verify/form.html.twig', ['input' => $normalized, 'invalid' => false, 'not_found' => true], Response::HTTP_NOT_FOUND);
        }
        $result = $this->service->verify($sheet);
        $document = $result['document'];
        $canSeeDetails = null !== $document && $this->isGranted(PortalVoter::DOCUMENT_EDIT, $document);
        $current = null;
        // Текущие цифры — только ответственным: по коду с листа посторонний не следит за ходом ознакомления.
        if ($canSeeDetails && null !== $result['snapshot']) {
            $assigned = $result['snapshot']['acknowledgements'];
            $current = [
                'assigned' => \count($assigned),
                'acknowledged' => $result['snapshot']['acknowledged'],
                'approvals' => \count($result['snapshot']['approvals']),
                'overdue' => \count(array_filter($assigned, static fn (DocumentAcknowledgement $a): bool => $a->isOverdue())),
            ];
        }

        return $this->page('verify/show.html.twig', [
            'sheet' => $sheet,
            'document' => $document,
            'matches' => $result['matches'],
            'file_matches' => $result['file_matches'],
            'current' => $current,
            'details' => $canSeeDetails ? $result['snapshot'] : null,
        ]);
    }

    /**
     * Адрес страницы содержит код листа: поисковикам и чужим сайтам (через Referer) он не нужен.
     *
     * @param array<string, mixed> $parameters
     */
    private function page(string $template, array $parameters, int $status = Response::HTTP_OK): Response
    {
        $response = $this->render($template, $parameters, new Response('', $status));
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }
}
