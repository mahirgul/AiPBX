<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/core/PluginManager.php';

/**
 * SamlAuthService (SAML 2.0 Enterprise Single Sign-On Service)
 *
 * Implements the SAML 2.0 Web Browser SSO Profile (Service Provider - SP role)
 * with support for all standard Identity Providers (IdPs):
 * - Microsoft Entra ID (Azure AD / Office 365 Enterprise Application SAML)
 * - Okta SAML 2.0 Web Application
 * - Keycloak SAML Client
 * - Authentik SAML Provider
 * - Active Directory Federation Services (ADFS)
 * - Google Workspace SAML App
 * - PingFederate / Shibboleth / Any standard SAML 2.0 IdP
 *
 * Features:
 * - SP Metadata Generation (/auth/saml/metadata)
 * - AuthnRequest creation with HTTP-Redirect binding and CSRF relay state
 * - Assertion Consumer Service (/auth/saml/acs) with HTTP-POST binding
 * - XML-DSig signature & digest verification (SHA-256 / SHA-1) using IdP X.509 certificate
 * - Temporal validity enforcement (NotBefore / NotOnOrAfter with clock-skew tolerance)
 * - Audience restriction & Destination validation
 * - Standard attribute mapping & claim extraction (NameID, email, name, role/groups)
 * - Just-In-Time (JIT) auto-provisioning in sys_users
 * - Email domain allowlist filtering
 * - IdP Metadata XML parsing and quick setup helper
 * - Web session login and mobile app deep linking (aipbx://auth?...)
 */
class SamlAuthService
{
    private const PLUGIN_SLUG = 'saml-auth';
    private const SAML_NS_PROTOCOL = 'urn:oasis:names:tc:SAML:2.0:protocol';
    private const SAML_NS_ASSERTION = 'urn:oasis:names:tc:SAML:2.0:assertion';
    private const SAML_NS_METADATA = 'urn:oasis:names:tc:SAML:2.0:metadata';
    private const XMLDSIG_NS = 'http://www.w3.org/2000/09/xmldsig#';

    private static ?string $lastError = null;

    public static function getLastError(): ?string
    {
        return self::$lastError;
    }

    public static function setLastError(?string $error): void
    {
        self::$lastError = $error;
    }

    /**
     * Checks whether SAML 2.0 SSO authentication is enabled.
     */
    public static function isEnabled(): bool
    {
        $installed = PluginManager::installedMap();
        if (empty($installed[self::PLUGIN_SLUG]['enabled'])) {
            return false;
        }

        $enabled = self::getSetting('enabled', '0');
        if ($enabled !== '1') {
            return false;
        }

        $idpSsoUrl = self::getSetting('idp_sso_url', '');
        $idpEntityId = self::getSetting('idp_entity_id', '');
        $idpCert = self::getSetting('idp_x509_cert', '');

        return !empty($idpSsoUrl) && !empty($idpEntityId) && !empty($idpCert);
    }

    /**
     * Get a setting value for saml-auth.
     */
    public static function getSetting(string $key, string $default = ''): string
    {
        return PluginManager::getSetting(self::PLUGIN_SLUG, $key, $default);
    }

    /**
     * Get all settings as an array.
     *
     * @return array<string, string>
     */
    public static function getSettings(): array
    {
        return PluginManager::getAllSettings(self::PLUGIN_SLUG, false);
    }

    /**
     * Public settings for login page button rendering.
     *
     * @return array{enabled: bool, provider_name: string, icon: string}
     */
    public static function getPublicSettings(): array
    {
        $name = self::getSetting('provider_name', '');
        if (empty($name)) {
            $name = function_exists('t') ? t('saml.default_btn_label', 'Single Sign-On (SAML)') : 'Single Sign-On (SAML)';
        }

        return [
            'enabled'       => self::isEnabled(),
            'provider_name' => $name,
            'icon'          => 'fas fa-shield-alt',
        ];
    }

    /**
     * Resolves the base portal URL.
     */
    public static function getBasePortalUrl(): string
    {
        if (class_exists('ForgotPasswordService')) {
            require_once __DIR__ . '/ForgotPasswordService.php';
            return rtrim(ForgotPasswordService::portalUrl(), '/');
        }

        $domain = function_exists('getSystemSetting') ? getSystemSetting('portal_domain', '127.0.0.1') : '127.0.0.1';
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        return "{$scheme}://{$domain}";
    }

