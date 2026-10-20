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

        // Handle AJAX reorder request
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_POST['action']) && $_POST['action'] === 'reorder') {
            header('Content-Type: application/json; charset=utf-8');
            $csrf = (string)($_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
            $ids = (array)($_POST['rule_ids'] ?? []);
            $res = DialplanRuleService::reorder($ids, $csrf);
            echo json_encode($res);
            exit;
        }

        $notices = static::handlePost([
            'save_rule' => fn() => DialplanRuleService::saveRule($_POST),
            'delete_rule' => fn() => DialplanRuleService::deleteRule(intval($_POST['rule_id'] ?? 0), static::csrfToken()),
            'toggle_status' => fn() => DialplanRuleService::toggleStatus(intval($_POST['rule_id'] ?? 0), static::csrfToken()),
            'load_defaults' => fn() => DialplanRuleService::loadDefaults(static::csrfToken(), (string)($_POST['country_code'] ?? '90')),
        ]);

        $active_tab = trim((string)($_GET['tab'] ?? 'mobile'));
        if (!in_array($active_tab, ['mobile', 'desktop', 'web', 'all', 'simulator'], true)) {
            $active_tab = 'mobile';
        }

        $country_code = (string)getSystemSetting('dialplan_rules_country_code', '90');

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
            'all_rules' => $all_rules,
            'rules_by_type' => $rules_by_type,
        ], ['title' => $page_title] + $notices);
    }
}
