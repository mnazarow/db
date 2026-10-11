<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Service\Csv;
use App\Service\PortalUrl;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGenerator;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

/**
 * Выгрузки CSV без формул и ссылки в уведомлениях от постоянного адреса портала.
 */
final class CsvTest extends TestCase
{
    public function testFormulaLikeValuesAreEscaped(): void
    {
        self::assertSame("'=HYPERLINK(\"http://evil\")", Csv::cell('=HYPERLINK("http://evil")'));
        self::assertSame("'+7 (999) cmd", Csv::cell('+7 (999) cmd'));
        self::assertSame("'@SUM(A1)", Csv::cell('@SUM(A1)'));
        self::assertSame("'-2+3", Csv::cell('-2+3'));
        self::assertSame("'\tTAB", Csv::cell("\tTAB"));
        self::assertSame('-5', Csv::cell('-5'), 'отрицательное число — не формула');
        self::assertSame('-12,5', Csv::cell('-12,5'));
        self::assertSame(42, Csv::cell(42));
        self::assertNull(Csv::cell(null));
        self::assertSame('Обычный текст', Csv::cell('Обычный текст'));
        self::assertSame(['1', "'=1+1", ''], Csv::row([true, '=1+1', '']));
    }

    public function testNotificationLinksIgnoreRequestHost(): void
    {
        $routes = new RouteCollection();
        $routes->add('app_document_show', new Route('/documents/{id}'));
        // Текущий запрос пришёл с подменённым заголовком Host.
        $generator = new UrlGenerator($routes, new RequestContext('', 'GET', 'evil.example', 'https'));

        $fixed = new PortalUrl($generator, 'https://docs.example.ru');
        self::assertTrue($fixed->isConfigured());
        self::assertSame('https://docs.example.ru/documents/5', $fixed->absolute('app_document_show', ['id' => 5]));
        self::assertSame('evil.example', $generator->getContext()->getHost(), 'контекст маршрутизатора восстановлен');

        // Адрес не настроен (localhost) — как раньше, по текущему запросу.
        $local = new PortalUrl($generator, 'http://localhost');
        self::assertFalse($local->isConfigured());
        self::assertSame('https://evil.example/documents/5', $local->absolute('app_document_show', ['id' => 5]));
    }
}
