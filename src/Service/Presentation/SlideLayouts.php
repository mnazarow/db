<?php

declare(strict_types=1);

namespace App\Service\Presentation;

/**
 * Макеты слайдов в корпоративном стиле — по образцу «Презентация к собранию» (Собрание №131): те же
 * размеры и положение знака, заголовка и строки «Ответственный», шрифты (DIN Condensed Light для заголовков
 * прописными, Roboto для текста), цвета и типы слайдов. Из макетов редактор создаёт новые слайды, а сборка
 * собрания — титульный слайд, содержание и финальный слайд.
 *
 * Геометрия — в пикселях слайда 1280×720 (1 px = 9525 EMU, как в PowerPoint 13,33″ × 7,5″), размеры шрифтов — в пунктах.
 */
final class SlideLayouts
{
    public const BLUE = '#0F4382';
    public const BLUE_DARK = '#034185';
    public const BLUE_LIGHT = '#79A7C6';
    public const GREY = '#DFDEDE';
    public const PANEL = '#E6E9F0';
    public const BG_LIGHT = '#FAFAFA';
    public const TEXT = '#262626';
    public const MUTED = '#808080';
    public const CAPTION = '#383838';
    public const RED = '#C00000';
    public const WHITE = '#FFFFFF';

    /** Фирменная палитра для выбора цвета в редакторе. */
    public const PALETTE = [
        ['#0F4382', 'Синий фирменный'], ['#034185', 'Синий тёмный'], ['#79A7C6', 'Голубой'], ['#DFDEDE', 'Серый светлый'],
        ['#E6E9F0', 'Фон панели'], ['#FAFAFA', 'Фон светлый'], ['#262626', 'Текст'], ['#383838', 'Подписи'],
        ['#808080', 'Текст второстепенный'], ['#C00000', 'Красный акцент'], ['#FFFFFF', 'Белый'], ['#000000', 'Чёрный'],
    ];

    /** Цвета рядов диаграмм. */
    public const CHART_COLORS = ['#0F4382', '#79A7C6', '#034185', '#DFDEDE', '#C00000', '#5B7FB0', '#A9C6DD', '#383838'];

    /** @var array<string, array{name: string, group: string, description: string}> */
    public const LAYOUTS = [
        'cover' => ['name' => 'Титульный', 'group' => 'Оформление', 'description' => 'Синий фон, белый знак и вертикальная надпись «ВОДО КОМФОРТ»'],
        'contents' => ['name' => 'Содержание', 'group' => 'Оформление', 'description' => 'Нумерованный список тем и фото справа'],
        'section' => ['name' => 'Раздел', 'group' => 'Оформление', 'description' => 'Синий разделитель с крупным заголовком'],
        'closing' => ['name' => 'Финальный', 'group' => 'Оформление', 'description' => 'Логотип ВОДОКОМФОРТ на синем фоне'],
        'title-text' => ['name' => 'Заголовок и текст', 'group' => 'Текст', 'description' => 'Шапка и текст списком'],
        'two-columns' => ['name' => 'Две колонки', 'group' => 'Текст', 'description' => 'Шапка и два столбца текста'],
        'text-photo' => ['name' => 'Анонс с фото', 'group' => 'Текст', 'description' => 'Тема, программа, дата и время; фото на светлой панели справа'],
        'steps' => ['name' => 'Пояснение с иллюстрацией', 'group' => 'Текст', 'description' => 'Иллюстрация слева, пояснение и шаги справа'],
        'table' => ['name' => 'Таблица', 'group' => 'Данные', 'description' => 'Таблица в фирменном стиле (как «План продаж»)'],
        'chart' => ['name' => 'Диаграмма', 'group' => 'Данные', 'description' => 'Столбчатая диаграмма в фирменных цветах'],
        'stats' => ['name' => 'Цифры', 'group' => 'Данные', 'description' => 'Три крупных показателя с подписями'],
        'screenshot' => ['name' => 'Скриншот', 'group' => 'Изображения', 'description' => 'Шапка и большой снимок экрана'],
        'two-photos' => ['name' => 'Два фото', 'group' => 'Изображения', 'description' => 'Шапка и два вертикальных фото'],
        'photo-full' => ['name' => 'Фото во весь слайд', 'group' => 'Изображения', 'description' => 'Изображение на весь слайд под шапкой'],
        'jubilee' => ['name' => 'Юбилей', 'group' => 'Люди', 'description' => 'Фото в круге с шарами, факты и число лет'],
        'qr' => ['name' => 'QR-коды', 'group' => 'Люди', 'description' => 'Четыре QR-кода с подписями (каналы, ссылки)'],
        'poster' => ['name' => 'Афиша', 'group' => 'Люди', 'description' => 'Название, подробности и большое изображение (как «Кино от Миши»)'],
        'header' => ['name' => 'Только шапка', 'group' => 'Пустые', 'description' => 'Знак, заголовок и ответственный'],
        'blank' => ['name' => 'Пустой', 'group' => 'Пустые', 'description' => 'Белый слайд без элементов'],
    ];

