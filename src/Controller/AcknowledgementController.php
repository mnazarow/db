<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Document;
use App\Entity\DocumentAcknowledgement;
use App\Entity\DocumentQuestion;
use App\Entity\Section;
use App\Entity\User;
use App\Repository\DocumentAcknowledgementRepository;
use App\Repository\DocumentQuestionRepository;
use App\Repository\SectionRepository;
use App\Repository\UserRepository;
use App\Security\Access;
use App\Security\Voter\PortalVoter;
use App\Service\Csv;
use App\Service\AcknowledgementService;
use App\Service\Pdf\PdfFactory;
use App\Service\Pdf\SignatureSheetService;
use App\Service\QuizService;
use App\Service\Video\VideoApi;
use App\Service\Video\VideoIntegration;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Ознакомление с документами: подтверждение сотрудником, назначение и отчёт для модератора,
 * лист ознакомления для печати и выгрузка CSV.
 */
final class AcknowledgementController extends AbstractController
{
    public function __construct(
        private readonly AcknowledgementService $service,
        private readonly DocumentAcknowledgementRepository $acknowledgements,
        private readonly UserRepository $users,
        private readonly Access $access,
        private readonly QuizService $quiz,
        private readonly DocumentQuestionRepository $questions,
        private readonly SectionRepository $sections,
        private readonly SignatureSheetService $signatureSheets,
        private readonly ?VideoApi $videoApi = null,
        private readonly ?VideoIntegration $video = null,
    ) {
    }

    /** Кнопка «Ознакомлен» в карточке документа. */
    #[Route('/documents/{id}/acknowledge', name: 'app_document_acknowledge', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted(PortalVoter::DOCUMENT_VIEW, subject: 'document')]
    public function acknowledge(Document $document, Request $request, #[CurrentUser] User $user): Response
    {
        if (!$this->isCsrfTokenValid('document_'.$document->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Неверный CSRF-токен.');
        }
        // Если к документу заданы вопросы, сначала проверка знаний.
        if ($this->quiz->hasQuiz($document) && null !== $this->acknowledgements->findPending($document, $user)) {
            return $this->redirectToRoute('app_document_acknowledge_quiz', ['id' => $document->getId()]);
        }
        $acknowledgement = $this->service->confirm($document, $user, $request->getClientIp());
        if (null === $acknowledgement) {
            $this->addFlash('info', 'Ознакомление с этим документом вам не назначено (или уже подтверждено).');
        } else {
            $this->addFlash('success', \sprintf('Ознакомление подтверждено: редакция %d, %s. Запись сохранена в журнале.', $acknowledgement->getVersionNumber(), $acknowledgement->getConfirmedAt()?->format('d.m.Y H:i')));
        }

        return $this->redirectToRoute('app_document_show', ['id' => $document->getId()]);
    }

    /** Проверка знаний перед подтверждением ознакомления. */
    #[Route('/documents/{id}/acknowledge/quiz', name: 'app_document_acknowledge_quiz', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    #[IsGranted(PortalVoter::DOCUMENT_VIEW, subject: 'document')]
    public function quiz(Document $document, Request $request, #[CurrentUser] User $user): Response
    {
        $acknowledgement = $this->acknowledgements->findPending($document, $user);
        if (null === $acknowledgement) {
            $this->addFlash('info', 'Ознакомление с этим документом вам не назначено (или уже подтверждено).');

            return $this->redirectToRoute('app_document_show', ['id' => $document->getId()]);
        }
        $questions = $this->quiz->questions($document);
        if ([] === $questions) {
            return $this->redirectToRoute('app_document_show', ['id' => $document->getId()]);
        }
        $result = null;
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('quiz_'.$document->getId(), (string) $request->request->get('_token'))) {
                throw $this->createAccessDeniedException('Неверный CSRF-токен.');
            }
            $answers = [];
            foreach ((array) $request->request->all('answers') as $questionId => $value) {
                $answers[(int) $questionId] = $value;
            }
            $result = $this->quiz->check($document, $answers);
            if ($result['passed']) {
                $acknowledgement->addQuizAttempt();
                $confirmed = $this->service->confirm($document, $user, $request->getClientIp(), $result);
                $this->addFlash('success', \sprintf('Проверка знаний пройдена (%d из %d). Ознакомление подтверждено: редакция %d, %s.',
                    $result['correct'], $result['total'], $confirmed?->getVersionNumber() ?? 0, $confirmed?->getConfirmedAt()?->format('d.m.Y H:i') ?? ''));

                return $this->redirectToRoute('app_document_show', ['id' => $document->getId()]);
            }
            $this->quiz->recordAttempt($acknowledgement, $result['correct'], $result['total']);
            $this->addFlash('warning', \sprintf('Верных ответов: %d из %d. Прочитайте документ ещё раз и ответьте на отмеченные вопросы.', $result['correct'], $result['total']));
        }

        return $this->render('document/acknowledge_quiz.html.twig', [
            'document' => $document,
            'questions' => $questions,
            'acknowledgement' => $acknowledgement,
            'result' => $result,
            'answers' => (array) $request->request->all('answers'),
        ]);
    }

    /** Вопросы для проверки знаний (модератор раздела). */
    #[Route('/documents/{id}/questions', name: 'app_document_questions', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    #[IsGranted(PortalVoter::DOCUMENT_EDIT, subject: 'document')]
    public function questions(Document $document, Request $request): Response
    {
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('questions_'.$document->getId(), (string) $request->request->get('_token'))) {
                throw $this->createAccessDeniedException('Неверный CSRF-токен.');
            }
            $questionId = (int) $request->request->get('question');
            $question = $questionId > 0 ? $this->questions->find($questionId) : null;
            if (null !== $question && $question->getDocument()->getId() !== $document->getId()) {
                throw $this->createNotFoundException('Вопрос относится к другому документу.');
            }
            if ('delete' === $request->request->get('action')) {
                if (null !== $question) {
                    $this->quiz->delete($question);
                    $this->addFlash('success', 'Вопрос удалён.');
                }

                return $this->redirectToRoute('app_document_questions', ['id' => $document->getId()]);
            }
            try {
                $this->quiz->save(
                    $document,
                    $question,
                    (string) $request->request->get('text'),
                    array_map('strval', (array) $request->request->all('options')),
                    (int) $request->request->get('correct'),
                );
                $this->addFlash('success', null === $question ? 'Вопрос добавлен.' : 'Вопрос изменён.');

                return $this->redirectToRoute('app_document_questions', ['id' => $document->getId()]);
            } catch (\InvalidArgumentException $e) {
                $this->addFlash('danger', $e->getMessage());
            }
        }

