<?php

declare(strict_types=1);

namespace App\Service\Sso;

use App\Service\Http\HttpTransportInterface;
use App\Service\PortalSettings;
use App\Service\PortalUrl;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use OneLogin\Saml2\Auth;
use OneLogin\Saml2\Constants;
use OneLogin\Saml2\IdPMetadataParser;
use OneLogin\Saml2\Settings;
use OneLogin\Saml2\Utils;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RequestContext;

/**
 * Вход через SAML 2.0 (AD FS, Keycloak, Blitz Identity, Shibboleth и др.) на библиотеке onelogin/php-saml.
 *
 * Портал — поставщик услуг (SP): запрос входа уходит к провайдеру (IdP) переадресацией, ответ приходит
 * POST-запросом на адрес ACS. Ответ принимается, только если подписан (ответ целиком или утверждение)
 * сертификатом провайдера, адресован порталу, отвечает на запрос, начатый в этом же браузере (если не
 * разрешён вход со стороны провайдера), не просрочен и не использовался раньше. Адрес портала для провайдера
 * (entityID, ACS) берётся из настроек, а не из заголовка Host запроса — иначе ответ, выданный другому
 * экземпляру портала (тестовому), принимался бы здесь. Зашифрованные утверждения и единый выход (SLO) не
 * поддерживаются: выход из портала завершает только сеанс портала.
 */
final class SamlService
{
    /** Атрибуты, которые пробуются для имени и почты, если в настройках не указаны свои. */
    private const NAME_ATTRS = ['displayName', 'http://schemas.xmlsoap.org/ws/2005/05/identity/claims/name', 'cn', 'name', 'urn:oid:2.16.840.1.113730.3.1.241'];
    private const EMAIL_ATTRS = ['mail', 'email', 'http://schemas.xmlsoap.org/ws/2005/05/identity/claims/emailaddress', 'urn:oid:0.9.2342.19200300.100.1.3'];

    /** Сколько хранится номер использованного утверждения (дольше любого разумного срока его действия). */
    private const REPLAY_TTL = 86400;

    public function __construct(
        private readonly PortalSettings $settings,
        private readonly UrlGeneratorInterface $urls,
        private readonly HttpTransportInterface $http,
        private readonly PortalUrl $portalUrl,
        private readonly Connection $connection,
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->settings->isSamlEnabled() && [] !== $this->certificates() && null !== $this->spBaseUrl();
    }

    /** Адрес портала для провайдера: из настроек SAML, иначе APP_DEFAULT_URI; null — не задан. */
    public function spBaseUrl(): ?string
    {
        $base = $this->settings->saml()['sp_base_url'];
        if ('' !== $base && preg_match('~^https?://[^/\s]+~i', $base)) {
            return $base;
        }

        return $this->portalUrl->baseUri();
    }

    private function absolute(string $route): string
    {
        $base = $this->spBaseUrl();
        if (null === $base) {
            return '';
        }
        $original = $this->urls->getContext();
        $this->urls->setContext(RequestContext::fromUri($base));
        try {
            return $this->urls->generate($route, [], UrlGeneratorInterface::ABSOLUTE_URL);
        } finally {
            $this->urls->setContext($original);
        }
    }

    public function buttonLabel(): string
    {
        return $this->settings->saml()['button'];
    }

    /** Идентификатор портала у провайдера (entityID) — адрес метаданных. */
    public function spEntityId(): string
    {
        return $this->absolute('app_saml_metadata');
    }

    public function acsUrl(): string
    {
        return $this->absolute('app_saml_acs');
    }

    /**
     * Сертификаты подписи провайдера (их может быть несколько — на время замены сертификата).
     *
     * @return list<string> PEM
     */
    public function certificates(?string $text = null): array
    {
        $text ??= $this->settings->saml()['idp_cert'];
        $out = [];
        if (preg_match_all('/-----BEGIN CERTIFICATE-----(.+?)-----END CERTIFICATE-----/s', $text, $m)) {
            foreach ($m[1] as $body) {
                $out[] = "-----BEGIN CERTIFICATE-----\n".chunk_split((string) preg_replace('/\s+/', '', $body), 64, "\n").'-----END CERTIFICATE-----';
            }
        } elseif ('' !== trim($text)) {
            $out[] = "-----BEGIN CERTIFICATE-----\n".chunk_split((string) preg_replace('/\s+/', '', $text), 64, "\n").'-----END CERTIFICATE-----';
        }

        return array_values(array_filter($out, static fn (string $pem): bool => false !== @openssl_x509_read($pem)));
    }

