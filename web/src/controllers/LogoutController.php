<?php

class LogoutController extends BaseController
{
    public static function index(): void
    {
        // session_destroy() alone deleted the server-side session data but did
        // not empty the $_SESSION array and did not explicitly invalidate the
        // session cookie on the client — thanks to the httponly/secure/samesite
        // flags the practical risk was low, but the standard logout pattern is
        // applied for defence in depth (found in the 2026-08-21 audit).
        $ext = $_SESSION['extension'] ?? '';
        if (!empty($ext)) {
            require_once __DIR__ . '/../queue_helper.php';
            try {
                $db = getDB();
                $stmt = $db->query("SELECT queue_name FROM pbx_queues WHERE is_active = 1");
                if ($stmt) {
                    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $qn) {
                        QueueHelper::setMembership($ext, $qn, false);
                    }
                }
                // A static agent stays in the queue even after closing the web session; the pause continues too.
                if (empty(QueueHelper::staticQueuesOf($ext))) {
                    $stmt_pause = $db->prepare("UPDATE cc_pause_logs SET end_time = NOW(), duration = TIMESTAMPDIFF(SECOND, start_time, NOW()), status = 'COMPLETED' WHERE agent_extension = ? AND status = 'PAUSED'");
                    $stmt_pause->execute([$ext]);
                }
            } catch (\Throwable $e) {
                error_log("Logout queue cleanup failed: " . $e->getMessage());
            }
        }

        // Read before the session goes. Only when this session signed in that way.
        $providerLogout = null;
        try {
            if (($_SESSION['auth_method'] ?? '') === 'saml') {
                require_once __DIR__ . '/../services/SamlAuthService.php';
                $providerLogout = SamlAuthService::buildLogoutUrl((string) ($_SESSION['saml_name_id'] ?? ''), (string) ($_SESSION['saml_session_index'] ?? ''));
            } elseif (($_SESSION['auth_method'] ?? '') === 'oidc') {
                require_once __DIR__ . '/../services/OidcAuthService.php';
                $providerLogout = OidcAuthService::buildLogoutUrl((string) ($_SESSION['oidc_id_token'] ?? ''), (string) preg_replace('#/auth/oidc/callback$#', '', OidcAuthService::getRedirectUri()) . '/login?signed_out=1');
            }
        } catch (\Throwable $e) {
            error_log('Single Logout: ' . $e->getMessage()); // Signing out of AiPBX still works.
        }

        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }
        session_destroy();
        static::redirect($providerLogout ?? '/login?signed_out=1');
    }
}
