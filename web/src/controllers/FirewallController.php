<?php
require_once __DIR__ . '/../services/FirewallService.php';

class FirewallController extends BaseController
{
    public static function index(): void
    {
        static::requireRole('admin');

        $message = '';
        $error = '';

        if (static::isPost()) {
            if (isset($_POST['add_port_rule'])) {
                $res = FirewallService::addPortRule(
                    $_POST['port'] ?? '',
                    $_POST['protocol'] ?? 'tcp',
                    $_POST['source_subnet'] ?? '',
                    $_POST['csrf_token'] ?? ''
                );
            } elseif (isset($_POST['remove_port_rule'])) {
                $res = FirewallService::removePortRule(
                    $_POST['port'] ?? '',
                    $_POST['protocol'] ?? 'tcp',
                    $_POST['csrf_token'] ?? ''
                );
            } elseif (isset($_POST['remove_rich_rule'])) {
                $res = FirewallService::removeRichRule(
                    $_POST['rule'] ?? '',
                    $_POST['csrf_token'] ?? ''
                );
            } else {
                $res = ['success' => false, 'error' => 'Bilinmeyen işlem.'];
            }
            if ($res['success']) $message = $res['message'] ?? ''; else $error = $res['error'] ?? '';
        }

        $status = FirewallService::getStatus();

        $page_title = t('firewall.title');
        require_once dirname(__DIR__) . '/../header.php';
        static::render('firewall/index', [
            'status' => $status,
            'protected_ports' => FirewallService::PROTECTED_PORTS,
            'csrf_token' => getCSRFToken(),
        ]);
        require_once dirname(__DIR__) . '/../footer.php';
    }
}
