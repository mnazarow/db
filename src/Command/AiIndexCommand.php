<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\AiQuestionRepository;
use App\Service\Llm\SemanticIndex;
use App\Service\PortalSettings;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Обслуживание ИИ (cron раз в час): смысловой индекс для поиска по смыслу — новые и изменившиеся документы,
 * смена модели — и удаление вопросов к ИИ старше срока хранения.
 */
#[AsCommand(name: 'app:ai:index', description: 'Смысловой индекс документов для ответов ИИ и очистка журнала вопросов')]
final class AiIndexCommand extends Command
{
    public function __construct(
        private readonly SemanticIndex $index,
        private readonly PortalSettings $settings,
        private readonly AiQuestionRepository $questions,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('all', null, InputOption::VALUE_NONE, 'Построить индекс всех опубликованных документов заново')
            ->addOption('limit', 'l', InputOption::VALUE_REQUIRED, 'Не больше N документов за запуск (без --all)', '200')
            ->addOption('status', null, InputOption::VALUE_NONE, 'Только показать состояние индекса')
            ->addOption('clear', null, InputOption::VALUE_NONE, 'Удалить весь смысловой индекс')
            ->addOption('quiet-if-disabled', null, InputOption::VALUE_NONE, 'Если поиск по смыслу выключен — только очистка журнала, без сообщений (для cron)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $cfg = $this->settings->embeddings();
        if ($input->getOption('status')) {
            $s = $this->index->status();
            $io->definitionList(
                ['Поиск по смыслу' => $cfg['enabled'] ? 'включён' : 'выключен'],
                ['Модель' => $s['model']],
                ['Опубликованных документов' => $s['published']],
                ['В индексе' => $s['indexed']],
                ['Без текста' => $s['empty']],
                ['С ошибкой' => $s['errors'].(null !== $s['last_error'] ? ' — '.$s['last_error'] : '')],
                ['Ждут индексации' => $s['outdated']],
                ['Фрагментов' => \sprintf('%d (%.1f МБ векторов)', $s['chunks'], $s['bytes'] / 1048576)],
                ['Хранить вопросы к ИИ' => $cfg['log_days'] > 0 ? $cfg['log_days'].' дн.' : 'не сохранять'],
            );

            return Command::SUCCESS;
        }
        if ($input->getOption('clear')) {
            $this->index->clear();
            $io->success('Смысловой индекс удалён.');

            return Command::SUCCESS;
        }

        // Журнал вопросов: старше срока хранения — удаляются (0 — не хранить вовсе).
        $purged = $this->questions->purgeBefore(new \DateTimeImmutable($cfg['log_days'] > 0 ? '-'.$cfg['log_days'].' days' : 'now'));

        if (!$cfg['enabled']) {
            if (!$input->getOption('quiet-if-disabled')) {
                $io->note('Поиск по смыслу выключен (Панель администратора → Интеграции → Поиск по смыслу).'.($purged > 0 ? ' Удалено старых вопросов к ИИ: '.$purged.'.' : ''));
            }

            return Command::SUCCESS;
        }
        $limit = max(1, (int) $input->getOption('limit'));
        $stats = $this->index->reindex($limit, (bool) $input->getOption('all'), $output->isVerbose()
            ? static fn ($document, string $status) => $io->writeln(\sprintf('  %s — %s', $document->getTitle(), $status))
            : null);
        $message = \sprintf('Обработано документов: %d (в индексе %d, без текста %d, с ошибкой %d), убрано из индекса: %d, ждут индексации: %d. Удалено старых вопросов к ИИ: %d.',
            $stats['processed'], $stats['ok'], $stats['empty'], $stats['errors'], $stats['removed'], $stats['left'], $purged);
        if ($stats['errors'] > 0 && 0 === $stats['ok']) {
            // Модель недоступна — задание в «Мониторинге» помечается ошибкой.
            $io->error($message.' Модель эмбеддингов не отвечает: '.((string) $this->index->status()['last_error']));

            return Command::FAILURE;
        }
        $io->success($message);

        return Command::SUCCESS;
    }
}
