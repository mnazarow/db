<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Document;

/**
 * Расчёт состояния актуальности документа по дате «действителен до».
 */
final class Validity
{
    public const NONE = 'none';       // срок не задан (бессрочный)
    public const VALID = 'valid';     // актуален
    public const SOON = 'soon';       // срок истекает в ближайшие N дней
    public const EXPIRED = 'expired'; // срок истёк

    public const LABELS = [
        self::NONE => 'Бессрочный',
        self::VALID => 'Актуален',
        self::SOON => 'Истекает',
        self::EXPIRED => 'Просрочен',
    ];

    public function __construct(
        private readonly int $expirySoonDays,
        private readonly string $timezone,
    ) {
    }

    public function getSoonDays(): int
    {
        return $this->expirySoonDays;
    }

    public function today(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('today', new \DateTimeZone($this->timezone));
    }

    public function stateOf(Document $document): string
    {
        return $this->stateForDate($document->getValidUntil());
    }

    public function stateForDate(?\DateTimeImmutable $validUntil): string
    {
        if (null === $validUntil) {
            return self::NONE;
        }
        $days = (int) $this->today()->diff($validUntil->setTime(0, 0))->format('%r%a');
        if ($days < 0) {
            return self::EXPIRED;
        }
        if ($days <= $this->expirySoonDays) {
            return self::SOON;
        }

        return self::VALID;
    }

    public function daysLeft(Document $document): ?int
    {
        return $document->getDaysLeft($this->today());
    }

    /** Человекочитаемое пояснение: «истекает через 12 дн.», «просрочен на 3 дн.», «до 31.12.2026». */
    public function describe(Document $document): string
    {
        $days = $this->daysLeft($document);
        if (null === $days) {
            return 'срок актуальности не ограничен';
        }
        $date = $document->getValidUntil()?->format('d.m.Y') ?? '';
        if ($days < 0) {
            return \sprintf('просрочен на %s (до %s)', self::plural(-$days, ['день', 'дня', 'дней']), $date);
        }
        if (0 === $days) {
            return \sprintf('истекает сегодня (%s)', $date);
        }
        if ($days <= $this->expirySoonDays) {
            return \sprintf('истекает через %s (%s)', self::plural($days, ['день', 'дня', 'дней']), $date);
        }

        return \sprintf('актуален до %s', $date);
    }

    /** @param array{0: string, 1: string, 2: string} $forms */
    public static function plural(int $n, array $forms): string
    {
        $n = abs($n);
        $mod10 = $n % 10;
        $mod100 = $n % 100;
        if (1 === $mod10 && 11 !== $mod100) {
            $form = $forms[0];
        } elseif ($mod10 >= 2 && $mod10 <= 4 && ($mod100 < 10 || $mod100 >= 20)) {
            $form = $forms[1];
        } else {
            $form = $forms[2];
        }

        return $n.' '.$form;
    }
}