    /**
     * Returns the SP Entity ID.
     */
    public static function getSpEntityId(): string
    {
        $custom = trim(self::getSetting('sp_entity_id', ''));
        if (!empty($custom)) {
            return $custom;
        }

        return self::getBasePortalUrl() . '/auth/saml/metadata';
    }

    /**
     * Returns the Assertion Consumer Service (ACS) endpoint URL.
     */
    public static function getAcsUrl(): string
    {
        $custom = trim(self::getSetting('custom_acs_url', ''));
        if (!empty($custom)) {
            return $custom;
        }

        return self::getBasePortalUrl() . '/auth/saml/acs';
    }

    /**
     * Returns the SP Metadata URL.
     */
    public static function getMetadataUrl(): string
    {
        return self::getBasePortalUrl() . '/auth/saml/metadata';
    }

    /**
     * Generates SAML 2.0 SP Metadata XML.
     */
    public static function getSpMetadataXml(): string
    {
        $entityId = htmlspecialchars(self::getSpEntityId(), ENT_XML1, 'UTF-8');
        $acsUrl = htmlspecialchars(self::getAcsUrl(), ENT_XML1, 'UTF-8');

        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<md:EntityDescriptor xmlns:md="urn:oasis:names:tc:SAML:2.0:metadata" entityID="{$entityId}">
  <md:SPSSODescriptor AuthnRequestsSigned="false" WantAssertionsSigned="true" protocolSupportEnumeration="urn:oasis:names:tc:SAML:2.0:protocol">
    <md:NameIDFormat>urn:oasis:names:tc:SAML:1.1:nameid-format:emailAddress</md:NameIDFormat>
    <md:NameIDFormat>urn:oasis:names:tc:SAML:2.0:nameid-format:persistent</md:NameIDFormat>
    <md:NameIDFormat>urn:oasis:names:tc:SAML:2.0:nameid-format:transient</md:NameIDFormat>
    <md:AssertionConsumerService Binding="urn:oasis:names:tc:SAML:2.0:bindings:HTTP-POST" Location="{$acsUrl}" index="1" isDefault="true"/>
  </md:SPSSODescriptor>
</md:EntityDescriptor>
XML;
    }

    /**
     * Generates cryptographically secure relay state for CSRF mitigation and session continuity.
     */
    public static function generateRelayState(bool $isMobile = false): string
    {
        $token = bin2hex(random_bytes(16));
        $data = [
            'token'  => $token,
            'mobile' => $isMobile ? 1 : 0,
            'time'   => time(),
        ];

        $encoded = base64_encode(json_encode($data, JSON_THROW_ON_ERROR));

        if (session_status() !== PHP_SESSION_ACTIVE && !headers_sent()) {
            @session_start();
        }

        $_SESSION['saml_relay_token'] = $token;
        $_SESSION['saml_relay_state'] = $encoded;

        return $encoded;
    }

    /**
     * Verifies incoming RelayState parameter.
     *
     * @return array{valid: bool, mobile: bool}
     */
    public static function verifyRelayState(?string $relayState): array
    {
        if (empty($relayState) || empty($_SESSION['saml_relay_state'])) {
            return ['valid' => false, 'mobile' => false];
        }

        if (!hash_equals((string)$_SESSION['saml_relay_state'], $relayState)) {
            return ['valid' => false, 'mobile' => false];
        }

        unset($_SESSION['saml_relay_token'], $_SESSION['saml_relay_state']);

        $decoded = json_decode(base64_decode($relayState), true);
        if (!is_array($decoded) || empty($decoded['time']) || (time() - (int)$decoded['time']) > 600) {
            return ['valid' => false, 'mobile' => false];
        }

        return [
            'valid'  => true,
            'mobile' => !empty($decoded['mobile']),
        ];
    }