    /**
     * Слайд по макету.
     *
     * @param array{bound?: bool, title?: string, date?: string, items?: list<string>} $o bound — заголовок
     *                                                                                    шапки привязан к названию темы
     *
     * @return array<string, mixed>
     */
    public static function slide(string $layout, array $o = []): array
    {
        $bound = $o['bound'] ?? false;
        $els = match ($layout) {
            'cover' => self::cover($o),
            'contents' => self::contents($o),
            'section' => self::section($o),
            'closing' => [self::image('brand:logo-full-white', 538, 290, 203, 141, 'contain', 'Логотип')],
            'title-text' => array_merge(self::header($bound), [
                self::text(47, 194, 1186, 470, '', ['fontSize' => 16, 'lineHeight' => 1.2, 'spaceBefore' => 4, 'placeholder' => 'Текст слайда: каждый абзац — с новой строки', 'listColor' => '#104382', 'list' => 'bullet']),
            ]),
            'two-columns' => array_merge(self::header($bound), [
                self::text(47, 194, 560, 470, '', ['fontSize' => 16, 'lineHeight' => 1.2, 'spaceBefore' => 4, 'placeholder' => 'Левая колонка', 'listColor' => '#104382', 'list' => 'bullet']),
                self::text(673, 194, 560, 470, '', ['fontSize' => 16, 'lineHeight' => 1.2, 'spaceBefore' => 4, 'placeholder' => 'Правая колонка', 'listColor' => '#104382', 'list' => 'bullet']),
            ]),
            'text-photo' => self::textPhoto($bound),
            'steps' => self::steps($bound),
            'table' => array_merge(self::header($bound), [self::table(35, 282, 1199, 238)]),
            'chart' => array_merge(self::header($bound), [self::chart(80, 180, 1120, 500)]),
            'stats' => self::stats($bound),
            'screenshot' => array_merge(self::header($bound), [
                self::image(null, 100, 194, 1081, 526, 'contain', 'Снимок экрана', ['radius' => 12, 'shadow' => ['blur' => 24, 'x' => 0, 'y' => 6, 'color' => '#0F438233']]),
            ]),
            'two-photos' => array_merge(self::header($bound), [
                self::image(null, 212, 177, 378, 504, 'cover', 'Фото'),
                self::image(null, 658, 177, 378, 504, 'cover', 'Фото'),
            ]),
            'photo-full' => array_merge(self::header($bound), [self::image(null, 47, 177, 1186, 503, 'cover', 'Изображение')]),
            'jubilee' => self::jubilee($bound),
            'qr' => self::qrSlide($bound),
            'poster' => self::poster($bound),
            'header' => self::header($bound),
            default => [],
        };
        $background = match ($layout) {
            'cover', 'section' => self::BLUE,
            'closing' => self::BLUE_DARK,
            'steps' => self::BG_LIGHT,
            default => self::WHITE,
        };

        return [
            'id' => DeckValidator::newId('s'),
            'layout' => isset(self::LAYOUTS[$layout]) ? $layout : 'blank',
            'background' => ['color' => $background, 'image' => null, 'fit' => 'cover'],
            'transition' => 'none',
            'hidden' => false,
            'notes' => '',
            'elements' => $els,
        ];
    }

