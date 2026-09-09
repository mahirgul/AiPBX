<?php

class CcAgentController extends BaseController
{
    public static function index(): void
    {
        static::requireRole(['admin', 'cc_manager', 'cc_agent']);

        $user = getCurrentUser();
        $agent_ext = $user['extension'] ?? '';
        $is_queue_agent = CcAgentRepository::isAssignedQueueAgent((string)$agent_ext);

        $page_title = t('cc_agent.title');
        require_once dirname(__DIR__) . '/../header.php';

        // Erişim yalnızca gerçek cc_agent rolüne VEYA en az bir aktif kuyruğa
        // temsilci olarak açıkça atanmış kullanıcıya izin verir.
        if ($user['role'] !== 'cc_agent' && !$is_queue_agent) {
            static::render('cc_agent/restricted', []);
            require_once dirname(__DIR__) . '/../footer.php';
            return;
        }

        $extra_js = '<script>
window.AGENT_EXT = "' . htmlspecialchars($agent_ext) . '";
</script>
<script src="/assets/js/agent_ui.js?v=' . time() . '"></script>';

        static::render('cc_agent/index', []);
        require_once dirname(__DIR__) . '/../footer.php';
    }
}
