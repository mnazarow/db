<?php

declare(strict_types=1);

namespace App\Message;

/** Пересчёт ежедневных итогов статистики, если задание cron ещё не выполнялось сегодня (App\Service\StatsAggregator). */
final class RefreshStatistics implements BackgroundMessage
{
}
