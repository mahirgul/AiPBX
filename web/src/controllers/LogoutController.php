<?php

class LogoutController extends BaseController
{
    public static function index(): void
    {
        // session_destroy() tek başına sunucu tarafındaki oturum verisini siliyordu
        // ama $_SESSION dizisini boşaltmıyordu ve istemci tarafındaki oturum
        // çerezini açıkça geçersiz kılmıyordu — httponly/secure/samesite bayrakları
        // sayesinde pratik risk düşüktü ama savunma-derinliği için standart çıkış
        // deseni uygulanıyor (2026-08-21 denetiminde bulundu).
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
                $stmt_pause = $db->prepare("UPDATE cc_pause_logs SET end_time = NOW(), duration = TIMESTAMPDIFF(SECOND, start_time, NOW()), status = 'COMPLETED' WHERE agent_extension = ? AND status = 'PAUSED'");
                $stmt_pause->execute([$ext]);
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
        static::redirect('/login');
    }
}
