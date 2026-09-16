<?php
require_once __DIR__ . '/../helpers.php';

class QueueController extends BaseController
{
    public static function index(): void
    {
        static::requireRole('admin');

        $message = '';
        $error = '';

        if (static::isPost()) {
            if (isset($_POST['save_queue'])) {
                $res = PBXHelper::saveQueue($_POST);
                if ($res['success']) $message = $res['message']; else $error = $res['error'];
            } elseif (isset($_POST['toggle_status'])) {
                $res = PBXHelper::toggleStatus('pbx_queues', $_POST['queue_id'] ?? 0, $_POST['csrf_token'] ?? '');
                if ($res['success']) $message = $res['message']; else $error = $res['error'];
            } elseif (isset($_POST['delete_queue'])) {
                $res = PBXHelper::deleteQueue($_POST['queue_id'] ?? 0, $_POST['csrf_token'] ?? '');
                if ($res['success']) $message = $res['message']; else $error = $res['error'];
            }
        }

        $queues = QueueRepository::allOrderedById();
        $all_agents = QueueRepository::extensionAgents();
        $queue_agents = QueueRepository::queueAgents();
        $queue_managers = QueueRepository::queueManagers();
        $legacy_agents = QueueRepository::assignedOutsideRole('members_json', QueueRepository::AGENT_ROLES);
        $legacy_managers = QueueRepository::assignedOutsideRole('supervisors_json', QueueRepository::MANAGER_ROLES);
        $moh_classes = QueueRepository::activeMohClasses();

        $page_title = t('queues.title');
        require_once dirname(__DIR__) . '/../header.php';
        static::render('queues/index', [
            'queues' => $queues,
            'all_agents' => $all_agents,
            'queue_agents' => $queue_agents,
            'queue_managers' => $queue_managers,
            'legacy_agents' => $legacy_agents,
            'legacy_managers' => $legacy_managers,
            'moh_classes' => $moh_classes,
        ]);
        require_once dirname(__DIR__) . '/../footer.php';
    }
}
