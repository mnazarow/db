<?php

declare(strict_types=1);

namespace App\Service\Text;

/**
 * Файлу нужно распознавание (скан), но в этом процессе оно не выполняется: в веб-запросе OCR мог бы
 * идти минутами. Текст распознает фоновое задание app:search:reindex (cron).
 */
final class OcrDeferredException extends \RuntimeException
{
    public function __construct(public readonly string $textLayer)
    {
        parent::__construct('Распознавание отложено до фонового задания.');
    }
}
