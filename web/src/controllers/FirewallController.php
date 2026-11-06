<?php
require_once __DIR__ . '/../services/FirewallService.php';

/**
 * Firewall page (/firewall).
 * Renders templates/views/firewall/index.php.
 * Logic: FirewallService.
 * POST actions: add_rule, remove_rule, use_general.
 * Admins only.
 */
class FirewallController extends BaseController
{
    public static function index(): void
    {
        static::requireRole('admin');

        $notices = static::handlePost([
            'add_rule' => fn() => FirewallService::addRule(
                $_POST['port'] ?? '',
                $_POST['protocol'] ?? 'tcp',
                $_POST['source_subnet'] ?? '',
                $_POST['target'] ?? 'all',
                static::csrfToken()
            ),
            'remove_rule' => fn() => FirewallService::removeRule($_POST['iface'] ?? '', $_POST['kind'] ?? '', $_POST['value'] ?? '', static::csrfToken()),
            'use_general' => fn() => FirewallService::useGeneralRules($_POST['iface'] ?? '', static::csrfToken()),
            'toggle_rule' => fn() => FirewallService::toggleRule($_POST['scope'] ?? '', $_POST['kind'] ?? '', $_POST['value'] ?? '', ($_POST['on'] ?? '') === '1', static::csrfToken()),
            'delete_disabled' => fn() => FirewallService::deleteDisabled($_POST['scope'] ?? '', $_POST['kind'] ?? '', $_POST['value'] ?? '', static::csrfToken()),
            'common_on_card' => fn() => FirewallService::commonOnCard($_POST['iface'] ?? '', $_POST['kind'] ?? '', $_POST['value'] ?? '', ($_POST['on'] ?? '') === '1', static::csrfToken()),
        ]);

        $page_title = t('firewall.title');
        static::renderPage('firewall/index', [
            'firewall' => FirewallService::overview(),
            'csrf_token' => getCSRFToken(),
        ], ['title' => $page_title] + $notices);
    }
}
