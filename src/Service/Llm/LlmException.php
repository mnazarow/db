<?php

declare(strict_types=1);

namespace App\Service\Llm;

/** Ошибка обращения к внешней языковой модели (сеть, ключ, лимиты, неожиданный ответ). */
final class LlmException extends \RuntimeException
{
}
