<?php

declare(strict_types=1);

namespace App\Service\Pdf;

use App\Entity\Document;
use App\Entity\User;
use App\Security\Access;
use App\Service\PortalSettings;
use App\Service\Process\Shell;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Водяной знак на PDF при просмотре и скачивании: ФИО и логин сотрудника, дата и время выдачи файла.
 * Отпугивает от пересылки документов наружу и помогает найти источник утечки по снимку экрана или копии.
 *
 * Как это работает: по размерам страниц исходного файла (их сообщает qpdf) портал рисует PDF с полупрозрачным
 * диагональным текстом — по странице на каждый размер, — и qpdf накладывает его поверх страниц исходника
 * (qpdf --overlay). Исходный файл в хранилище не меняется; каждый раз готовится новая копия.
 *
 * Нет qpdf или файл не удалось обработать (повреждён, зашифрован с паролем) — отдаётся исходный файл, а в журнал
 * пишется предупреждение: сотрудник получает документ, администратор видит проблему в «Мониторинге».
 */
final class PdfWatermarker
{
    public const MODE_OFF = 'off';
    /** Только документы с ограниченным доступом (открыты отдельным сотрудникам или подразделениям). */
    public const MODE_RESTRICTED = 'restricted';
    /** Все документы, закрытые от гостей (внутренние и с ограниченным доступом). */
    public const MODE_INTERNAL = 'internal';
    public const MODE_ALL = 'all';

    public const MODES = [
        self::MODE_OFF => 'Выключен',
        self::MODE_RESTRICTED => 'Только документы с ограниченным доступом',
        self::MODE_INTERNAL => 'Все документы, закрытые от гостей',
        self::MODE_ALL => 'Все документы в PDF',
    ];

    /** Файлы крупнее не обрабатываются (отдаются как есть): наложение на сотни мегабайт задержало бы скачивание. */
    public const MAX_BYTES = 200 * 1024 * 1024;

    private const TIMEOUT = 60;
    /** Разных размеров страниц больше — документ отдаётся без знака (так не бывает у обычных документов). */
    private const MAX_SIZES = 50;

    public function __construct(
        private readonly PdfFactory $factory,
        private readonly PortalSettings $settings,
        private readonly LoggerInterface $logger,
        private readonly Access $access,
        #[Autowire(env: 'default::QPDF_BIN')]
        private readonly ?string $qpdfBin = null,
    ) {
    }

    /** На сервере есть qpdf. */
    public function isAvailable(): bool
    {
        return Shell::exists($this->bin());
    }

    /** Нужен ли знак на файле этого документа для этого сотрудника (тем, кто правит документ, — по настройке нет). */
    public function needsMark(Document $document, ?User $user): bool
    {
        if (!$this->appliesTo($document)) {
            return false;
        }

        return !($this->settings->watermark()['skip_editors'] && null !== $user && $this->access->canEditDocument($user, $document));
    }

    /**
     * Ставить ли знак на сборники разделов (PDF из многих документов): при режимах «закрытые от гостей» и «все».
     * Сборники собирают модераторы раздела, поэтому при «не ставить тем, кто правит документы» знака нет.
     */
    public function marksBundles(): bool
    {
        $settings = $this->settings->watermark();

        return \in_array($settings['mode'], [self::MODE_INTERNAL, self::MODE_ALL], true) && !$settings['skip_editors'];
    }

    /** Нужен ли знак для этого документа при текущей настройке. */
    public function appliesTo(Document $document): bool
    {
        return match ($this->settings->watermark()['mode']) {
            self::MODE_ALL => true,
            self::MODE_INTERNAL => !$document->isPublic() || $document->isRestricted(),
            self::MODE_RESTRICTED => $document->isRestricted(),
            default => false,
        };
    }

    /**
     * Текст знака: крупная диагональная строка и строка внизу страницы.
     *
     * @return array{main: string, footer: string}
     */
    public function texts(?User $user, ?string $ip, ?\DateTimeInterface $at = null, string $documentTitle = ''): array
    {
        $at ??= new \DateTimeImmutable();
        if (null !== $user) {
            $name = trim($user->getDisplayName());
            $who = '' !== $name && $name !== $user->getUsername() ? $name.' ('.$user->getUsername().')' : $user->getUsername();
        } else {
            $who = 'Гость'.(null !== $ip && '' !== $ip ? ' · '.$ip : '');
        }
        $when = $at->format('d.m.Y H:i');
        $extra = trim($this->settings->watermark()['text']);
        $footer = 'Выдано: '.$who.', '.$when.(null !== $ip && '' !== $ip && null !== $user ? ', '.$ip : '');
        if ('' !== $documentTitle) {
            $footer .= ' · '.mb_strimwidth($documentTitle, 0, 80, '…');
        }

        return [
            'main' => ('' !== $extra ? $extra.' · ' : '').$who.' · '.$when,
            'footer' => $footer,
        ];
    }

