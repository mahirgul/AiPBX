<?php
require_once __DIR__ . '/../services/FirewallService.php';

class FirewallController extends BaseController
{
    public static function index(): void
    {
        static::requireRole('admin');

        $notices = static::handlePost([
            'add_port_rule' => fn() => FirewallService::addPortRule(
                $_POST['port'] ?? '',
                $_POST['protocol'] ?? 'tcp',
                $_POST['source_subnet'] ?? '',
                static::csrfToken()
            ),
            'remove_port_rule' => fn() => FirewallService::removePortRule($_POST['port'] ?? '', $_POST['protocol'] ?? 'tcp', static::csrfToken()),
            'remove_rich_rule' => fn() => FirewallService::removeRichRule($_POST['rule'] ?? '', static::csrfToken()),
        ]);

        $status = FirewallService::getStatus();

        $page_title = t('firewall.title');
        static::renderPage('firewall/index', [
            'status' => $status,
            'protected_ports' => FirewallService::PROTECTED_PORTS,
            'csrf_token' => getCSRFToken(),
        ], ['title' => $page_title] + $notices);
    }
}