        return $this->render('document/questions.html.twig', [
            'document' => $document,
            'questions' => $this->quiz->questions($document),
            'max_options' => DocumentQuestion::MAX_OPTIONS,
        ]);
    }

    /** Назначение ознакомления (модератор раздела или администратор). */
    #[Route('/documents/{id}/acknowledgements/assign', name: 'app_document_acknowledge_assign', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    #[IsGranted(PortalVoter::DOCUMENT_EDIT, subject: 'document')]
    public function assign(Document $document, Request $request, #[CurrentUser] User $user): Response
    {
        $byDepartment = $this->service->candidatesByDepartment();
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('acknowledge_'.$document->getId(), (string) $request->request->get('_token'))) {
                throw $this->createAccessDeniedException('Неверный CSRF-токен.');
            }
            $ids = array_map('intval', (array) $request->request->all('users'));
            $selected = [] === $ids ? [] : $this->users->findBy(['id' => $ids]);
            $dueRaw = trim((string) $request->request->get('due', ''));
            $due = null;
            if ('' !== $dueRaw) {
                $due = \DateTimeImmutable::createFromFormat('!Y-m-d', $dueRaw) ?: null;
                if (null === $due) {
                    $this->addFlash('danger', 'Неверная дата срока ознакомления.');

                    return $this->redirectToRoute('app_document_acknowledge_assign', ['id' => $document->getId()]);
                }
            }
            if ([] === $selected) {
                $this->addFlash('warning', 'Выберите хотя бы одного сотрудника.');
            } else {
                try {
                    $result = $this->service->assign($document, $selected, $due, $user, !$request->request->has('silent'));
                    $this->addFlash('success', \sprintf('Ознакомление с редакцией %d назначено: %d %s%s%s.',
                        $result['version'], $result['created'], 1 === $result['created'] % 10 && 11 !== $result['created'] % 100 ? 'сотруднику' : 'сотрудникам',
                        $result['existing'] > 0 ? \sprintf(' (уже было назначено: %d)', $result['existing']) : '',
                        $result['skipped'] > 0 ? \sprintf(', пропущено заблокированных: %d', $result['skipped']) : ''));

                    return $this->redirectToRoute('app_document_acknowledgements', ['id' => $document->getId()]);
                } catch (\DomainException $e) {
                    $this->addFlash('danger', $e->getMessage());
                }
            }
        }

        $report = $this->service->report($document);
        $assigned = [];
        foreach ($report['rows'] as $row) {
            $assigned[$row->getUser()->getId()] = $row;
        }

        return $this->render('document/acknowledge_assign.html.twig', [
            'document' => $document,
            'by_department' => $byDepartment,
            'assigned' => $assigned,
            'version' => $document->getCurrentVersion()?->getNumber() ?? 0,
            'default_due' => (new \DateTimeImmutable('+14 days'))->format('Y-m-d'),
        ]);
    }

    /** Отчёт по ознакомлению: кто ознакомился, кто нет. */
    #[Route('/documents/{id}/acknowledgements', name: 'app_document_acknowledgements', requirements: ['id' => '\d+'], methods: ['GET'])]
    #[IsGranted(PortalVoter::DOCUMENT_EDIT, subject: 'document')]
    public function report(Document $document, Request $request): Response
    {
        $version = (int) $request->query->get('version');
        $report = $this->service->report($document, $version > 0 ? $version : null);

        return $this->render('document/acknowledge_report.html.twig', [
            'document' => $document,
            'report' => $report,
            'today' => new \DateTimeImmutable('today'),
        ]);
    }

    /** Лист ознакомления для печати (аналог бумажного листа: ФИО, подразделение, дата, подпись). */
    #[Route('/documents/{id}/acknowledgements/sheet', name: 'app_document_acknowledgements_sheet', requirements: ['id' => '\d+'], methods: ['GET'])]
    #[IsGranted(PortalVoter::DOCUMENT_EDIT, subject: 'document')]
    public function sheet(Document $document, Request $request): Response
    {
        $version = (int) $request->query->get('version');
        $report = $this->service->report($document, $version > 0 ? $version : null);

        return $this->render('document/acknowledge_sheet.html.twig', [
            'document' => $document,
            'report' => $report,
            'printed_at' => new \DateTimeImmutable(),
        ]);
    }

    /**
     * Лист ознакомления в PDF с кодом проверки подлинности (QR): по коду страница /verify/<код> покажет,
     * что лист выдан порталом и совпадают ли записи с текущими. При тех же записях код прежний.
     */
    #[Route('/documents/{id}/acknowledgements/sheet.pdf', name: 'app_document_acknowledgements_pdf', requirements: ['id' => '\d+'], methods: ['GET'])]
    #[IsGranted(PortalVoter::DOCUMENT_EDIT, subject: 'document')]
    public function sheetPdf(Document $document, Request $request, #[CurrentUser] User $user): Response
    {
        $version = (int) $request->query->get('version');
        $report = $this->service->report($document, $version > 0 ? $version : null);
        try {
            $result = $this->signatureSheets->generate($document, $report['version'], $user);
        } catch (\DomainException $e) {
            $this->addFlash('error', $e->getMessage());

            return $this->redirectToRoute('app_document_acknowledgements', ['id' => $document->getId()]);
        }

        return new Response($result['pdf'], Response::HTTP_OK, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => PdfFactory::disposition($result['filename'], 'inline' === $request->query->get('disposition')),
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** Выгрузка отчёта в CSV. */
    #[Route('/documents/{id}/acknowledgements.csv', name: 'app_document_acknowledgements_csv', requirements: ['id' => '\d+'], methods: ['GET'])]
    #[IsGranted(PortalVoter::DOCUMENT_EDIT, subject: 'document')]
    public function csv(Document $document, Request $request): StreamedResponse
    {
        $version = (int) $request->query->get('version');
        $report = $this->service->report($document, $version > 0 ? $version : null);
        $response = new StreamedResponse(function () use ($document, $report): void {
            $out = fopen('php://output', 'wb');
            fwrite($out, "\xEF\xBB\xBF");
            Csv::put($out, ['Документ', $document->getTitle()]);
            Csv::put($out, ['Обозначение', (string) $document->getCode()]);
            fputcsv($out, ['Редакция (версия)', (string) $report['version']], ';', '"', '\\');
            fputcsv($out, [], ';', '"', '\\');
            fputcsv($out, ['Сотрудник', 'Подразделение', 'Назначено', 'Срок', 'Ознакомлен', 'Проверка знаний', 'IP'], ';', '"', '\\');
            foreach ($report['rows'] as $row) {
                Csv::put($out, [
                    $row->getUser()->getDisplayName(),
                    (string) $row->getUser()->getDepartment(),
                    $row->getAssignedAt()->format('d.m.Y'),
                    $row->getDueAt()?->format('d.m.Y') ?? '',
                    $row->getConfirmedAt()?->format('d.m.Y H:i') ?? 'не ознакомлен',
                    $row->hasQuizResult() ? \sprintf('%d из %d (попыток: %d)', $row->getQuizScore(), $row->getQuizTotal(), $row->getQuizAttempts()) : '',
                    (string) $row->getConfirmedIp(),
                ]);
            }
            fclose($out);
        });
        $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $response->headers->set('Content-Disposition', \sprintf('attachment; filename="acknowledgements-%d-v%d.csv"', $document->getId(), $report['version']));

        return $response;
    }

    /** Мои ознакомления: что нужно прочитать и история подтверждений. */
    #[Route('/profile/acknowledgements', name: 'app_profile_acknowledgements', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function mine(#[CurrentUser] User $user): Response
    {
        return $this->render('profile/acknowledgements.html.twig', [
            'pending' => $this->acknowledgements->findPendingForUser($user, 100, $this->access->viewer($user)),
            'confirmed' => $this->acknowledgements->findConfirmedForUser($user, 50),
            'today' => new \DateTimeImmutable('today'),
        ]);
    }

    /** Документов-столбцов в сводке по подразделениям (остальные — в выгрузке CSV). */
    private const MATRIX_DOCUMENTS = 30;

    /** Сводка по ознакомлениям в панели администратора: по документам, по подразделениям или по сотрудникам (документы и видео). */
    #[Route('/admin/acknowledgements', name: 'admin_acknowledgements', methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    public function overview(Request $request): Response
    {
        $onlyPending = filter_var($request->query->get('pending'), \FILTER_VALIDATE_BOOL);
        $view = \in_array($request->query->get('view'), ['departments', 'people'], true) ? (string) $request->query->get('view') : 'documents';
        $section = $this->filterSection($request);
        $sort = 'pct' === $request->query->get('sort') ? 'pct' : 'name';
        $department = trim((string) $request->query->get('department', ''));

        return $this->render('admin/acknowledgements/index.html.twig', [
            'view' => $view,
            'rows' => 'documents' === $view ? $this->acknowledgements->overview($onlyPending) : [],
            'matrix' => 'departments' === $view ? $this->service->departmentMatrix($this->sectionIds($section), self::MATRIX_DOCUMENTS, $sort) : null,
            'people' => 'people' === $view ? $this->service->peopleReport($this->sectionIds($section), $this->videoAssignments(), $department, $onlyPending) : null,
            'video_on' => $this->video?->isEnabled() ?? false,
            'department' => $department,
            'totals' => $this->acknowledgements->totals(),
            'only_pending' => $onlyPending,
            'tree' => 'departments' === $view || 'people' === $view ? $this->sections->findAllTree() : [],
            'section' => $section,
            'sort' => $sort,
        ]);
    }

    /** Сводка по подразделениям в CSV: строка на пару «подразделение — документ» (удобно для сводных таблиц Excel). */
    #[Route('/admin/acknowledgements/departments.csv', name: 'admin_acknowledgements_departments_csv', methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    public function departmentsCsv(Request $request): StreamedResponse
    {
        $matrix = $this->service->departmentMatrix($this->sectionIds($this->filterSection($request)), \PHP_INT_MAX);
        $response = new StreamedResponse(static function () use ($matrix): void {
            $out = fopen('php://output', 'wb');
            fwrite($out, "\xEF\xBB\xBF");
            Csv::put($out, ['Подразделение', 'Документ', 'Обозначение', 'Раздел', 'Назначено', 'Ознакомились', 'Просрочено', 'Ознакомились, %']);
            foreach ($matrix['departments'] as $department) {
                foreach ($matrix['all_documents'] as $column) {
                    $cell = $department['cells'][$column['document']->getId()] ?? null;
                    if (null === $cell) {
                        continue;
                    }
                    Csv::put($out, [$department['name'], $column['document']->getTitle(), (string) $column['document']->getCode(),
                        $column['document']->getSection()->getFullName(), $cell['assigned'], $cell['confirmed'], $cell['overdue'], $cell['pct']]);
                }
            }
            fclose($out);
        });
        $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $response->headers->set('Content-Disposition', 'attachment; filename="acknowledgements-departments-'.date('Y-m-d').'.csv"');

        return $response;
    }

    /** Единый отчёт по сотрудникам в CSV: строка на сотрудника (документы и обязательные просмотры видео). */
    #[Route('/admin/acknowledgements/people.csv', name: 'admin_acknowledgements_people_csv', methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    public function peopleCsv(Request $request): StreamedResponse
    {
        $report = $this->service->peopleReport($this->sectionIds($this->filterSection($request)), $this->videoAssignments(),
            trim((string) $request->query->get('department', '')), filter_var($request->query->get('pending'), \FILTER_VALIDATE_BOOL));
        $withVideo = $this->video?->isEnabled() ?? false;
        $response = new StreamedResponse(static function () use ($report, $withVideo): void {
            $out = fopen('php://output', 'wb');
            fwrite($out, "\xEF\xBB\xBF");
            $head = ['Подразделение', 'Сотрудник', 'Логин', 'Документов назначено', 'Ознакомился', 'Документов просрочено'];
            if ($withVideo) {
                $head = array_merge($head, ['Видео назначено', 'Просмотрено', 'Видео просрочено']);
            }
            Csv::put($out, array_merge($head, ['Не выполнено всего']));
            foreach ($report['departments'] as $department) {
                foreach ($department['people'] as $row) {
                    $line = [$department['name'], $row['user']->getDisplayName(), $row['user']->getUsername(), $row['docs']['assigned'], $row['docs']['confirmed'], $row['docs']['overdue']];
                    if ($withVideo) {
                        $line = array_merge($line, [$row['video']['assigned'], $row['video']['completed'], $row['video']['overdue']]);
                    }
                    Csv::put($out, array_merge($line, [$row['pending']]));
                }
            }
            fclose($out);
        });
        $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $response->headers->set('Content-Disposition', 'attachment; filename="acknowledgements-people-'.date('Y-m-d').'.csv"');

        return $response;
    }

    /**
     * «Мои сотрудники»: руководитель видит, кто из подчинённых (по оргструктуре) не ознакомился с документами
     * и не посмотрел обязательные видео. Администратор может открыть страницу любого руководителя (?manager=логин).
     */
    #[Route('/profile/team', name: 'app_profile_team', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function team(Request $request, #[CurrentUser] User $user): Response
    {
        $manager = $this->teamManager($request, $user);

        return $this->render('profile/team.html.twig', [
            'manager' => $manager,
            'own' => $manager === $user,
            'report' => $this->service->teamReport($manager, $this->videoAssignments()),
            'video_on' => $this->video?->isEnabled() ?? false,
        ]);
    }

    #[Route('/profile/team.csv', name: 'app_profile_team_csv', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function teamCsv(Request $request, #[CurrentUser] User $user): StreamedResponse
    {
        $manager = $this->teamManager($request, $user);
        $report = $this->service->teamReport($manager, $this->videoAssignments(), false);
        $withVideo = $this->video?->isEnabled() ?? false;
        $response = new StreamedResponse(static function () use ($report, $withVideo): void {
            $out = fopen('php://output', 'wb');
            fwrite($out, "\xEF\xBB\xBF");
            $head = ['Сотрудник', 'Логин', 'Должность', 'Подразделение', 'Руководитель', 'Документов назначено', 'Ознакомился', 'Документов просрочено'];
            if ($withVideo) {
                $head = array_merge($head, ['Видео назначено', 'Просмотрено', 'Видео просрочено']);
            }
            Csv::put($out, array_merge($head, ['Не выполнено всего']));
            foreach ($report['people'] as $row) {
                $u = $row['user'];
                $line = [$u->getDisplayName(), $u->getUsername(), (string) $u->getJobTitle(), (string) $u->getDepartment(), (string) $u->getManager()?->getDisplayName(), $row['docs']['assigned'], $row['docs']['confirmed'], $row['docs']['overdue']];
                if ($withVideo) {
                    $line = array_merge($line, [$row['video']['assigned'], $row['video']['completed'], $row['video']['overdue']]);
                }
                Csv::put($out, array_merge($line, [$row['pending']]));
            }
            fclose($out);
        });
        $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $response->headers->set('Content-Disposition', 'attachment; filename="team-'.$manager->getUsername().'-'.date('Y-m-d').'.csv"');
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }

    private function teamManager(Request $request, User $user): User
    {
        $login = trim((string) $request->query->get('manager', ''));
        if ('' === $login || $login === $user->getUsername()) {
            return $user;
        }
        if (!$this->isGranted('ROLE_ADMIN')) {
            throw $this->createAccessDeniedException('Сотрудников другого руководителя видит только администратор.');
        }

        return $this->users->findOneByUsername($login) ?? throw $this->createNotFoundException('Нет такого пользователя.');
    }

    /** @return array<int, array{assigned: int, completed: int, overdue: int}>|null */
    private function videoAssignments(): ?array
    {
        return null !== $this->videoApi && ($this->video?->isEnabled() ?? false) ? $this->videoApi->assignmentsByUser() : null;
    }

    private function filterSection(Request $request): ?Section
    {
        $id = (int) $request->query->get('section');

        return $id > 0 ? $this->sections->find($id) : null;
    }

    /** @return list<int>|null */
    private function sectionIds(?Section $section): ?array
    {
        return null === $section ? null : $this->sections->findSubtreeIds($section);
    }
}
