<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Задача формирует ссылки на портал (письма, сводки, сборники). Без постоянного адреса портала
 * (APP_DEFAULT_URI) обработчик знает только «localhost» — такие задачи выполняются в запросе пользователя.
 */
interface UsesPortalLinks
{
}
