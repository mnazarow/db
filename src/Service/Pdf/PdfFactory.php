<?php

declare(strict_types=1);

namespace App\Service\Pdf;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\String\Slugger\AsciiSlugger;

/**
 * Новые документы PDF со шрифтами портала (DejaVu Sans: кириллица и типографские знаки; файлы шрифтов —
 * в resources/fonts, лицензия — рядом). Шрифты читаются один раз на запрос.
 */
final class PdfFactory
{
    private ?TrueTypeFont $regular = null;
    private ?TrueTypeFont $bold = null;

    public function __construct(
        #[Autowire('%kernel.project_dir%/resources/fonts')]
        private readonly string $fontDir,
    ) {
    }

    public function create(): PdfDocument
    {
        $this->regular ??= TrueTypeFont::fromFile($this->fontDir.'/DejaVuSans.ttf');
        $this->bold ??= TrueTypeFont::fromFile($this->fontDir.'/DejaVuSans-Bold.ttf');

        return new PdfDocument($this->regular, $this->bold);
    }

    /** Имя файла для заголовка Content-Disposition: латиница для старых программ и UTF-8 для остальных. */
    public static function disposition(string $name, bool $inline = false): string
    {
        $base = (string) preg_replace('/\.pdf$/i', '', $name);
        $ascii = (new AsciiSlugger('ru'))->slug($base, '-')->lower()->toString();
        $ascii = '' !== $ascii ? mb_substr($ascii, 0, 120).'.pdf' : 'document.pdf';

        return \sprintf('%s; filename="%s"; filename*=UTF-8\'\'%s', $inline ? 'inline' : 'attachment', $ascii, rawurlencode($name));
    }
}
