<?php

declare(strict_types=1);

namespace App\Service\Price;

use App\Service\Process\Shell;
use App\Service\Xlsx\XlsxReader;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Листы файла прайс-листа: .xlsx — своим разбором (XlsxReader), .csv — с определением разделителя и кодировки
 * (UTF-8 или Windows-1251), .xls и .ods — через LibreOffice (переводится в .xlsx), если он установлен.
 */
final class PriceSheetReader
{
    public const EXTENSIONS = ['xlsx', 'xlsm', 'xls', 'ods', 'csv'];

    /** Предел строк листа: больше — прайс разбивают на несколько. */
    public const MAX_ROWS = 100000;

    public function __construct(
        private readonly XlsxReader $xlsx,
        #[Autowire('%env(SOFFICE_BIN)%')] private readonly ?string $sofficeBin = null,
    ) {
    }

    public function canConvert(): bool
    {
        return Shell::exists($this->soffice());
    }

    /**
     * @return list<array{name: string, hidden: bool, rows: array<int, array<int, string>>}>
     *
     * @throws \RuntimeException файл не читается
     */
    public function read(string $path, string $extension): array
    {
        $extension = strtolower($extension);
        $sheets = match ($extension) {
            'xlsx', 'xlsm' => $this->xlsx->read($path),
            'csv' => [['name' => 'CSV', 'hidden' => false, 'rows' => self::csv($path)]],
            'xls', 'ods' => $this->converted($path, $extension),
            default => throw new \RuntimeException('Прайс-лист загружается из файла Excel (.xlsx, .xls), OpenDocument (.ods) или CSV.'),
        };
        $out = [];
        foreach ($sheets as $sheet) {
            if (\count($sheet['rows']) > self::MAX_ROWS) {
                throw new \RuntimeException(\sprintf('На листе «%s» больше %d строк — разбейте прайс-лист на несколько.', $sheet['name'], self::MAX_ROWS));
            }
            $out[] = ['name' => (string) $sheet['name'], 'hidden' => (bool) ($sheet['hidden'] ?? false), 'rows' => $sheet['rows']];
        }
        if ([] === $out) {
            throw new \RuntimeException('В файле нет листов с данными.');
        }

        return $out;
    }

    /**
     * Формат файла по содержимому: книга Excel 2007+ (.xlsx), OpenDocument (.ods), старый Excel (.xls);
     * иначе — по расширению имени (файл без расширения с текстом — CSV). Выгрузки из учётных систем нередко
     * называются .xls, а внутри — .xlsx, — такие читаются как .xlsx.
     */
    public static function detect(string $path, string $originalName): ?string
    {
        $ext = strtolower(pathinfo($originalName, \PATHINFO_EXTENSION));
        $head = (string) @file_get_contents($path, false, null, 0, 8);
        if (str_starts_with($head, "PK\x03\x04")) {
            $zip = new \ZipArchive();
            if (true === $zip->open($path)) {
                $mime = (string) $zip->getFromName('mimetype');
                $isXlsx = false !== $zip->locateName('xl/workbook.xml');
                $zip->close();
                if (str_contains($mime, 'opendocument.spreadsheet')) {
                    return 'ods';
                }
                if ($isXlsx) {
                    return 'xlsm' === $ext ? 'xlsm' : 'xlsx';
                }
            }

            return null;
        }
        if (str_starts_with($head, "\xD0\xCF\x11\xE0")) {
            return 'xls';
        }
        if (\in_array($ext, self::EXTENSIONS, true)) {
            return $ext;
        }
        $sample = (string) @file_get_contents($path, false, null, 0, 4096);

        return '' !== $sample && !str_contains($sample, "\0") ? 'csv' : null;
    }

    /** @return array<int, array<int, string>> */
    public static function csv(string $path): array
    {
        $raw = (string) file_get_contents($path);
        if (str_starts_with($raw, "\xEF\xBB\xBF")) {
            $raw = substr($raw, 3);
        }
        if (!mb_check_encoding($raw, 'UTF-8')) {
            $raw = (string) mb_convert_encoding($raw, 'UTF-8', 'Windows-1251');
        }
        $first = strtok($raw, "\n") ?: '';
        $delimiter = ';';
        $best = -1;
        foreach ([';', ',', "\t", '|'] as $d) {
            $n = substr_count($first, $d);
            if ($n > $best) {
                $best = $n;
                $delimiter = $d;
            }
        }
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $raw);
        rewind($stream);
        $rows = [];
        $r = 0;
        while (false !== ($cells = fgetcsv($stream, null, $delimiter, '"', ''))) {
            ++$r;
            foreach ($cells as $i => $cell) {
                $value = trim(str_replace("\u{00A0}", ' ', (string) $cell));
                if ('' !== $value) {
                    $rows[$r][$i + 1] = $value;
                }
            }
            if ($r > self::MAX_ROWS + 1) {
                break;
            }
        }
        fclose($stream);

        return $rows;
    }

    /** @return list<array{name: string, hidden: bool, rows: array<int, array<int, string>>}> */
    private function converted(string $path, string $extension): array
    {
        if (!$this->canConvert()) {
            throw new \RuntimeException('Файлы .'.$extension.' читаются через LibreOffice, а он на сервере не установлен. Сохраните прайс-лист в Excel как .xlsx («Файл → Сохранить как → Книга Excel») и загрузите снова.');
        }
        $work = sys_get_temp_dir().'/price-'.bin2hex(random_bytes(6));
        @mkdir($work, 0700, true);
        try {
            $input = $work.'/source.'.$extension;
            copy($path, $input);
            $run = Shell::run([$this->soffice(), '-env:UserInstallation=file://'.$work.'/profile', '--headless', '--norestore', '--nolockcheck',
                '--convert-to', 'xlsx', '--outdir', $work, $input], 180, $work, ['HOME' => $work, 'TMPDIR' => $work]);
            $out = $work.'/source.xlsx';
            if (!is_file($out)) {
                throw new \RuntimeException('LibreOffice не смог прочитать файл ('.mb_substr(trim($run['err'].' '.$run['out']), 0, 200).'). Сохраните его как .xlsx.');
            }

            return $this->xlsx->read($out);
        } finally {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($work, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $f) {
                $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
            }
            @rmdir($work);
        }
    }

    private function soffice(): string
    {
        return null !== $this->sofficeBin && '' !== $this->sofficeBin ? $this->sofficeBin : 'soffice';
    }
}
