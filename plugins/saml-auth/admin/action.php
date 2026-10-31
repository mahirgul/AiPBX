<?php
/**
 * SAML 2.0 Auth Plugin - Admin Pre-render Action Handler
 * Handles IdP XML metadata parsing and automatic setting population.
 */

return static function (string $slug, array $settings): array {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_POST['parse_saml_idp_metadata'])) {
        return [];
    }

    if (!function_exists('verifyCSRFToken') || !verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        return ['error' => t('common.invalid_csrf', 'Invalid CSRF token')];
    }

    $xml = trim((string)($_POST['idp_metadata_xml'] ?? ''));
    if (empty($xml)) {
        return ['error' => t('saml.err_empty_metadata', 'Please paste the XML metadata from your Identity Provider.')];
    }

    require_once dirname(__DIR__, 3) . '/web/src/services/SamlAuthService.php';

    $parsed = SamlAuthService::parseIdpMetadataXml($xml);
    if (!$parsed) {
        $lastErr = SamlAuthService::getLastError();
        return ['error' => sprintf(t('saml.err_metadata_parse_failed', 'Failed to parse IdP XML metadata: %s'), $lastErr ?: 'Invalid XML')];
    }

    // Automatically update the plugin settings with extracted values
    if (!empty($parsed['entity_id'])) {
        PluginManager::setSetting($slug, 'idp_entity_id', $parsed['entity_id'], false);
    }
    if (!empty($parsed['sso_url'])) {
        PluginManager::setSetting($slug, 'idp_sso_url', $parsed['sso_url'], false);
    }
    if (!empty($parsed['x509_cert'])) {
        PluginManager::setSetting($slug, 'idp_x509_cert', $parsed['x509_cert'], false);
    }

    PluginManager::resetCache();

    return [
        'message' => sprintf(
            t('saml.msg_metadata_imported', '✓ IdP metadata parsed and applied successfully! EntityID: %s | SSO URL: %s | Certificate: extracted (%d bytes)'),
            htmlspecialchars($parsed['entity_id']),
            htmlspecialchars($parsed['sso_url']),
            strlen($parsed['x509_cert'])
        )
    ];
};
