<?php

declare(strict_types=1);

namespace App\Messenger;

use App\Message\BackgroundMessage;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;

/**
 * Фоновая задача выполняется не больше одного раза: если обработчик прервали посреди неё (остановка контейнера,
 * перезагрузка сервера), очередь через час выдаст ту же запись снова — и письма ушли бы повторно. Начатая задача
 * отмечается в кэше по номеру записи очереди; повтор после ошибки — это новая запись, он выполняется.
 */
final class AtMostOnceMiddleware implements MiddlewareInterface
{
    private const TTL = 2 * 86400;

    public function __construct(
        private readonly CacheItemPoolInterface $cache,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        $received = $envelope->last(ReceivedStamp::class);
        $id = $envelope->last(TransportMessageIdStamp::class)?->getId();
        if (null === $received || null === $id || !$envelope->getMessage() instanceof BackgroundMessage) {
            return $stack->next()->handle($envelope, $stack);
        }
        try {
            $item = $this->cache->getItem('background_task_'.sha1($received->getTransportName().'|'.$id));
            if ($item->isHit()) {
                $this->logger?->warning('Фоновая задача уже выполнялась (обработчик прерывали) — повтор пропущен', ['task' => $envelope->getMessage()::class, 'id' => $id]);

                return $envelope;
            }
            $this->cache->save($item->set(time())->expiresAfter(self::TTL));
        } catch (\Throwable) {
            // Кэш недоступен — выполняем как обычно.
        }

        return $stack->next()->handle($envelope, $stack);
    }
}
