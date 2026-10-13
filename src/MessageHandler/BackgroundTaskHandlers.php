<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\BuildSectionBundle;
use App\Message\NotifyAcknowledgementAssigned;
use App\Message\RefreshStatistics;
use App\Message\SendMaterialsReminder;
use App\Message\SendTeamDigest;
use App\Repository\DocumentRepository;
use App\Repository\UserRepository;
use App\Service\AcknowledgementService;
use App\Service\Material\MaterialReminder;
use App\Service\Org\TeamDigest;
use App\Service\Pdf\Bundle\BundleJobStore;
use App\Service\StatsAggregator;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Обработчики фоновых задач (App\Service\BackgroundJobs). Ошибка, после которой задачу можно безопасно
 * повторить (почта не работала — ни одно письмо не ушло), выбрасывается: очередь повторит задачу позже.
 */
final class BackgroundTaskHandlers
{
    /**
     * Сборник собирается частями по минуте: после каждой задача ставится в очередь снова — письма и другие задачи
     * не ждут, пока соберётся большой сборник.
     */
    private const BUNDLE_SLICE_SECONDS = 60;

    public function __construct(
        private readonly TeamDigest $teamDigest,
        private readonly MaterialReminder $materialReminder,
        private readonly AcknowledgementService $acknowledgements,
        private readonly DocumentRepository $documents,
        private readonly UserRepository $users,
        private readonly BundleJobStore $bundles,
        private readonly StatsAggregator $stats,
        private readonly MessageBusInterface $bus,
    ) {
    }

    #[AsMessageHandler]
    public function sendTeamDigest(SendTeamDigest $message): void
    {
        $result = $this->teamDigest->run(false, true);
        if ('error' === $result['status']) {
            throw new \RuntimeException($result['message']);
        }
    }

    #[AsMessageHandler]
    public function sendMaterialsReminder(SendMaterialsReminder $message): void
    {
        $this->materialReminder->run(false, true);
    }

    #[AsMessageHandler]
    public function notifyAcknowledgementAssigned(NotifyAcknowledgementAssigned $message): void
    {
        $document = $this->documents->find($message->documentId);
        if (null === $document) {
            return;
        }
        $users = [] === $message->userIds ? [] : $this->users->findBy(['id' => $message->userIds, 'active' => true]);
        $this->acknowledgements->notifyAssignedUsers($document, $users, null !== $message->due ? new \DateTimeImmutable($message->due) : null);
    }

    #[AsMessageHandler]
    public function buildSectionBundle(BuildSectionBundle $message): void
    {
        $deadline = microtime(true) + self::BUNDLE_SLICE_SECONDS;
        while (null !== ($job = $this->bundles->load($message->jobId)) && !$job->isFinished()) {
            if (microtime(true) > $deadline) {
                $this->bus->dispatch(new BuildSectionBundle($job->id));

                return;
            }
            // Порцию может собирать и открытая страница задания — тогда ждём её.
            if (!$this->bundles->runBatch($job, 20.0)) {
                usleep(500_000);
            }
        }
    }

    #[AsMessageHandler]
    public function refreshStatistics(RefreshStatistics $message): void
    {
        $this->stats->refresh(false);
    }
}
