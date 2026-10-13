<?php

declare(strict_types=1);

namespace App\Message;

/** «Отправить сейчас» сводки модераторам по таблицам материалов (App\Service\Material\MaterialReminder). */
final class SendMaterialsReminder implements BackgroundMessage, UsesPortalLinks
{
    public function __construct(public readonly ?string $requestedBy = null)
    {
    }
}
