<?php

class CcAgentController extends BaseController
{
    public static function index(): void
    {
        static::requireRole(['admin', 'cc_manager', 'cc_agent']);

        $user = getCurrentUser();
        $agent_ext = $user['extension'] ?? '';
        $is_queue_agent = CcAgentRepository::isAssignedQueueAgent((string)$agent_ext);

        $page = ['title' => t('cc_agent.title')];

        // Erişim yalnızca gerçek cc_agent rolüne VEYA en az bir aktif kuyruğa
        // temsilci olarak açıkça atanmış kullanıcıya izin verir.
        if ($user['role'] !== 'cc_agent' && !$is_queue_agent) {
            static::renderPage('cc_agent/restricted', [], $page);
            return;
        }

        $page['extra_js'] = '<script>window.AGENT_EXT = ' . json_encode((string) $agent_ext) . ';</script>'
            . '<script src="' . asset('/assets/js/agent_ui.js') . '"></script>';

        static::renderPage('cc_agent/index', [], $page);
    }
}
