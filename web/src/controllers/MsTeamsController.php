<?php

require_once __DIR__ . '/../../auth.php';
require_once __DIR__ . '/../services/MsTeamsService.php';
require_once __DIR__ . '/../repositories/MsTeamsRepository.php';

class MsTeamsController extends BaseController
{
    public static function index(): void
    {
        requireLogin();
        static::requireModule('ms_teams', 'view');

        $active_tab = $_GET['tab'] ?? 'direct_routing';

        $ajax = $_GET['action'] ?? '';
        $noEdit = t('ms_teams.msg_no_edit_perm');
        $badCsrf = t('ms_teams.msg_invalid_csrf');

        // --- AJAX: send a test webhook ---
        if ($ajax === 'test_webhook') {
            static::requireAjaxAccess('ms_teams', 'edit', $noEdit, $badCsrf);
            $url = trim($_POST['webhook_url'] ?? '');
            if ($url === '') {
                $url = MsTeamsRepository::currentSettings()['teams_webhook_url'] ?? '';
            }
            static::json(MsTeamsService::sendTestWebhook($url));
        }

        // --- AJAX: save / edit a user mapping ---
        if ($ajax === 'save_mapping') {
            static::requireAjaxAccess('ms_teams', 'edit', $noEdit, $badCsrf);
            static::json(MsTeamsRepository::saveUserMapping($_POST));
        }

        // --- AJAX: delete a user mapping ---
        if ($ajax === 'delete_mapping') {
            static::requireAjaxAccess('ms_teams', 'delete', t('ms_teams.msg_no_delete_perm'), $badCsrf);
            $id = (int) ($_POST['id'] ?? 0);
            if ($id <= 0) {
                static::json(['success' => false, 'message' => t('ms_teams.msg_invalid_id')]);
            }
            static::json(MsTeamsRepository::deleteUserMapping($id)
                ? ['success' => true, 'message' => t('ms_teams.msg_delete_success')]
                : ['success' => false, 'message' => t('ms_teams.msg_delete_error')]);
        }

        // --- Download the PowerShell script ---
        if ($ajax === 'download_powershell') {
            $settings = MsTeamsRepository::currentSettings();
            $mappings = MsTeamsRepository::allUserMappings();
            $script = MsTeamsService::generatePowerShellScript($settings, $mappings);

            $filename = 'aipbx_teams_setup_' . date('Ymd_His') . '.ps1';
            header('Content-Type: text/plain; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Content-Length: ' . strlen($script));
            echo $script;
            exit;
        }

        // --- Regular form posts (Direct Routing & webhook settings) ---
        $guarded = fn(callable $save) => match (true) {
            !static::verifyCsrf() => ['success' => false, 'error' => $badCsrf],
            !hasModulePermission('ms_teams', 'edit') => ['success' => false, 'error' => $noEdit],
            default => $save(),
        };
        $notices = static::handlePost([
            'save_direct_routing' => fn() => $guarded(fn() => MsTeamsService::saveDirectRoutingSettings($_POST)),
            'save_webhook_settings' => fn() => $guarded(fn() => MsTeamsService::saveWebhookSettings($_POST)),
        ]);
        if (isset($_POST['save_webhook_settings'])) {
            $active_tab = 'webhooks';
        } elseif (isset($_POST['save_direct_routing'])) {
            $active_tab = 'direct_routing';
        }

        $settings = MsTeamsRepository::currentSettings();
        $mappings = MsTeamsRepository::allUserMappings();
        $extensions = MsTeamsRepository::availableExtensions();
        $certInfo = MsTeamsService::inspectTlsCert($settings['teams_tls_cert_path'] ?? '');
        $powerShellScript = MsTeamsService::generatePowerShellScript($settings, $mappings);

        $page_title = t('ms_teams.title');
        static::renderPage('ms_teams/index', [
            'settings'         => $settings,
            'mappings'         => $mappings,
            'extensions'       => $extensions,
            'certInfo'         => $certInfo,
            'powerShellScript' => $powerShellScript,
            'active_tab'       => $active_tab,
            'can_edit'         => hasModulePermission('ms_teams', 'edit'),
            'can_delete'       => hasModulePermission('ms_teams', 'delete'),
        ], ['title' => $page_title] + $notices);
    }
}
