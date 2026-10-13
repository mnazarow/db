<?php

declare(strict_types=1);

namespace App\Service\Sso;

/**
 * Ошибка входа через внешнего провайдера. Сообщение для сотрудника показывается на странице входа; ошибки
 * проверки ответа провайдера (подпись, адресат, срок) — техническиe: сотруднику показывается общее сообщение,
 * подробности пишутся в журнал аудита.
 */
final class SsoException extends \RuntimeException
{
    public const TECHNICAL = 1;

    public static function technical(string $message, ?\Throwable $previous = null): self
    {
        return new self($message, self::TECHNICAL, $previous);
    }

    public function isTechnical(): bool
    {
        return self::TECHNICAL === $this->getCode();
    }
}
