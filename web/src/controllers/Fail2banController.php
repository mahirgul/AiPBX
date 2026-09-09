<?php
require_once __DIR__ . '/../services/Fail2banService.php';

class Fail2banController extends BaseController
{
    public static function index(): void
    {
        static::requireRole('admin');

        $message = '';
        $error = '';

        if (static::isPost()) {
            if (isset($_POST['unban_ip'])) {
                $res = Fail2banService::unbanIp($_POST['jail'] ?? '', $_POST['ip'] ?? '', $_POST['csrf_token'] ?? '');
            } elseif (isset($_POST['update_jail_config'])) {
                $res = Fail2banService::updateJailConfig(
                    $_POST['jail'] ?? '',
                    intval($_POST['bantime'] ?? 0),
                    intval($_POST['findtime'] ?? 0),
                    intval($_POST['maxretry'] ?? 0),
                    $_POST['csrf_token'] ?? ''
                );
            } elseif (isset($_POST['add_ignoreip'])) {
                $res = Fail2banService::addIgnoreIp($_POST['ip'] ?? '', $_POST['csrf_token'] ?? '');
            } elseif (isset($_POST['remove_ignoreip'])) {
                $res = Fail2banService::removeIgnoreIp($_POST['ip'] ?? '', $_POST['csrf_token'] ?? '');
            } else {
                $res = ['success' => false, 'error' => 'Bilinmeyen işlem.'];
            }
            if ($res['success']) $message = $res['message'] ?? ''; else $error = $res['error'] ?? '';
        }

        $jail_names = Fail2banService::listJails();
        $jails = [];
        foreach ($jail_names as $jn) {
            $detail = Fail2banService::jailDetail($jn);
            if ($detail) $jails[] = $detail;
        }
        $ignoreips = Fail2banService::getIgnoreIps();

        $page_title = t('fail2ban.title');
        require_once dirname(__DIR__) . '/../header.php';
        static::render('fail2ban/index', [
            'service_active' => (bool) fail2ban_is_active(),
            'jails' => $jails,
            'ignoreips' => $ignoreips,
            'protected_ignoreips' => Fail2banService::PROTECTED_IGNOREIPS,
            'csrf_token' => getCSRFToken(),
        ]);
        require_once dirname(__DIR__) . '/../footer.php';
    }
}
