<?php

declare(strict_types=1);

namespace App\Service\Text;

use App\Service\PortalSettings;
use App\Service\Process\Shell;
use Psr\Log\LoggerInterface;

/**
 * Распознавание текста на сканах (OCR) для поиска: PDF без текстового слоя разбирается
 * постранично (pdftoppm) и прогоняется через tesseract; изображения распознаются напрямую.
 *
 * Включается администратором: распознавание нагружает процессор, и выполняется оно при
 * индексации (команда app:search:reindex по cron), а не в момент открытия документа.
 */
final class OcrReader
{
    /** Изображения, которые имеет смысл распознавать. */
    public const IMAGE_EXTENSIONS = ['png', 'jpg', 'jpeg', 'tif', 'tiff', 'bmp', 'webp'];

    /** Если в PDF текста меньше этого на страницу — считаем, что это скан. */
    public const SCAN_CHARS_PER_PAGE = 60;

    private ?bool $available = null;

    public function __construct(
        private readonly PortalSettings $settings,
        private readonly LoggerInterface $logger,
        private readonly string $defaultLanguages = 'rus+eng',
        private readonly string $tesseractBin = 'tesseract',
        private readonly string $pdftoppmBin = 'pdftoppm',
        private readonly int $timeoutSeconds = 120,
    ) {
    }

    /** Установлены ли tesseract и pdftoppm. */
    public function isAvailable(): bool
    {
        return $this->available ??= Shell::exists($this->tesseractBin) && Shell::exists($this->pdftoppmBin);
    }

    /** Включено ли распознавание администратором (и доступно ли технически). */
    public function isEnabled(): bool
    {
        if (!$this->isAvailable()) {
            return false;
        }
        try {
            return $this->settings->ocr($this->defaultLanguages)['enabled'];
        } catch (\Throwable) {
            // Настройки недоступны (например, база ещё не готова) — распознавание просто не выполняется.
            return false;
        }
    }

    /** @return list<string> языки, установленные в tesseract */
    public function installedLanguages(): array
    {
        $run = Shell::run([$this->tesseractBin, '--list-langs'], 20);
        $lines = preg_split('/\R/', $run['out'].$run['err']) ?: [];

        return array_values(array_filter(array_map('trim', $lines), static fn (string $l): bool => (bool) preg_match('/^[a-z]{3}(_\w+)?$/', $l)));
    }

    public function languages(): string
    {
        return $this->settings->ocr($this->defaultLanguages)['languages'];
    }

    /** Похож ли PDF на скан: текста почти нет. */
    public function looksLikeScan(string $text, int $pages): bool
    {
        return mb_strlen(trim($text)) < self::SCAN_CHARS_PER_PAGE * max(1, $pages);
    }

    /** Распознаёт текст в PDF-файле (постранично). Пустая строка — распознать не удалось. */
    public function readPdf(string $path): string
    {
        if (!$this->isEnabled()) {
            return '';
        }
        $options = $this->settings->ocr($this->defaultLanguages);
        $work = sys_get_temp_dir().'/docportal-ocr-'.bin2hex(random_bytes(6));
        if (!@mkdir($work, 0700, true)) {
            return '';
        }
        try {
            $render = Shell::run([
                $this->pdftoppmBin, '-r', (string) $options['dpi'], '-png',
                '-f', '1', '-l', (string) $options['max_pages'],
                $path, $work.'/page',
            ], $this->timeoutSeconds);
            $pages = glob($work.'/page*.png') ?: [];
            sort($pages);
            if ([] === $pages) {
                $this->logger->info('OCR: не удалось получить страницы PDF', ['code' => $render['code'], 'error' => mb_substr(trim($render['err']), 0, 200)]);

                return '';
            }
            $parts = [];
            foreach ($pages as $page) {
                $text = trim($this->readImageFile($page, $options['languages']));
                if ('' !== $text) {
                    $parts[] = $text;
                }
            }

            return implode("\n\n", $parts);
        } finally {
            foreach (glob($work.'/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($work);
        }
    }

    /** Распознаёт текст на изображении. */
    public function readImage(string $path): string
    {
        if (!$this->isEnabled()) {
            return '';
        }

        return trim($this->readImageFile($path, $this->settings->ocr($this->defaultLanguages)['languages']));
    }

    private function readImageFile(string $path, string $languages): string
    {
        $run = Shell::run([$this->tesseractBin, $path, 'stdout', '-l', $languages], $this->timeoutSeconds);
        if (0 !== $run['code'] && '' === trim($run['out'])) {
            $this->logger->info('OCR: распознать страницу не удалось', ['code' => $run['code'], 'error' => mb_substr(trim($run['err']), 0, 200)]);

            return '';
        }

        return $run['out'];
    }
}
