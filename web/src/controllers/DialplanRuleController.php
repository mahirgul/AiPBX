<?php

declare(strict_types=1);

require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../repositories/DialplanRuleRepository.php';
require_once __DIR__ . '/../services/DialplanRuleService.php';

/**
 * Controller for Ingress Call Normalization / Dialplan Rules (/dialplan-rules).
 * Renders templates/views/dialplan_rules/index.php.
 */
class DialplanRuleController extends BaseController
{
    public static function index(): void
    {
        static::requireRole('admin');

        // Handle AJAX simulation request
        if (isset($_REQUEST['action']) && $_REQUEST['action'] === 'simulate') {
            header('Content-Type: application/json; charset=utf-8');
            if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
                $csrf = (string)($_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
                if (!verifyCSRFToken($csrf)) {
                    http_response_code(403);
                    echo json_encode(['success' => false, 'error' => 'Invalid CSRF token.']);
                    exit;
                }
            }

            $number = trim((string)($_REQUEST['number'] ?? ''));
            $res = DialplanRuleRepository::simulateAll($number);
            echo json_encode(['success' => true, 'data' => $res]);
            exit;
        }


        $action = (string)($_POST['action'] ?? '');
        $postActions = [
            'save_rule' => fn() => DialplanRuleService::saveRule($_POST),
            'delete_rule' => fn() => DialplanRuleService::deleteRule(intval($_POST['rule_id'] ?? 0), static::csrfToken()),
            'toggle_status' => fn() => DialplanRuleService::toggleStatus(intval($_POST['rule_id'] ?? 0), static::csrfToken()),
            'load_defaults' => fn() => DialplanRuleService::loadDefaults(static::csrfToken(), (string)($_POST['country_code'] ?? '')),
            'set_enabled' => fn() => DialplanRuleService::setEnabled(static::csrfToken(), !empty($_POST['enabled'])),
        ];

        $notices = ['message' => '', 'error' => ''];
        if (static::isPost()) {
            if ($action !== '' && isset($postActions[$action])) {
                $notices = static::notices($postActions[$action]());
            } else {
                $notices = static::handlePost($postActions);
            }
        }

        $active_tab = trim((string)($_GET['tab'] ?? 'all'));
        if (!in_array($active_tab, ['mobile', 'desktop', 'web', 'all'], true)) {
            $active_tab = 'all';
        }

        // No country until the administrator picks one (no Turkey default).
        $country_code = preg_replace('/[^0-9]/', '', (string)getSystemSetting('dialplan_rules_country_code', ''));
        $all_countries = DialplanRuleService::getAllCountries();

        $all_rules = DialplanRuleRepository::allOrdered();
        $rules_by_type = [
            'mobile'  => [],
            'desktop' => [],
            'web'     => [],
            'all'     => [],
        ];

        foreach ($all_rules as $r) {
            $ct = $r['client_type'] ?? 'all';
            if (isset($rules_by_type[$ct])) {
                $rules_by_type[$ct][] = $r;
            }
            if ($ct === 'all') {
                $rules_by_type['mobile'][] = $r;
                $rules_by_type['desktop'][] = $r;
                $rules_by_type['web'][] = $r;
            }
        }

        $page_title = t('dialplan_rules.title');
        static::renderPage('dialplan_rules/index', [
            'active_tab' => $active_tab,
            'country_code' => $country_code,
            'all_countries' => $all_countries,
            'rules_enabled' => DialplanRuleService::isEnabled(),
            'all_rules' => $all_rules,
            'rules_by_type' => $rules_by_type,
        ], ['title' => $page_title] + $notices);
    }
}