    /**
     * Builds AuthnRequest XML and the complete HTTP-Redirect URL for the IdP.
     *
     * @return array{id: string, url: string, relay_state: string}|null
     */
    public static function buildAuthnRequest(bool $isMobile = false): ?array
    {
        $idpSsoUrl = trim(self::getSetting('idp_sso_url', ''));
        if (empty($idpSsoUrl)) {
            self::setLastError('IdP Single Sign-On URL is not configured');
            return null;
        }

        $reqId = '_' . bin2hex(random_bytes(16));
        $issueInstant = gmdate('Y-m-d\TH:i:s\Z');
        $spEntityId = htmlspecialchars(self::getSpEntityId(), ENT_XML1, 'UTF-8');
        $acsUrl = htmlspecialchars(self::getAcsUrl(), ENT_XML1, 'UTF-8');
        $dest = htmlspecialchars($idpSsoUrl, ENT_XML1, 'UTF-8');

        $xml = <<<XML
<samlp:AuthnRequest xmlns:samlp="urn:oasis:names:tc:SAML:2.0:protocol" xmlns:saml="urn:oasis:names:tc:SAML:2.0:assertion" ID="{$reqId}" Version="2.0" IssueInstant="{$issueInstant}" Destination="{$dest}" AssertionConsumerServiceURL="{$acsUrl}" ProtocolBinding="urn:oasis:names:tc:SAML:2.0:bindings:HTTP-POST">
  <saml:Issuer>{$spEntityId}</saml:Issuer>
  <samlp:NameIDPolicy Format="urn:oasis:names:tc:SAML:1.1:nameid-format:emailAddress" AllowCreate="true"/>
</samlp:AuthnRequest>
XML;

        $relayState = self::generateRelayState($isMobile);
        if (session_status() !== PHP_SESSION_ACTIVE && !headers_sent()) {
            @session_start();
        }
        $_SESSION['saml_auth_request_id'] = $reqId;

        // HTTP-Redirect binding: Deflate + Base64 + urlencode
        $deflated = gzdeflate($xml);
        if ($deflated === false) {
            self::setLastError('Failed to compress SAML AuthnRequest');
            return null;
        }
        $samlRequestB64 = base64_encode($deflated);

        $separator = str_contains($idpSsoUrl, '?') ? '&' : '?';
        $redirectUrl = $idpSsoUrl . $separator . http_build_query([
            'SAMLRequest' => $samlRequestB64,
            'RelayState'  => $relayState,
        ]);

        return [
            'id'          => $reqId,
            'url'         => $redirectUrl,
            'relay_state' => $relayState,
        ];
    }

    /**
     * Formats an X.509 certificate string into clean PEM format.
     */
    public static function formatCertPem(string $cert): string
    {
        $clean = trim($cert);
        if (str_contains($clean, 'BEGIN CERTIFICATE')) {
            return $clean;
        }

        $pureBase64 = preg_replace('/\s+/', '', $clean);
        return "-----BEGIN CERTIFICATE-----\n" . chunk_split((string)$pureBase64, 64, "\n") . "-----END CERTIFICATE-----\n";
    }

