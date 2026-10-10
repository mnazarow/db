<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RequestContext;

/**
 * Абсолютные ссылки для писем, сообщений Telegram и webhook.
 *
 * Адрес берётся из APP_DEFAULT_URI, а не из заголовка Host текущего запроса: иначе любой, кто
 * отправит запрос с подменённым Host (например, запрос на согласование или комментарий), получил бы
 * письма коллегам со ссылками на чужой сайт. Если APP_DEFAULT_URI не настроен (пусто или localhost),
 * используется адрес текущего запроса — как раньше.
 */
final class PortalUrl
{
    private ?RequestContext $context = null;

    public function __construct(
        private readonly UrlGeneratorInterface $urls,
        #[Autowire('%env(default::APP_DEFAULT_URI)%')]
        private readonly ?string $defaultUri = null,
    ) {
    }

    /** Настроен ли постоянный адрес портала (APP_DEFAULT_URI с настоящим именем хоста). */
    public function isConfigured(): bool
    {
        return null !== $this->fixedContext();
    }

    /** @param array<string, mixed> $parameters */
    public function absolute(string $route, array $parameters = []): string
    {
        $fixed = $this->fixedContext();
        if (null === $fixed) {
            return $this->urls->generate($route, $parameters, UrlGeneratorInterface::ABSOLUTE_URL);
        }
        $original = $this->urls->getContext();
        $this->urls->setContext($fixed);
        try {
            return $this->urls->generate($route, $parameters, UrlGeneratorInterface::ABSOLUTE_URL);
        } finally {
            $this->urls->setContext($original);
        }
    }

    private function fixedContext(): ?RequestContext
    {
        if (null !== $this->context) {
            return $this->context;
        }
        $uri = trim((string) $this->defaultUri);
        $host = strtolower((string) parse_url($uri, \PHP_URL_HOST));
        if ('' === $uri || '' === $host || \in_array($host, ['localhost', '127.0.0.1', '::1', '[::1]'], true)) {
            return null;
        }

        return $this->context = RequestContext::fromUri($uri);
    }
}
