<?php
declare(strict_types=1);

require_once __DIR__ . '/QueueCallbackService.php';
require_once __DIR__ . '/../repositories/QueueRepository.php';

final class QueueCallbackDispatcher
{
    /**
     * Dispatch oldest available requests using current queue membership and device state.
     *
     * @param callable(): array $readQueues Live queue status from Asterisk
     * @param callable(array, string, int, int): bool $originate Starts the agent-first call
     */
    public static function dispatch(callable $readQueues, callable $originate): int
    {
        $db = getDB();
        $queues = $db->query('SELECT q.id, q.queue_name, q.members_json, q.wrapuptime
            FROM pbx_queues q WHERE q.is_active = 1 AND q.callback_enabled = 1 AND q.callback_auto_enabled = 1
            AND EXISTS (SELECT 1 FROM cc_callbacks c WHERE c.queue_id = q.id
                AND c.auto_attempts < ' . QueueCallbackService::MAX_AUTOMATIC_ATTEMPTS . '
                AND (c.last_auto_result IS NULL OR c.last_auto_result <> \'customer_answered\')
                AND (c.claimed_until IS NULL OR c.claimed_until <= NOW()))')->fetchAll(PDO::FETCH_ASSOC);
        if (!$queues || (int) DBHelper::fetchColumn("SELECT GET_LOCK('aipbx-automatic-callbacks', 0)") !== 1) {
            return 0;
        }
        try {
            return self::dispatchReady($queues, $readQueues(), $originate);
        } finally {
            DBHelper::fetchColumn("SELECT RELEASE_LOCK('aipbx-automatic-callbacks')");
        }
    }

    private static function dispatchReady(array $queues, array $liveQueues, callable $originate): int
    {
        $busy = [];
        foreach ($liveQueues as $live) {
            foreach ($live['members'] as $extension => $member) {
                if ($member['is_busy'] || $member['is_ringing'] || ($live['waiting_count'] > 0 && !$member['is_paused'])) {
                    $busy[$extension] = true;
                }
            }
        }

        $roles = QueueRepository::AGENT_ROLES;
        $roleArgs = implode(',', array_fill(0, count($roles), '?'));
        $lookupAgent = getDB()->prepare("SELECT id, extension, outbound_group, permission_group_id
            FROM sys_users u WHERE extension = ? AND COALESCE(is_active, 1) = 1 AND COALESCE(dnd_enabled, 0) = 0
            AND COALESCE(extension_type, '') <> 'fax' AND (role IN ($roleArgs) OR EXISTS
                (SELECT 1 FROM sys_user_roles r WHERE r.user_id = u.id AND r.role_key IN ($roleArgs)))
            ORDER BY id LIMIT 1");

        $agentsByQueue = [];
        $requests = [];
        foreach ($queues as $queue) {
            $live = $liveQueues[$queue['queue_name']] ?? null;
            if (!$live || $live['waiting_count'] > 0) {
                continue;
            }
            $queueId = (int) $queue['id'];
            $members = array_map('strval', json_decode((string) $queue['members_json'], true) ?: []);
            foreach ($members as $extension) {
                $member = $live['members'][$extension] ?? null;
                if (isset($busy[$extension]) || !preg_match('/^[0-9]{1,20}$/D', $extension)
                    || !$member || !self::isReady($member, max(0, (int) $queue['wrapuptime']))) {
                    continue;
                }
                $lookupAgent->execute([$extension, ...$roles, ...$roles]);
                $agent = $lookupAgent->fetch(PDO::FETCH_ASSOC);
                if ($agent) {
                    $agentsByQueue[$queueId][] = $agent;
                }
            }
            $max = count($agentsByQueue[$queueId] ?? []);
            if ($max === 0) {
                continue;
            }
            $pending = getDB()->prepare('SELECT id, queue_id, requested_at FROM cc_callbacks WHERE queue_id = ?
                AND auto_attempts < ' . QueueCallbackService::MAX_AUTOMATIC_ATTEMPTS . '
                AND (last_auto_result IS NULL OR last_auto_result <> \'customer_answered\')
                AND (claimed_until IS NULL OR claimed_until <= NOW()) ORDER BY requested_at, id LIMIT ' . $max);
            $pending->execute([$queueId]);
            array_push($requests, ...$pending->fetchAll(PDO::FETCH_ASSOC));
        }
        usort($requests, static fn (array $a, array $b): int => [$a['requested_at'], $a['id']] <=> [$b['requested_at'], $b['id']]);

        $count = 0;
        foreach ($requests as $request) {
            foreach ($agentsByQueue[(int) $request['queue_id']] as $agent) {
                $extension = (string) $agent['extension'];
                if (isset($busy[$extension])) {
                    continue;
                }
                $number = QueueCallbackService::claim((int) $request['id'], $extension, true);
                if ($number === null) {
                    continue;
                }
                // Keep the reservation even if the AMI response is lost. Asterisk may have queued the call.
                $busy[$extension] = true;
                $id = (int) $request['id'];
                $attempt = QueueCallbackService::automaticAttempt($id);
                if ($attempt === 0) {
                    break;
                }
                $accepted = $originate($agent, $number, $id, $attempt);
                QueueCallbackService::recordAutomaticResult($id, $attempt, $accepted ? 'queued' : 'start_unconfirmed');
                if ($accepted) {
                    $count++;
                }
                break;
            }
        }
        return $count;
    }

    private static function isReady(array $member, int $wrapup): bool
    {
        $line = $member['raw_line'];
        return !$member['is_paused'] && !$member['is_unavailable'] && stripos($line, '(Not in use)') !== false
            && (!preg_match('/last (?:was )?(\d+) secs? ago/i', $line, $m) || (int) $m[1] >= $wrapup);
    }
}