    /** @return array<string, array{name: string, group: string, description: string, slide: array<string, mixed>, boundSlide: array<string, mixed>}> */
    public static function forEditor(): array
    {
        $out = [];
        foreach (self::LAYOUTS as $key => $info) {
            $out[$key] = $info + ['slide' => self::slide($key), 'boundSlide' => self::slide($key, ['bound' => true])];
        }

        return $out;
    }

    // ---- Шапка светлых слайдов ---------------------------------------------------------------------------

    /**
     * Знак «капля», заголовок (DIN Condensed Light 40 пт, прописные, #0F4382) и «Ответственный: …» (Roboto 14 пт).
     *
     * @return list<array<string, mixed>>
     */
    public static function header(bool $bound, float $titleWidth = 943, int $titleLines = 1, ?string $title = null): array
    {
        $titleH = 1 === $titleLines ? 65 : 109;

        return [
            self::image('brand:logo-mark', 47, 48, 75, 61, 'contain', 'Знак', ['locked' => true, 'name' => 'Знак']),
            self::text(146, 48, $titleWidth, $titleH, null !== $title ? '<p>'.DeckValidator::esc($title).'</p>' : '', [
                'fontFamily' => 'DIN Condensed Light', 'fontSize' => 40, 'color' => self::BLUE, 'caps' => true, 'lineHeight' => 1.0,
                'placeholder' => 'Заголовок слайда', 'name' => 'Заголовок', 'autoFit' => true,
            ] + ($bound ? ['bind' => 'title'] : [])),
            self::text(146, 48 + $titleH, 571, 25, '', ['fontSize' => 14, 'lineHeight' => 1.2, 'bind' => 'responsible', 'name' => 'Ответственный', 'color' => self::TEXT]),
        ];
    }

    // ---- Макеты -----------------------------------------------------------------------------------------

    /** @return list<array<string, mixed>> */
    private static function cover(array $o): array
    {
        $title = $o['title'] ?? null;

        return [
            self::image('brand:logo-white', 48, 40, 82, 65, 'contain', 'Знак', ['locked' => true]),
            self::text(46, 528, 825, 107, null !== $title ? '<p>'.DeckValidator::esc($title).'</p>' : '', [
                'fontFamily' => 'DIN Condensed Light', 'fontSize' => 66, 'color' => self::WHITE, 'caps' => true, 'lineHeight' => 1.0, 'valign' => 'bottom',
                'placeholder' => 'Название презентации', 'name' => 'Название',
            ] + (isset($o['bindTitle']) ? ['bind' => $o['bindTitle']] : [])),
            self::text(46, 648, 439, 29, isset($o['date']) ? '<p>'.DeckValidator::esc($o['date']).'</p>' : '', [
                'fontSize' => 12, 'color' => self::WHITE, 'placeholder' => 'Дата', 'name' => 'Дата',
            ] + (isset($o['bindDate']) ? ['bind' => $o['bindDate']] : [])),
            self::image('brand:wordmark-vertical', 946, 0, 336, 720, 'contain', 'ВОДО КОМФОРТ', ['locked' => true]),
        ];
    }