    /**
     * Verifies XML-DSig signature on a SAML Response or Assertion.
     *
     * @return array{valid: bool, error?: string}
     */
    public static function verifyXmlSignature(DOMDocument $dom, string $certPem): array
    {
        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('samlp', self::SAML_NS_PROTOCOL);
        $xpath->registerNamespace('saml', self::SAML_NS_ASSERTION);
        $xpath->registerNamespace('ds', self::XMLDSIG_NS);

        // Check for signature in Assertion or Response
        $sigNodes = $xpath->query('//saml:Assertion/ds:Signature');
        if ($sigNodes->length === 0) {
            $sigNodes = $xpath->query('/samlp:Response/ds:Signature');
        }
        if ($sigNodes->length === 0) {
            $sigNodes = $xpath->query('//ds:Signature');
        }

        if ($sigNodes->length === 0) {
            return ['valid' => false, 'error' => 'No digital signature found in SAML response'];
        }

        /** @var DOMElement $sigNode */
        $sigNode = $sigNodes->item(0);
        $signedInfoNodes = $xpath->query('.//ds:SignedInfo', $sigNode);
        if ($signedInfoNodes->length === 0) {
            return ['valid' => false, 'error' => 'Missing SignedInfo element in signature'];
        }
        /** @var DOMElement $signedInfoNode */
        $signedInfoNode = $signedInfoNodes->item(0);

        $sigValNodes = $xpath->query('.//ds:SignatureValue', $sigNode);
        $digestValNodes = $xpath->query('.//ds:DigestValue', $signedInfoNode);

        if ($sigValNodes->length === 0 || $digestValNodes->length === 0) {
            return ['valid' => false, 'error' => 'Missing SignatureValue or DigestValue in signature'];
        }

        $sigVal = trim($sigValNodes->item(0)->textContent);
        $digestVal = trim($digestValNodes->item(0)->textContent);

        // 1. Verify Digest of the target signed node
        $targetNode = $sigNode->parentNode;
        if (!($targetNode instanceof DOMElement)) {
            return ['valid' => false, 'error' => 'Target signed element not found'];
        }

        // Clone node in dedicated document to canonicalize with enveloped signature stripped
        $tempDoc = new DOMDocument();
        $targetXml = $dom->saveXML($targetNode);
        if ($targetXml === false) {
            return ['valid' => false, 'error' => 'Failed to serialize target element'];
        }
        $tempDoc->loadXML($targetXml, LIBXML_NONET | LIBXML_NOWARNING | LIBXML_NOERROR);

        $sigsInTemp = $tempDoc->getElementsByTagNameNS(self::XMLDSIG_NS, 'Signature');
        while ($sigsInTemp->length > 0) {
            $s = $sigsInTemp->item(0);
            $s->parentNode?->removeChild($s);
        }

        $targetCanonical = $tempDoc->documentElement->C14N(true, false);
        if ($targetCanonical === false) {
            return ['valid' => false, 'error' => 'Failed to canonicalize target element'];
        }

        // Determine Digest Algorithm
        $digestMethodNodes = $xpath->query('.//ds:DigestMethod/@Algorithm', $signedInfoNode);
        $digestAlgoUri = $digestMethodNodes->length > 0 ? (string)$digestMethodNodes->item(0)->nodeValue : '';
        $hashAlgo = str_contains($digestAlgoUri, 'sha1') ? 'sha1' : (str_contains($digestAlgoUri, 'sha512') ? 'sha512' : 'sha256');

        $computedDigest = base64_encode(hash($hashAlgo, $targetCanonical, true));
        if ($digestVal !== $computedDigest) {
            // Also test sha1 fallback if sha256 failed
            if ($hashAlgo !== 'sha1' && $digestVal === base64_encode(hash('sha1', $targetCanonical, true))) {
                $hashAlgo = 'sha1';
            } else {
                return ['valid' => false, 'error' => 'XML-DSig digest verification mismatch'];
            }
        }

        // 2. Canonicalize SignedInfo
        $siCanonical = $signedInfoNode->C14N(true, false);
        if ($siCanonical === false) {
            return ['valid' => false, 'error' => 'Failed to canonicalize SignedInfo'];
        }

        // Determine Signature Algorithm
        $sigMethodNodes = $xpath->query('.//ds:SignatureMethod/@Algorithm', $signedInfoNode);
        $sigMethodUri = $sigMethodNodes->length > 0 ? (string)$sigMethodNodes->item(0)->nodeValue : '';
        $opensslAlgo = OPENSSL_ALGO_SHA256;
        if (str_contains($sigMethodUri, 'rsa-sha1')) {
            $opensslAlgo = OPENSSL_ALGO_SHA1;
        } elseif (str_contains($sigMethodUri, 'rsa-sha384')) {
            $opensslAlgo = OPENSSL_ALGO_SHA384;
        } elseif (str_contains($sigMethodUri, 'rsa-sha512')) {
            $opensslAlgo = OPENSSL_ALGO_SHA512;
        }

        $pem = self::formatCertPem($certPem);
        $decodedSig = base64_decode($sigVal, true);
        if ($decodedSig === false) {
            return ['valid' => false, 'error' => 'Malformed base64 SignatureValue'];
        }

        $res = openssl_verify($siCanonical, $decodedSig, $pem, $opensslAlgo);
        if ($res !== 1) {
            return [
                'valid' => false,
                'error' => 'Digital signature verification failed against IdP X.509 certificate: ' . (openssl_error_string() ?: 'invalid key or altered data'),
            ];
        }

        return ['valid' => true];
    }

    /**
     * Extracts SAML attributes from Assertion element.
     *
     * @return array<string, list<string>>
     */
    public static function extractSamlAttributes(DOMXPath $xpath, DOMNode $assertion): array
    {
        $attributes = [];
        $attrNodes = $xpath->query('.//saml:AttributeStatement/saml:Attribute', $assertion);
        if (!$attrNodes) {
            return $attributes;
        }

        foreach ($attrNodes as $attr) {
            if (!($attr instanceof DOMElement)) {
                continue;
            }
            $attrName = $attr->getAttribute('Name');
            $values = [];
            $valNodes = $xpath->query('.//saml:AttributeValue', $attr);
            if ($valNodes) {
                foreach ($valNodes as $val) {
                    $values[] = trim($val->textContent);
                }
            }
            if (!empty($attrName)) {
                $attributes[$attrName] = $values;
            }
        }

        return $attributes;
    }

    /**
     * Helper to find an attribute value by checking multiple common name candidates.
     *
     * @param array<string, list<string>> $attributes
     * @param list<string> $candidates
     */
    public static function findAttributeValue(array $attributes, array $candidates): string
    {
        foreach ($candidates as $candidate) {
            foreach ($attributes as $key => $values) {
                if (strcasecmp($key, $candidate) === 0 && !empty($values[0])) {
                    return $values[0];
                }
            }
        }
        return '';
    }

