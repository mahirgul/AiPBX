<?php
require_once __DIR__ . '/../services/ai/AiAgentService.php';
require_once __DIR__ . '/../../modules/destinations/DestinationRegistry.php';

use PBX\Destinations\DestinationRegistry;

/**
 * /ai-agents — AI → External agents (admin only: calls leave for a SIP
 * address with the service's password). The list, and the form of one agent
 * (?edit=<id>, ?new). Changes take effect with "Apply".
 */
class AiAgentsController extends BaseController
{
    private const BLANK = ['id' => 0, 'title' => '', 'sip_uri' => '', 'transport' => 'udp', 'auth_username' => '',
        'max_concurrent' => 4, 'max_minutes' => 15, 'ring_seconds' => 20, 'handover_dest_type' => '', 'handover_dest_id' => '',
        'fallback_dest_type' => 'hangup', 'fallback_dest_id' => '', 'is_active' => 1];

    public static function index(): void
    {
        static::requireRole('admin');
        $token = (string) ($_POST['csrf_token'] ?? '');
        $notices = static::handlePost([
            'save_agent' => fn() => PBXHelper::handleAction($token, function () {
                AiAgentService::save($_POST);
                return t('ai_agents.msg_saved');
            }),
            'delete_agent' => fn() => PBXHelper::handleAction($token, function () {
                AiAgentService::delete((int) ($_POST['agent_id'] ?? 0));
                return t('ai_agents.msg_deleted');
            }),
        ]);
        $edit = null;
        if (isset($_POST['save_agent']) && $notices['error'] !== '') {
            // Saving failed: the form again with what was typed (never the password).
            $edit = array_merge(self::BLANK, array_intersect_key($_POST, self::BLANK));
        } elseif (($editId = (int) ($_GET['edit'] ?? 0)) > 0) {
            $edit = AiAgentService::get($editId);
        } elseif (isset($_GET['new'])) {
            $edit = self::BLANK;
        }
        static::renderPage('ai_agents/index', [
            'agents' => AiAgentService::all(),
            'edit' => $edit,
            'privacy_blocked' => function_exists('getSystemSetting') && getSystemSetting('privacy_block_cloud_ai', '0') === '1',
            'modules' => DestinationRegistry::getModuleList(),
            'csrf_token' => getCSRFToken(),
        ], ['title' => t('ai_agents.title')] + $notices);
    }
}
