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
use OneLogin\Saml2\LogoutRequest;
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
 * экземпляру портала (тестовому), принимался бы здесь.
 *
 * С ключом портала (создаётся на странице настроек) провайдер может шифровать утверждения — портал их
 * расшифровывает, а при «только зашифрованные» другие не принимает; ключом же подписываются запросы выхода.
 * Единый выход (SLO, HTTP-Redirect): выход из портала завершает и сеанс у провайдера (запрос LogoutRequest),
 * а выход в другой системе провайдера (его LogoutRequest, обязательно подписанный) завершает сеанс портала.
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

    /** Адрес единого выхода портала (SingleLogoutService): сюда провайдер присылает запросы и ответы выхода. */
    public function slsUrl(): string
    {
        return $this->absolute('app_saml_sls');
    }

    /** Есть ли ключ портала (расшифровка утверждений, подпись запросов выхода). */
    public function hasSpKey(): bool
    {
        $saml = $this->settings->saml();

        return '' !== $saml['sp_key'] && '' !== $saml['sp_cert'] && false !== @openssl_pkey_get_private($saml['sp_key']) && false !== @openssl_x509_read($saml['sp_cert']);
    }

    /** Включён ли единый выход: выход из портала уводит к провайдеру. */
    public function isSloEnabled(): bool
    {
        $saml = $this->settings->saml();

        return $saml['slo'] && '' !== $saml['idp_slo_url'] && $this->isEnabled();
    }

    /**
     * Новый ключ портала: RSA 2048 и самоподписанный сертификат на 10 лет (для расшифровки утверждений и подписи
     * запросов выхода; провайдер получает сертификат из метаданных портала).
     *
     * @return array{key: string, cert: string}
     */
    public function generateSpKey(): array
    {
        $host = parse_url((string) $this->spBaseUrl(), \PHP_URL_HOST) ?: 'docportal';
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => \OPENSSL_KEYTYPE_RSA]);
        if (false === $key) {
            throw SsoException::technical('ключ не создан: '.openssl_error_string());
        }
        $csr = openssl_csr_new(['commonName' => mb_substr((string) $host, 0, 64)], $key, ['digest_alg' => 'sha256']);
        $x509 = false !== $csr ? openssl_csr_sign($csr, null, $key, 3650, ['digest_alg' => 'sha256'], random_int(1, \PHP_INT_MAX)) : false;
        if (false === $x509 || !openssl_pkey_export($key, $pem) || !openssl_x509_export($x509, $cert)) {
            throw SsoException::technical('сертификат не создан: '.openssl_error_string());
        }

        return ['key' => $pem, 'cert' => $cert];
    }

    /** @return array{subject: string, valid_to: \DateTimeImmutable, expired: bool}|null сертификат портала */
    public function spCertificateInfo(): ?array
    {
        return $this->certificateInfo($this->settings->saml()['sp_cert'])[0] ?? null;
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

    /**
     * @param array<string, mixed> $security переопределения раздела security (например, wantMessagesSigned для выхода)
     *
     * @return array<string, mixed> настройки onelogin/php-saml
     */
    public function toolkitSettings(array $security = []): array
    {
        $saml = $this->settings->saml();
        $certs = $this->certificates();
        $key = $this->hasSpKey();
        $sp = [
            'entityId' => $this->spEntityId(),
            'assertionConsumerService' => ['url' => $this->acsUrl(), 'binding' => Constants::BINDING_HTTP_POST],
            'singleLogoutService' => ['url' => $this->slsUrl(), 'binding' => Constants::BINDING_HTTP_REDIRECT],
            'NameIDFormat' => Constants::NAMEID_UNSPECIFIED,
        ];
        if ($key) {
            $sp['x509cert'] = $saml['sp_cert'];
            $sp['privateKey'] = $saml['sp_key'];
        }
        $idp = [
            'entityId' => $saml['idp_entity_id'],
            'singleSignOnService' => ['url' => $saml['idp_sso_url'], 'binding' => Constants::BINDING_HTTP_REDIRECT],
            'x509certMulti' => ['signing' => $certs],
        ];
        if ('' !== $saml['idp_slo_url']) {
            $idp['singleLogoutService'] = ['url' => $saml['idp_slo_url'], 'binding' => Constants::BINDING_HTTP_REDIRECT];
        }

        return [
            'strict' => true,
            'debug' => false,
            'sp' => $sp,
            'idp' => $idp,
            'security' => $security + [
                // Запросы и ответы выхода подписываются ключом портала (AD FS их без подписи не принимает).
                'logoutRequestSigned' => $key,
                'logoutResponseSigned' => $key,
                'wantAssertionsEncrypted' => $key && $saml['want_encrypted'],
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
        // С ключом портала сертификат публикуется и для шифрования — провайдер сможет шифровать утверждения.
        $xml = $settings->getSPMetadata($this->hasSpKey());
        $errors = $settings->validateMetadata($xml);
        if ([] !== $errors) {
            throw SsoException::technical('метаданные не прошли проверку: '.implode(', ', $errors));
        }

        return $xml;
    }

    /**
     * Проверка ответа провайдера (POST на ACS) и данные сотрудника.
     *
     * @return array{session: array{idp: string, name_id: string, format: string, nq: ?string, spnq: ?string, session_index: ?string}, subject: string, username: string, name: ?string, email: ?string, department: ?string, roles: list<string>|null}
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
            'session' => [
                'idp' => $saml['idp_entity_id'],
                'name_id' => $nameId,
                'format' => (string) $auth->getNameIdFormat(),
                'nq' => $auth->getNameIdNameQualifier(),
                'spnq' => $auth->getNameIdSPNameQualifier(),
                'session_index' => $auth->getSessionIndex(),
            ],
            'subject' => mb_substr($subject, 0, 255),
            'username' => trim($username),
            'name' => self::first($attributes, '' !== $saml['name_attr'] ? [$saml['name_attr']] : self::NAME_ATTRS),
            'email' => self::first($attributes, '' !== $saml['email_attr'] ? [$saml['email_attr']] : self::EMAIL_ATTRS),
            'department' => '' !== $saml['department_attr'] ? self::first($attributes, [$saml['department_attr']]) : null,
            'roles' => $roles,
        ];
    }

    /**
     * Запрос выхода к провайдеру (единый выход по кнопке «Выйти» портала).
     *
     * @param array{idp?: string, name_id?: string, format?: string, nq?: ?string, spnq?: ?string, session_index?: ?string} $session
     *
     * @return array{url: string, request_id: string}
     *
     * @throws SsoException
     */
    public function logoutRequest(array $session): array
    {
        if (!$this->isSloEnabled()) {
            throw SsoException::technical('единый выход не настроен.');
        }
        try {
            $auth = new Auth($this->toolkitSettings());
            $url = (string) $auth->logout(null, [], ($session['name_id'] ?? '') ?: null, $session['session_index'] ?? null, true,
                ($session['format'] ?? '') ?: null, $session['nq'] ?? null, $session['spnq'] ?? null);

            return ['url' => $url, 'request_id' => (string) $auth->getLastRequestID()];
        } catch (\Throwable $e) {
            throw SsoException::technical('запрос выхода не сформирован: '.$e->getMessage(), $e);
        }
    }

    /**
     * Запрос или ответ выхода от провайдера (GET на адрес единого выхода).
     *
     * Ответ (SAMLResponse) — на выход, начатый порталом: проверяется адресат и номер запроса. Запрос (SAMLRequest)
     * — сотрудник вышел в другой системе провайдера: принимается только подписанный сертификатом провайдера;
     * redirect — адрес, по которому браузер вернёт провайдеру ответ «выход выполнен».
     *
     * @return array{kind: 'response', request_id: ?string}|array{kind: 'request', name_id: ?string, session_indexes: list<string>, redirect: string}
     *
     * @throws SsoException
     */
    public function processLogout(Request $request, ?string $expectedRequestId = null): array
    {
        $sls = parse_url($this->slsUrl());
        if (!\is_array($sls) || !isset($sls['scheme'], $sls['host'])) {
            throw SsoException::technical('не задан адрес портала для провайдера.');
        }
        $isRequest = $request->query->has('SAMLRequest');
        if (!$isRequest && !$request->query->has('SAMLResponse')) {
            throw new SsoException('провайдер не передал запрос или ответ выхода.');
        }
        Utils::setSelfProtocol($sls['scheme']);
        Utils::setSelfHost($sls['host']);
        Utils::setSelfPort((string) ($sls['port'] ?? ('https' === $sls['scheme'] ? 443 : 80)));
        $previous = [$_GET, $_SERVER['REQUEST_URI'] ?? null, $_SERVER['QUERY_STRING'] ?? null];
        $_GET = [];
        foreach (['SAMLRequest', 'SAMLResponse', 'RelayState', 'SigAlg', 'Signature'] as $name) {
            if ($request->query->has($name)) {
                $_GET[$name] = (string) $request->query->get($name);
            }
        }
        // Подпись HTTP-Redirect проверяется по параметрам в том виде, в каком их закодировал провайдер.
        $_SERVER['QUERY_STRING'] = (string) $request->server->get('QUERY_STRING', '');
        $_SERVER['REQUEST_URI'] = ($sls['path'] ?? '/').('' !== $_SERVER['QUERY_STRING'] ? '?'.$_SERVER['QUERY_STRING'] : '');
        try {
            if ($isRequest) {
                $auth = new Auth($this->toolkitSettings(['wantMessagesSigned' => true]));
                $xml = self::inflate((string) $_GET['SAMLRequest']);
                $saml = $this->settings->saml();
                $nameId = null;
                $indexes = [];
                if (null !== $xml) {
                    try {
                        $nameId = LogoutRequest::getNameId($xml, $this->hasSpKey() ? $saml['sp_key'] : null);
                        $indexes = array_values(array_map('strval', LogoutRequest::getSessionIndexes($xml)));
                    } catch (\Throwable) {
                        $nameId = null;
                    }
                }
                $redirect = (string) $auth->processSLO(true, null, true, null, true);
            } else {
                $auth = new Auth($this->toolkitSettings());
                $auth->processSLO(true, $expectedRequestId, true, null, true);
            }
        } catch (\Throwable $e) {
            throw SsoException::technical('сообщение выхода не принято: '.$e->getMessage(), $e);
        } finally {
            [$_GET, $_SERVER['REQUEST_URI'], $_SERVER['QUERY_STRING']] = $previous;
        }
        if ([] !== $auth->getErrors()) {
            throw SsoException::technical('сообщение выхода не принято: '.($auth->getLastErrorReason() ?: implode(', ', $auth->getErrors())));
        }

        return $isRequest
            ? ['kind' => 'request', 'name_id' => $nameId, 'session_indexes' => $indexes, 'redirect' => $redirect]
            : ['kind' => 'response', 'request_id' => $expectedRequestId];
    }

    /** XML сообщения HTTP-Redirect (base64 + deflate); null — не читается. */
    public static function inflate(string $encoded): ?string
    {
        $raw = base64_decode($encoded, true);
        if (false === $raw || '' === $raw || \strlen($raw) > 1_000_000) {
            return null;
        }
        $xml = @gzinflate($raw);

        return false === $xml ? (str_starts_with(ltrim($raw), '<') ? $raw : null) : $xml;
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
     * @return array{entity_id: string, sso_url: string, slo_url: string, certs: list<string>}
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
            $parsed = IdPMetadataParser::parseXML($xml, null, null, Constants::BINDING_HTTP_REDIRECT, Constants::BINDING_HTTP_REDIRECT);
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

        $slo = (string) ($idp['singleLogoutService']['url'] ?? '');

        return [
            'entity_id' => (string) $idp['entityId'],
            'sso_url' => (string) $idp['singleSignOnService']['url'],
            // Адрес выхода — только https (иначе запрос выхода с данными сотрудника шёл бы открыто).
            'slo_url' => preg_match('~^https://~i', $slo) ? $slo : '',
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
