<?php
/**
 * Flexible Key-Value Queue Details Helper Module
 * Interacts with MariaDB `queues_details` table (`id`, `keyword`, `data`, `flags`)
 */

require_once __DIR__ . '/../config.php';

class QueueHelper {

    /**
     * Get raw details array for a queue name
     */
    public static function getDetails($q_name) {
        $db = getDB();
        $stmt = $db->prepare("SELECT keyword, data, flags FROM queues_details WHERE id = ? ORDER BY flags ASC, keyword ASC");
        $stmt->execute([$q_name]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Get details as associative map ['keyword' => 'data']
     */
    public static function getDetailsMap($q_name) {
        $rows = self::getDetails($q_name);
        $map = [];
        foreach ($rows as $r) {
            $map[$r['keyword']] = $r['data'];
        }
        return $map;
    }

    /**
     * Bulk upsert multiple queue details
     */
    public static function setDetails($q_name, array $pairs, $flags = 0) {
        $db = getDB();
        $stmt = $db->prepare("INSERT INTO queues_details (id, keyword, data, flags) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE data = VALUES(data), flags = VALUES(flags)");
        foreach ($pairs as $key => $val) {
            if ($val === null) continue;
            $stmt->execute([$q_name, (string)$key, (string)$val, (int)$flags]);
        }
        return true;
    }

    /**
     * Delete all details for a queue
     */
    public static function deleteDetails($q_name) {
        $db = getDB();
        $stmt = $db->prepare("DELETE FROM queues_details WHERE id = ?");
        return $stmt->execute([$q_name]);
    }

    /**
     * Populate standard queue parameters into `queues_details`
     */
    public static function syncQueueToDetails(array $q) {
        $q_name = trim($q['queue_name'] ?? '');
        if (empty($q_name)) return false;

        $strategy = !empty($q['strategy']) ? $q['strategy'] : 'rrmemory';
        $timeout = intval($q['timeout'] ?? 15) ?: 15;
        $retry = intval($q['retry'] ?? 5) ?: 5;
        $wrapuptime = intval($q['wrapuptime'] ?? 10) ?: 10;
        $announce_frequency = intval($q['announce_frequency'] ?? 30) ?: 30;
        $announce_holdtime = !empty($q['announce_holdtime']) ? $q['announce_holdtime'] : 'yes';
        $ringinuse = ($q['ringinuse'] ?? 'no') === 'yes' ? 'yes' : 'no';
        $joinempty = ($q['joinempty'] ?? 'yes') === 'no' ? 'no' : 'yes';
        $leavewhenempty = ($q['leavewhenempty'] ?? 'no') === 'yes' ? 'yes' : 'no';
        $maxlen = intval($q['maxlen'] ?? 0);

        // The per-queue monitor-type directive is missing here on purpose:
        // Asterisk 22 accepts it only in the queues.conf [general] section (see
        // the global "monitor-type = MixMonitor" line in SyncQueues.php);
        // written in a per-queue section it gives an "Unknown keyword" warning.
        $defaults = [
            'strategy' => $strategy,
            'timeout' => (string)$timeout,
            'retry' => (string)$retry,
            'wrapuptime' => (string)$wrapuptime,
            'autofill' => 'yes',
            'ringinuse' => $ringinuse,
            'announce-frequency' => (string)$announce_frequency,
            'announce-holdtime' => $announce_holdtime,
            'announce-position' => 'yes',
            'periodic-announce-frequency' => '45',
            // Asterisk's default "queue-periodic-announce" file is missing from
            // the Turkish sound pack (only en/es/fr/pr/pt_BR have it) — when it
            // is not found Asterisk silently falls back to English. Listing the
            // youarenext+thankyou prompts, which exist in Turkish, makes them
            // repeat in turn periodically (2026-08-31, user request).
            'periodic-announce' => 'queue-youarenext,queue-thankyou',
            'monitor-format' => 'wav',
            'joinempty' => $joinempty,
            'leavewhenempty' => $leavewhenempty,
            'maxlen' => (string)$maxlen,
            'setinterfacevar' => 'yes',
            'setqueueentryvar' => 'yes',
            'setqueuevar' => 'yes'
        ];

        return self::setDetails($q_name, $defaults);
    }

    /**
     * Checks whether an extension is assigned to a queue in the DB ($members_json).
     * Independent of the live Asterisk membership — the question is "may this
     * person work in this queue".
     */
    public static function isAssignedMember($ext, $queue_name) {
        $db = getDB();
        $stmt = $db->prepare("SELECT members_json FROM pbx_queues WHERE queue_name = ? AND is_active = 1");
        $stmt->execute([$queue_name]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) return false;
        $members = json_decode($row['members_json'] ?? '[]', true) ?: [];
        return in_array((string)$ext, array_map('strval', $members));
    }

    /**
     * Static agent: written to queues_pbx.conf as a "member =>" line, is in
     * the queue as soon as Asterisk starts and CANNOT be removed from the
     * queue by CLI/panel/feature code — it can only pause. A dynamic agent
     * logs in and out of the queue itself.
     * static_members_json is always a subset of members_json (see QueueService).
     */
    public static function staticMembersOf(array $q_row) {
        $members = array_map('strval', json_decode($q_row['members_json'] ?? '[]', true) ?: []);
        $static = array_map('strval', json_decode($q_row['static_members_json'] ?? '[]', true) ?: []);
        return array_values(array_intersect($static, $members));
    }

    public static function isStaticMember($ext, $queue_name) {
        $db = getDB();
        $stmt = $db->prepare("SELECT members_json, static_members_json FROM pbx_queues WHERE queue_name = ? AND is_active = 1");
        $stmt->execute([$queue_name]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) return false;
        return in_array((string)$ext, self::staticMembersOf($row), true);
    }

    /**
     * Names of the active queues the extension is a static agent of.
     */
    public static function staticQueuesOf($ext) {
        $db = getDB();
        $result = [];
        foreach ($db->query("SELECT queue_name, members_json, static_members_json FROM pbx_queues WHERE is_active = 1")->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (in_array((string)$ext, self::staticMembersOf($row), true)) {
                $result[] = $row['queue_name'];
            }
        }
        return $result;
    }

    /**
     * Adds/removes an extension to/from a queue live (asterisk -rx "queue
     * add/remove member ..."). Both the "Join/Leave queue" button in the web
     * panel (api/cc_actions/queues.php) and the phone feature codes (*81/*80,
     * see feature_code_action.php) use this single place.
     */
    public static function setMembership($ext, $queue_name, $join) {
        $ext = preg_replace('/[^0-9]/', '', (string)$ext);
        $queue_name = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)$queue_name);
        if ($ext === '' || $queue_name === '') return false;

        // A static agent cannot be removed from the queue (Asterisk refuses
        // with "Not dynamic" anyway); membership from the config is left
        // alone and false is returned. On login it is only unpaused (the pause
        // record is closed together with the login).
        if (self::isStaticMember($ext, $queue_name)) {
            if (!$join) return false;
            @exec("asterisk -rx " . escapeshellarg("queue unpause member Local/$ext@from-internal-pbx/n queue $queue_name"));
            return true;
        }

        if ($join) {
            @exec("asterisk -rx " . escapeshellarg("queue add member Local/$ext@from-internal-pbx/n to $queue_name penalty 0 as \"Temsilci $ext\" state_interface hint:$ext@from-internal-pbx"));
            @exec("asterisk -rx " . escapeshellarg("queue unpause member Local/$ext@from-internal-pbx/n queue $queue_name"));
        } else {
            @exec("asterisk -rx " . escapeshellarg("queue remove member Local/$ext@from-internal-pbx/n from $queue_name"));
            @exec("asterisk -rx " . escapeshellarg("queue remove member PJSIP/$ext from $queue_name"));
        }
        return true;
    }