    /** @return list<array<string, mixed>> */
    private static function contents(array $o): array
    {
        $items = $o['items'] ?? [];
        $html = '';
        foreach ($items as $item) {
            $html .= '<p data-list="number">'.DeckValidator::esc($item).'</p>';
        }
        // Больше десяти тем — две колонки и чуть мельче, как подбирал бы дизайнер.
        $els = [
            self::image('brand:logo-mark', 47, 48, 75, 61, 'contain', 'Знак', ['locked' => true]),
            self::text(146, 48, 494, 65, '<p>Содержание</p>', ['fontFamily' => 'DIN Condensed Light', 'fontSize' => 40, 'color' => self::BLUE, 'caps' => true, 'name' => 'Заголовок']),
            self::image('brand:contents-photo', 776, 0, 504, 720, 'cover', 'Фото'),
        ];
        $style = ['fontSize' => 16, 'lineHeight' => 1.2, 'spaceBefore' => 4, 'listColor' => '#104382', 'placeholder' => 'Темы — по одной в строке', 'name' => 'Темы'];
        if (\count($items) > 10) {
            $half = (int) ceil(\count($items) / 2);
            $left = '';
            $right = '';
            foreach ($items as $i => $item) {
                $p = '<p data-list="number">'.DeckValidator::esc($item).'</p>';
                if ($i < $half) {
                    $left .= $p;
                } else {
                    $right .= $p;
                }
            }
            $size = \count($items) > 20 ? 12 : 14;
            $els[] = self::text(47, 178, 330, 500, $left, ['fontSize' => $size] + $style);
            // Нумерация второй колонки продолжается — отдельный текст с явными номерами.
            $numbered = '';
            foreach (\array_slice($items, $half) as $i => $item) {
                $numbered .= '<p>'.($half + $i + 1).'. '.DeckValidator::esc($item).'</p>';
            }
            $els[] = self::text(400, 178, 340, 500, $numbered, ['fontSize' => $size] + $style);
        } else {
            $els[] = self::text(47, 178, 529, 320, '' === $html ? '' : $html, $style + ['list' => 'number']);
        }

        return $els;
    }

    /** @return list<array<string, mixed>> */
    private static function section(array $o): array
    {
        return [
            self::image('brand:logo-white', 48, 40, 82, 65, 'contain', 'Знак', ['locked' => true]),
            self::text(46, 300, 1100, 120, isset($o['title']) ? '<p>'.DeckValidator::esc($o['title']).'</p>' : '', [
                'fontFamily' => 'DIN Condensed Light', 'fontSize' => 66, 'color' => self::WHITE, 'caps' => true, 'valign' => 'middle', 'placeholder' => 'Название раздела',
            ]),
            self::text(48, 430, 900, 40, '', ['fontSize' => 16, 'color' => self::BLUE_LIGHT, 'placeholder' => 'Подзаголовок']),
        ];
    }

    /** @return list<array<string, mixed>> */
    private static function textPhoto(bool $bound): array
    {
        $label = ['fontSize' => 16, 'fontWeight' => 700, 'color' => self::BLUE_DARK];

        return array_merge(
            [self::shape('rect', 665, 0, 615, 720, self::PANEL, ['name' => 'Панель'])],
            [self::image(null, 717, 53, 545, 667, 'contain', 'Фото')],
            self::header($bound, 494, 2),
            [
                self::text(39, 157 + 44, 479, 28, '<p>Тема:</p>', $label),
                self::text(39, 195 + 44, 479, 112, '', ['fontSize' => 14, 'lineHeight' => 1.2, 'placeholder' => 'О чём будет мероприятие']),
                self::text(39, 371, 479, 28, '<p>В программе:</p>', $label),
                self::text(39, 414, 402, 149, '', ['fontSize' => 12, 'lineHeight' => 1.2, 'spaceBefore' => 4, 'list' => 'bullet', 'listColor' => '#104382', 'placeholder' => 'Пункты программы']),
                self::text(39, 625, 190, 25, '<p><b><span style="color:#034185">Дата: </span></b><span style="color:#1D1E1E">20 октября</span></p>', ['fontSize' => 14]),
                self::text(240, 625, 245, 25, '<p><b><span style="color:#034185">Время: </span></b><span style="color:#1D1E1E">11:00–13:00</span></p>', ['fontSize' => 14]),
            ],
        );
    }