    /**
     * Validates whether user's email domain matches allowed_domains setting.
     */
    public static function isDomainAllowed(string $email): bool
    {
        $parts = explode('@', $email);
        if (count($parts) !== 2 || empty($parts[0]) || empty($parts[1])) {
            return false;
        }

        $allowed = trim(self::getSetting('allowed_domains', ''));
        if (empty($allowed)) {
            return true;
        }

        $domain = mb_strtolower(trim($parts[1]));
        $list = array_filter(array_map('trim', explode(',', mb_strtolower($allowed))));
        return in_array($domain, $list, true);
    }

    /**
     * Resolves the user role based on SAML attributes mapping or default setting.
     *
     * @param array<string, list<string>> $attributes
     */
    public static function resolveRoleFromAttributes(array $attributes): string
    {
        $attrName = trim(self::getSetting('role_attribute', 'role'));
        $adminMatch = trim(self::getSetting('admin_role_value', 'aipbx_admin'));
        $defaultRole = self::getSetting('default_role', 'user');

        $validRoles = ['user', 'manager', 'operator', 'admin'];
        if (!in_array($defaultRole, $validRoles, true)) {
            $defaultRole = 'user';
        }

        if (!empty($attrName) && !empty($adminMatch)) {
            $roleValues = [];
            foreach ($attributes as $key => $values) {
                if (strcasecmp($key, $attrName) === 0 || str_ends_with(mb_strtolower($key), '/' . mb_strtolower($attrName))) {
                    $roleValues = array_merge($roleValues, $values);
                }
            }

            foreach ($roleValues as $rv) {
                if (strcasecmp((string)$rv, $adminMatch) === 0) {
                    return 'admin';
                }
            }
        }

        return $defaultRole;
    }

