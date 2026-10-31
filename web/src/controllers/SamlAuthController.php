<?php

declare(strict_types=1);

require_once __DIR__ . '/../core/BaseController.php';
require_once __DIR__ . '/../services/SamlAuthService.php';

/**
 * SamlAuthController
 * SAML 2.0 Enterprise Single Sign-On Web and Mobile Authorization / ACS Handler.
 */
class SamlAuthController extends BaseController
{
    /**
     * Initiates SAML AuthnRequest and redirects user to IdP SSO URL.
     */
    public static function login(): void
    {
        if (!SamlAuthService::isEnabled()) {
            notify(t('saml.err_disabled', 'SAML 2.0 Single Sign-On is not enabled on this PBX.'), 'warning');
            static::redirect('/login');
            return;
        }

        $isMobile = !empty($_GET['mobile']) || (isset($_GET['platform']) && in_array($_GET['platform'], ['android', 'ios'], true));

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }

        $req = SamlAuthService::buildAuthnRequest($isMobile);
        if (!$req || empty($req['url'])) {
            $lastErr = SamlAuthService::getLastError();
            notify(sprintf(t('saml.err_config', 'SAML configuration error: %s'), $lastErr ?: 'IdP SSO URL is invalid'), 'danger');
            static::redirect('/login');
            return;
        }

        header('Location: ' . $req['url']);
        exit;
    }

    /**
     * Assertion Consumer Service (ACS) endpoint.
     * Receives SAMLResponse from IdP via HTTP-POST.
     */
    public static function acs(): void
    {
        $samlResponse = trim((string)($_POST['SAMLResponse'] ?? ''));
        $relayState = isset($_POST['RelayState']) ? (string)$_POST['RelayState'] : null;

        if (empty($samlResponse)) {
            notify(t('saml.err_no_response', 'No SAMLResponse received from Identity Provider.'), 'danger');
            static::redirect('/login');
            return;
        }

        $result = SamlAuthService::processResponse($samlResponse, $relayState);
        $isMobile = !empty($result['is_mobile']);

        if (!$result['success'] || empty($result['user'])) {
            $err = $result['error'] ?? 'Unknown authentication error';
            if ($isMobile) {
                self::renderMobileCallback(false, t('mobile_api.login_failed', 'Login failed'), null, $err);
                return;
            }
            notify(sprintf(t('saml.err_auth_failed', 'SAML Single Sign-On failed: %s'), htmlspecialchars($err)), 'danger');
            static::redirect('/login');
            return;
        }

        $user = $result['user'];

        // Two-factor authentication check
        if (!empty($user['two_factor_enabled'])) {
            if ($isMobile) {
                self::renderMobileCallback(false, t('google.otp_title', 'Two-step verification'), null, t('google.otp_text', 'Two-step verification is required.'));
                return;
            }
            if (session_status() !== PHP_SESSION_ACTIVE && !headers_sent()) {
                @session_start();
            }
            session_regenerate_id(true);
            $_SESSION['pending_2fa_user_id'] = $user['id'];
            $_SESSION['pending_2fa_username'] = $user['username'];
            $_SESSION['pending_2fa_full_name'] = $user['full_name'];
            static::redirect('/login-2fa');
            return;
        }

        // Mobile app sign-in deep link
        if ($isMobile) {
            require_once dirname(__DIR__) . '/services/QrLoginService.php';
            $codeRes = QrLoginService::createGoogleCode((int)$user['id']);
            if (empty($codeRes['success'])) {
                self::renderMobileCallback(false, t('mobile_api.login_failed', 'Login failed'), null, $codeRes['error'] ?? 'Token error');
                return;
            }
            if (function_exists('writeAuditLog')) {
                writeAuditLog(null, 'user_account', (string)$user['id'], "Mobile sign-in code created via SAML 2.0: {$user['username']}", 'login', (int)$user['id']);
            }
            self::renderMobileCallback(true, t('google.login_ok', 'Signed in'), ['code' => $codeRes['token']]);
            return;
        }

        // Web login session
        SamlAuthService::loginUserSession($user);

        notify(sprintf(t('saml.welcome', 'Welcome, %s! Signed in via SAML 2.0 SSO.'), htmlspecialchars((string)$user['full_name'])), 'success');

        $returnTo = '/dashboard';
        if (!empty($_SESSION['return_to'])) {
            $returnTo = (string)$_SESSION['return_to'];
            unset($_SESSION['return_to']);
        }

        static::redirect($returnTo);
    }

    /**
     * Outputs SP EntityDescriptor Metadata XML for IdP configuration.
     */
    public static function metadata(): void
    {
        $xml = SamlAuthService::getSpMetadataXml();
        header('Content-Type: application/xml; charset=utf-8');
        header('Content-Disposition: inline; filename="aipbx-sp-metadata.xml"');
        echo $xml;
        exit;
    }

    /**
     * Builds the deep-link redirect screen for the mobile application.
     *
     * @param array<string, mixed>|null $data
     */
    private static function renderMobileCallback(bool $success, string $title, ?array $data = null, string $errorMessage = ''): void
    {
        $deepLink = 'aipbx://auth?success=' . ($success ? '1' : '0');
        if ($success && !empty($data['code'])) {
            $deepLink .= '&code=' . urlencode((string)$data['code']);
        } else {
            $deepLink .= '&error=' . urlencode($errorMessage);
        }

        header('Referrer-Policy: no-referrer');
        static::renderAuthPage('google_auth/mobile_callback', [
            'success'       => $success,
            'title'         => $title,
            'error_message' => $errorMessage,
            'deep_link'     => $deepLink,
        ], ['title' => $title, 'head' => '<meta name="referrer" content="no-referrer">']);
        exit;
    }
}
