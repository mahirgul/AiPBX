<?php

class CcBoardController extends BaseController
{
    public static function index(): void
    {
        static::requireRole(['admin', 'cc_manager', 'cc_agent', 'read_only_admin']);

        $user = getCurrentUser();
        $user_ext = (string)($user['extension'] ?? '');

        // Erişim kontrolü: Kuyruk İzleme (queue_monitor) ile aynı kapsam mantığı —
        // global izin YOKSA, kullanıcının süpervizör olarak atandığı kuyruklar üzerinden bakılır.
        $has_global_queue_view = (
            !empty($user['can_view_queue_monitor']) ||
            hasModulePermission('cc_board', 'view')
        );

        $all_queues = CcBoardRepository::activeQueuesForSupervisorScope();

        $my_queues = [];
        foreach ($all_queues as $q) {
            if ($has_global_queue_view || CcBoardRepository::isSupervisorOf($q, $user_ext)) {
                $my_queues[] = $q;
            }
        }
        $can_view = $has_global_queue_view || !empty($my_queues);

        $page_title = t('sidebar.item_cc_board_unified');
        require_once dirname(__DIR__) . '/../header.php';

        if (!$can_view) {
            static::render('cc_board/restricted', []);
            require_once dirname(__DIR__) . '/../footer.php';
            return;
        }

        static::render('cc_board/index', [
            'my_queues' => $my_queues,
        ]);
        require_once dirname(__DIR__) . '/../footer.php';
    }
}
