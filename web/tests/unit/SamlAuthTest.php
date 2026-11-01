<?php

declare(strict_types=1);

namespace tests\unit;

use PHPUnit\Framework\TestCase;
use SamlAuthService;
use PluginManager;
use DOMDocument;
use DOMXPath;
use PDO;

require_once dirname(__DIR__, 2) . '/src/services/SamlAuthService.php';
require_once dirname(__DIR__, 2) . '/src/core/PluginManager.php';

final class SamlAuthTest extends TestCase
{
    private PDO $db;
    /** @var list<int> */
    private array $cleanUserIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->db = getDB();
    }

    protected function tearDown(): void
    {
        if (!empty($this->cleanUserIds)) {
            $in = implode(',', array_map('intval', $this->cleanUserIds));
            $this->db->exec("DELETE FROM sys_users WHERE id IN ({$in})");
        }
        $this->db->exec("DELETE FROM sys_users WHERE username LIKE 'saml_test_%'");
        $this->db->exec('DELETE FROM sys_saml_used_assertions');
        $this->db->exec("DELETE FROM sys_plugin_settings WHERE plugin_slug = 'saml-auth'");
        PluginManager::resetCache();
        unset($_COOKIE['aipbx_saml_request']);
        parent::tearDown();
    }

    public function testSamlAuthDisabledByDefault(): void
    {
        PluginManager::resetCache();
        $this->assertFalse(SamlAuthService::isEnabled());
    }

    public function testSamlAuthPublicSettings(): void
    {
        $public = SamlAuthService::getPublicSettings();
        $this->assertArrayHasKey('enabled', $public);
        $this->assertArrayHasKey('provider_name', $public);
        $this->assertArrayHasKey('icon', $public);
        $this->assertSame('fas fa-shield-alt', $public['icon']);
    }

    public function testSpEndpointsResolution(): void
    {
        $entityId = SamlAuthService::getSpEntityId();
        $acsUrl = SamlAuthService::getAcsUrl();
        $metadataUrl = SamlAuthService::getMetadataUrl();

        $this->assertStringContainsString('/auth/saml/metadata', $entityId);
        $this->assertStringContainsString('/auth/saml/acs', $acsUrl);
        $this->assertStringContainsString('/auth/saml/metadata', $metadataUrl);
    }

    public function testSpMetadataXmlGeneration(): void
    {
        $xml = SamlAuthService::getSpMetadataXml();
        $this->assertNotEmpty($xml);

        $dom = new DOMDocument();
        $loaded = $dom->loadXML($xml);
        $this->assertTrue($loaded, 'SP Metadata must be valid XML');

        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('md', 'urn:oasis:names:tc:SAML:2.0:metadata');

        $entities = $xpath->query('//md:EntityDescriptor');
        $this->assertSame(1, $entities->length);

        $ssoDescriptors = $xpath->query('//md:SPSSODescriptor');
        $this->assertSame(1, $ssoDescriptors->length);

        $acs = $xpath->query('//md:AssertionConsumerService');
        $this->assertSame(1, $acs->length);
        $this->assertSame('urn:oasis:names:tc:SAML:2.0:bindings:HTTP-POST', $acs->item(0)->getAttribute('Binding'));
    }

    public function testDomainFiltering(): void
    {
        // When allowed_domains is empty, any domain is allowed
        $this->assertTrue(SamlAuthService::isDomainAllowed('john@anydomain.com'));

        $this->assertFalse(SamlAuthService::isDomainAllowed('invalid-email-address'));
    }

    public function testRoleResolutionFromAttributes(): void
    {
        // Default role fallback
        $role1 = SamlAuthService::resolveRoleFromAttributes([]);
        $this->assertSame('user', $role1);

        // Role mapping with matching admin value
        $attrs = [
            'role' => ['aipbx_admin'],
        ];
        $role2 = SamlAuthService::resolveRoleFromAttributes($attrs);
        $this->assertSame('admin', $role2);

        // Standard Microsoft URN claim
        $attrsMs = [
            'http://schemas.microsoft.com/ws/2008/06/identity/claims/role' => ['aipbx_admin'],
        ];
        $role3 = SamlAuthService::resolveRoleFromAttributes($attrsMs);
        $this->assertSame('admin', $role3);
    }

    public function testParseIdpMetadataXml(): void
    {
        $idpXml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<md:EntityDescriptor xmlns:md="urn:oasis:names:tc:SAML:2.0:metadata" entityID="https://sts.windows.net/test-tenant/">
  <md:IDPSSODescriptor protocolSupportEnumeration="urn:oasis:names:tc:SAML:2.0:protocol">
    <md:KeyDescriptor use="signing">
      <ds:KeyInfo xmlns:ds="http://www.w3.org/2000/09/xmldsig#">
        <ds:X509Data>
          <ds:X509Certificate>MIICtestCertificateDataXYZ==</ds:X509Certificate>
        </ds:X509Data>
      </ds:KeyInfo>
    </md:KeyDescriptor>
    <md:SingleSignOnService Binding="urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect" Location="https://login.microsoftonline.com/test-tenant/saml2"/>
    <md:SingleSignOnService Binding="urn:oasis:names:tc:SAML:2.0:bindings:HTTP-POST" Location="https://login.microsoftonline.com/test-tenant/saml2-post"/>
  </md:IDPSSODescriptor>
</md:EntityDescriptor>
XML;

        $parsed = SamlAuthService::parseIdpMetadataXml($idpXml);
        $this->assertNotNull($parsed);
        $this->assertSame('https://sts.windows.net/test-tenant/', $parsed['entity_id']);
        $this->assertSame('https://login.microsoftonline.com/test-tenant/saml2', $parsed['sso_url']);
        $this->assertStringContainsString('BEGIN CERTIFICATE', $parsed['x509_cert']);
        $this->assertStringContainsString('MIICtestCertificateDataXYZ==', $parsed['x509_cert']);
    }

    public function testXmlDsigSignatureVerification(): void
    {
        // 1. Generate test RSA keypair and self-signed X.509 cert
        $res = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $this->assertNotFalse($res);
        $csr = openssl_csr_new(['commonName' => 'idp.unit-test.local'], $res);
        $x509 = openssl_csr_sign($csr, null, $res, 365);
        openssl_x509_export($x509, $certPem);

        $assertionId = '_ass_' . bin2hex(random_bytes(8));
        $nowIso = gmdate('Y-m-d\TH:i:s\Z');

        // Create assertion
        $assXml = "<saml:Assertion xmlns:saml=\"urn:oasis:names:tc:SAML:2.0:assertion\" ID=\"{$assertionId}\" IssueInstant=\"{$nowIso}\" Version=\"2.0\"><saml:Issuer>https://idp.unit-test.local</saml:Issuer><saml:Subject><saml:NameID>user@test.local</saml:NameID></saml:Subject></saml:Assertion>";

        $assDom = new DOMDocument();
        $assDom->loadXML($assXml);
        $canonicalAss = $assDom->documentElement->C14N(true, false);
        $digest = base64_encode(hash('sha256', $canonicalAss, true));

        $signedInfoXml = "<ds:SignedInfo xmlns:ds=\"http://www.w3.org/2000/09/xmldsig#\"><ds:CanonicalizationMethod Algorithm=\"http://www.w3.org/2001/10/xml-exc-c14n#\"/><ds:SignatureMethod Algorithm=\"http://www.w3.org/2001/04/xmldsig-more#rsa-sha256\"/><ds:Reference URI=\"#{$assertionId}\"><ds:Transforms><ds:Transform Algorithm=\"http://www.w3.org/2000/09/xmldsig#enveloped-signature\"/><ds:Transform Algorithm=\"http://www.w3.org/2001/10/xml-exc-c14n#\"/></ds:Transforms><ds:DigestMethod Algorithm=\"http://www.w3.org/2001/04/xmlenc#sha256\"/><ds:DigestValue>{$digest}</ds:DigestValue></ds:Reference></ds:SignedInfo>";

        $siDom = new DOMDocument();
        $siDom->loadXML($signedInfoXml);
        $canonicalSi = $siDom->documentElement->C14N(true, false);
        openssl_sign($canonicalSi, $signature, $res, OPENSSL_ALGO_SHA256);
        $sigB64 = base64_encode($signature);

        $sigElementXml = "<ds:Signature xmlns:ds=\"http://www.w3.org/2000/09/xmldsig#\">{$signedInfoXml}<ds:SignatureValue>{$sigB64}</ds:SignatureValue></ds:Signature>";
        $frag = $assDom->createDocumentFragment();
        $frag->appendXML($sigElementXml);
        $assDom->documentElement->appendChild($frag);

        $responseXml = "<samlp:Response xmlns:samlp=\"urn:oasis:names:tc:SAML:2.0:protocol\" xmlns:saml=\"urn:oasis:names:tc:SAML:2.0:assertion\" ID=\"_resp_123\" Version=\"2.0\"><samlp:Status><samlp:StatusCode Value=\"urn:oasis:names:tc:SAML:2.0:status:Success\"/></samlp:Status>" . $assDom->saveXML($assDom->documentElement) . "</samlp:Response>";

        $respDom = new DOMDocument();
        $respDom->loadXML($responseXml);

        // Verification with matching certificate succeeds
        $vResult = SamlAuthService::verifyXmlSignature($respDom, $certPem);
        $this->assertTrue($vResult['valid'], 'XML-DSig signature should be valid');

        // Verification with forged or wrong certificate fails
        $wrongKey = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $wrongCsr = openssl_csr_new(['commonName' => 'attacker.local'], $wrongKey);
        $wrongX509 = openssl_csr_sign($wrongCsr, null, $wrongKey, 365);
        openssl_x509_export($wrongX509, $wrongCertPem);

        $wrongResult = SamlAuthService::verifyXmlSignature($respDom, $wrongCertPem);
        $this->assertFalse($wrongResult['valid'], 'Wrong certificate should fail signature check');
    }

    public function testAutoProvisionUserCreation(): void
    {
        $testEmail = 'saml_test_' . bin2hex(random_bytes(4)) . '@example.com';
        $testName = 'SAML Provisioned User';
        $testUsername = 'saml_test_user';

        $user = SamlAuthService::autoProvisionUser($testEmail, $testName, $testUsername, 'admin');
        $this->assertNotNull($user);
        $this->cleanUserIds[] = (int)$user['id'];

        $this->assertSame(mb_strtolower($testEmail), mb_strtolower((string)$user['email']));
        $this->assertSame($testName, $user['full_name']);
        $this->assertSame('admin', $user['role']);
        $this->assertSame(1, (int)$user['is_active']);

        // Check unique username generation collision resolution
        $testEmail2 = 'saml_test2_' . bin2hex(random_bytes(4)) . '@example.com';
        $user2 = SamlAuthService::autoProvisionUser($testEmail2, 'Second User', $testUsername, 'user');
        $this->assertNotNull($user2);
        $this->cleanUserIds[] = (int)$user2['id'];

        $this->assertNotSame($user['username'], $user2['username']);
        $this->assertStringStartsWith('saml_test_user', $user2['username']);
    }

    public function testLoginUserSessionSetsKeysAndRedirectsOnMustResetPassword(): void
    {
        $_SESSION = [];
        $fakeUser = [
            'id'                  => 77777,
            'username'            => 'saml_session_user',
            'full_name'           => 'SAML Session User',
            'role'                => 'supervisor',
            'extension'           => '2001',
            'email'               => 'saml_sess@example.com',
            'theme_preference'    => 'dark',
            'language_preference' => 'tr',
            'must_reset_password' => 0,
        ];

        $res = SamlAuthService::loginUserSession($fakeUser);
        $this->assertSame('', $res);
        $this->assertSame(77777, $_SESSION['user_id']);
        $this->assertSame('saml_session_user', $_SESSION['username']);
        $this->assertSame('supervisor', $_SESSION['role']);
        $this->assertSame('supervisor', $_SESSION['user_role']);
        $this->assertSame('saml', $_SESSION['auth_provider']);
        $this->assertSame('saml', $_SESSION['auth_method']);
        $this->assertSame('both', $_SESSION['allowed_phone_mode']);
        $this->assertGreaterThan(0, $_SESSION['last_activity']);

        // Now test with must_reset_password = 1
        $_SESSION = [];
        $fakeUser['must_reset_password'] = 1;
        $resReset = SamlAuthService::loginUserSession($fakeUser);
        $this->assertSame('/force-reset', $resReset);
        $this->assertSame(77777, $_SESSION['pending_reset_user_id']);
        $this->assertArrayNotHasKey('user_id', $_SESSION);
    }

    public function testXmlSignatureWrappingProtection(): void
    {
        $res = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $this->assertNotFalse($res);
        $csr = openssl_csr_new(['commonName' => 'idp.xsw-test.local'], $res);
        $x509 = openssl_csr_sign($csr, null, $res, 365);
        openssl_x509_export($x509, $certPem);

        // 1. Multiple assertions in one Response must be rejected
        $multiAssXml = '<samlp:Response xmlns:samlp="urn:oasis:names:tc:SAML:2.0:protocol" xmlns:saml="urn:oasis:names:tc:SAML:2.0:assertion" ID="_resp_xsw" Version="2.0">'
            . '<samlp:Status><samlp:StatusCode Value="urn:oasis:names:tc:SAML:2.0:status:Success"/></samlp:Status>'
            . '<saml:Assertion ID="_evil" Version="2.0"><saml:Subject><saml:NameID>admin@company.com</saml:NameID></saml:Subject></saml:Assertion>'
            . '<saml:Assertion ID="_legit" Version="2.0"><saml:Subject><saml:NameID>user@company.com</saml:NameID></saml:Subject></saml:Assertion>'
            . '</samlp:Response>';
        $domMulti = new DOMDocument();
        $domMulti->loadXML($multiAssXml);
        $vMulti = SamlAuthService::verifyXmlSignature($domMulti, $certPem);
        $this->assertFalse($vMulti['valid']);
        $this->assertStringContainsString('XSW', (string)($vMulti['error'] ?? ''));

        // 2. Reference URI mismatch against target element ID must be rejected
        $assXml = '<saml:Assertion xmlns:saml="urn:oasis:names:tc:SAML:2.0:assertion" ID="_real_id" IssueInstant="2026-10-31T12:00:00Z" Version="2.0"><saml:Issuer>https://idp.local</saml:Issuer></saml:Assertion>';
        $assDom = new DOMDocument();
        $assDom->loadXML($assXml);
        $digest = base64_encode(hash('sha256', (string)$assDom->documentElement->C14N(true, false), true));
        $signedInfoXml = '<ds:SignedInfo xmlns:ds="http://www.w3.org/2000/09/xmldsig#"><ds:CanonicalizationMethod Algorithm="http://www.w3.org/2001/10/xml-exc-c14n#"/><ds:SignatureMethod Algorithm="http://www.w3.org/2001/04/xmldsig-more#rsa-sha256"/><ds:Reference URI="#_different_id"><ds:DigestMethod Algorithm="http://www.w3.org/2001/04/xmlenc#sha256"/><ds:DigestValue>' . $digest . '</ds:DigestValue></ds:Reference></ds:SignedInfo>';
        $siDom = new DOMDocument();
        $siDom->loadXML($signedInfoXml);
        openssl_sign((string)$siDom->documentElement->C14N(true, false), $signature, $res, OPENSSL_ALGO_SHA256);
        $sigElementXml = '<ds:Signature xmlns:ds="http://www.w3.org/2000/09/xmldsig#">' . $signedInfoXml . '<ds:SignatureValue>' . base64_encode($signature) . '</ds:SignatureValue></ds:Signature>';
        $frag = $assDom->createDocumentFragment();
        $frag->appendXML($sigElementXml);
        $assDom->documentElement->appendChild($frag);

        $respXml = '<samlp:Response xmlns:samlp="urn:oasis:names:tc:SAML:2.0:protocol" xmlns:saml="urn:oasis:names:tc:SAML:2.0:assertion" ID="_resp_2" Version="2.0"><samlp:Status><samlp:StatusCode Value="urn:oasis:names:tc:SAML:2.0:status:Success"/></samlp:Status>' . $assDom->saveXML($assDom->documentElement) . '</samlp:Response>';
        $domMismatch = new DOMDocument();
        $domMismatch->loadXML($respXml);
        $vMismatch = SamlAuthService::verifyXmlSignature($domMismatch, $certPem);
        $this->assertFalse($vMismatch['valid']);
        $this->assertStringContainsString('XSW', (string)($vMismatch['error'] ?? ''));
    }

    /** Signs an assertion the way an IdP does and wraps it in a Response. */
    private function signedResponse(array $o = []): array
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $x509 = openssl_csr_sign(openssl_csr_new(['commonName' => 'idp.test'], $key), null, $key, 1);
        openssl_x509_export($x509, $pem);

        $nsA = 'urn:oasis:names:tc:SAML:2.0:assertion';
        $nsP = 'urn:oasis:names:tc:SAML:2.0:protocol';
        $ds = 'http://www.w3.org/2000/09/xmldsig#';
        $id = '_a' . bin2hex(random_bytes(8));
        $until = gmdate('Y-m-d\TH:i:s\Z', time() + ($o['ttl'] ?? 300));
        $email = $o['email'] ?? 'saml_test_low@example.com';
        $req = $o['in_response_to'] ?? '_' . str_repeat('a', 32);
        $aud = $o['audience'] ?? SamlAuthService::getSpEntityId();
        $nsDecl = ($o['ns_on_response'] ?? false) ? '' : ' xmlns:saml="' . $nsA . '"';
        $assertion = '<saml:Assertion' . $nsDecl . ' ID="' . $id . '" Version="2.0" IssueInstant="' . gmdate('Y-m-d\TH:i:s\Z') . '">'
            . '<saml:Issuer>idp</saml:Issuer>'
            . '<saml:Subject><saml:NameID>' . $email . '</saml:NameID>'
            . '<saml:SubjectConfirmation Method="urn:oasis:names:tc:SAML:2.0:cm:bearer"><saml:SubjectConfirmationData NotOnOrAfter="' . $until . '" InResponseTo="' . $req . '"/></saml:SubjectConfirmation></saml:Subject>'
            . '<saml:Conditions NotOnOrAfter="' . $until . '"><saml:AudienceRestriction><saml:Audience>' . $aud . '</saml:Audience></saml:AudienceRestriction></saml:Conditions>'
            . '</saml:Assertion>';
        $wrapOpen = '<samlp:Response xmlns:samlp="' . $nsP . '"' . (($o['ns_on_response'] ?? false) ? ' xmlns:saml="' . $nsA . '"' : '') . ' ID="_r' . bin2hex(random_bytes(6)) . '">'
            . '<samlp:Status><samlp:StatusCode Value="urn:oasis:names:tc:SAML:2.0:status:Success"/></samlp:Status>';

        $d = new DOMDocument();
        $d->loadXML($wrapOpen . $assertion . '</samlp:Response>');
        $node = $d->getElementsByTagNameNS($nsA, 'Assertion')->item(0);
        $digest = base64_encode(hash('sha256', (string)$node->C14N(true, false), true));
        $si = '<ds:SignedInfo xmlns:ds="' . $ds . '"><ds:CanonicalizationMethod Algorithm="http://www.w3.org/2001/10/xml-exc-c14n#"/>'
            . '<ds:SignatureMethod Algorithm="http://www.w3.org/2001/04/xmldsig-more#rsa-sha256"/>'
            . '<ds:Reference URI="#' . $id . '"><ds:Transforms><ds:Transform Algorithm="' . $ds . 'enveloped-signature"/><ds:Transform Algorithm="http://www.w3.org/2001/10/xml-exc-c14n#"/></ds:Transforms>'
            . '<ds:DigestMethod Algorithm="http://www.w3.org/2001/04/xmlenc#sha256"/><ds:DigestValue>' . $digest . '</ds:DigestValue></ds:Reference></ds:SignedInfo>';
        $sd = new DOMDocument();
        $sd->loadXML($si);
        openssl_sign((string)$sd->documentElement->C14N(true, false), $sig, $key, OPENSSL_ALGO_SHA256);
        $signature = '<ds:Signature xmlns:ds="' . $ds . '">' . $si . '<ds:SignatureValue>' . base64_encode($sig) . '</ds:SignatureValue></ds:Signature>';

        $xml = $wrapOpen . str_replace('</saml:Issuer>', '</saml:Issuer>' . $signature, $assertion) . '</samlp:Response>';
        return ['xml' => $xml, 'cert' => $pem, 'signature' => $signature, 'request' => $req];
    }

    private function dom(string $xml): DOMDocument
    {
        $d = new DOMDocument();
        $d->loadXML($xml);
        return $d;
    }

    public function testSignedNameIdHiddenInsideASecondSignatureIsRefused(): void
    {
        $r = $this->signedResponse();
        $this->assertTrue(SamlAuthService::verifyXmlSignature($this->dom($r['xml']), $r['cert'])['valid']);

        // The attack: a Signature element is stripped before the digest, so what it holds is unsigned.
        $inject = '<ds:Signature xmlns:ds="http://www.w3.org/2000/09/xmldsig#"><saml:Subject><saml:NameID>admin@example.com</saml:NameID></saml:Subject></ds:Signature>';
        $forged = str_replace('</ds:Signature><saml:Subject>', '</ds:Signature>' . $inject . '<saml:Subject>', $r['xml']);
        $this->assertNotSame($r['xml'], $forged);
        $res = SamlAuthService::verifyXmlSignature($this->dom($forged), $r['cert']);
        $this->assertFalse($res['valid']);
        $this->assertStringContainsString('XSW', (string)$res['error']);

        // The same content in another unsigned wrapper breaks the digest.
        $inject2 = '<saml:Advice><saml:Subject><saml:NameID>admin@example.com</saml:NameID></saml:Subject></saml:Advice>';
        $forged2 = str_replace('</ds:Signature><saml:Subject>', '</ds:Signature>' . $inject2 . '<saml:Subject>', $r['xml']);
        $this->assertFalse(SamlAuthService::verifyXmlSignature($this->dom($forged2), $r['cert'])['valid']);

        // And a changed value too.
        $tampered = str_replace('saml_test_low@', 'admin@', $r['xml']);
        $this->assertFalse(SamlAuthService::verifyXmlSignature($this->dom($tampered), $r['cert'])['valid']);
    }

    public function testNamespacesDeclaredOnTheResponseVerify(): void
    {
        $r = $this->signedResponse(['ns_on_response' => true]);
        $res = SamlAuthService::verifyXmlSignature($this->dom($r['xml']), $r['cert']);
        $this->assertTrue($res['valid'], (string)($res['error'] ?? ''));
    }

    public function testDoctypeAndForeignTransformAreRefused(): void
    {
        $r = $this->signedResponse();
        $withDoctype = '<!DOCTYPE x [<!ENTITY a "b">]>' . $r['xml'];
        $this->assertFalse(SamlAuthService::verifyXmlSignature($this->dom($withDoctype), $r['cert'])['valid']);
        $xslt = str_replace('enveloped-signature', 'xslt-transform', $r['xml']);
        $this->assertFalse(SamlAuthService::verifyXmlSignature($this->dom($xslt), $r['cert'])['valid']);
    }

    private function process(array $r, ?string $cookieRequest = null, bool $mobile = false): array
    {
        PluginManager::setSetting('saml-auth', 'idp_x509_cert', $r['cert']);
        $_COOKIE['aipbx_saml_request'] = ($cookieRequest ?? $r['request']) . '.' . ($mobile ? '1' : '0');
        return SamlAuthService::processResponse(base64_encode($r['xml']));
    }

    public function testFullResponseSignsInOnceAndKeepsTheMobileFlag(): void
    {
        $this->db->exec("INSERT INTO sys_users (username, full_name, email, password_hash, role, is_active) VALUES ('saml_test_low', 'Low', 'saml_test_low@example.com', 'x', 'user', 1)");
        $r = $this->signedResponse();

        $ok = $this->process($r, null, true);
        $this->assertTrue($ok['success'], (string)($ok['error'] ?? ''));
        $this->assertSame('saml_test_low@example.com', $ok['user']['email']);
        $this->assertTrue($ok['is_mobile']);

        $replay = $this->process($r);
        $this->assertFalse($replay['success']);
        $this->assertStringContainsString('already been used', (string)$replay['error']);
    }

    public function testResponsesThisBrowserDidNotAskForAreRefused(): void
    {
        $r = $this->signedResponse();
        $other = $this->process($r, '_' . str_repeat('b', 32));
        $this->assertFalse($other['success']);
        $this->assertStringContainsString('started in this browser', (string)$other['error']);

        PluginManager::setSetting('saml-auth', 'idp_x509_cert', $r['cert']);
        unset($_COOKIE['aipbx_saml_request']);
        $none = SamlAuthService::processResponse(base64_encode($r['xml']));
        $this->assertFalse($none['success']);
    }

    public function testExpiredAndForeignAudienceResponsesAreRefused(): void
    {
        $expired = $this->process($this->signedResponse(['ttl' => -600]));
        $this->assertFalse($expired['success']);
        $this->assertStringContainsString('expired', (string)$expired['error']);

        $foreign = $this->process($this->signedResponse(['audience' => 'https://other.example.com/sp']));
        $this->assertFalse($foreign['success']);
        $this->assertStringContainsString('Audience', (string)$foreign['error']);
    }

    public function testAuthnRequestRemembersTheRequestInTheBrowser(): void
    {
        PluginManager::setSetting('saml-auth', 'idp_sso_url', 'https://idp.example.com/sso');
        $req = SamlAuthService::buildAuthnRequest(true);
        $this->assertNotNull($req);
        $pending = SamlAuthService::pendingRequest();
        $this->assertSame($req['id'], $pending['id']);
        $this->assertTrue($pending['mobile']);
    }

    public function testDefaultRoleOffersTheRolesThePluginLists(): void
    {
        foreach (['cc_manager', 'cc_agent', 'read_only_admin', 'user'] as $role) {
            PluginManager::setSetting('saml-auth', 'default_role', $role);
            $this->assertSame($role, SamlAuthService::resolveRoleFromAttributes([]));
        }
        PluginManager::setSetting('saml-auth', 'default_role', 'nonsense');
        $this->assertSame('user', SamlAuthService::resolveRoleFromAttributes([]));
    }
}