    /**
     * Сведения о сертификатах для страницы настроек: кому выдан и до какого числа действует.
     *
     * @return list<array{subject: string, valid_to: \DateTimeImmutable, expired: bool}>
     */
    public function certificateInfo(?string $text = null): array
    {
        $out = [];
        foreach ($this->certificates($text) as $pem) {
            $info = openssl_x509_parse($pem);
            if (!\is_array($info)) {
                continue;
            }
            $validTo = (new \DateTimeImmutable())->setTimestamp((int) ($info['validTo_time_t'] ?? 0));
            $out[] = ['subject' => (string) ($info['subject']['CN'] ?? $info['name'] ?? ''), 'valid_to' => $validTo, 'expired' => $validTo < new \DateTimeImmutable()];
        }

        return $out;
    }

    /** @return array<string, mixed> настройки onelogin/php-saml */
    public function toolkitSettings(): array
    {
        $saml = $this->settings->saml();
        $certs = $this->certificates();

        return [
            'strict' => true,
            'debug' => false,
            'sp' => [
                'entityId' => $this->spEntityId(),
                'assertionConsumerService' => ['url' => $this->acsUrl(), 'binding' => Constants::BINDING_HTTP_POST],
                'NameIDFormat' => Constants::NAMEID_UNSPECIFIED,
            ],
            'idp' => [
                'entityId' => $saml['idp_entity_id'],
                'singleSignOnService' => ['url' => $saml['idp_sso_url'], 'binding' => Constants::BINDING_HTTP_REDIRECT],
                'x509certMulti' => ['signing' => $certs],
            ],
            'security' => [
                'authnRequestsSigned' => false,
                // Подписан должен быть ответ или утверждение (без подписи библиотека ответ отклоняет).
                'wantAssertionsSigned' => false,
                'wantMessagesSigned' => false,
                'wantNameId' => true,
                // Не требовать способ входа «пароль»: иначе AD FS не пустит по Kerberos (вход Windows).
                'requestedAuthnContext' => false,
                'wantXMLValidation' => true,
                // Ответ без запроса из этого браузера (или на чужой запрос) не принимается — иначе на страницу
                // ACS можно было бы отправить свой ответ и «войти» коллегу под своей учётной записью.
                'rejectUnsolicitedResponsesWithInResponseTo' => !$saml['allow_idp_initiated'],
                'signatureAlgorithm' => 'http://www.w3.org/2001/04/xmldsig-more#rsa-sha256',
                'digestAlgorithm' => 'http://www.w3.org/2001/04/xmlenc#sha256',
            ],
        ];
    }

    /**
     * Адрес перехода к провайдеру и номер запроса (по нему проверяется, что ответ — на наш запрос).
     *
     * @return array{url: string, request_id: string}
     *
     * @throws SsoException
     */
    public function loginRequest(?string $returnTo = null): array
    {
        try {
            $auth = new Auth($this->toolkitSettings());
            $url = (string) $auth->login($returnTo, [], false, false, true);

            return ['url' => $url, 'request_id' => (string) $auth->getLastRequestID()];
        } catch (\Throwable $e) {
            throw SsoException::technical('настройки SAML неполные: '.$e->getMessage(), $e);
        }
    }

    /** Метаданные портала (SP) для регистрации у провайдера. */
    public function metadataXml(): string
    {
        $settings = new Settings($this->toolkitSettings(), true);
        $xml = $settings->getSPMetadata();
        $errors = $settings->validateMetadata($xml);
        if ([] !== $errors) {
            throw SsoException::technical('метаданные не прошли проверку: '.implode(', ', $errors));
        }

        return $xml;
    }