    /** @return list<array<string, mixed>> */
    private static function steps(bool $bound): array
    {
        return array_merge(
            [self::image(null, 40, 200, 600, 480, 'contain', 'Иллюстрация')],
            self::header($bound, 571, 2),
            [
                self::text(690, 194, 529, 79, '', ['fontSize' => 14, 'lineHeight' => 1.2, 'placeholder' => 'Что изменилось']),
                self::text(690, 317, 529, 28, '<p>Как это сделать:</p>', ['fontSize' => 16, 'fontWeight' => 700, 'color' => self::BLUE_DARK]),
                self::text(690, 362, 529, 216, '', ['fontSize' => 13, 'lineHeight' => 1.2, 'spaceBefore' => 4, 'list' => 'number', 'listColor' => '#104382', 'placeholder' => 'Шаги — по одному в строке']),
                self::text(690, 611, 549, 48, '', ['fontSize' => 13, 'color' => self::BLUE_DARK, 'placeholder' => 'Важное примечание']),
            ],
        );
    }

    /** @return list<array<string, mixed>> */
    private static function stats(bool $bound): array
    {
        $els = self::header($bound);
        foreach ([[60, '20', 'лет на рынке'], [460, '48', 'филиалов'], [860, '17%', 'выполнение плана']] as [$x, $n, $caption]) {
            $els[] = self::text($x, 250, 360, 170, '<p>'.$n.'</p>', ['fontFamily' => 'DIN Condensed Light', 'fontSize' => 120, 'color' => self::BLUE, 'lineHeight' => 1.0]);
            $els[] = self::text($x, 430, 360, 60, '<p>'.$caption.'</p>', ['fontSize' => 16, 'color' => self::CAPTION, 'lineHeight' => 1.2]);
        }

        return $els;
    }

    /** @return list<array<string, mixed>> */
    private static function jubilee(bool $bound): array
    {
        return array_merge(
            [
                self::image(null, 718, 217, 458, 458, 'cover', 'Фото сотрудника', ['mask' => 'circle']),
                self::image('brand:balloons', 501, 95, 733, 661, 'contain', 'Шары', ['locked' => true]),
            ],
            self::header($bound, 545, 2),
            [
                self::text(39, 229, 479, 28, '<p>Факты о сотруднике:</p>', ['fontSize' => 16, 'fontWeight' => 700, 'color' => self::BLUE_DARK]),
                self::text(39, 274, 674, 155, '', ['fontSize' => 14, 'lineHeight' => 1.2, 'spaceBefore' => 4, 'list' => 'number', 'listColor' => '#104382', 'placeholder' => 'Интересные факты — по одному в строке']),
                self::text(39, 560, 545, 120, '<p><span style="font-size:120pt">20</span> лет</p>', ['fontFamily' => 'DIN Condensed Light', 'fontSize' => 40, 'color' => self::BLUE, 'lineHeight' => 1.0, 'valign' => 'bottom', 'caps' => true]),
            ],
        );
    }

    /** @return list<array<string, mixed>> */
    private static function qrSlide(bool $bound): array
    {
        $els = self::header($bound);
        foreach ([[70, 'Корпоративный Telegram-канал'], [370, 'Основной Telegram-канал'], [670, 'ДЗЕН'], [970, 'MAX']] as [$x, $caption]) {
            $els[] = self::el('qr', $x, 230, 240, 240, ['text' => 'https://vodokomfort.ru', 'color' => self::BLUE, 'bg' => self::WHITE]);
            $els[] = self::text($x - 20, 485, 280, 74, '<p>'.$caption.'</p>', ['fontSize' => 20, 'color' => self::CAPTION, 'align' => 'center', 'lineHeight' => 1.0]);
        }

        return $els;
    }

    /** @return list<array<string, mixed>> */
    private static function poster(bool $bound): array
    {
        return array_merge(
            [self::image(null, 560, 60, 680, 620, 'contain', 'Изображение')],
            self::header($bound),
            [
                self::text(47, 218, 500, 120, '<p>Название</p><p><span style="color:#808080">(Original title)</span></p>', ['fontFamily' => 'DIN Condensed Light', 'fontSize' => 36, 'color' => self::BLUE, 'caps' => true, 'lineHeight' => 1.0, 'autoFit' => true]),
                self::text(47, 350, 479, 58, '<p>Режиссёр: </p><p>Год: </p>', ['fontSize' => 14, 'lineHeight' => 1.2]),
            ],
        );
    }