    /**
     * Pauses the agent in the queues (queue pause member).
     * Both the "Pause" button in the web panel and the phone feature code
     * (*22<pause_id>) use this shared function.
     *
     * @param string|int $ext Extension number
     * @param string $reason Pause reason (e.g. "Lunch break")
     * @param string|null $queue_name A specific queue name or null (all assigned queues)
     * @return bool
     */
    public static function pauseMember($ext, $reason = 'Mola', $queue_name = null) {
        $ext = preg_replace('/[^0-9]/', '', (string)$ext);
        if ($ext === '') return false;
        $reason_cli = str_replace('"', '', trim($reason ?: 'Mola'));

        self::pauseInAsterisk($ext, $reason_cli, $queue_name);

        $db = getDB();
        // Get the agent's name from sys_users
        $stmt_user = $db->prepare("SELECT full_name FROM sys_users WHERE extension = ?");
        $stmt_user->execute([$ext]);
        $agent_name = $stmt_user->fetchColumn() ?: "Temsilci $ext";

        // cc_pause_logs tablosuna kaydet
        $stmt_close = $db->prepare("UPDATE cc_pause_logs SET end_time = NOW(), duration = TIMESTAMPDIFF(SECOND, start_time, NOW()), status = 'COMPLETED' WHERE agent_extension = ? AND status = 'PAUSED'");
        $stmt_close->execute([$ext]);

        $stmt_insert = $db->prepare("INSERT INTO cc_pause_logs (agent_extension, agent_name, pause_reason, start_time, status) VALUES (?, ?, ?, NOW(), 'PAUSED')");
        $stmt_insert->execute([$ext, $agent_name, $reason_cli]);

        return true;
    }