    /**
     * Проверка ответа провайдера (POST на ACS) и данные сотрудника.
     *
     * @return array{subject: string, username: string, name: ?string, email: ?string, department: ?string, roles: list<string>|null}
     *
     * @throws SsoException
     */
    public function processResponse(Request $request, ?string $requestId): array
    {
        $saml = $this->settings->saml();
        if ('' === (string) $request->request->get('SAMLResponse')) {
            throw new SsoException('провайдер не передал ответ (SAMLResponse).');
        }
        if ((null === $requestId || '' === $requestId) && !$saml['allow_idp_initiated']) {
            throw new SsoException('вход не был начат на этом портале — нажмите кнопку входа на странице портала.');
        }
        // Ответ проверяется как пришедший на адрес ACS из настроек (с ним сверяются Destination и Recipient),
        // а не по заголовкам запроса.
        $acs = parse_url($this->acsUrl());
        if (!\is_array($acs) || !isset($acs['scheme'], $acs['host'])) {
            throw SsoException::technical('не задан адрес портала для провайдера (APP_DEFAULT_URI или «Адрес портала» в настройках SAML).');
        }
        Utils::setSelfProtocol($acs['scheme']);
        Utils::setSelfHost($acs['host']);
        Utils::setSelfPort((string) ($acs['port'] ?? ('https' === $acs['scheme'] ? 443 : 80)));
        $previousServer = [$_SERVER['REQUEST_URI'] ?? null, $_SERVER['QUERY_STRING'] ?? null];
        $_SERVER['REQUEST_URI'] = $acs['path'] ?? '/';
        $_SERVER['QUERY_STRING'] = '';
        $previousPost = $_POST;
        $_POST['SAMLResponse'] = (string) $request->request->get('SAMLResponse');
        try {
            $auth = new Auth($this->toolkitSettings());
            $auth->processResponse(null !== $requestId && '' !== $requestId ? $requestId : null);
        } catch (\Throwable $e) {
            throw SsoException::technical('ответ провайдера не принят: '.$e->getMessage(), $e);
        } finally {
            $_POST = $previousPost;
            [$_SERVER['REQUEST_URI'], $_SERVER['QUERY_STRING']] = $previousServer;
        }
        if ([] !== $auth->getErrors() || !$auth->isAuthenticated()) {
            throw SsoException::technical('ответ провайдера не принят: '.($auth->getLastErrorReason() ?: implode(', ', $auth->getErrors()) ?: 'нет подтверждения входа'));
        }
        // Повторно использовать перехваченный ответ нельзя: номер утверждения запоминается в базе (уникальный
        // ключ — и при двух одновременных запросах, и на нескольких серверах).
        $assertionId = (string) $auth->getLastAssertionId();
        if ('' === $assertionId) {
            throw SsoException::technical('в ответе провайдера нет номера утверждения.');
        }
        $notOnOrAfter = (int) ($auth->getLastAssertionNotOnOrAfter() ?? 0);
        $this->remember(hash('sha256', $saml['idp_entity_id'].'|'.$assertionId), max(time() + self::REPLAY_TTL, $notOnOrAfter + 300));

        $attributes = $auth->getAttributes() + $auth->getAttributesWithFriendlyName();
        $nameId = trim((string) $auth->getNameId());
        $transient = Constants::NAMEID_TRANSIENT === $auth->getNameIdFormat();
        if ('' === $saml['username_attr'] && $transient) {
            throw SsoException::technical('провайдер передаёт временный идентификатор (NameID transient), а атрибут логина не задан — укажите его в настройках SAML.');
        }
        $username = '' !== $saml['username_attr'] ? self::first($attributes, [$saml['username_attr']]) : $nameId;
        if (null === $username || '' === trim($username)) {
            throw SsoException::technical('провайдер не передал логин пользователя'.('' !== $saml['username_attr'] ? ' (атрибут '.$saml['username_attr'].')' : ' (NameID)').'.');
        }
        // Постоянный идентификатор у провайдера — NameID формата persistent (не меняется при смене почты или
        // логина); при других форматах (почта, временный) учётная запись привязывается к логину.
        $persistent = '' !== $nameId && Constants::NAMEID_PERSISTENT === $auth->getNameIdFormat();
        $subject = 'saml:'.$saml['idp_entity_id'].'|'.($persistent ? $nameId : mb_strtolower(trim($username)));
        $roles = null;
        if ('' !== $saml['roles_attr']) {
            $roles = array_values(array_map('strval', $attributes[$saml['roles_attr']] ?? []));
        }

        return [
            'subject' => mb_substr($subject, 0, 255),
            'username' => trim($username),
            'name' => self::first($attributes, '' !== $saml['name_attr'] ? [$saml['name_attr']] : self::NAME_ATTRS),
            'email' => self::first($attributes, '' !== $saml['email_attr'] ? [$saml['email_attr']] : self::EMAIL_ATTRS),
            'department' => '' !== $saml['department_attr'] ? self::first($attributes, [$saml['department_attr']]) : null,
            'roles' => $roles,
        ];
    }

