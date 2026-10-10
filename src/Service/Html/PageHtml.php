<?php

declare(strict_types=1);

namespace App\Service\Html;

/**
 * Приведение HTML страниц документов к виду, который нормально переносится по строкам.
 *
 * Редактор Quill 2 при сохранении (getSemanticHTML) заменял каждый пробел неразрывным — такой абзац
 * не переносится по строкам, растягивает страницу вширь и даёт горизонтальную прокрутку на телефоне.
 */
final class PageHtml
{
    /**
     * В фрагментах текста без обычных пробелов неразрывные пробелы заменяются обычными: одиночный —
     * пробелом, серия — неразрывными, кроме последнего (видимый отступ сохраняется, а строка может
     * переноситься). Где обычные пробелы есть, неразрывные поставлены намеренно («10 000 руб.») и не трогаются.
     * Повторное применение ничего не меняет.
     */
    public static function breakableSpaces(string $html): string
    {
        if (!str_contains($html, "\u{00A0}")) {
            return $html;
        }
        $result = preg_replace_callback('/>([^<]*\x{00A0}[^<]*)</u', static function (array $m): string {
            // Обычные пробелы есть — неразрывные намеренные; только неразрывные («пустой» абзац) — тоже.
            if (str_contains($m[1], ' ') || '' === trim($m[1], "\u{00A0}\t\n\r")) {
                return $m[0];
            }

            return '>'.self::fixText($m[1]).'<';
        }, '>'.$html.'<');
        if (null === $result) {
            return $html; // некорректный UTF-8 — оставляем как есть
        }

        return substr($result, 1, -1);
    }

    private static function fixText(string $text): string
    {
        return preg_replace_callback('/\x{00A0}+/u', static fn (array $run): string => str_repeat("\u{00A0}", mb_strlen($run[0]) - 1).' ', $text) ?? $text;
    }
}
