<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Service\Html\PageHtml;
use PHPUnit\Framework\TestCase;

/** Неразрывные пробелы из редактора страниц: текст снова переносится по строкам. */
final class PageHtmlTest extends TestCase
{
    private const NBSP = "\u{00A0}";

    public function testSpacesFromEditorBecomeBreakable(): void
    {
        $n = self::NBSP;
        self::assertSame('<p>Один два</p>', PageHtml::breakableSpaces("<p>Один{$n}два</p>"));
        self::assertSame("<p>Один <strong>два</strong> три</p>", PageHtml::breakableSpaces("<p>Один{$n}<strong>два</strong>{$n}три</p>"));
        self::assertSame("<p>а{$n}{$n} б</p>", PageHtml::breakableSpaces("<p>а{$n}{$n}{$n}б</p>"), 'из серии пробелов переносимым становится последний');
        self::assertSame("<p>{$n} Отступ</p>", PageHtml::breakableSpaces("<p>{$n}{$n}Отступ</p>"));
        self::assertSame('текст без тегов', PageHtml::breakableSpaces("текст{$n}без{$n}тегов"));
    }

    public function testIntendedNonBreakingSpacesStay(): void
    {
        $n = self::NBSP;
        self::assertSame("<p>Цена 10{$n}000 руб.</p>", PageHtml::breakableSpaces("<p>Цена 10{$n}000 руб.</p>"), 'рядом с обычными пробелами неразрывный — намеренный');
        self::assertSame("<p>{$n}</p>", PageHtml::breakableSpaces("<p>{$n}</p>"), '«пустой» абзац сохраняет высоту');
        self::assertSame('<p>обычный текст</p>', PageHtml::breakableSpaces('<p>обычный текст</p>'));
        $once = PageHtml::breakableSpaces("<p>а{$n}б{$n}{$n}в</p>");
        self::assertSame($once, PageHtml::breakableSpaces($once), 'повторное применение ничего не меняет');
    }
}
