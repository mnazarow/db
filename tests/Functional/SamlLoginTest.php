<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\User;
use App\Service\PortalSettings;
use App\Tests\PortalTestCase;
use RobRichards\XMLSecLibs\XMLSecurityDSig;
use RobRichards\XMLSecLibs\XMLSecurityKey;

/**
 * Вход через SAML 2.0: переход к провайдеру, метаданные портала, приём подписанного ответа (создание учётной
 * записи и роль из групп), отказ при повторе, подделке, чужом адресате, без подписи, с истёкшим сроком, без
 * запроса из этого браузера и при подмене заголовка Host, ограничения по доменам и защита существующих
 * учётных записей, заполнение настроек по метаданным провайдера.
 */
final class SamlLoginTest extends PortalTestCase
{
    private const IDP = 'https://idp.test/metadata';
    private const ACS = 'http://localhost/login/saml/acs';
    private const SP = 'http://localhost/saml/metadata';

    private static ?string $key = null;
    private static ?string $cert = null;

    protected function setUp(): void
    {
        parent::setUp();
        if (null === self::$key) {
            $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => \OPENSSL_KEYTYPE_RSA]);
            $csr = openssl_csr_new(['commonName' => 'idp.test'], $key, ['digest_alg' => 'sha256']);
            $x509 = openssl_csr_sign($csr, null, $key, 365, ['digest_alg' => 'sha256']);
            openssl_pkey_export($key, $pem);
            openssl_x509_export($x509, $certPem);
            [self::$key, self::$cert] = [$pem, $certPem];
        }
        $this->settings()->setSaml([
            'enabled' => true, 'idp_entity_id' => self::IDP, 'idp_sso_url' => 'https://idp.test/sso', 'idp_cert' => self::$cert,
            'create_users' => true, 'roles_attr' => 'groups', 'admin_value' => 'portal-admins', 'marketer_value' => 'marketing', 'department_attr' => 'department',
            'sp_base_url' => 'http://localhost',
        ]);
    }

    protected function tearDown(): void
    {
        $this->settings()->setSaml(['enabled' => false]);
        parent::tearDown();
    }

    public function testLoginPageRedirectAndMetadata(): void
    {
        $crawler = $this->client->request('GET', '/login?kerberos=off');
        self::assertSelectorTextContains('a[href="/login/saml"]', 'Войти через SAML');
        $this->client->request('GET', '/login/saml');
        $location = (string) $this->client->getResponse()->headers->get('Location');
        self::assertStringStartsWith('https://idp.test/sso?SAMLRequest=', $location);
        parse_str((string) parse_url($location, \PHP_URL_QUERY), $query);
        $request = (string) gzinflate(base64_decode((string) $query['SAMLRequest']));
        self::assertStringContainsString('AssertionConsumerServiceURL="'.self::ACS.'"', $request);
        self::assertStringContainsString('<saml:Issuer>'.self::SP.'</saml:Issuer>', $request);

        $this->client->request('GET', '/saml/metadata');
        self::assertResponseIsSuccessful();
        $xml = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('entityID="'.self::SP.'"', $xml);
        self::assertStringContainsString('Location="'.self::ACS.'"', $xml);
    }

    public function testSignedResponseCreatesUserAndRejectsForgeries(): void
    {
        $requestId = $this->startLogin();
        $response = $this->response('ivan.petrov@corp.local', ['displayName' => ['Иван Петров'], 'mail' => ['ivan.petrov@corp.local'], 'department' => ['Бухгалтерия'], 'groups' => ['staff', 'marketing']], inResponseTo: $requestId);
        $this->postResponse($response);
        self::assertResponseRedirects('/');
        $user = $this->em()->getRepository(User::class)->findOneBy(['username' => 'ivan.petrov']);
        self::assertNotNull($user);
        self::assertSame('Иван Петров', $user->getDisplayName());
        self::assertSame('ivan.petrov@corp.local', $user->getEmail());
        self::assertSame('Бухгалтерия', $user->getDepartment());
        self::assertSame(User::SOURCE_SSO, $user->getAuthSource());
        self::assertSame('saml:'.self::IDP.'|ivan.petrov@corp.local', $user->getSsoSubject());
        self::assertTrue($user->isMarketer(), 'роль — из групп провайдера');
        self::assertFalse($user->isAdmin());
        $this->client->request('GET', '/');
        self::assertSelectorExists('form[action="/logout"]');

        // Тот же ответ второй раз — отказ (запрос уже использован; перехваченный ответ не пускает).
        $this->client->getCookieJar()->clear();
        $this->postResponse($response);
        self::assertResponseRedirects('/login');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert', 'другому входу');

        // Подделка после подписи, чужой адресат, без подписи, просроченный ответ.
        $forged = str_replace('Иван Петров', 'Пётр Иванов', $this->response('ivan.petrov@corp.local', ['displayName' => ['Иван Петров']], inResponseTo: $this->startLogin()));
        $this->assertRejected($forged);
        $this->assertRejected($this->response('ivan.petrov@corp.local', [], audience: 'https://other.example.ru/metadata', inResponseTo: $this->startLogin()));
        $this->assertRejected($this->response('ivan.petrov@corp.local', [], sign: false, inResponseTo: $this->startLogin()));
        $this->assertRejected($this->response('ivan.petrov@corp.local', [], expired: true, inResponseTo: $this->startLogin()));
        self::assertSame('Иван Петров', $this->em()->getRepository(User::class)->findOneBy(['username' => 'ivan.petrov'])->getDisplayName());

        // В локальную учётную запись портала (admin) через SAML не войти.
        $this->postResponse($this->response('admin', ['groups' => ['portal-admins']], inResponseTo: $this->startLogin()));
        self::assertResponseRedirects('/login');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert', 'локальная учётная запись');
    }

    public function testUnsolicitedAndForeignResponsesAreRejected(): void
    {
        // Ответ без запроса из этого браузера (чужой ответ, подставленный коллеге) — отказ.
        $this->postResponse($this->response('anna.unsolicited@corp.local', []));
        self::assertResponseRedirects('/login');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert', 'вход не был начат на этом портале');
        // Ответ на запрос, начатый в другом браузере, — отказ.
        $otherRequest = $this->startLogin();
        $this->client->getCookieJar()->clear();
        $this->startLogin();
        $this->postResponse($this->response('anna.unsolicited@corp.local', [], inResponseTo: $otherRequest));
        self::assertResponseRedirects('/login');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert', 'другому входу');
        self::assertNull($this->em()->getRepository(User::class)->findOneBy(['username' => 'anna.unsolicited']));

        // Вход со стороны провайдера — только если разрешён; повтор того же ответа — отказ.
        $this->settings()->setSaml(['allow_idp_initiated' => true] + $this->settings()->saml());
        $this->client->getCookieJar()->clear();
        $unsolicited = $this->response('anna.unsolicited@corp.local', []);
        $this->postResponse($unsolicited);
        self::assertResponseRedirects('/');
        $this->client->getCookieJar()->clear();
        $this->postResponse($unsolicited);
        self::assertResponseRedirects('/login');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert', 'уже использован');

        // Ответ для другого экземпляра портала (тестового) с подменённым заголовком Host — отказ: адрес портала
        // для провайдера берётся из настроек, а не из запроса. Ответ для этого портала под тем же Host — принят.
        $this->client->getCookieJar()->clear();
        $staging = $this->response('boris.staging@corp.local', ['groups' => ['portal-admins']], audience: 'http://staging.corp.example/saml/metadata', acs: 'http://staging.corp.example/login/saml/acs');
        $this->postResponse($staging, 'staging.corp.example');
        self::assertResponseRedirects('/login');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert', 'портал не принял');
        self::assertNull($this->em()->getRepository(User::class)->findOneBy(['username' => 'boris.staging']));
        $this->client->getCookieJar()->clear();
        $this->postResponse($this->response('boris.staging@corp.local', []), 'staging.corp.example');
        self::assertResponseRedirects('/');
        self::assertFalse($this->em()->getRepository(User::class)->findOneBy(['username' => 'boris.staging'])->isAdmin());
        $this->settings()->setSaml(['allow_idp_initiated' => false] + $this->settings()->saml());

        // Временный идентификатор без атрибута логина — отказ (иначе при каждом входе новая учётная запись).
        $this->client->getCookieJar()->clear();
        $this->assertRejected($this->response('_t'.bin2hex(random_bytes(6)), [], format: 'urn:oasis:names:tc:SAML:2.0:nameid-format:transient', inResponseTo: $this->startLogin()));
    }

    public function testDomainsAndExistingAccounts(): void
    {
        $em = $this->em();
        $kuzmin = (new User())->setUsername('kuzmin')->setDisplayName('Кузьмин')->setEmail('kuzmin@corp.local')->setAuthSource(User::SOURCE_LDAP)->setPassword('')->setActive(true);
        $em->persist($kuzmin);
        $em->flush();

        // Тёзка из другого домена (гость у того же провайдера) не занимает учётную запись сотрудника.
        $this->postResponse($this->response('kuzmin@partner.example', ['mail' => ['kuzmin@partner.example']], format: 'urn:oasis:names:tc:SAML:1.1:nameid-format:emailAddress', inResponseTo: $this->startLogin()));
        self::assertResponseRedirects('/login');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert', 'уже есть в портале');
        // Допустимые домены заданы — чужой домен не пускается вовсе.
        $this->settings()->setSaml(['domains' => 'corp.local, CORP'] + $this->settings()->saml());
        $this->postResponse($this->response('kuzmin@partner.example', [], format: 'urn:oasis:names:tc:SAML:1.1:nameid-format:emailAddress', inResponseTo: $this->startLogin()));
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert', 'не допускаются');
        // Свой домен — учётная запись из каталога связывается с провайдером; привязка — к логину (не к почте).
        $this->postResponse($this->response('kuzmin@corp.local', ['displayName' => ['Кузьмин Павел']], format: 'urn:oasis:names:tc:SAML:1.1:nameid-format:emailAddress', inResponseTo: $this->startLogin()));
        self::assertResponseRedirects('/');
        $em->clear();
        $linked = $em->getRepository(User::class)->findOneBy(['username' => 'kuzmin']);
        self::assertSame('saml:'.self::IDP.'|kuzmin@corp.local', $linked->getSsoSubject());
        self::assertSame('Кузьмин Павел', $linked->getDisplayName());
    }

    public function testAdminFillsSettingsFromIdpMetadata(): void
    {
        $this->settings()->setSaml(['enabled' => false]);
        $this->loginAs('admin');
        $crawler = $this->client->request('GET', '/admin/security');
        // Адрес портала не задан (ни здесь, ни в APP_DEFAULT_URI) — администратор предупреждён.
        self::assertSelectorTextContains('#saml .alert--warning', 'Не задан адрес портала');
        $body = (string) preg_replace('/-----[A-Z ]+-----|\s+/', '', (string) self::$cert);
        $metadata = '<md:EntityDescriptor xmlns:md="urn:oasis:names:tc:SAML:2.0:metadata" entityID="https://adfs.corp.local/adfs/services/trust">'
            .'<md:IDPSSODescriptor protocolSupportEnumeration="urn:oasis:names:tc:SAML:2.0:protocol">'
            .'<md:KeyDescriptor use="signing"><ds:KeyInfo xmlns:ds="http://www.w3.org/2000/09/xmldsig#"><ds:X509Data><ds:X509Certificate>'.$body.'</ds:X509Certificate></ds:X509Data></ds:KeyInfo></md:KeyDescriptor>'
            .'<md:SingleSignOnService Binding="urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect" Location="https://adfs.corp.local/adfs/ls/"/>'
            .'</md:IDPSSODescriptor></md:EntityDescriptor>';
        $this->client->submit($crawler->filter('form[action$="/admin/security/saml/metadata"]')->form(['metadata' => $metadata]));
        $saml = $this->settings()->saml();
        self::assertSame('https://adfs.corp.local/adfs/services/trust', $saml['idp_entity_id']);
        self::assertSame('https://adfs.corp.local/adfs/ls/', $saml['idp_sso_url']);
        self::assertStringContainsString('BEGIN CERTIFICATE', $saml['idp_cert']);

        $crawler = $this->client->request('GET', '/admin/security');
        $this->client->submit($crawler->filter('form[action$="/admin/security/saml"]')->form(['enabled' => '1', 'idp_cert' => 'не сертификат']));
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert', 'сертификат провайдера не распознан');
        $crawler = $this->client->request('GET', '/admin/security');
        $form = $crawler->filter('form[action$="/admin/security/saml"]')->form();
        $form['enabled']->tick();
        $form['sp_base_url'] = 'http://localhost/';
        $this->client->submit($form);
        self::assertTrue($this->settings()->isSamlEnabled());
        self::assertSame('http://localhost', $this->settings()->saml()['sp_base_url']);
        $this->client->request('GET', '/admin/security');
        self::assertSelectorTextContains('#saml', self::ACS);
        self::assertSelectorNotExists('#saml .alert--warning');
        // Метаданные провайдера по http не скачиваются: сертификат подписи по пути можно подменить.
        $crawler = $this->client->request('GET', '/admin/security');
        $this->client->submit($crawler->filter('form[action$="/admin/security/saml/metadata"]')->form(['metadata' => 'http://adfs.corp.local/FederationMetadata.xml']));
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert', 'только по https');
    }

    private function assertRejected(string $response): void
    {
        $this->postResponse($response);
        self::assertResponseRedirects('/login');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert', 'Вход через SAML не удался');
    }

    /** Начало входа (кнопка на странице портала): номер запроса, на который должен ответить провайдер. */
    private function startLogin(): string
    {
        $this->client->request('GET', '/login/saml');
        parse_str((string) parse_url((string) $this->client->getResponse()->headers->get('Location'), \PHP_URL_QUERY), $query);
        $request = (string) gzinflate(base64_decode((string) $query['SAMLRequest']));
        self::assertMatchesRegularExpression('/ID="([^"]+)"/', $request);
        preg_match('/ID="([^"]+)"/', $request, $m);

        return $m[1];
    }

    /** Ответ провайдера приходит на ACS, страница сразу пересылает его на этот же портал (как браузер). */
    private function postResponse(string $xml, ?string $host = null): void
    {
        $server = null !== $host ? ['HTTP_HOST' => $host] : [];
        $crawler = $this->client->request('POST', '/login/saml/acs', ['SAMLResponse' => base64_encode($xml)], [], $server);
        self::assertResponseIsSuccessful();
        $this->client->submit($crawler->filter('form#saml-post')->form(), [], $server);
    }

    /** @param array<string, list<string>> $attributes */
    private function response(string $nameId, array $attributes, string $audience = self::SP, bool $sign = true, bool $expired = false, ?string $inResponseTo = null, string $acs = self::ACS, string $format = 'urn:oasis:names:tc:SAML:2.0:nameid-format:persistent'): string
    {
        $id = bin2hex(random_bytes(8));
        $now = gmdate('Y-m-d\TH:i:s\Z', $expired ? time() - 7200 : time());
        $before = gmdate('Y-m-d\TH:i:s\Z', ($expired ? time() - 7200 : time()) - 60);
        $later = gmdate('Y-m-d\TH:i:s\Z', ($expired ? time() - 7200 : time()) + 300);
        $irt = null !== $inResponseTo ? ' InResponseTo="'.$inResponseTo.'"' : '';
        $attrs = '';
        foreach ($attributes as $name => $values) {
            $attrs .= '<saml:Attribute Name="'.$name.'">';
            foreach ($values as $value) {
                $attrs .= '<saml:AttributeValue>'.htmlspecialchars($value, \ENT_XML1).'</saml:AttributeValue>';
            }
            $attrs .= '</saml:Attribute>';
        }
        $assertion = '<saml:Assertion xmlns:saml="urn:oasis:names:tc:SAML:2.0:assertion" ID="_a'.$id.'" Version="2.0" IssueInstant="'.$now.'">'
            .'<saml:Issuer>'.self::IDP.'</saml:Issuer>'
            .'<saml:Subject><saml:NameID Format="'.$format.'">'.htmlspecialchars($nameId, \ENT_XML1).'</saml:NameID>'
            .'<saml:SubjectConfirmation Method="urn:oasis:names:tc:SAML:2.0:cm:bearer"><saml:SubjectConfirmationData NotOnOrAfter="'.$later.'" Recipient="'.$acs.'"'.$irt.'/></saml:SubjectConfirmation></saml:Subject>'
            .'<saml:Conditions NotBefore="'.$before.'" NotOnOrAfter="'.$later.'"><saml:AudienceRestriction><saml:Audience>'.$audience.'</saml:Audience></saml:AudienceRestriction></saml:Conditions>'
            .'<saml:AuthnStatement AuthnInstant="'.$now.'" SessionIndex="_s'.$id.'"><saml:AuthnContext><saml:AuthnContextClassRef>urn:oasis:names:tc:SAML:2.0:ac:classes:PasswordProtectedTransport</saml:AuthnContextClassRef></saml:AuthnContext></saml:AuthnStatement>'
            .('' !== $attrs ? '<saml:AttributeStatement>'.$attrs.'</saml:AttributeStatement>' : '')
            .'</saml:Assertion>';
        if ($sign) {
            $dom = new \DOMDocument();
            $dom->loadXML($assertion);
            $root = $dom->documentElement;
            $dsig = new XMLSecurityDSig();
            $dsig->setCanonicalMethod(XMLSecurityDSig::EXC_C14N);
            $dsig->addReference($root, XMLSecurityDSig::SHA256, ['http://www.w3.org/2000/09/xmldsig#enveloped-signature', XMLSecurityDSig::EXC_C14N], ['id_name' => 'ID', 'overwrite' => false]);
            $key = new XMLSecurityKey(XMLSecurityKey::RSA_SHA256, ['type' => 'private']);
            $key->loadKey((string) self::$key);
            $dsig->sign($key);
            $dsig->add509Cert((string) self::$cert);
            $dsig->insertSignature($root, $root->getElementsByTagName('Issuer')->item(0)->nextSibling);
            $assertion = (string) $dom->saveXML($root);
        }

        return '<samlp:Response xmlns:samlp="urn:oasis:names:tc:SAML:2.0:protocol" xmlns:saml="urn:oasis:names:tc:SAML:2.0:assertion" ID="_r'.$id.'" Version="2.0" IssueInstant="'.$now.'" Destination="'.$acs.'"'.$irt.'>'
            .'<saml:Issuer>'.self::IDP.'</saml:Issuer>'
            .'<samlp:Status><samlp:StatusCode Value="urn:oasis:names:tc:SAML:2.0:status:Success"/></samlp:Status>'
            .$assertion
            .'</samlp:Response>';
    }

    private function settings(): PortalSettings
    {
        return static::getContainer()->get(PortalSettings::class);
    }
}
