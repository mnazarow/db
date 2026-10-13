<?php

declare(strict_types=1);

namespace App\Message;

/** Сборка PDF-сборника раздела (App\Service\Pdf\Bundle\BundleJobStore). */
final class BuildSectionBundle implements BackgroundMessage, UsesPortalLinks
{
    public function __construct(public readonly string $jobId)
    {
    }
}
