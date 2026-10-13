<?php

declare(strict_types=1);

namespace App\Message;

/** Письма и уведомления сотрудникам, которым назначено ознакомление с документом. */
final class NotifyAcknowledgementAssigned implements BackgroundMessage, UsesPortalLinks
{
    /**
     * @param list<int> $userIds
     */
    public function __construct(
        public readonly int $documentId,
        public readonly array $userIds,
        public readonly ?string $due = null,
    ) {
    }
}
