<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Document;
use App\Entity\Section;
use App\Entity\User;
use App\Security\Voter\PortalVoter;
use App\Service\FileStorage;
use App\Service\Pdf\Bundle\BundleJob;
use App\Service\Pdf\Bundle\BundleJobStore;
use App\Service\Pdf\Bundle\SectionBundleBuilder;
use App\Service\Pdf\PdfFactory;
use App\Service\Pdf\PdfWatermarker;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * PDF-сборник раздела: выбор документов, сборка порциями с индикатором хода и скачивание готового файла.
 * Собирать сборники могут модераторы раздела и администраторы; задание и файл видит только тот, кто его
 * запустил (и администратор).
 */
#[Route('/sections/{id}/bundle', requirements: ['id' => '\d+'])]
final class SectionBundleController extends AbstractController
{
    private const JOB_ID = '\d{8}-\d{6}-[0-9a-f]{8}';

    /** Незавершённых сборников на сотрудника одновременно. */
    private const MAX_ACTIVE_JOBS = 2;

    /** Запас свободного места сверх удвоенного объёма исходных файлов. */
    private const DISK_RESERVE_BYTES = 200 * 1024 * 1024;

    /** Как документ войдёт в сборник — подписи для формы и хода сборки. */
    public const KIND_LABELS = [
        BundleJob::KIND_PDF => 'PDF',
        BundleJob::KIND_OFFICE => 'в PDF через LibreOffice',
        BundleJob::KIND_IMAGE => 'картинка',
        BundleJob::KIND_TEXT => 'текст',
        BundleJob::KIND_PAGE => 'страница портала',
        BundleJob::KIND_OTHER => 'только реквизиты',
    ];

    public function __construct(
        private readonly SectionBundleBuilder $builder,
        private readonly BundleJobStore $jobs,
        private readonly ?PdfWatermarker $watermarker = null,
    ) {
    }

