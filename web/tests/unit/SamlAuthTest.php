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

    public function testRelayStateGenerationAndVerification(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }

        $relayState = SamlAuthService::generateRelayState(false);
        $this->assertNotEmpty($relayState);
        $this->assertSame($relayState, $_SESSION['saml_relay_state'] ?? null);

        // Verification with matching state succeeds
        $res = SamlAuthService::verifyRelayState($relayState);
        $this->assertTrue($res['valid']);
        $this->assertFalse($res['mobile']);

        // Replay attack: second verification fails
        $replay = SamlAuthService::verifyRelayState($relayState);
        $this->assertFalse($replay['valid']);

        // Empty state fails
        $empty = SamlAuthService::verifyRelayState('');
        $this->assertFalse($empty['valid']);
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
}
