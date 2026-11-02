<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/src/helpers.php';
require_once dirname(__DIR__, 2) . '/src/core/PluginManager.php';
require_once dirname(__DIR__, 2) . '/src/services/SamlAuthService.php';

final class SingleLogoutTest extends TestCase
{
    protected function tearDown(): void
    {
        PluginManager::setSetting('saml-auth', 'idp_slo_url', '');
    }

    public function testWithoutASloUrlThereIsNoProviderLogout(): void
    {
        PluginManager::setSetting('saml-auth', 'idp_slo_url', '');
        $this->assertNull(SamlAuthService::buildLogoutUrl('a@b.example', 'i1'));
    }

    public function testTheLogoutRequestNamesTheUserAndIsEscaped(): void
    {
        PluginManager::setSetting('saml-auth', 'idp_slo_url', 'https://idp.example/slo?x=1');
        $url = SamlAuthService::buildLogoutUrl('a&b"<c>@b.example', 'i1');
        $this->assertNotNull($url);
        $this->assertStringStartsWith('https://idp.example/slo?x=1&SAMLRequest=', $url);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
        $xml = gzinflate(base64_decode($q['SAMLRequest']));
        $doc = new DOMDocument();
        $this->assertTrue($doc->loadXML($xml), 'stays valid XML with odd characters');
        $this->assertSame('a&b"<c>@b.example', $doc->getElementsByTagNameNS('urn:oasis:names:tc:SAML:2.0:assertion', 'NameID')->item(0)->textContent);
        $this->assertSame('i1', $doc->getElementsByTagNameNS('urn:oasis:names:tc:SAML:2.0:protocol', 'SessionIndex')->item(0)->textContent);
    }

    public function testNoProviderLogoutWithoutAUserOrWithABadAddress(): void
    {
        PluginManager::setSetting('saml-auth', 'idp_slo_url', 'https://idp.example/slo');
        $this->assertNull(SamlAuthService::buildLogoutUrl('', 'i1'));
        PluginManager::setSetting('saml-auth', 'idp_slo_url', 'javascript:alert(1)');
        $this->assertNull(SamlAuthService::buildLogoutUrl('a@b.example', 'i1'));
    }
}
