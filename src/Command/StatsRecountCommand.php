<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\DocumentEvent;
use App\Repository\DocumentEventRepository;
use App\Repository\DocumentRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:stats:recount', description: 'Пересчитать счётчики просмотров и скачиваний документов по журналу событий')]
final class StatsRecountCommand extends Command
{
    public function __construct(private readonly DocumentRepository $documents, private readonly DocumentEventRepository $events, private readonly EntityManagerInterface $em)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $counts = $this->events->countsPerDocumentSince(null);
        $n = 0;
        foreach ($this->documents->findAll() as $doc) {
            $c = $counts[$doc->getId()] ?? ['view' => 0, 'download' => 0];
            $doc->setViewCount($c['view'])->setDownloadCount($c['download']);
            $last = $this->events->findForDocument($doc, 1, DocumentEvent::VIEW);
            $doc->setLastViewedAt([] !== $last ? $last[0]->getCreatedAt() : null);
            ++$n;
        }
        $this->em->flush();
        $io->success(\sprintf('Пересчитано документов: %d.', $n));

        return Command::SUCCESS;
    }
}
