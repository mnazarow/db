<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Задача для очереди фоновых задач (config/packages/messenger.yaml): долгие операции выполняет обработчик
 * (docportal console messenger:consume async), а не запрос пользователя. Ставит их App\Service\BackgroundJobs.
 */
interface BackgroundMessage
{
}
