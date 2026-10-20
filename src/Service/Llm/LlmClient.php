<?php

declare(strict_types=1);

namespace App\Service\Llm;

use App\Service\Http\HttpResponse;
use App\Service\Http\HttpTransportInterface;
use App\Service\PortalSettings;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;

/**
 * Клиент OpenAI-совместимого API чата (POST {base_url}/chat/completions).
 * Подходит для OpenAI, OpenRouter, Ollama (http://host:11434/v1), LM Studio, vLLM, LocalAI и других серверов
 * с таким же протоколом. Параметры (адрес, ключ, модель, промпт) задаются в панели администратора → Интеграции.
 */
final class LlmClient
{
    public function __construct(
        private readonly HttpTransportInterface $http,
        private readonly PortalSettings $settings,
        private readonly LoggerInterface $logger,
        private readonly ?CacheItemPoolInterface $cache = null,
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->settings->isLlmEnabled();
    }

    /** @return array{enabled: bool, base_url: string, api_key: string, model: string, prompt: string, max_input_chars: int, timeout: int, auto_describe: bool, temperature: float} */
    public function config(): array
    {
        return $this->settings->llm();
    }

    /**
     * Отправляет системную инструкцию и запрос пользователя, возвращает текст ответа модели.
     *
     * @throws LlmException
     */
    public function complete(string $system, string $user, ?int $maxTokens = 500, bool $assistant = false): string
    {
        // Помощник может работать со своей моделью на другом сервере (Интеграции → «Помощник»).
        $cfg = $assistant ? $this->settings->assistant() : $this->config();
        $payload = [
            'model' => $cfg['model'],
            'messages' => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => $user],
            ],
            'temperature' => $cfg['temperature'],
        ];
        if (null !== $maxTokens) {
            $payload['max_tokens'] = $maxTokens;
        }
        $response = $this->send($payload, $cfg);
        if (!$response->ok() && 400 === $response->status) {
            // Часть моделей не принимает max_tokens (нужен max_completion_tokens) или фиксирует температуру —
            // разбираем ответ и повторяем запрос без спорных параметров.
            $adjusted = self::adjustPayload($payload, $response->body);
            if (null !== $adjusted) {
                $this->logger->info('Повтор запроса к LLM без неподдерживаемых параметров', ['model' => $cfg['model'], 'error' => $response->describeError()]);
                $response = $this->send($adjusted, $cfg);
            }
        }
        if (!$response->ok()) {
            $this->logger->warning('Ошибка обращения к LLM', ['model' => $cfg['model'], 'base_url' => $cfg['base_url'], 'error' => $response->describeError()]);
            throw new LlmException('Ошибка LLM: '.$response->describeError());
        }
        $data = $response->json();
        $content = $data['choices'][0]['message']['content'] ?? null;
        if (\is_array($content)) { // некоторые серверы отдают массив частей
            $content = implode('', array_map(static fn ($part) => \is_array($part) ? (string) ($part['text'] ?? '') : (string) $part, $content));
        }
        // Модели с рассуждениями (Qwen3, DeepSeek-R1 на своём сервере) пишут ход мысли в <think>…</think> —
        // сотруднику нужен только ответ.
        if (\is_string($content)) {
            $content = self::withoutThinking($content);
        }
        if (!\is_string($content) || '' === trim($content)) {
            throw new LlmException('Ошибка LLM: пустой ответ модели'.(isset($data['error']) ? ' — '.json_encode($data['error'], \JSON_UNESCAPED_UNICODE) : '.'));
        }

        return trim($content);
    }

    /** Текст ответа без блоков рассуждений <think>…</think> (и без незакрытого блока, если ответ обрезан). */
    public static function withoutThinking(string $content): string
    {
        $content = (string) preg_replace('#<think(?:ing)?>.*?</think(?:ing)?>#isu', '', $content);
        if (preg_match('#^\s*<think(?:ing)?>#iu', $content)) {
            return ''; // рассуждение не закончилось — ответа нет
        }

        return trim($content);
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $cfg
     */
    private function send(array $payload, array $cfg): HttpResponse
    {
        $headers = ['Content-Type' => 'application/json', 'Accept' => 'application/json'];
        if ('' !== $cfg['api_key']) {
            $headers['Authorization'] = 'Bearer '.$cfg['api_key'];
        }

        return $this->http->request('POST', $cfg['base_url'].'/chat/completions', json_encode($payload, \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR), $headers, $cfg['timeout']);
    }

    /**
     * По тексту ошибки убирает или переименовывает параметры, которые модель не поддерживает.
     *
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>|null null — повторять нечего
     */
    private static function adjustPayload(array $payload, string $errorBody): ?array
    {
        $body = mb_strtolower($errorBody);
        $changed = false;
        if (isset($payload['max_tokens']) && str_contains($body, 'max_completion_tokens')) {
            $payload['max_completion_tokens'] = $payload['max_tokens'];
            unset($payload['max_tokens']);
            $changed = true;
        } elseif (isset($payload['max_tokens']) && str_contains($body, 'max_tokens')) {
            unset($payload['max_tokens']);
            $changed = true;
        }
        if (isset($payload['temperature']) && str_contains($body, 'temperature')) {
            unset($payload['temperature']);
            $changed = true;
        }

        return $changed ? $payload : null;
    }

    /** Поиск по смыслу включён (своя настройка, от описаний и ответов не зависит). */
    public function embeddingsEnabled(): bool
    {
        return $this->settings->embeddings()['enabled'];
    }

    /**
     * Векторы текстов (POST {адрес}/embeddings — OpenAI, Ollama, LM Studio, vLLM, LocalAI…), нормированные к
     * длине 1: косинусное сходство — скалярное произведение. Порядок векторов — как у текстов.
     *
     * $timeout — секунд на ответ (для вопроса сотрудника — коротко, для индексации — тайм-аут из настроек).
     *
     * @param list<string> $texts
     *
     * @return list<list<float>>
     *
     * @throws LlmException
     */
    public function embed(array $texts, ?int $timeout = null): array
    {
        if ([] === $texts) {
            return [];
        }
        $cfg = $this->settings->embeddings();
        $payload = ['model' => $cfg['model'], 'input' => array_values(array_map(static fn (string $t): string => '' !== trim($t) ? $t : '-', $texts))];
        // Модель не умеет сокращать вектор — это запоминается на сутки, чтобы не тратить на отказ лишний запрос.
        $noDimensionsKey = 'llm_embed_no_dims_'.md5($cfg['base_url'].'|'.$cfg['model']);
        if ($cfg['dimensions'] > 0 && !$this->cacheHas($noDimensionsKey)) {
            $payload['dimensions'] = $cfg['dimensions'];
        }
        $timeout = max(2, $timeout ?? max(10, $cfg['timeout']));
        $response = $this->sendEmbeddings($payload, $cfg, $timeout);
        if (!$response->ok() && 400 === $response->status && isset($payload['dimensions']) && str_contains(mb_strtolower($response->body), 'dimension')) {
            unset($payload['dimensions']);
            $this->cacheSet($noDimensionsKey, 86400);
            $response = $this->sendEmbeddings($payload, $cfg, $timeout);
        }
        if (!$response->ok()) {
            $this->logger->warning('Ошибка обращения к модели эмбеддингов', ['model' => $cfg['model'], 'base_url' => $cfg['base_url'], 'error' => $response->describeError()]);
            throw new LlmException('Ошибка модели эмбеддингов: '.$response->describeError());
        }
        $data = $response->json();
        $rows = \is_array($data['data'] ?? null) ? $data['data'] : (\is_array($data['embeddings'] ?? null) ? array_map(static fn ($e): array => ['embedding' => $e], $data['embeddings']) : null);
        if (null === $rows || \count($rows) !== \count($texts)) {
            throw new LlmException('Ошибка модели эмбеддингов: в ответе нет векторов для всех текстов.');
        }
        usort($rows, static fn ($a, $b): int => ((int) ($a['index'] ?? 0)) <=> ((int) ($b['index'] ?? 0)));
        $out = [];
        $dims = null;
        foreach ($rows as $row) {
            $vector = $row['embedding'] ?? null;
            if (!\is_array($vector) || [] === $vector) {
                throw new LlmException('Ошибка модели эмбеддингов: пустой вектор.');
            }
            $dims ??= \count($vector);
            if (\count($vector) !== $dims) {
                throw new LlmException('Ошибка модели эмбеддингов: векторы разной длины.');
            }
            $out[] = self::normalize(array_map('floatval', array_values($vector)));
        }

        return $out;
    }

    /**
     * Вектор длины 1 (нулевой остаётся нулевым).
     *
     * @param list<float> $vector
     *
     * @return list<float>
     */
    public static function normalize(array $vector): array
    {
        $norm = 0.0;
        foreach ($vector as $v) {
            $norm += $v * $v;
        }
        if ($norm <= 0.0) {
            return $vector;
        }
        $norm = sqrt($norm);

        return array_map(static fn (float $v): float => $v / $norm, $vector);
    }

    /**
     * Пробный запрос к модели эмбеддингов.
     *
     * @return array{ok: bool, message: string}
     */
    public function testEmbeddings(): array
    {
        $started = microtime(true);
        try {
            $vectors = $this->embed(['Проверка связи с моделью эмбеддингов']);

            return ['ok' => true, 'message' => \sprintf('Модель %s ответила за %.1f с, длина вектора — %d.', $this->settings->embeddings()['model'], microtime(true) - $started, \count($vectors[0]))];
        } catch (LlmException $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $cfg
     */
    private function sendEmbeddings(array $payload, array $cfg, int $timeout): HttpResponse
    {
        $headers = ['Content-Type' => 'application/json', 'Accept' => 'application/json'];
        if ('' !== $cfg['api_key']) {
            $headers['Authorization'] = 'Bearer '.$cfg['api_key'];
        }

        return $this->http->request('POST', $cfg['base_url'].'/embeddings', json_encode($payload, \JSON_UNESCAPED_UNICODE | \JSON_INVALID_UTF8_SUBSTITUTE | \JSON_THROW_ON_ERROR), $headers, $timeout);
    }

    /** Отметка в кэше (на время): есть ли она. */
    public function cacheHas(string $key): bool
    {
        try {
            return null !== $this->cache && $this->cache->getItem($key)->isHit();
        } catch (\Throwable) {
            return false;
        }
    }

    /** Поставить отметку в кэше на $seconds секунд. */
    public function cacheSet(string $key, int $seconds): void
    {
        try {
            if (null !== $this->cache) {
                $this->cache->save($this->cache->getItem($key)->set(true)->expiresAfter($seconds));
            }
        } catch (\Throwable) {
        }
    }

    /**
     * Пробный запрос к модели.
     *
     * @return array{ok: bool, message: string, seconds: float}
     */
    public function test(bool $assistant = false): array
    {
        $started = microtime(true);
        try {
            $answer = $this->complete('Отвечай одним словом.', 'Напиши слово «готово».', 20, $assistant);
            $cfg = $assistant ? $this->settings->assistant() : $this->config();

            return ['ok' => true, 'message' => \sprintf('Модель %s (%s) ответила за %.1f с: «%s».', $cfg['model'], $cfg['base_url'], microtime(true) - $started, mb_substr($answer, 0, 60)), 'seconds' => microtime(true) - $started];
        } catch (LlmException $e) {
            return ['ok' => false, 'message' => $e->getMessage(), 'seconds' => microtime(true) - $started];
        }
    }
}
