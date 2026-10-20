<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Llm\LlmClient;
use App\Service\PortalSettings;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Настройка языковой модели из консоли — то же, что «Панель администратора → Интеграции → LLM» и «Поиск по
 * смыслу». Ей пользуется команда сервера `docportal ai enable`, которая ставит Ollama и скачивает модели:
 * портал сразу начинает отвечать на вопросы (помощник, ответы на странице поиска) моделью на своём сервере.
 * Заданные ключи меняют только свои настройки, остальные остаются как были.
 */
#[AsCommand(name: 'app:ai:configure', description: 'Настроить языковую модель (OpenAI-совместимый API: Ollama, vLLM, облако) и поиск по смыслу')]
final class AiConfigureCommand extends Command
{
    public function __construct(
        private readonly PortalSettings $settings,
        private readonly LlmClient $llm,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('url', null, InputOption::VALUE_REQUIRED, 'Адрес API, например http://127.0.0.1:11434/v1 (Ollama) или http://gpu-server:8000/v1 (vLLM)')
            ->addOption('model', null, InputOption::VALUE_REQUIRED, 'Модель для ответов и описаний документов, например qwen2.5:7b-instruct')
            ->addOption('api-key', null, InputOption::VALUE_REQUIRED, 'Ключ API (для Ollama не нужен)')
            ->addOption('timeout', null, InputOption::VALUE_REQUIRED, 'Ожидание ответа модели, секунд (5–600)')
            ->addOption('max-input-chars', null, InputOption::VALUE_REQUIRED, 'Сколько символов источников передавать модели')
            ->addOption('embeddings-model', null, InputOption::VALUE_REQUIRED, 'Модель эмбеддингов для поиска по смыслу, например bge-m3')
            ->addOption('embeddings-url', null, InputOption::VALUE_REQUIRED, 'Адрес API эмбеддингов, если не тот же')
            ->addOption('embeddings-api-key', null, InputOption::VALUE_REQUIRED, 'Ключ API эмбеддингов (если адрес свой)')
            ->addOption('assistant-url', null, InputOption::VALUE_REQUIRED, 'Помощник — отдельный сервер модели: адрес API, например http://gpu-server:11435/v1')
            ->addOption('assistant-model', null, InputOption::VALUE_REQUIRED, 'Модель помощника на отдельном сервере')
            ->addOption('assistant-api-key', null, InputOption::VALUE_REQUIRED, 'Ключ API сервера помощника')
            ->addOption('assistant-timeout', null, InputOption::VALUE_REQUIRED, 'Ожидание ответа сервера помощника, секунд')
            ->addOption('assistant-shared', null, InputOption::VALUE_NONE, 'Помощник отвечает той же моделью, что описывает документы')
            ->addOption('enable', null, InputOption::VALUE_NONE, 'Включить ответы модели (и поиск по смыслу, если задана модель эмбеддингов)')
            ->addOption('disable', null, InputOption::VALUE_NONE, 'Выключить модель и поиск по смыслу')
            ->addOption('test', null, InputOption::VALUE_NONE, 'Проверить связь: пробный вопрос модели и пробный вектор')
            ->addOption('status', null, InputOption::VALUE_NONE, 'Показать текущие настройки');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        // Своя инструкция для описаний хранится как есть: пустая — значит «по умолчанию» (не подставляем текст по умолчанию).
        $llm = ['prompt' => (string) $this->settings->get(PortalSettings::LLM_PROMPT, '')] + $this->settings->llm();
        $emb = $this->settings->embeddings();
        $changed = false;
        try {
            if ($input->getOption('disable')) {
                $this->settings->setLlm(['enabled' => false] + $llm);
                $this->settings->setEmbeddings(['enabled' => false, 'base_url' => $emb['own_base_url']] + $emb);
                $a = $this->settings->assistant();
                $this->settings->setAssistant(['enabled' => false, 'own' => $a['own'], 'base_url' => $a['own_base_url'], 'model' => $a['own_model'], 'timeout' => $a['own_timeout']]);
                $io->success('Языковая модель и поиск по смыслу выключены. Проверенные ответы на частые вопросы продолжают работать.');

                return Command::SUCCESS;
            }
            $values = $llm;
            foreach (['url' => 'base_url', 'model' => 'model', 'api-key' => 'api_key', 'timeout' => 'timeout', 'max-input-chars' => 'max_input_chars'] as $option => $key) {
                if (null !== $input->getOption($option)) {
                    $values[$key] = $input->getOption($option);
                    $changed = true;
                }
            }
            $assistantOptions = null !== $input->getOption('assistant-url') || null !== $input->getOption('assistant-model');
            if ($input->getOption('enable')) {
                // --enable с настройками только помощника не включает модель описаний.
                if ($changed || !$assistantOptions) {
                    $values['enabled'] = true;
                }
                $values['answers'] = true;
                $changed = true;
            }
            if ($changed) {
                $this->settings->setLlm($values);
            }
            $embValues = ['base_url' => $emb['own_base_url']] + $emb;
            $embChanged = false;
            if (null !== $input->getOption('embeddings-model')) {
                $embValues['model'] = $input->getOption('embeddings-model');
                $embValues['enabled'] = $embValues['enabled'] || $input->getOption('enable');
                $embChanged = true;
            }
            if (null !== $input->getOption('embeddings-url')) {
                $embValues['base_url'] = $input->getOption('embeddings-url');
                $embChanged = true;
            }
            if (null !== $input->getOption('embeddings-api-key')) {
                $embValues['api_key'] = $input->getOption('embeddings-api-key');
                $embChanged = true;
            }
            if ($embChanged) {
                $this->settings->setEmbeddings($embValues);
            }
            // Помощник: своя модель на другом сервере или та же, что для описаний.
            $assistant = $this->settings->assistant();
            $asValues = ['own' => $assistant['own'], 'base_url' => $assistant['own_base_url'], 'model' => $assistant['own_model'], 'timeout' => $assistant['own_timeout']];
            $asChanged = false;
            foreach (['assistant-url' => 'base_url', 'assistant-model' => 'model', 'assistant-api-key' => 'api_key', 'assistant-timeout' => 'timeout'] as $option => $key) {
                if (null !== $input->getOption($option)) {
                    $asValues[$key] = $input->getOption($option);
                    $asValues['own'] = true;
                    $asChanged = true;
                }
            }
            if ($input->getOption('assistant-shared')) {
                $asValues['own'] = false;
                $asChanged = true;
            }
            if ($input->getOption('enable')) {
                $asValues['enabled'] = true;
                $asChanged = $asChanged || $asValues['own'];
            }
            if ($asChanged) {
                $this->settings->setAssistant($asValues);
            }
            $changed = $changed || $embChanged || $asChanged;
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage());

            return Command::INVALID;
        }
        $llm = $this->settings->llm();
        $emb = $this->settings->embeddings();
        if ($changed || $input->getOption('status') || !$input->getOption('test')) {
            $io->definitionList(
                ['Модель (описания документов)' => ($llm['enabled'] ? 'включена: ' : 'выключена: ').$llm['model']],
                ['Адрес API' => $llm['base_url']],
                ['Ожидание ответа' => $llm['timeout'].' с'],
                ['Поиск по смыслу' => $emb['enabled'] ? 'включён, модель '.$emb['model'].('' !== $emb['own_base_url'] ? ' ('.$emb['own_base_url'].')' : '') : 'выключен'],
                ['Помощник' => $this->assistantLine()],
            );
        }
        if ($changed && $emb['enabled']) {
            $io->note('Смысловой индекс документов строится заданием app:ai:index (раз в час) или сразу: app:ai:index --all.');
        }
        if ($input->getOption('test')) {
            $ok = true;
            $assistant = $this->settings->assistant();
            if ($llm['enabled'] || !$assistant['own']) {
                $result = $this->llm->test();
                $result['ok'] ? $io->success($result['message']) : $io->error('Модель: '.$result['message']);
                $ok = $ok && $result['ok'];
            }
            if ($assistant['own']) {
                $result = $this->llm->test(true);
                $result['ok'] ? $io->success('Помощник: '.$result['message']) : $io->error('Помощник: '.$result['message']);
                $ok = $ok && $result['ok'];
            }
            if ($emb['enabled']) {
                $vector = $this->llm->testEmbeddings();
                $vector['ok'] ? $io->success($vector['message']) : $io->error('Эмбеддинги: '.$vector['message']);
                $ok = $ok && $vector['ok'];
            }

            return $ok ? Command::SUCCESS : Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    private function assistantLine(): string
    {
        $a = $this->settings->assistant();
        if (!$a['enabled']) {
            return 'выключен';
        }
        $where = $a['own'] ? 'отдельный сервер: '.$a['model'].' ('.$a['base_url'].')' : 'та же модель';

        return ($a['ready'] ? 'отвечает, ' : 'нет модели, ').$where;
    }
}
