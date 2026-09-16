<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Section;
use App\Repository\DocumentRepository;
use App\Repository\SectionRepository;
use App\Service\Llm\DocumentDescriber;
use App\Service\Llm\LlmException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Массовое формирование описаний документов через внешнюю LLM (настройки — панель администратора → Интеграции).
 * По умолчанию обрабатываются документы без описания; --regenerate — и сгенерированные ранее; --force — все.
 */
#[AsCommand(name: 'app:documents:describe', description: 'Сформировать описания документов через LLM (по умолчанию — для документов без описания)')]
final class DescribeDocumentsCommand extends Command
{
    public function __construct(
        private readonly DocumentDescriber $describer,
        private readonly DocumentRepository $documents,
        private readonly SectionRepository $sections,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('missing', null, InputOption::VALUE_NONE, 'Только документы без описания (режим по умолчанию)')
            ->addOption('regenerate', null, InputOption::VALUE_NONE, 'Также переформировать описания, созданные LLM ранее')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Переписать все описания, включая написанные вручную')
            ->addOption('limit', 'l', InputOption::VALUE_REQUIRED, 'Не более N документов за запуск', '50')
            ->addOption('section', 's', InputOption::VALUE_REQUIRED, 'Только раздел с этим id (с подразделами)')
            ->addOption('include-archived', null, InputOption::VALUE_NONE, 'Обрабатывать и архивные документы')
            ->addOption('sleep', null, InputOption::VALUE_REQUIRED, 'Пауза между запросами к модели, мс', '0')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Показать, какие документы будут обработаны, ничего не меняя')
            ->addOption('quiet-if-disabled', null, InputOption::VALUE_NONE, 'Тихо завершиться, если интеграция выключена (для cron)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        if (!$this->describer->isEnabled()) {
            if ($input->getOption('quiet-if-disabled')) {
                return Command::SUCCESS;
            }
            $io->warning('Формирование описаний через LLM выключено: включите его в панели администратора → Интеграции.');

            return Command::SUCCESS;
        }
        $mode = $input->getOption('force') ? 'force' : ($input->getOption('regenerate') ? 'regenerate' : 'missing');
        $limit = max(1, (int) $input->getOption('limit'));
        $sleepMs = max(0, (int) $input->getOption('sleep'));
        $section = null;
        if (null !== $input->getOption('section')) {
            $section = $this->sections->find((int) $input->getOption('section'));
            if (!$section instanceof Section) {
                $io->error('Раздел не найден.');

                return Command::INVALID;
            }
        }
        $documents = $this->documents->findForDescribing($mode, $limit, $section, (bool) $input->getOption('include-archived'));
        $io->title('Описания документов через LLM');
        $io->text(\sprintf('Режим: %s; найдено документов: %d (лимит %d)%s.', ['missing' => 'без описания', 'regenerate' => 'без описания и сгенерированные ранее', 'force' => 'все'][$mode], \count($documents), $limit, null !== $section ? '; раздел «'.$section->getFullName().'»' : ''));
        if ([] === $documents) {
            $io->success('Обрабатывать нечего.');

            return Command::SUCCESS;
        }
        if ($input->getOption('dry-run')) {
            $io->table(['ID', 'Документ', 'Раздел', 'Описание сейчас'], array_map(static fn ($d) => [$d->getId(), $d->getTitle(), $d->getSection()->getFullName(), null === $d->getDescription() ? '—' : ($d->isDescriptionGenerated() ? 'LLM' : 'вручную')], $documents));
            $io->note('Режим --dry-run: изменения не внесены.');

            return Command::SUCCESS;
        }
        $done = 0;
        $failed = 0;
        $progress = $io->createProgressBar(\count($documents));
        $progress->setFormat(' %current%/%max% [%bar%] %message%');
        $progress->setMessage('');
        $progress->start();
        $errors = [];
        foreach ($documents as $document) {
            $progress->setMessage(mb_substr($document->getTitle(), 0, 60));
            try {
                $this->describer->describe($document);
                ++$done;
            } catch (LlmException $e) {
                ++$failed;
                $errors[] = \sprintf('#%d %s: %s', $document->getId(), $document->getTitle(), $e->getMessage());
                if ($failed >= 5 && 0 === $done) {
                    $progress->finish();
                    $io->newLine(2);
                    $io->error(array_merge(['Пять ошибок подряд — остановка. Проверьте настройки LLM («Проверить подключение» в разделе Интеграции).'], $errors));

                    return Command::FAILURE;
                }
            }
            $progress->advance();
            if ($sleepMs > 0) {
                usleep($sleepMs * 1000);
            }
        }
        $progress->finish();
        $io->newLine(2);
        if ([] !== $errors) {
            $io->warning($errors);
        }
        $left = $this->documents->countDescriptions()['without'];
        $io->success(\sprintf('Сформировано описаний: %d, ошибок: %d. Документов без описания осталось: %d.', $done, $failed, $left));

        return $failed > 0 && 0 === $done ? Command::FAILURE : Command::SUCCESS;
    }
}
