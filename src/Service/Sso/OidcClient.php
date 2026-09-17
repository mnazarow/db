<?php

declare(strict_types=1);

namespace App\Service\Sso;

use App\Service\Http\HttpTransportInterface;
use App\Service\PortalSettings;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Вход через внешнего провайдера по OpenID Connect (Keycloak, Blitz Identity, AD FS, Authentik…).
 *
 * Используется поток «код авторизации» с PKCE и секретом клиента. Токен портал получает
 * напрямую от провайдера по HTTPS, поэтому подпись id_token не проверяется (OIDC Core §3.1.3.7,
 * п. 6 это допускает): сверяются издатель, адресат, срок и nonce, а данные пользователя
 * запрашиваются на userinfo.
 */
final class OidcClient
{
    /** Сколько держим ответ discovery в кэше. */
    private const DISCOVERY_TTL = 3600;

    public function __construct(
        private readonly PortalSettings $settings,
        private readonly HttpTransportInterface $http,
        private readonly CacheInterface $cache,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->settings->isSsoEnabled();
    }

    public function buttonLabel(): string
    {
        return $this->settings->sso()['button'];
    }

    /**
     * Адреса провайдера из .well-known/openid-configuration.
     *
     * @return array{authorization_endpoint: string, token_endpoint: string, userinfo_endpoint: string, issuer: string}
     *
     * @throws SsoException если провайдер недоступен или ответ неполный
     */
    public function discover(bool $refresh = false): array
    {
        $issuer = $this->settings->sso()['issuer'];
        if ('' === $issuer) {
            throw new SsoException('Не задан адрес провайдера (issuer).');
        }
        $key = 'sso_discovery_'.substr(hash('sha256', $issuer), 0, 16);
        if ($refresh) {
            $this->cache->delete($key);
        }

        return $this->cache->get($key, function (ItemInterface $item) use ($issuer): array {
            $item->expiresAfter(self::DISCOVERY_TTL);
            $url = $issuer.'/.well-known/openid-configuration';
            $response = $this->http->request('GET', $url, null, ['Accept' => 'application/json'], 15);
            if (!$response->ok()) {
                throw new SsoException(\sprintf('Провайдер не ответил на %s: %s', $url, $response->error ?? 'код '.$response->status));
            }
            $data = $response->json();
            foreach (['authorization_endpoint', 'token_endpoint', 'userinfo_endpoint', 'issuer'] as $field) {
                if (!isset($data[$field]) || !\is_string($data[$field]) || '' === $data[$field]) {
                    throw new SsoException('В ответе провайдера нет поля '.$field.'.');
                }
            }

            return [
                'authorization_endpoint' => $data['authorization_endpoint'],
                'token_endpoint' => $data['token_endpoint'],
                'userinfo_endpoint' => $data['userinfo_endpoint'],
                'issuer' => $data['issuer'],
            ];
        });
    }

    /** Проверка настроек: доступен ли провайдер и какие у него адреса. */
    public function test(): array
    {
        try {
            $endpoints = $this->discover(true);

            return ['ok' => true, 'message' => 'Провайдер доступен: '.$endpoints['issuer'], 'endpoints' => $endpoints];
        } catch (SsoException $e) {
            return ['ok' => false, 'message' => $e->getMessage(), 'endpoints' => []];
        }
    }

    /** Адрес страницы входа провайдера. */
    public function authorizationUrl(string $redirectUri, string $state, string $nonce, string $codeVerifier): string
    {
        $sso = $this->settings->sso();
        $query = http_build_query([
            'response_type' => 'code',
            'client_id' => $sso['client_id'],
            'redirect_uri' => $redirectUri,
            'scope' => $sso['scopes'],
            'state' => $state,
            'nonce' => $nonce,
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '='),
            'code_challenge_method' => 'S256',
        ]);

        return $this->discover()['authorization_endpoint'].(str_contains($this->discover()['authorization_endpoint'], '?') ? '&' : '?').$query;
    }

    /**
     * Обмен кода на токены и получение сведений о пользователе.
     *
     * @return array<string, mixed> набор утверждений (claims)
     *
     * @throws SsoException
     */
    public function claims(string $code, string $redirectUri, string $codeVerifier, string $nonce): array
    {
        $sso = $this->settings->sso();
        $endpoints = $this->discover();
        $body = http_build_query([
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $redirectUri,
            'client_id' => $sso['client_id'],
            'client_secret' => $sso['client_secret'],
            'code_verifier' => $codeVerifier,
        ]);
        $response = $this->http->request('POST', $endpoints['token_endpoint'], $body, [
            'Content-Type' => 'application/x-www-form-urlencoded',
            'Accept' => 'application/json',
        ], 20);
        if (!$response->ok()) {
            $this->logger->warning('SSO: провайдер отклонил обмен кода', ['status' => $response->status, 'error' => $response->error, 'body' => mb_substr($response->body, 0, 300)]);

            throw new SsoException('Провайдер отклонил вход: '.($response->error ?? 'код '.$response->status).'.');
        }
        $tokens = $response->json() ?? [];
        $idToken = (string) ($tokens['id_token'] ?? '');
        $accessToken = (string) ($tokens['access_token'] ?? '');
        if ('' === $idToken || '' === $accessToken) {
            throw new SsoException('Провайдер не вернул токены.');
        }
        $claims = self::decodeJwtPayload($idToken);
        $this->checkIdToken($claims, $endpoints['issuer'], $sso['client_id'], $nonce);

        // Сведения о пользователе берём на userinfo: там полный набор утверждений.
        $info = $this->http->request('GET', $endpoints['userinfo_endpoint'], null, [
            'Authorization' => 'Bearer '.$accessToken,
            'Accept' => 'application/json',
        ], 20);
        if ($info->ok()) {
            $claims = array_merge($claims, $info->json() ?? []);
        } else {
            $this->logger->info('SSO: userinfo недоступен, используем данные id_token', ['status' => $info->status]);
        }

        return $claims;
    }

    /**
     * @param array<string, mixed> $claims
     *
     * @throws SsoException
     */
    private function checkIdToken(array $claims, string $issuer, string $clientId, string $nonce): void
    {
        if (($claims['iss'] ?? '') !== $issuer) {
            throw new SsoException('Токен выдан другим издателем.');
        }
        $audience = $claims['aud'] ?? '';
        $audiences = \is_array($audience) ? $audience : [$audience];
        if (!\in_array($clientId, $audiences, true)) {
            throw new SsoException('Токен выдан другому приложению.');
        }
        if (isset($claims['exp']) && (int) $claims['exp'] < time() - 60) {
            throw new SsoException('Срок действия токена истёк.');
        }
        if ('' !== $nonce && isset($claims['nonce']) && !hash_equals((string) $claims['nonce'], $nonce)) {
            throw new SsoException('Не совпал одноразовый код (nonce).');
        }
    }

    /**
     * Полезная нагрузка JWT без проверки подписи: токен получен напрямую от провайдера по TLS.
     *
     * @return array<string, mixed>
     */
    public static function decodeJwtPayload(string $jwt): array
    {
        $parts = explode('.', $jwt);
        if (3 !== \count($parts)) {
            throw new SsoException('Провайдер вернул токен неизвестного формата.');
        }
        $payload = base64_decode(strtr($parts[1], '-_', '+/').str_repeat('=', (4 - \strlen($parts[1]) % 4) % 4), true);
        $data = json_decode((string) $payload, true);
        if (!\is_array($data)) {
            throw new SsoException('Не удалось разобрать токен провайдера.');
        }

        return $data;
    }
}
