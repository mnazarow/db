<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\AuditEvent;
use App\Repository\AuditEventRepository;
use App\Service\Audit\AuditExporter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Выгрузка журнала аудита для службы информационной безопасности и SIEM
 * и очистка старых записей по сроку хранения.
 */
#[AsCommand(name: 'app:audit:export', description: 'Выгрузить журнал аудита (JSON Lines или CEF) и удалить устаревшие записи')]
final class AuditExportCommand extends Command
{
    public function __construct(
        private readonly AuditEventRepository $events,
        private readonly AuditExporter $exporter,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('since', null, InputOption::VALUE_REQUIRED, 'Выгружать записи с указанной даты и времени (например 2026-09-01 или "2026-09-01 12:00")')
            ->addOption('format', 'f', InputOption::VALUE_REQUIRED, 'Формат: jsonl или cef', AuditExporter::FORMAT_JSONL)
            ->addOption('out', 'o', InputOption::VALUE_REQUIRED, 'Записать в файл (по умолчанию — в стандартный вывод)')
            ->addOption('limit', 'l', InputOption::VALUE_REQUIRED, 'Не больше N записей', '5000')
            ->addOption('purge-older-than', null, InputOption::VALUE_REQUIRED, 'Удалить записи старше указанного срока (например "1 year", "180 days")')
            ->addOption('status', null, InputOption::VALUE_NONE, 'Показать состояние журнала и выйти');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        if ($input->getOption('status')) {
            $summary = $this->events->summary();
            $io->definitionList(
                ['Записей в журнале' => $summary['total']],
                ['Предупреждений и ошибок' => $summary['warnings']],
                ['Последняя запись' => $summary['last']?->format('d.m.Y H:i:s') ?? '—'],
            );

            return Command::SUCCESS;
        }

        $purge = (string) $input->getOption('purge-older-than');
        if ('' !== $purge) {
            try {
                $before = new \DateTimeImmutable('-'.ltrim($purge, '-'));
            } catch (\Exception) {
                $io->error('Не удалось разобрать срок хранения: '.$purge);

                return Command::INVALID;
            }
            $removed = $this->events->purgeOlderThan($before);
            $io->success(\sprintf('Удалено записей старше %s: %d.', $before->format('d.m.Y'), $removed));

            return Command::SUCCESS;
        }

        $format = (string) $input->getOption('format');
        if (!\in_array($format, AuditExporter::FORMATS, true)) {
            $io->error('Неизвестный формат: '.$format.'. Допустимо: '.implode(', ', AuditExporter::FORMATS).'.');

            return Command::INVALID;
        }
        $sinceRaw = trim((string) $input->getOption('since'));
        $since = null;
        if ('' !== $sinceRaw) {
            try {
                $since = new \DateTimeImmutable($sinceRaw);
            } catch (\Exception) {
                $io->error('Не удалось разобрать дату: '.$sinceRaw);

                return Command::INVALID;
            }
        }
        $events = $this->events->findSince($since, max(1, (int) $input->getOption('limit')));
        $lines = array_map(fn (AuditEvent $event): string => $this->exporter->line($event, $format), $events);
        $file = trim((string) $input->getOption('out'));
        if ('' !== $file) {
            if (false === @file_put_contents($file, implode("\n", $lines)."\n")) {
                $io->error('Не удалось записать файл: '.$file);

                return Command::FAILURE;
            }
            $io->success(\sprintf('Выгружено записей: %d → %s', \count($lines), $file));

            return Command::SUCCESS;
        }
        foreach ($lines as $line) {
            $output->writeln($line, OutputInterface::OUTPUT_RAW);
        }

        return Command::SUCCESS;
    }
}