    /** @throws SsoException утверждение уже использовано */
    private function remember(string $key, int $expiresAt): void
    {
        $now = new \DateTimeImmutable();
        try {
            $this->connection->executeStatement('DELETE FROM saml_assertion WHERE expires_at < ?', [$now->format('Y-m-d H:i:s')]);
            $this->connection->insert('saml_assertion', ['id' => $key, 'expires_at' => $now->setTimestamp($expiresAt)->format('Y-m-d H:i:s')]);
        } catch (UniqueConstraintViolationException) {
            throw new SsoException('этот ответ провайдера уже использован — войдите заново.');
        }
    }

    /**
     * Адрес провайдера, его идентификатор и сертификаты — из метаданных (XML или адрес, откуда их скачать).
     *
     * @return array{entity_id: string, sso_url: string, certs: list<string>}
     *
     * @throws SsoException
     */
    public function parseMetadata(string $xmlOrUrl): array
    {
        $xml = trim($xmlOrUrl);
        if (preg_match('~^http://\S+$~i', $xml)) {
            // По http сертификат провайдера мог бы подменить любой на пути — а с ним и вход в портал.
            throw new SsoException('метаданные скачиваются только по https — укажите адрес https:// или вставьте XML.');
        }
        if (preg_match('~^https://\S+$~i', $xml)) {
            $response = $this->http->request('GET', $xml, null, ['Accept' => 'application/samlmetadata+xml, application/xml, text/xml'], 15);
            if (!$response->ok()) {
                throw new SsoException('метаданные не скачаны: '.$response->describeError());
            }
            $xml = $response->body;
        }
        try {
            $parsed = IdPMetadataParser::parseXML($xml, null, null, Constants::BINDING_HTTP_REDIRECT);
        } catch (\Throwable $e) {
            throw new SsoException('метаданные не разобраны: '.$e->getMessage(), 0, $e);
        }
        $idp = $parsed['idp'] ?? [];
        $certs = $idp['x509certMulti']['signing'] ?? (isset($idp['x509cert']) ? [$idp['x509cert']] : []);
        if (!empty($idp['singleSignOnService']['url']) && !preg_match('~^https://~i', (string) $idp['singleSignOnService']['url'])) {
            throw new SsoException('адрес входа провайдера в метаданных — не https: '.$idp['singleSignOnService']['url']);
        }
        if (empty($idp['entityId']) || empty($idp['singleSignOnService']['url']) || [] === $certs) {
            throw new SsoException('в метаданных нет идентификатора провайдера, адреса входа (HTTP-Redirect) или сертификата подписи.');
        }

        return [
            'entity_id' => (string) $idp['entityId'],
            'sso_url' => (string) $idp['singleSignOnService']['url'],
            'certs' => array_map(static fn (string $c): string => "-----BEGIN CERTIFICATE-----\n".chunk_split(preg_replace('/\s+/', '', $c) ?? '', 64, "\n").'-----END CERTIFICATE-----', array_values($certs)),
        ];
    }

    /**
     * @param array<string, list<string>> $attributes
     * @param list<string>                $names
     */
    private static function first(array $attributes, array $names): ?string
    {
        foreach ($names as $name) {
            foreach ($attributes[$name] ?? [] as $value) {
                if ('' !== trim((string) $value)) {
                    return trim((string) $value);
                }
            }
        }

        return null;
    }
}
