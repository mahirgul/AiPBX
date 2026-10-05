<?php

class CcBoardController extends BaseController
{
    public static function index(): void
    {
        static::requireRole(['admin', 'cc_manager', 'cc_agent', 'read_only_admin']);
        static::renderBoard('cc_board');
    }

    /**
     * /cc-board and /cc-supervisor open the same screen; only the module key
     * of the global view permission differs (cc_board / queue_monitor). It
     * used to be copied verbatim in two controllers.
     */
    protected static function renderBoard(string $globalViewPermission): void
    {
        $user = getCurrentUser();
        $user_ext = (string)($user['extension'] ?? '');

        // WITHOUT the global permission, look at the queues the user is assigned to as supervisor.
        $has_global_queue_view = (
            !empty($user['can_view_queue_monitor']) ||
            hasModulePermission($globalViewPermission, 'view')
        );

        $my_queues = [];
        foreach (CcBoardRepository::activeQueuesForSupervisorScope() as $q) {
            if ($has_global_queue_view || CcBoardRepository::isSupervisorOf($q, $user_ext)) {
                $my_queues[] = $q;
            }
        }
        $page = ['title' => t('sidebar.item_cc_board_unified')];

        if (!$has_global_queue_view && empty($my_queues)) {
            static::renderPage('cc_board/restricted', [], $page);
            return;
        }

        static::renderPage('cc_board/index', [
            'my_queues' => $my_queues,
            // The same rule as api/cc.php spy_call
            'can_spy' => in_array($user['role'] ?? '', ['admin', 'cc_manager'], true),
        ], $page);
    }
}
