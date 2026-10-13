<?php

declare(strict_types=1);

namespace App\Message;

/** «Отправить сейчас» сводки руководителям (App\Service\Org\TeamDigest). */
final class SendTeamDigest implements BackgroundMessage, UsesPortalLinks
{
    public function __construct(public readonly ?string $requestedBy = null)
    {
    }
}
