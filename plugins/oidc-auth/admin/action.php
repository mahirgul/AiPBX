<?php
/**
 * OIDC Auth Plugin - Admin Pre-render Action Handler
 * Handles test discovery requests and diagnostic checks.
 */

return static function (string $slug, array $settings): array {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_POST['test_oidc_discovery'])) {
        return [];
    }

    if (!function_exists('verifyCSRFToken') || !verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        return ['error' => t('common.invalid_csrf', 'Invalid CSRF token')];
    }

    $discoveryUrl = trim((string)($_POST['test_discovery_url'] ?? $settings['discovery_url'] ?? ''));
    if (empty($discoveryUrl)) {
        return ['error' => t('oidc.err_empty_discovery', 'Please provide an OIDC Discovery URL or Issuer URL to test.')];
    }

    require_once dirname(__DIR__, 3) . '/web/src/services/OidcAuthService.php';

    $discovery = OidcAuthService::discover($discoveryUrl);
    if (!$discovery || empty($discovery['authorization_endpoint'])) {
        $lastErr = OidcAuthService::getLastError();
        return ['error' => sprintf(t('oidc.err_discovery_failed', 'OIDC Discovery failed: %s'), $lastErr ?: 'Invalid or unreachable discovery document')];
    }

    $authEp = htmlspecialchars($discovery['authorization_endpoint']);
    $tokenEp = htmlspecialchars($discovery['token_endpoint'] ?? 'N/A');
    $userinfoEp = htmlspecialchars($discovery['userinfo_endpoint'] ?? 'N/A');

    return [
        'message' => sprintf(
            t('oidc.msg_discovery_ok', '✓ OIDC Discovery successful! Auth: %s | Token: %s | UserInfo: %s'),
            $authEp,
            $tokenEp,
            $userinfoEp
        )
    ];
};
