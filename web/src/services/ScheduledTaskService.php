<?php

declare(strict_types=1);

/**
 * Service for Scheduled Tasks management and execution (Roadmap 2.1).
 */
class ScheduledTaskService
{
    public const SETTING_KEY = 'scheduled_tasks_enabled';

    public static function isEnabled(): bool
    {
        $db = getDB();
        $stmt = $db->prepare('SELECT setting_value FROM sys_settings WHERE setting_key = ?');
        $stmt->execute([self::SETTING_KEY]);
        $val = $stmt->fetchColumn();
        return $val === '1' || $val === 'true' || $val === 'on';
    }

    public static function setEnabled(bool $enabled): void
    {
        $db = getDB();
        $stmt = $db->prepare(
            'INSERT INTO sys_settings (setting_key, setting_value) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
        );
        $stmt->execute([self::SETTING_KEY, $enabled ? '1' : '0']);
    }

    /**
     * @param array<string, mixed> $data
     * @return array{ok: bool, id?: int, errors: array<string, string>}
     */
    public static function saveTask(array $data, ?int $userId = null): array
    {
        $errors = [];
        $name = trim((string)($data['name'] ?? ''));
        if ($name === '' || strlen($name) > 100) {
            $errors['name'] = 'Name must be between 1 and 100 characters';
        }

        $cronExpr = trim((string)($data['cron_expression'] ?? '0 3 * * *'));
        if (!CronScheduleHelper::isValid($cronExpr)) {
            $errors['cron_expression'] = 'Invalid cron expression';
        }

        $scriptType = (string)($data['script_type'] ?? 'shell');
        if (!in_array($scriptType, ['shell', 'php', 'python'], true)) {
            $errors['script_type'] = 'Script type must be shell, php, or python';
        }

        $scriptContent = trim((string)($data['script_content'] ?? ''));
        if ($scriptContent === '') {
            $errors['script_content'] = 'Script content cannot be empty';
        }

        $timeout = (int)($data['timeout_sec'] ?? 300);
        if ($timeout < 5 || $timeout > 3600) {
            $errors['timeout_sec'] = 'Timeout must be between 5 and 3600 seconds';
        }

        $memory = (int)($data['memory_limit_mb'] ?? 256);
        if ($memory < 32 || $memory > 4096) {
            $errors['memory_limit_mb'] = 'Memory limit must be between 32 MB and 4096 MB';
        }

        $cpu = (int)($data['cpu_limit_pct'] ?? 50);
        if ($cpu < 5 || $cpu > 100) {
            $errors['cpu_limit_pct'] = 'CPU limit must be between 5% and 100%';
        }

        if (!empty($errors)) {
            return ['ok' => false, 'errors' => $errors];
        }

        $db = getDB();
        $id = !empty($data['id']) ? (int)$data['id'] : null;
        $isActive = !empty($data['is_active']) ? 1 : 0;
        $networkAccess = !empty($data['network_access']) ? 1 : 0;
        $allowPersonal = !empty($data['allow_personal_data']) ? 1 : 0;
        $description = trim((string)($data['description'] ?? ''));
        $envVars = is_array($data['env_vars'] ?? null) ? json_encode($data['env_vars']) : (string)($data['env_vars'] ?? '');
        $dataScopes = is_array($data['data_scopes'] ?? null) ? json_encode($data['data_scopes']) : (string)($data['data_scopes'] ?? '');
        $apiScopes = is_array($data['api_scopes'] ?? null) ? json_encode($data['api_scopes']) : (string)($data['api_scopes'] ?? '');

        if ($id !== null) {
            $existing = ScheduledTaskRepository::findById($id);
            if (!$existing) {
                return ['ok' => false, 'errors' => ['id' => 'Task not found']];
            }

            $stmt = $db->prepare(
                'UPDATE pbx_scheduled_tasks
                 SET name = ?, description = ?, is_active = ?, cron_expression = ?, script_type = ?,
                     script_content = ?, timeout_sec = ?, memory_limit_mb = ?, cpu_limit_pct = ?,
                     network_access = ?, env_vars = ?, data_scopes = ?, allow_personal_data = ?,
                     api_scopes = ?, updated_at = NOW()
                 WHERE id = ?'
            );
            $stmt->execute([
                $name, $description, $isActive, $cronExpr, $scriptType,
                $scriptContent, $timeout, $memory, $cpu,
                $networkAccess, $envVars, $dataScopes, $allowPersonal,
                $apiScopes, $id
            ]);

            // If script content changed, record a new version
            if ($existing['script_content'] !== $scriptContent) {
                ScheduledTaskRepository::recordVersion($id, $scriptContent, $userId);
            }

            return ['ok' => true, 'id' => $id, 'errors' => []];
        }

        $stmt = $db->prepare(
            'INSERT INTO pbx_scheduled_tasks
             (name, description, is_active, cron_expression, script_type, script_content,
              timeout_sec, memory_limit_mb, cpu_limit_pct, network_access, env_vars,
              data_scopes, allow_personal_data, api_scopes, created_at, updated_at, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW(), ?)'
        );
        $stmt->execute([
            $name, $description, $isActive, $cronExpr, $scriptType, $scriptContent,
            $timeout, $memory, $cpu, $networkAccess, $envVars,
            $dataScopes, $allowPersonal, $apiScopes, $userId
        ]);
        $newId = (int)$db->lastInsertId();

        // Initial version
        ScheduledTaskRepository::recordVersion($newId, $scriptContent, $userId);

        return ['ok' => true, 'id' => $newId, 'errors' => []];
    }

    public static function deleteTask(int $taskId): bool
    {
        $db = getDB();
        $db->prepare('DELETE FROM pbx_scheduled_task_versions WHERE task_id = ?')->execute([$taskId]);
        $db->prepare('DELETE FROM pbx_scheduled_task_runs WHERE task_id = ?')->execute([$taskId]);
        $stmt = $db->prepare('DELETE FROM pbx_scheduled_tasks WHERE id = ?');
        $stmt->execute([$taskId]);
        return $stmt->rowCount() > 0;
    }

    public static function toggleTask(int $taskId, bool $isActive): bool
    {
        $db = getDB();
        $stmt = $db->prepare('UPDATE pbx_scheduled_tasks SET is_active = ?, updated_at = NOW() WHERE id = ?');
        $stmt->execute([$isActive ? 1 : 0, $taskId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function getTaskDetails(int $taskId): ?array
    {
        $task = ScheduledTaskRepository::findById($taskId);
        if (!$task) {
            return null;
        }

        $task['runs'] = ScheduledTaskRepository::getRunsForTask($taskId, 20);
        $task['versions'] = ScheduledTaskRepository::getVersions($taskId);
        $task['next_runs'] = CronScheduleHelper::getNextRuns((string)$task['cron_expression'], 5);
        $task['is_running'] = ScheduledTaskRepository::isTaskRunning($taskId);

        return $task;
    }
}
