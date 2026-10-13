<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Entity\Document;
use App\Service\StatsAggregator;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PostRemoveEventArgs;
use Doctrine\ORM\Events;

/**
 * Удалён документ — вместе с ним из журнала удалены его события, а в ежедневных итогах статистики они остались:
 * итоги помечаются устаревшими и пересчитываются при следующем обращении к статистике.
 */
#[AsDoctrineListener(event: Events::postRemove)]
final class StatsInvalidationListener
{
    public function postRemove(PostRemoveEventArgs $args): void
    {
        if ($args->getObject() instanceof Document) {
            StatsAggregator::markStale($args->getObjectManager()->getConnection());
        }
    }
}