    /**
     * Знак для страниц портала, которые показываются в браузере: SVG-плитка с повёрнутым текстом (data:-адрес
     * для background-image). Отпугивает от снимков экрана так же, как знак на PDF.
     */
    public function svgDataUri(string $text): string
    {
        $text = mb_strimwidth($text, 0, 110, '…');
        // Плитка под длину строки: при повороте на 20° строка целиком помещается в плитку и не обрезается.
        $width = (int) max(360, min(1000, mb_strlen($text) * 7.6 + 80));
        $height = (int) max(180, round($width * 0.42));
        $cx = intdiv($width, 2);
        $cy = intdiv($height, 2);
        $text = htmlspecialchars($text, \ENT_XML1 | \ENT_QUOTES, 'UTF-8');
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="'.$width.'" height="'.$height.'" viewBox="0 0 '.$width.' '.$height.'">'
            .'<text x="'.$cx.'" y="'.$cy.'" text-anchor="middle" dominant-baseline="middle" transform="rotate(-20 '.$cx.' '.$cy.')" '
            .'font-family="DejaVu Sans, Arial, sans-serif" font-size="13" fill="#5f6673" fill-opacity="0.17">'.$text.'</text></svg>';

        return 'data:image/svg+xml;charset=utf-8,'.rawurlencode($svg);
    }

    /**
     * Копия PDF с водяным знаком во временном файле (его удаляет вызывающий) или null — отдать исходный файл.
     *
     * @param array{main: string, footer: string} $texts
     */
    public function apply(string $source, array $texts): ?string
    {
        if (!$this->isAvailable()) {
            $this->logger->warning('Водяной знак не наложен: на сервере нет qpdf (установите пакет qpdf)', ['file' => basename($source)]);

            return null;
        }
        $size = @filesize($source);
        if (false === $size || $size > self::MAX_BYTES) {
            $this->logger->notice('Водяной знак не наложен: файл слишком большой или недоступен', ['file' => basename($source), 'bytes' => $size]);

            return null;
        }
        $pages = $this->pageSizes($source);
        if (null === $pages || [] === $pages) {
            return null;
        }
        // Страницы одного размера получают одну страницу знака: --overlay знак.pdf --from=N --to=список страниц.
        $sizes = [];
        $groups = [];
        foreach ($pages as $number => [$width, $height]) {
            $key = \sprintf('%.1fx%.1f', $width, $height);
            if (!isset($sizes[$key])) {
                if (\count($sizes) >= self::MAX_SIZES) {
                    $this->logger->notice('Водяной знак не наложен: слишком много разных размеров страниц', ['file' => basename($source)]);

                    return null;
                }
                $sizes[$key] = [$width, $height];
            }
            $groups[$key][] = $number;
        }

        $mark = (string) tempnam(sys_get_temp_dir(), 'docportal-wm-');
        $out = (string) tempnam(sys_get_temp_dir(), 'docportal-pdf-');
        try {
            if ('' === $mark || '' === $out || false === @file_put_contents($mark, $this->markPdf(array_values($sizes), $texts))) {
                throw new \RuntimeException('не удалось записать временный файл');
            }
            $argv = [$this->bin(), '--warning-exit-0', $source];
            $from = 0;
            foreach ($groups as $numbers) {
                ++$from;
                $argv = [...$argv, '--overlay', $mark, '--to='.self::ranges($numbers), '--from=', '--repeat='.$from, '--'];
            }
            $argv[] = $out;
            $result = Shell::run($argv, self::TIMEOUT);
            if (0 !== $result['code'] || 0 === (int) @filesize($out)) {
                throw new \RuntimeException(trim($result['err']) ?: 'код '.$result['code']);
            }
        } catch (\Throwable $e) {
            @unlink($out);
            $this->logger->warning('Водяной знак не наложен: qpdf не обработал файл', ['file' => basename($source), 'error' => mb_substr($e->getMessage(), 0, 500)]);

            return null;
        } finally {
            @unlink($mark);
        }

        return $out;
    }

    /**
     * PDF со страницами знака заданных размеров.
     *
     * @param list<array{0: float, 1: float}> $sizes
     * @param array{main: string, footer: string} $texts
     */
    public function markPdf(array $sizes, array $texts): string
    {
        $pdf = $this->factory->create();
        $pdf->setInfo('Водяной знак');
        foreach ($sizes as [$width, $height]) {
            $pdf->addPage($width, $height);
            $diagonal = sqrt($width * $width + $height * $height);
            $angle = rad2deg(atan2($height, $width));
            $sin = sin(deg2rad($angle));
            $cos = cos(deg2rad($angle));
            // Центральная строка — по диагонали страницы, ~70 % её длины (не крупнее 34 и не мельче 9 пунктов).
            $base = $pdf->textWidth($texts['main'], 10.0);
            $font = $base > 0 ? max(9.0, min(34.0, 10.0 * $diagonal * 0.7 / $base)) : 24.0;
            $pdf->rotatedText($width / 2, $height / 2, $texts['main'], $font, $angle, [0.45, 0.47, 0.52], 0.16);
            // Ещё две строки параллельно ей, ближе к углам: знак виден и на снимке части страницы.
            // Расстояние до дальнего угла от диагонали — w·h/диагональ; длина хорды на расстоянии d — диагональ·(1 − d/это).
            $far = $width * $height / $diagonal;
            $offset = 0.38 * $far;
            $chord = $diagonal * (1 - $offset / $far);
            $side = $base > 0 ? max(7.0, min($font, 10.0 * $chord * 0.62 / $base)) : $font * 0.7;
            foreach ([-1, 1] as $direction) {
                $pdf->rotatedText($width / 2 - $direction * $offset * $sin, $height / 2 + $direction * $offset * $cos, $texts['main'], $side, $angle, [0.45, 0.47, 0.52], 0.12);
            }
            $footerSize = max(5.0, min(7.0, $width / 90));
            $footer = $texts['footer'];
            while ('' !== $footer && $pdf->textWidth($footer, $footerSize) > $width - 24 && mb_strlen($footer) > 10) {
                $footer = mb_substr($footer, 0, -6).'…';
            }
            $pdf->rotatedText($width / 2, 10 + $footerSize / 2, $footer, $footerSize, 0.0, [0.35, 0.37, 0.42], 0.55);
        }

        return $pdf->output();
    }

