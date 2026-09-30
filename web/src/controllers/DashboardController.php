<?php
/**
 * Kontrol Paneli Controller'ı — eski src/dashboard.php'nin MVC karşılığı.
 * Sayfa mantığı (yetki kontrolü + POST işleme) burada, veri toplama
 * DashboardRepository'de, HTML çıktısı templates/views/dashboard/index.php'de.
 */
require_once __DIR__ . '/../asterisk_sync.php';
require_once __DIR__ . '/../priv_helper.php';

class DashboardController extends BaseController
{
    public static function index(): void
    {
        static::requireRole('admin');

        $message = '';
        $error = '';

        if (static::isPost() && isset($_POST['system_action'])) {
            if (!static::verifyCsrf()) {
                $error = t('dashboard.msg_csrf_error');
            } else {
                [$message, $error] = static::handleSystemAction();
            }
            if ($message !== '') static::notifySuccess($message);
            if ($error !== '') static::notifyError($error);
        }

        $stats = DashboardRepository::getStats();
        $metrics = DashboardRepository::getSystemMetrics();
        $metrics['live'] = DashboardRepository::getLiveStats();

        $page_title = t('dashboard.title');
        static::renderPage('dashboard/index', array_merge($stats, $metrics), ['title' => $page_title, 'message' => $message ?? '', 'error' => $error ?? '']);
    }

    /**
     * @return array{0: string, 1: string} [message, error]
     */
    private static function handleSystemAction(): array
    {
        $action = trim($_POST['system_action'] ?? '');
        $service = trim($_POST['service_name'] ?? '');

        $uid = $_SESSION['user_id'] ?? null;

        if ($action === 'reload_asterisk') {
            // 2026-08-24: `asterisk -rx` her zaman exit code 0 döner (canlı
            // doğrulandı) — execCLI() çıktı metnini looksLikeCliFailure() ile
            // kontrol ediyor. sudo gerekmiyor: web kullanıcısı asterisk
            // grubunda, kontrol soketine doğrudan erişiyor.
            $res = AsteriskHelper::execCLI('core reload');
            $output = trim($res['output']);
            $failed = !$res['success'];
            // 2026-08-25: başarısızlıkta ham Asterisk çıktısı da audit kaydına
            // yazılıyor (önceden sadece "core reload" yazıp asıl hata metnini
            // kalıcı kayıttan dışarıda bırakıyordu).
            $label = t('dashboard.service_asterisk_name') . ' (core reload)' . ($failed ? ': ' . mb_substr($output, 0, 200) : '');
            writeAuditLog(null, 'system_service', 'asterisk', $label, $failed ? 'reload_failed' : 'reload', $uid);
            if ($failed) {
                return ['', t('dashboard.msg_reload_failed') . ' ' . $output];
            }
            return [t('dashboard.msg_reload_success'), ''];
        }

        if ($action === 'restart_service') {
            $allowed_services = [
                'asterisk' => t('dashboard.service_asterisk_name'),
                'apache2' => t('dashboard.service_httpd_name'),
                'mariadb' => t('dashboard.service_mariadb_name'),
                'postfix' => t('dashboard.service_postfix_name'),
            ];
            if (isset($allowed_services[$service])) {
                // systemctl, asterisk -rx'in aksine GERÇEK bir exit code
                // döndürüyor (canlı doğrulandı: başarısız bir restart
                // sıfırdan farklı bir kod veriyor) — burada $ret güvenilir.
                $res = PrivHelper::run(['service', 'restart', $service]);
                $output = $res['output'];
                $failed = !$res['success'];
                $label = $allowed_services[$service] . ' (systemctl restart)' . ($failed ? ': ' . mb_substr($output, 0, 200) : '');
                writeAuditLog(null, 'system_service', $service, $label, $failed ? 'restart_failed' : 'restart', $uid);
                if ($failed) {
                    return ['', $allowed_services[$service] . ' ' . t('dashboard.msg_restart_failed') . ' ' . $output];
                }
                return [$allowed_services[$service] . ' ' . t('dashboard.msg_service_restarted'), ''];
            }
            return ['', t('dashboard.msg_invalid_service')];
        }

        return ['', ''];
    }
}
