<?php
require_once __DIR__ . '/../services/Fail2banService.php';

class Fail2banController extends BaseController
{
    public static function index(): void
    {
        static::requireRole('admin');

        $notices = static::handlePost([
            'unban_ip' => fn() => Fail2banService::unbanIp($_POST['jail'] ?? '', $_POST['ip'] ?? '', static::csrfToken()),
            'update_jail_config' => fn() => Fail2banService::updateJailConfig(
                $_POST['jail'] ?? '',
                intval($_POST['bantime'] ?? 0),
                intval($_POST['findtime'] ?? 0),
                intval($_POST['maxretry'] ?? 0),
                static::csrfToken()
            ),
            'add_ignoreip' => fn() => Fail2banService::addIgnoreIp($_POST['ip'] ?? '', static::csrfToken()),
            'remove_ignoreip' => fn() => Fail2banService::removeIgnoreIp($_POST['ip'] ?? '', static::csrfToken()),
        ]);

        $jail_names = Fail2banService::listJails();
        $jails = [];
        foreach ($jail_names as $jn) {
            $detail = Fail2banService::jailDetail($jn);
            if ($detail) $jails[] = $detail;
        }
        $ignoreips = Fail2banService::getIgnoreIps();

        $page_title = t('fail2ban.title');
        static::renderPage('fail2ban/index', [
            'service_active' => (bool) fail2ban_is_active(),
            'jails' => $jails,
            'ignoreips' => $ignoreips,
            'protected_ignoreips' => Fail2banService::PROTECTED_IGNOREIPS,
            'csrf_token' => getCSRFToken(),
        ], ['title' => $page_title] + $notices);
    }
}
