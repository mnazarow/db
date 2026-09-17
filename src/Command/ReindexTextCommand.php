<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\DocumentTextRepository;
use App\Service\Text\TextExtractor;
use App\Service\Text\TextIndexer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Индекс содержимого документов для полнотекстового поиска: по умолчанию обрабатываются документы,
 * у которых индекса ещё нет или он отстал от текущей версии (по cron), с --all — все заново.
 */
#[AsCommand(name: 'app:search:reindex', description: 'Переиндексация содержимого документов для полнотекстового поиска')]
final class ReindexTextCommand extends Command
{
    public function __construct(
        private readonly TextIndexer $indexer,
        private readonly DocumentTextRepository $texts,
        private readonly TextExtractor $extractor,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('all', null, InputOption::VALUE_NONE, 'Переиндексировать все документы, а не только изменившиеся')
            ->addOption('limit', 'l', InputOption::VALUE_REQUIRED, 'Не больше N документов за запуск', '500')
            ->addOption('status', null, InputOption::VALUE_NONE, 'Только показать состояние индекса');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $summary = $this->texts->summary();
        if ($input->getOption('status')) {
            $io->definitionList(
                ['Документов в портале' => $summary['documents']],
                ['В индексе' => $summary['indexed']],
                ['С текстом' => $summary['with_text']],
                ['Объём текста' => \sprintf('%.1f млн символов', $summary['chars'] / 1_000_000)],
                ['Ожидают индексации' => \count($this->texts->findOutdatedDocumentIds(100000))],
                ['Извлечение текста из PDF' => $this->extractor->hasPdftotext() ? 'доступно (pdftotext)' : 'НЕДОСТУПНО — установите poppler-utils'],
            );

            return Command::SUCCESS;
        }
        if (!$this->extractor->hasPdftotext()) {
            $io->warning('pdftotext не найден (пакет poppler-utils): текст из PDF-файлов в индекс не попадёт.');
        }
        $limit = max(1, (int) $input->getOption('limit'));
        $all = (bool) $input->getOption('all');
        $io->title('Индексация содержимого документов');
        $stats = $this->indexer->reindex($limit, $all, $output->isVerbose()
            ? static fn ($document, string $status) => $io->writeln(\sprintf('  %s — %s', $document->getTitle(), $status))
            : null);
        $left = \count($this->texts->findOutdatedDocumentIds(100000));
        $io->success(\sprintf('Обработано документов: %d (с текстом %d, без текста %d, пропущено %d). Ожидают индексации: %d.',
            $stats['processed'], $stats['indexed'], $stats['empty'], $stats['skipped'], $left));

        return Command::SUCCESS;
    }
}
