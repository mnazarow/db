<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\DocumentEvent;
use App\Repository\DocumentEventRepository;
use App\Repository\DocumentRepository;
use App\Repository\SectionRepository;
use App\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Панель администратора: журнал событий по документам.
 */
#[Route('/admin/events')]
final class EventLogController extends AbstractController
{
    #[Route('', name: 'admin_events', methods: ['GET'])]
    public function index(Request $request, DocumentEventRepository $events, UserRepository $users, SectionRepository $sections, DocumentRepository $documents): Response
    {
        $userId = (int) $request->query->get('user');
        $sectionId = (int) $request->query->get('section');
        $documentId = (int) $request->query->get('document');
        $from = self::date((string) $request->query->get('from', ''));
        $to = self::date((string) $request->query->get('to', ''));
        $type = (string) $request->query->get('type', '');
        $filters = [
            'type' => isset(DocumentEvent::LABELS[$type]) ? $type : null,
            'user' => $userId > 0 ? $users->find($userId) : null,
            'section' => $sectionId > 0 ? $sections->find($sectionId) : null,
            'document' => $documentId > 0 ? $documents->find($documentId) : null,
            'from' => $from,
            'to' => $to,
        ];

        return $this->render('admin/events/index.html.twig', [
            'events' => $events->findFiltered($filters),
            'filters' => $filters,
            'types' => DocumentEvent::LABELS,
            'users' => $users->findAllOrdered(),
            'tree' => $sections->findAllTree(),
            'from' => $from?->format('Y-m-d') ?? '',
            'to' => $to?->format('Y-m-d') ?? '',
        ]);
    }

    private static function date(string $value): ?\DateTimeImmutable
    {
        if ('' === $value) {
            return null;
        }
        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }
}
