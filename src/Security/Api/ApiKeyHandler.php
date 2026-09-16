<?php

declare(strict_types=1);

namespace App\Security\Api;

use App\Entity\ApiKey;
use App\Repository\ApiKeyRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Http\AccessToken\AccessTokenHandlerInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;

/**
 * Проверка ключа API: токен из заголовка Authorization: Bearer … (или X-Api-Key) сравнивается по хэшу
 * с таблицей api_key; у найденного ключа обновляются время и адрес последнего обращения.
 */
final class ApiKeyHandler implements AccessTokenHandlerInterface
{
    public function __construct(
        private readonly ApiKeyRepository $keys,
        private readonly EntityManagerInterface $em,
        private readonly RequestStack $requests,
    ) {
    }

    public function getUserBadgeFrom(string $accessToken): UserBadge
    {
        $accessToken = trim($accessToken);
        if ('' === $accessToken || !str_starts_with($accessToken, ApiKey::TOKEN_PREFIX)) {
            throw new BadCredentialsException('Неверный ключ API.');
        }
        $key = $this->keys->findOneByToken($accessToken);
        if (null === $key) {
            throw new BadCredentialsException('Неверный ключ API.');
        }
        if (!$key->isEnabled()) {
            throw new BadCredentialsException('Ключ API отключён.');
        }
        $key->touch($this->requests->getCurrentRequest()?->getClientIp());
        $this->em->flush();

        return new UserBadge('api-key:'.$key->getId(), static fn (): ApiPrincipal => new ApiPrincipal($key));
    }
}