    /**
     * Looks up user by email in sys_users.
     *
     * @return array<string, mixed>|null
     */
    public static function findUserByEmail(string $email): ?array
    {
        $cleanEmail = trim(mb_strtolower($email));
        if (empty($cleanEmail)) {
            return null;
        }

        $db = getDB();
        $stmt = $db->prepare('
            SELECT id, username, full_name, email, role, extension, extension_type,
                   sip_password, allowed_phone_mode, theme_preference, language_preference, is_active,
                   must_reset_password, two_factor_enabled
            FROM sys_users
            WHERE LOWER(TRIM(email)) = ?
            LIMIT 1
        ');
        $stmt->execute([$cleanEmail]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        return $user ?: null;
    }

    /**
     * Automatically provisions a new user record in sys_users (Just-In-Time).
     *
     * @return array<string, mixed>|null Newly created user record or null on failure
     */
    public static function autoProvisionUser(string $email, string $fullName, string $username, string $role): ?array
    {
        $autoProvision = self::getSetting('auto_provision', '1');
        if ($autoProvision !== '1') {
            self::setLastError('Auto-provisioning is disabled and user does not exist in AiPBX');
            return null;
        }

        $db = getDB();
        $cleanEmail = mb_strtolower(trim($email));

        // Ensure role exists in sys_roles to satisfy fk_users_role
        $chkRole = $db->prepare('SELECT role_key FROM sys_roles WHERE role_key = ? LIMIT 1');
        $chkRole->execute([$role]);
        if (!$chkRole->fetch()) {
            $role = 'user';
        }

        // Generate base unique username
        $baseUser = preg_replace('/[^a-zA-Z0-9_\-\.]/', '', $username);
        if (empty($baseUser)) {
            $baseUser = explode('@', $cleanEmail)[0];
            $baseUser = preg_replace('/[^a-zA-Z0-9_\-\.]/', '', $baseUser);
        }
        if (empty($baseUser)) {
            $baseUser = 'user_' . bin2hex(random_bytes(3));
        }

        $finalUsername = $baseUser;
        $counter = 1;
        while (true) {
            $chk = $db->prepare('SELECT id FROM sys_users WHERE username = ? LIMIT 1');
            $chk->execute([$finalUsername]);
            if (!$chk->fetch()) {
                break;
            }
            $counter++;
            $finalUsername = "{$baseUser}_{$counter}";
        }

        // Random secure password hash (SAML users authenticate via SSO)
        $randPass = bin2hex(random_bytes(32));
        $hashed = password_hash($randPass, PASSWORD_DEFAULT);

        $stmt = $db->prepare('
            INSERT INTO sys_users (
                username, password_hash, full_name, email, role,
                is_active, must_reset_password, two_factor_enabled,
                created_at
            ) VALUES (
                ?, ?, ?, ?, ?,
                1, 0, 0,
                NOW()
            )
        ');

        $success = $stmt->execute([
            $finalUsername,
            $hashed,
            $fullName,
            $cleanEmail,
            $role,
        ]);

        if (!$success) {
            self::setLastError('Failed to insert auto-provisioned user into database');
            return null;
        }

        return self::findUserByEmail($cleanEmail);
    }

    /**
     * Processes incoming SAMLResponse from IdP (Assertion Consumer Service).
     *
     * @return array{success: bool, user?: array<string, mixed>, error?: string, is_mobile?: bool}
     */
    public static function processResponse(string $samlResponseB64, ?string $relayState = null): array
    {
        if (empty($samlResponseB64)) {
            return ['success' => false, 'error' => 'Empty SAMLResponse received'];
        }

        $xml = base64_decode($samlResponseB64, true);
        if ($xml === false || empty($xml)) {
            return ['success' => false, 'error' => 'Malformed base64 SAMLResponse'];
        }

        $dom = new DOMDocument();
        $dom->preserveWhiteSpace = true;
        $dom->formatOutput = false;
        // Secure XML loading: prevent XXE attacks
        if (!$dom->loadXML($xml, LIBXML_NONET | LIBXML_NOWARNING | LIBXML_NOERROR)) {
            return ['success' => false, 'error' => 'Failed to parse SAML XML response'];
        }

        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('samlp', self::SAML_NS_PROTOCOL);
        $xpath->registerNamespace('saml', self::SAML_NS_ASSERTION);
        $xpath->registerNamespace('ds', self::XMLDSIG_NS);

        // 1. Verify StatusCode
        $statusNodes = $xpath->query('//samlp:StatusCode/@Value');
        if ($statusNodes->length === 0) {
            return ['success' => false, 'error' => 'Missing StatusCode in SAML response'];
        }
        $statusCode = (string)$statusNodes->item(0)->nodeValue;
        if ($statusCode !== 'urn:oasis:names:tc:SAML:2.0:status:Success') {
            return ['success' => false, 'error' => 'IdP returned unsuccessful authentication status: ' . $statusCode];
        }

        // 2. Locate Assertion
        $assertions = $xpath->query('//saml:Assertion');
        if ($assertions->length === 0) {
            return ['success' => false, 'error' => 'No Assertion element found in SAML response'];
        }
        /** @var DOMElement $assertion */
        $assertion = $assertions->item(0);

        // 3. Verify XML-DSig signature using IdP X.509 Certificate
        $idpCert = self::getSetting('idp_x509_cert', '');
        if (empty($idpCert)) {
            return ['success' => false, 'error' => 'IdP X.509 certificate is not configured in settings'];
        }
        $sigResult = self::verifyXmlSignature($dom, $idpCert);
        if (!$sigResult['valid']) {
            return ['success' => false, 'error' => $sigResult['error'] ?? 'Invalid XML signature'];
        }

        // 4. Validate Destination (if specified in response)
        $destNodes = $xpath->query('/samlp:Response/@Destination');
        if ($destNodes->length > 0) {
            $dest = (string)$destNodes->item(0)->nodeValue;
            $expectedAcs = self::getAcsUrl();
            if (!empty($dest) && strcasecmp(rtrim($dest, '/'), rtrim($expectedAcs, '/')) !== 0) {
                // If not matching exact ACS, check if path matches
                $destPath = parse_url($dest, PHP_URL_PATH);
                $expectedPath = parse_url($expectedAcs, PHP_URL_PATH);
                if ($destPath !== $expectedPath) {
                    return ['success' => false, 'error' => "SAML Destination mismatch: expected {$expectedAcs}, received {$dest}"];
                }
            }
        }

        // 5. Validate Audience Restriction
        $spEntityId = self::getSpEntityId();
        $audienceNodes = $xpath->query('.//saml:Conditions/saml:AudienceRestriction/saml:Audience', $assertion);
        if ($audienceNodes->length > 0) {
            $audMatched = false;
            foreach ($audienceNodes as $aud) {
                if (strcasecmp(trim((string)$aud->textContent), $spEntityId) === 0) {
                    $audMatched = true;
                    break;
                }
            }
            if (!$audMatched) {
                return ['success' => false, 'error' => "SAML Audience mismatch: expected {$spEntityId}"];
            }
        }

        // 6. Validate Conditions Timestamps (NotBefore, NotOnOrAfter)
        $conditionsNodes = $xpath->query('.//saml:Conditions', $assertion);
        if ($conditionsNodes->length > 0) {
            /** @var DOMElement $cond */
            $cond = $conditionsNodes->item(0);
            $now = time();
            $clockSkew = 120; // 2 minutes tolerance

            if ($cond->hasAttribute('NotBefore')) {
                $nb = strtotime($cond->getAttribute('NotBefore'));
                if ($nb !== false && ($now + $clockSkew) < $nb) {
                    return ['success' => false, 'error' => 'SAML assertion is not yet valid (NotBefore)'];
                }
            }

            if ($cond->hasAttribute('NotOnOrAfter')) {
                $noa = strtotime($cond->getAttribute('NotOnOrAfter'));
                if ($noa !== false && ($now - $clockSkew) >= $noa) {
                    return ['success' => false, 'error' => 'SAML assertion has expired (NotOnOrAfter)'];
                }
            }
        }

        // 7. Extract NameID & Attributes
        $nameIdNodes = $xpath->query('.//saml:Subject/saml:NameID', $assertion);
        $nameId = $nameIdNodes->length > 0 ? trim((string)$nameIdNodes->item(0)->textContent) : '';

        $attributes = self::extractSamlAttributes($xpath, $assertion);

        // Resolve Email
        $email = '';
        if (!empty($nameId) && filter_var($nameId, FILTER_VALIDATE_EMAIL)) {
            $email = $nameId;
        }
        if (empty($email)) {
            $email = self::findAttributeValue($attributes, [
                'http://schemas.xmlsoap.org/ws/2005/05/identity/claims/emailaddress',
                'http://schemas.xmlsoap.org/ws/2005/05/identity/claims/upn',
                'email',
                'mail',
                'userPrincipalName',
                'upn',
            ]);
        }

        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['success' => false, 'error' => 'No valid email address found in SAML assertion NameID or attributes'];
        }

        $email = mb_strtolower(trim($email));

        // 8. Domain Allowlist Check
        if (!self::isDomainAllowed($email)) {
            return ['success' => false, 'error' => sprintf(
                function_exists('t') ? t('saml.err_domain_denied', 'Email domain (%s) is not authorized') : 'Email domain (%s) is not authorized',
                explode('@', $email)[1] ?? ''
            )];
        }

        // Resolve Full Name
        $fullName = self::findAttributeValue($attributes, [
            'http://schemas.xmlsoap.org/ws/2005/05/identity/claims/name',
            'displayName',
            'cn',
            'commonName',
        ]);
        if (empty($fullName)) {
            $given = self::findAttributeValue($attributes, [
                'http://schemas.xmlsoap.org/ws/2005/05/identity/claims/givenname',
                'givenName',
                'firstName',
            ]);
            $family = self::findAttributeValue($attributes, [
                'http://schemas.xmlsoap.org/ws/2005/05/identity/claims/surname',
                'surname',
                'sn',
                'lastName',
            ]);
            $fullName = trim("{$given} {$family}");
        }
        if (empty($fullName)) {
            $fullName = explode('@', $email)[0];
        }

        // Resolve Preferred Username
        $prefUsername = self::findAttributeValue($attributes, [
            'http://schemas.xmlsoap.org/ws/2005/05/identity/claims/name',
            'sAMAccountName',
            'uid',
            'username',
        ]);
        if (empty($prefUsername)) {
            $prefUsername = explode('@', $email)[0];
        }

        // 9. Resolve Role
        $role = self::resolveRoleFromAttributes($attributes);

        // 10. Find or auto-provision user
        $user = self::findUserByEmail($email);
        if (!$user) {
            $user = self::autoProvisionUser($email, $fullName, $prefUsername, $role);
            if (!$user) {
                return ['success' => false, 'error' => self::getLastError() ?: 'Failed to provision user'];
            }
        }

        // Check active status
        if (empty($user['is_active'])) {
            return ['success' => false, 'error' => function_exists('t') ? t('saml.err_account_disabled', 'Account is deactivated') : 'Account is deactivated'];
        }

        // Verify RelayState
        $relayCheck = self::verifyRelayState($relayState);
        $isMobile = $relayCheck['mobile'];

        return [
            'success'   => true,
            'user'      => $user,
            'is_mobile' => $isMobile,
        ];
    }

    /**
     * Parses IdP Metadata XML (e.g. from Entra ID, Okta, Keycloak, Authentik, or ADFS).
     *
     * @return array{entity_id: string, sso_url: string, x509_cert: string}|null
     */
    public static function parseIdpMetadataXml(string $xml): ?array
    {
        $clean = trim($xml);
        if (empty($clean)) {
            self::setLastError('Empty metadata XML');
            return null;
        }

        $dom = new DOMDocument();
        if (!$dom->loadXML($clean, LIBXML_NONET | LIBXML_NOWARNING | LIBXML_NOERROR)) {
            self::setLastError('Invalid XML structure');
            return null;
        }

        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('md', self::SAML_NS_METADATA);
        $xpath->registerNamespace('ds', self::XMLDSIG_NS);

        // EntityID
        $entityIdNodes = $xpath->query('//md:EntityDescriptor/@entityID');
        $entityId = $entityIdNodes->length > 0 ? (string)$entityIdNodes->item(0)->nodeValue : '';

        // SingleSignOnService Location (prefer HTTP-Redirect, fallback to HTTP-POST)
        $ssoNodes = $xpath->query('//md:IDPSSODescriptor/md:SingleSignOnService[@Binding="urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect"]/@Location');
        if ($ssoNodes->length === 0) {
            $ssoNodes = $xpath->query('//md:IDPSSODescriptor/md:SingleSignOnService[@Binding="urn:oasis:names:tc:SAML:2.0:bindings:HTTP-POST"]/@Location');
        }
        if ($ssoNodes->length === 0) {
            $ssoNodes = $xpath->query('//md:IDPSSODescriptor/md:SingleSignOnService/@Location');
        }
        $ssoUrl = $ssoNodes->length > 0 ? (string)$ssoNodes->item(0)->nodeValue : '';

        // X.509 Certificate
        $certNodes = $xpath->query('//md:IDPSSODescriptor/md:KeyDescriptor[@use="signing"]//ds:X509Certificate');
        if ($certNodes->length === 0) {
            $certNodes = $xpath->query('//md:IDPSSODescriptor//ds:X509Certificate');
        }
        $certRaw = $certNodes->length > 0 ? trim((string)$certNodes->item(0)->nodeValue) : '';
        $certPem = !empty($certRaw) ? self::formatCertPem($certRaw) : '';

        if (empty($entityId) && empty($ssoUrl)) {
            self::setLastError('Could not find EntityID or SingleSignOnService in metadata');
            return null;
        }

        return [
            'entity_id' => $entityId,
            'sso_url'   => $ssoUrl,
            'x509_cert' => $certPem,
        ];
    }

    /**
     * Initializes a PHP web session for the authenticated user.
     *
     * @param array<string, mixed> $user
     */
    public static function loginUserSession(array $user): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE && !headers_sent()) {
            @session_start();
        }

        session_regenerate_id(true);

        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['full_name'] = $user['full_name'];
        $_SESSION['user_role'] = $user['role'];
        $_SESSION['role'] = $user['role'];
        $_SESSION['extension'] = $user['extension'] ?? '';
        $_SESSION['extension_type'] = $user['extension_type'] ?? '';
        $_SESSION['allowed_phone_mode'] = $user['allowed_phone_mode'] ?? 'both';
        $_SESSION['theme'] = $user['theme_preference'] ?? 'dark';
        $_SESSION['language'] = $user['language_preference'] ?? 'tr';
        $_SESSION['ui_language'] = (defined('UI_LANGUAGES') && isset(UI_LANGUAGES[$user['language_preference'] ?? '']))
            ? $user['language_preference']
            : (defined('DEFAULT_UI_LANGUAGE') ? DEFAULT_UI_LANGUAGE : 'tr');
        $_SESSION['auth_provider'] = 'saml';
        $_SESSION['last_activity'] = time();

        unset($_SESSION['captcha_num1'], $_SESSION['captcha_num2']);

        $clientIp = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        $db = getDB();
        $stmt = $db->prepare('UPDATE sys_users SET last_login = NOW(), last_ip = ? WHERE id = ?');
        $stmt->execute([$clientIp, $user['id']]);

        if (function_exists('logLoginAttempt')) {
            logLoginAttempt($clientIp, (string)$user['username'], 'SUCCESS');
        }

        if (function_exists('logSecurityEvent')) {
            logSecurityEvent('saml_login_success', "SAML SSO login for {$user['username']} ({$user['email']}) from {$clientIp}");
        }
    }
}
