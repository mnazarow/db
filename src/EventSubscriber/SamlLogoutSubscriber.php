<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Controller\SamlController;
use App\Service\Sso\SamlService;
use App\Service\Sso\SsoException;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Security\Http\Event\LogoutEvent;

/**
 * Единый выход через SAML: сотрудник, вошедший через провайдера, по кнопке «Выйти» уходит к провайдеру с запросом
 * выхода — и выходит из всех систем организации, а не только из портала. Сеанс портала завершается сразу (как
 * при обычном выходе); провайдер возвращает ответ на /login/saml/sls. Если запрос не сформировался, выход
 * остаётся обычным — из одного портала.
 */
final class SamlLogoutSubscriber implements EventSubscriberInterface
{
    private const PENDING_TTL = 900;

    public function __construct(
        private readonly SamlService $saml,
        private readonly CacheItemPoolInterface $cache,
        private readonly LoggerInterface $auditLogger,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        // Раньше ответа по умолчанию (переход на страницу входа, приоритет 64) и до завершения сеанса (0).
        return [LogoutEvent::class => ['onLogout', 128]];
    }

    public function onLogout(LogoutEvent $event): void
    {
        $request = $event->getRequest();
        if (null !== $event->getResponse() || true === $request->attributes->get(SamlController::IDP_LOGOUT_ATTRIBUTE) || !$request->hasSession()) {
            return;
        }
        $session = $request->getSession()->get(SamlController::SESSION_SAML);
        if (!\is_array($session) || !$this->saml->isSloEnabled()) {
            return;
        }
        try {
            $logout = $this->saml->logoutRequest($session);
            $item = $this->cache->getItem(SamlController::LOGOUT_CACHE_PREFIX.hash('sha256', $logout['request_id']));
            $this->cache->save($item->set(time())->expiresAfter(self::PENDING_TTL));
        } catch (SsoException|\Throwable $e) {
            $this->auditLogger->warning('SAML: единый выход не выполнен — только выход из портала', ['error' => $e->getMessage()]);

            return;
        }
        $event->setResponse(new RedirectResponse($logout['url']));
    }
}
