<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Document;
use App\Entity\DocumentAcknowledgement;
use App\Entity\DocumentQuestion;
use App\Entity\User;
use App\Repository\DocumentAcknowledgementRepository;
use App\Repository\DocumentQuestionRepository;
use App\Repository\UserRepository;
use App\Security\Access;
use App\Security\Voter\PortalVoter;
use App\Service\AcknowledgementService;
use App\Service\QuizService;
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
            fputcsv($out, ['Документ', $document->getTitle()], ';', '"', '\\');
            fputcsv($out, ['Обозначение', (string) $document->getCode()], ';', '"', '\\');
            fputcsv($out, ['Редакция (версия)', (string) $report['version']], ';', '"', '\\');
            fputcsv($out, [], ';', '"', '\\');
            fputcsv($out, ['Сотрудник', 'Подразделение', 'Назначено', 'Срок', 'Ознакомлен', 'Проверка знаний', 'IP'], ';', '"', '\\');
            foreach ($report['rows'] as $row) {
                fputcsv($out, [
                    $row->getUser()->getDisplayName(),
                    (string) $row->getUser()->getDepartment(),
                    $row->getAssignedAt()->format('d.m.Y'),
                    $row->getDueAt()?->format('d.m.Y') ?? '',
                    $row->getConfirmedAt()?->format('d.m.Y H:i') ?? 'не ознакомлен',
                    $row->hasQuizResult() ? \sprintf('%d из %d (попыток: %d)', $row->getQuizScore(), $row->getQuizTotal(), $row->getQuizAttempts()) : '',
                    (string) $row->getConfirmedIp(),
                ], ';', '"', '\\');
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
            'pending' => $this->acknowledgements->findPendingForUser($user),
            'confirmed' => $this->acknowledgements->findConfirmedForUser($user, 50),
            'today' => new \DateTimeImmutable('today'),
        ]);
    }

    /** Сводка по ознакомлениям в панели администратора. */
    #[Route('/admin/acknowledgements', name: 'admin_acknowledgements', methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    public function overview(Request $request): Response
    {
        $onlyPending = filter_var($request->query->get('pending'), \FILTER_VALIDATE_BOOL);

        return $this->render('admin/acknowledgements/index.html.twig', [
            'rows' => $this->acknowledgements->overview($onlyPending),
            'totals' => $this->acknowledgements->totals(),
            'only_pending' => $onlyPending,
        ]);
    }
}
