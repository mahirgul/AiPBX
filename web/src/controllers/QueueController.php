<?php
require_once __DIR__ . '/../helpers.php';

class QueueController extends BaseController
{
    public static function index(): void
    {
        static::requireRole('admin');

        $notices = static::handlePost([
            'save_queue' => fn() => QueueService::saveQueue($_POST),
            'toggle_status' => fn() => PBXHelper::toggleStatus('pbx_queues', $_POST['queue_id'] ?? 0, static::csrfToken()),
            'delete_queue' => fn() => QueueService::deleteQueue($_POST['queue_id'] ?? 0, static::csrfToken()),
        ]);

        $queues = QueueRepository::allOrderedById();
        $all_agents = QueueRepository::extensionAgents();
        $queue_agents = QueueRepository::queueAgents();
        $queue_managers = QueueRepository::queueManagers();
        $legacy_agents = QueueRepository::assignedOutsideRole('members_json', QueueRepository::AGENT_ROLES);
        $legacy_managers = QueueRepository::assignedOutsideRole('supervisors_json', QueueRepository::MANAGER_ROLES);
        $moh_classes = QueueRepository::activeMohClasses();

        $page_title = t('queues.title');
        static::renderPage('queues/index', [
            'queues' => $queues,
            'all_agents' => $all_agents,
            'queue_agents' => $queue_agents,
            'queue_managers' => $queue_managers,
            'legacy_agents' => $legacy_agents,
            'legacy_managers' => $legacy_managers,
            'moh_classes' => $moh_classes,
        ], ['title' => $page_title] + $notices);
    }
}
