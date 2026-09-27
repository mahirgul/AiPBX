<?php

class CcBoardController extends BaseController
{
    public static function index(): void
    {
        static::requireRole(['admin', 'cc_manager', 'cc_agent', 'read_only_admin']);
        static::renderBoard('cc_board');
    }

    /**
     * /cc-board ve /cc-supervisor aynı ekranı açar; yalnızca global görme
     * izninin modül anahtarı farklıdır (cc_board / queue_monitor). Önceden
     * iki controller'da birebir kopyalanmıştı.
     */
    protected static function renderBoard(string $globalViewPermission): void
    {
        $user = getCurrentUser();
        $user_ext = (string)($user['extension'] ?? '');

        // Global izin YOKSA, kullanıcının süpervizör olarak atandığı kuyruklar üzerinden bakılır.
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
        ], $page);
    }
}