    /**
     * Applies the pause on the Asterisk side only (does not touch cc_pause_logs).
     *
     * Asterisk syntax: queue pause member <member> [queue <queue> [reason <reason>]]
     * — a reason can ONLY be given together with a queue. The
     * "…member X reason Y" form is rejected with "Usage" (which is why the
     * Pause button on the agent screen never paused in Asterisk at all).
     * First a general pause without a reason, then a pause with the reason in
     * every queue the agent is a member of.
     */
    public static function pauseInAsterisk(string $ext, string $reason, ?string $queue_name = null): void {
        $ext = preg_replace('/[^0-9]/', '', $ext);
        if ($ext === '' || getenv('AIPBX_NO_ASTERISK') === '1') return;
        $reason_cli = str_replace('"', '', trim($reason) ?: 'Mola');

        $target_queues = [];
        if (!empty($queue_name)) {
            $target_queues = [preg_replace('/[^a-zA-Z0-9_-]/', '', $queue_name)];
        } else {
            $stmt = getDB()->query("SELECT queue_name, members_json FROM pbx_queues WHERE is_active = 1");
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $q_row) {
                $mems = json_decode($q_row['members_json'] ?? '[]', true) ?: [];
                if (in_array($ext, array_map('strval', $mems), true)) {
                    $target_queues[] = $q_row['queue_name'];
                }
            }
        }

        @exec("asterisk -rx " . escapeshellarg("queue pause member Local/$ext@from-internal-pbx/n"));
        @exec("asterisk -rx " . escapeshellarg("queue pause member PJSIP/$ext"));
        foreach ($target_queues as $qn) {
            @exec("asterisk -rx " . escapeshellarg("queue pause member Local/$ext@from-internal-pbx/n queue $qn reason \"$reason_cli\""));
            @exec("asterisk -rx " . escapeshellarg("queue pause member PJSIP/$ext queue $qn reason \"$reason_cli\""));
        }
    }

    /**
     * Unpauses the agent (queue unpause member).
     *
     * @param string|int $ext Extension number
     * @param string|null $queue_name A specific queue name or null (all queues)
     * @return bool
     */
    public static function unpauseMember($ext, $queue_name = null) {
        $ext = preg_replace('/[^0-9]/', '', (string)$ext);
        if ($ext === '') return false;

        $db = getDB();
        if (!empty($queue_name)) {
            $qn = preg_replace('/[^a-zA-Z0-9_-]/', '', $queue_name);
            @exec("asterisk -rx " . escapeshellarg("queue unpause member Local/$ext@from-internal-pbx/n queue $qn"));
            @exec("asterisk -rx " . escapeshellarg("queue unpause member PJSIP/$ext queue $qn"));
        } else {
            @exec("asterisk -rx " . escapeshellarg("queue unpause member Local/$ext@from-internal-pbx/n"));
            @exec("asterisk -rx " . escapeshellarg("queue unpause member PJSIP/$ext"));
        }

        // Close the cc_pause_logs record
        $stmt_close = $db->prepare("UPDATE cc_pause_logs SET end_time = NOW(), duration = TIMESTAMPDIFF(SECOND, start_time, NOW()), status = 'COMPLETED' WHERE agent_extension = ? AND status = 'PAUSED'");
        $stmt_close->execute([$ext]);

        return true;
    }
}
