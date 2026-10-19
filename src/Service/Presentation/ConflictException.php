<?php

declare(strict_types=1);

namespace App\Service\Presentation;

use App\Entity\Presentation;

/** Слайды уже сохранил кто-то другой: рабочая редакция на сервере новее той, что правили в редакторе. */
final class ConflictException extends \RuntimeException
{
    public function __construct(public readonly Presentation $presentation)
    {
        parent::__construct('Презентацию уже изменили в другом окне или другой сотрудник.');
    }
}
