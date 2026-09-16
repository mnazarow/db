<?php

declare(strict_types=1);

namespace App\Service\Llm;

use App\Service\Http\HttpResponse;
use App\Service\Http\HttpTransportInterface;
use App\Service\PortalSettings;
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
    public function complete(string $system, string $user, ?int $maxTokens = 500): string
    {
        $cfg = $this->config();
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
        if (!\is_string($content) || '' === trim($content)) {
            throw new LlmException('Ошибка LLM: пустой ответ модели'.(isset($data['error']) ? ' — '.json_encode($data['error'], \JSON_UNESCAPED_UNICODE) : '.'));
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

    /**
     * Пробный запрос к модели.
     *
     * @return array{ok: bool, message: string, seconds: float}
     */
    public function test(): array
    {
        $started = microtime(true);
        try {
            $answer = $this->complete('Отвечай одним словом.', 'Напиши слово «готово».', 20);

            return ['ok' => true, 'message' => \sprintf('Модель %s ответила за %.1f с: «%s».', $this->config()['model'], microtime(true) - $started, mb_substr($answer, 0, 60)), 'seconds' => microtime(true) - $started];
        } catch (LlmException $e) {
            return ['ok' => false, 'message' => $e->getMessage(), 'seconds' => microtime(true) - $started];
        }
    }
}