    // ---- Элементы ---------------------------------------------------------------------------------------

    /** @return array<string, mixed> */
    public static function text(float $x, float $y, float $w, float $h, string $html, array $o = []): array
    {
        $list = $o['list'] ?? null;
        unset($o['list']);
        if (null !== $list && '' === $html && '' !== ($o['placeholder'] ?? '')) {
            // Пустой список: первый абзац уже маркирован — сотрудник сразу пишет пункты.
            $html = '<p data-list="'.$list.'"><br></p>';
        }

        return self::el('text', $x, $y, $w, $h, $o + [
            'html' => $html, 'fontFamily' => 'Roboto', 'fontSize' => 16, 'fontWeight' => 400, 'color' => self::TEXT,
            'align' => 'left', 'valign' => 'top', 'lineHeight' => 1.0, 'letterSpacing' => 0, 'caps' => false, 'fill' => null,
        ]);
    }

    /** @return array<string, mixed> */
    public static function image(?string $src, float $x, float $y, float $w, float $h, string $fit = 'cover', string $alt = '', array $o = []): array
    {
        return self::el('image', $x, $y, $w, $h, $o + ['src' => $src, 'fit' => $fit, 'alt' => $alt]);
    }

    /** @return array<string, mixed> */
    public static function shape(string $shape, float $x, float $y, float $w, float $h, ?string $fill, array $o = []): array
    {
        return self::el('shape', $x, $y, $w, $h, $o + ['shape' => $shape, 'fill' => $fill, 'html' => '', 'fontSize' => 16, 'color' => self::WHITE, 'align' => 'center', 'valign' => 'middle']);
    }

    /** @return array<string, mixed> */
    public static function table(float $x, float $y, float $w, float $h): array
    {
        $cell = static fn (string $t, array $o = []): array => $o + ['text' => $t];
        $blue = ['fill' => self::BLUE_DARK, 'color' => self::WHITE];

        return self::el('table', $x, $y, $w, $h, [
            'rows' => [
                [$cell('', $blue), $cell('План', $blue), $cell('Факт', $blue), $cell('% выполнения', $blue)],
                [$cell('Общий план', $blue + ['align' => 'left']), $cell('2 800 000 000 ₽'), $cell('481 014 477 ₽'), $cell('17%', ['color' => self::RED, 'bold' => true])],
                [$cell('План SPL', $blue + ['align' => 'left']), $cell('2 000 000 000 ₽'), $cell('255 871 754 ₽'), $cell('13%', ['color' => self::RED, 'bold' => true])],
            ],
            'cols' => [296, 301, 313, 288],
            'heights' => [52, 93, 93],
            'style' => ['header' => true, 'firstCol' => true, 'fontSize' => 20, 'fontFamily' => 'Roboto', 'borderColor' => '#BFBFBF', 'color' => self::TEXT, 'align' => 'center'],
        ]);
    }

    /** @return array<string, mixed> */
    public static function chart(float $x, float $y, float $w, float $h): array
    {
        return self::el('chart', $x, $y, $w, $h, [
            'chart' => 'column',
            'categories' => ['Октябрь', 'Ноябрь', 'Декабрь'],
            'series' => [
                ['name' => 'План', 'values' => [900, 950, 950], 'color' => self::BLUE],
                ['name' => 'Факт', 'values' => [481, 0, 0], 'color' => self::BLUE_LIGHT],
            ],
            'legend' => 'bottom', 'labels' => true, 'fontSize' => 14,
        ]);
    }

    /** @return array<string, mixed> */
    private static function el(string $type, float $x, float $y, float $w, float $h, array $props): array
    {
        return ['id' => DeckValidator::newId(), 'type' => $type, 'x' => $x, 'y' => $y, 'w' => $w, 'h' => $h, 'rotation' => 0, 'opacity' => 1] + $props;
    }
}