    /**
     * Размеры страниц в том виде, как их видит читатель (с учётом /Rotate): номер страницы → [ширина, высота].
     * null — qpdf не смог прочитать файл.
     *
     * @return array<int, array{0: float, 1: float}>|null
     */
    public function pageSizes(string $source): ?array
    {
        $result = Shell::run([$this->bin(), '--warning-exit-0', '--json=2', '--json-key=pages', '--json-key=qpdf', '--json-stream-data=none', $source], self::TIMEOUT, null, [], 256 * 1024 * 1024);
        $data = 0 === $result['code'] && !$result['truncated'] ? json_decode($result['out'], true) : null;
        if (!\is_array($data) || !\is_array($data['pages'] ?? null) || !\is_array($data['qpdf'][1] ?? null)) {
            $this->logger->warning('Водяной знак не наложен: qpdf не прочитал PDF', ['file' => basename($source), 'error' => mb_substr(trim($result['err']), 0, 500)]);

            return null;
        }
        $objects = $data['qpdf'][1];
        $sizes = [];
        foreach ($data['pages'] as $i => $page) {
            $ref = (string) ($page['object'] ?? '');
            $box = $this->inherited($objects, $ref, '/CropBox') ?? $this->inherited($objects, $ref, '/MediaBox');
            $box = \is_array($box) ? $this->resolve($objects, $box) : null;
            if (!\is_array($box) || 4 !== \count($box)) {
                $box = [0, 0, PdfDocument::A4_WIDTH, PdfDocument::A4_HEIGHT];
            }
            $values = array_map(fn ($v): float => (float) $this->resolve($objects, $v), array_values($box));
            $width = abs($values[2] - $values[0]);
            $height = abs($values[3] - $values[1]);
            if ($width < 1 || $height < 1) {
                [$width, $height] = [PdfDocument::A4_WIDTH, PdfDocument::A4_HEIGHT];
            }
            $rotate = ((int) $this->resolve($objects, $this->inherited($objects, $ref, '/Rotate') ?? 0)) % 360;
            if (90 === abs($rotate) || 270 === abs($rotate)) {
                [$width, $height] = [$height, $width];
            }
            $sizes[$i + 1] = [round($width, 1), round($height, 1)];
        }

        return $sizes;
    }

    /** «1,2,3,5,7,8» → «1-3,5,7-8». */
    public static function ranges(array $numbers): string
    {
        sort($numbers);
        $parts = [];
        $start = $prev = null;
        foreach ($numbers as $n) {
            if (null !== $prev && $n === $prev + 1) {
                $prev = $n;
                continue;
            }
            if (null !== $start) {
                $parts[] = $start === $prev ? (string) $start : $start.'-'.$prev;
            }
            $start = $prev = $n;
        }
        if (null !== $start) {
            $parts[] = $start === $prev ? (string) $start : $start.'-'.$prev;
        }

        return implode(',', $parts);
    }

    /** Значение ключа страницы с учётом наследования от родительских /Pages. */
    private function inherited(array $objects, string $ref, string $key): mixed
    {
        for ($depth = 0; $depth < 32 && '' !== $ref; ++$depth) {
            $dict = $objects['obj:'.$ref]['value'] ?? null;
            if (!\is_array($dict)) {
                return null;
            }
            if (\array_key_exists($key, $dict)) {
                return $dict[$key];
            }
            $ref = \is_string($dict['/Parent'] ?? null) ? $dict['/Parent'] : '';
        }

        return null;
    }

    /** Ссылка «N 0 R» → значение объекта. */
    private function resolve(array $objects, mixed $value): mixed
    {
        for ($depth = 0; $depth < 8 && \is_string($value) && preg_match('/^\d+ \d+ R$/', $value); ++$depth) {
            $value = $objects['obj:'.$value]['value'] ?? null;
        }

        return $value;
    }

    private function bin(): string
    {
        $bin = trim((string) $this->qpdfBin);

        return '' !== $bin ? $bin : 'qpdf';
    }
}
