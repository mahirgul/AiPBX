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

        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }
        session_destroy();
        // The web chat token (ChatController) is a separate 24-hour cookie;
        // without this the next person on a shared browser could keep using
        // the chat as the logged-out user.
        setcookie('chat_token', '', [
            'expires' => time() - 42000,
            'path' => '/',
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        static::redirect('/login');
    }
}