    #[Route('', name: 'app_section_bundle', methods: ['GET', 'POST'])]
    #[IsGranted(PortalVoter::SECTION_MANAGE, subject: 'section')]
    public function form(Section $section, Request $request, #[CurrentUser] User $user): Response
    {
        $this->jobs->cleanup(); // сборники старше суток удаляются при каждом заходе на страницу
        $groups = $this->builder->candidates($section, $user);
        $errors = [];
        $selected = null;
        $titlePages = true;
        if ($request->isMethod('POST')) {
            $ids = array_map('intval', $request->request->all('documents'));
            $titlePages = $request->request->getBoolean('title_pages');
            $chosen = [];
            foreach ($groups as $group) {
                foreach ($group['documents'] as $document) {
                    if (\in_array($document->getId(), $ids, true)) {
                        $chosen[] = $document;
                    }
                }
            }
            $selected = array_map(static fn (Document $d): int => (int) $d->getId(), $chosen);
            if (!$this->isCsrfTokenValid('bundle_'.$section->getId(), (string) $request->request->get('_token'))) {
                $errors[] = 'Форма устарела — обновите страницу и отметьте документы снова.';
            }
            if (!$this->builder->isAvailable()) {
                $errors[] = 'На сервере не установлен пакет poppler-utils (программы pdfunite и pdfinfo) — сборник собрать нельзя.';
            }
            if ([] === $chosen) {
                $errors[] = 'Отметьте хотя бы один документ.';
            } elseif (\count($chosen) > SectionBundleBuilder::MAX_DOCUMENTS) {
                $errors[] = \sprintf('В сборнике может быть не больше %d документов, отмечено %d. Соберите несколько сборников по подразделам.', SectionBundleBuilder::MAX_DOCUMENTS, \count($chosen));
            } elseif (SectionBundleBuilder::sourceBytes($chosen) > SectionBundleBuilder::MAX_SOURCE_BYTES) {
                $errors[] = \sprintf('Файлы отмеченных документов занимают %s — больше предела %s. Соберите несколько сборников по подразделам.',
                    FileStorage::humanSize(SectionBundleBuilder::sourceBytes($chosen)), FileStorage::humanSize(SectionBundleBuilder::MAX_SOURCE_BYTES));
            }
            if ([] === $errors && $this->jobs->activeCount($user) >= self::MAX_ACTIVE_JOBS) {
                $errors[] = 'У вас уже собираются '.self::MAX_ACTIVE_JOBS.' сборника — дождитесь окончания или отмените один из них.';
            }
            $free = $this->jobs->freeBytes();
            if ([] === $errors && null !== $free && $free < 2 * SectionBundleBuilder::sourceBytes($chosen) + self::DISK_RESERVE_BYTES) {
                $errors[] = 'На сервере мало свободного места для сборника ('.FileStorage::humanSize($free).'). Сообщите администратору или соберите сборник поменьше.';
            }
            if ([] === $errors) {
                $job = $this->jobs->create($section, $user, $this->builder->items($chosen), $titlePages);

                return $this->redirectToRoute('app_section_bundle_job', ['id' => $section->getId(), 'job' => $job->id]);
            }
        }

        $kinds = [];
        $total = 0;
        foreach ($groups as $group) {
            foreach ($group['documents'] as $document) {
                $version = $document->getCurrentVersion();
                $kinds[$document->getId()] = null !== $version ? $this->builder->kindOf($version) : BundleJob::KIND_OTHER;
                ++$total;
            }
        }

        return $this->render('section/bundle.html.twig', [
            'section' => $section,
            'groups' => $groups,
            'kinds' => $kinds,
            'kind_labels' => self::KIND_LABELS,
            'total' => $total,
            'selected' => $selected,
            'title_pages' => $titlePages,
            'errors' => $errors,
            'available' => $this->builder->isAvailable(),
            'office_available' => $this->builder->officeAvailable(),
            'office_count' => \count(array_filter($kinds, static fn (string $k): bool => BundleJob::KIND_OFFICE === $k)),
            'recent' => $this->jobs->recentFor($user, (int) $section->getId()),
            'max_documents' => SectionBundleBuilder::MAX_DOCUMENTS,
        ], new Response('', [] === $errors ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY));
    }

    #[Route('/{job}', name: 'app_section_bundle_job', requirements: ['job' => self::JOB_ID], methods: ['GET'])]
    #[IsGranted(PortalVoter::SECTION_MANAGE, subject: 'section')]
    public function job(Section $section, string $job, #[CurrentUser] User $user): Response
    {
        $bundle = $this->ownJob($section, $job, $user);

        return $this->render('section/bundle_job.html.twig', [
            'section' => $section,
            'job' => $bundle,
            'kind_labels' => self::KIND_LABELS,
            'ready' => null !== $this->jobs->result($bundle),
        ]);
    }

    /** Очередная порция сборки; ответ — состояние задания (JSON). */
    #[Route('/{job}/run', name: 'app_section_bundle_run', requirements: ['job' => self::JOB_ID], methods: ['POST'])]
    #[IsGranted(PortalVoter::SECTION_MANAGE, subject: 'section')]
    public function run(Section $section, string $job, Request $request, #[CurrentUser] User $user): JsonResponse
    {
        if (!$this->isCsrfTokenValid('bundle_'.$section->getId(), (string) $request->request->get('_token'))) {
            return new JsonResponse(['error' => 'Неверный CSRF-токен — обновите страницу.'], Response::HTTP_FORBIDDEN);
        }
        $bundle = $this->ownJob($section, $job, $user);
        @set_time_limit(170);
        if (!$this->jobs->runBatch($bundle)) {
            return new JsonResponse(['busy' => true], Response::HTTP_CONFLICT);
        }

        return new JsonResponse($this->state($section, $bundle));
    }

    #[Route('/{job}/download', name: 'app_section_bundle_download', requirements: ['job' => self::JOB_ID], methods: ['GET'])]
    #[IsGranted(PortalVoter::SECTION_MANAGE, subject: 'section')]
    public function download(Section $section, string $job, Request $request, #[CurrentUser] User $user): Response
    {
        $bundle = $this->ownJob($section, $job, $user);
        $path = $this->jobs->result($bundle);
        if (null === $path) {
            throw $this->createNotFoundException('Сборник ещё не готов или уже удалён.');
        }
        $name = 'Сборник — '.$section->getName().' — '.$bundle->createdAt->format('Y-m-d').'.pdf';
        $name = (string) preg_replace('~[\\\\/:*?"<>|\x00-\x1f]+~u', ' ', $name);
        $marked = null !== $this->watermarker && $this->watermarker->marksBundles()
            ? $this->watermarker->apply($path, $this->watermarker->texts($user, $request->getClientIp(), null, 'Сборник: '.$section->getName()))
            : null;
        $response = new BinaryFileResponse($marked ?? $path, Response::HTTP_OK, ['Content-Type' => 'application/pdf', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
        $response->headers->set('Content-Disposition', PdfFactory::disposition($name));
        if (null !== $marked) {
            // Копия со знаком своя у каждого скачивания: удаляется после отправки, частями не отдаётся.
            $response->deleteFileAfterSend(true);
            $response->headers->set('Accept-Ranges', 'none');
            $request->headers->remove('Range');
        }

        return $response;
    }

    #[Route('/{job}/delete', name: 'app_section_bundle_delete', requirements: ['job' => self::JOB_ID], methods: ['POST'])]
    #[IsGranted(PortalVoter::SECTION_MANAGE, subject: 'section')]
    public function delete(Section $section, string $job, Request $request, #[CurrentUser] User $user): Response
    {
        $bundle = $this->ownJob($section, $job, $user);
        if ($this->isCsrfTokenValid('bundle_'.$section->getId(), (string) $request->request->get('_token'))) {
            $this->jobs->delete($bundle);
            $this->addFlash('success', $bundle->isFinished() ? 'Сборник удалён.' : 'Сборка отменена.');
        }

        return $this->redirectToRoute('app_section_bundle', ['id' => $section->getId()]);
    }

    private function ownJob(Section $section, string $id, User $user): BundleJob
    {
        $job = $this->jobs->load($id);
        if (null === $job || $job->sectionId !== $section->getId() || ($job->actorId !== $user->getId() && !$this->isGranted('ROLE_ADMIN'))) {
            throw $this->createNotFoundException('Задание сборника не найдено (готовые сборники хранятся сутки).');
        }

        return $job;
    }

    /** @return array<string, mixed> */
    private function state(Section $section, BundleJob $job): array
    {
        $ready = null !== $this->jobs->result($job);

        return [
            'position' => $job->position,
            'total' => $job->total(),
            'percent' => $job->progressPercent(),
            'phase' => $job->phase,
            'finished' => $job->isFinished(),
            'error' => $job->error,
            'counters' => $job->counters(),
            'items' => array_map(static fn (array $item): array => ['status' => $item['status'], 'pages' => $item['pages'], 'note' => $item['note']], $job->items),
            'download' => $ready ? [
                'url' => $this->generateUrl('app_section_bundle_download', ['id' => $section->getId(), 'job' => $job->id]),
                'pages' => $job->pages,
                'size' => FileStorage::humanSize((int) $job->bytes),
            ] : null,
        ];
    }
}
