<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/web/src/helpers.php';
require_once dirname(__DIR__, 2) . '/web/src/asterisk_helper.php';
require_once dirname(__DIR__, 2) . '/web/src/services/AsteriskSettingsService.php';
require_once dirname(__DIR__, 2) . '/web/src/sync/DialplanDestinationBuilder.php';

/**
 * Core service for the `auto-dialer` plugin.
 *
 * Uses `plg_autodial_campaigns` and `plg_autodial_numbers` tables created via `schema.sql`.
 */
final class AutoDialerService
{
    public const ALLOWED_DEST_TYPES = [
        'queue',
        'ivr',
        'extension',
        'ring_group',
        'announcement',
        'ai_app',
    ];

    /**
     * Ensure the plugin's `plg_*` tables exist.
     */
    public static function ensureSchema(): void
    {
        if (class_exists('PluginManager')) {
            PluginManager::applyPluginSchema('auto-dialer', __DIR__);
        }
    }

    /**
     * Return all campaigns with aggregated number counts.
     *
     * @return list<array<string, mixed>>
     */
    public static function listCampaigns(): array
    {
        self::ensureSchema();
        $db = getDB();
        $sql = "SELECT c.*,
                    COUNT(n.id) AS total_numbers,
                    SUM(CASE WHEN n.status = 'pending' THEN 1 ELSE 0 END) AS pending_count,
                    SUM(CASE WHEN n.status = 'dialing' THEN 1 ELSE 0 END) AS dialing_count,
                    SUM(CASE WHEN n.status = 'completed' THEN 1 ELSE 0 END) AS completed_count,
                    SUM(CASE WHEN n.status = 'failed' THEN 1 ELSE 0 END) AS failed_count,
                    SUM(CASE WHEN n.amd_status = 'HUMAN' THEN 1 ELSE 0 END) AS human_count,
                    SUM(CASE WHEN n.amd_status = 'MACHINE' THEN 1 ELSE 0 END) AS machine_count
                FROM plg_autodial_campaigns c
                LEFT JOIN plg_autodial_numbers n ON n.campaign_id = c.id
                GROUP BY c.id
                ORDER BY c.id DESC";
        return $db->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function getCampaign(int $id): ?array
    {
        self::ensureSchema();
        $stmt = getDB()->prepare('SELECT * FROM plg_autodial_campaigns WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Create a new campaign and sync the dialplan immediately.
     *
     * @param array<string, mixed> $input
     */
    public static function createCampaign(array $input): int
    {
        self::ensureSchema();
        $name = trim((string)($input['name'] ?? ''));
        if ($name === '') {
            throw new \InvalidArgumentException('Campaign name is required.');
        }

        $destType = (string)($input['dest_type'] ?? 'queue');
        if (!in_array($destType, self::ALLOWED_DEST_TYPES, true)) {
            $destType = 'queue';
        }
        $destId = preg_replace('/[^a-zA-Z0-9_-]/', '', trim((string)($input['dest_id'] ?? '')));
        if ($destId === '') {
            throw new \InvalidArgumentException('Please select a target destination.');
        }

        $callerId = preg_replace('/[^0-9+]/', '', trim((string)($input['caller_id'] ?? '')));
        $maxConcurrent = max(1, min(50, (int)($input['max_concurrent'] ?? 2)));
        $queuePacing = !empty($input['queue_free_agent_pacing']) ? 1 : 0;
        $maxRetries = max(0, min(10, (int)($input['max_retries'] ?? 2)));
        $retryInterval = max(30, min(86400, (int)($input['retry_interval_sec'] ?? 300)));
        $amdEnabled = !empty($input['amd_enabled']) ? 1 : 0;
        $amdAction = (($input['amd_machine_action'] ?? 'hangup') === 'play_sound') ? 'play_sound' : 'hangup';
        $amdSound = preg_replace('/[^a-zA-Z0-9_\/-]/', '', trim((string)($input['amd_machine_sound'] ?? '')));

        $db = getDB();
        $stmt = $db->prepare(
            "INSERT INTO plg_autodial_campaigns
             (name, status, caller_id, max_concurrent, queue_free_agent_pacing, max_retries, retry_interval_sec, amd_enabled, amd_machine_action, amd_machine_sound, dest_type, dest_id)
             VALUES (?, 'paused', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->execute([
            mb_substr($name, 0, 120),
            $callerId,
            $maxConcurrent,
            $queuePacing,
            $maxRetries,
            $retryInterval,
            $amdEnabled,
            $amdAction,
            $amdSound,
            $destType,
            $destId,
        ]);
        $campId = (int)$db->lastInsertId();

        // Optional bulk numbers submitted during campaign creation
        if (!empty($input['numbers_bulk'])) {
            self::importNumbersFromText($campId, (string)$input['numbers_bulk']);
        }

        self::syncDialplan();
        return $campId;
    }

    public static function setCampaignStatus(int $campaignId, string $status): void
    {
        if (!in_array($status, ['running', 'paused', 'completed'], true)) {
            return;
        }
        $stmt = getDB()->prepare('UPDATE plg_autodial_campaigns SET status = ? WHERE id = ?');
        $stmt->execute([$status, $campaignId]);
        self::syncDialplan();
    }

    public static function deleteCampaign(int $campaignId): void
    {
        $db = getDB();
        $db->prepare('DELETE FROM plg_autodial_numbers WHERE campaign_id = ?')->execute([$campaignId]);
        $db->prepare('DELETE FROM plg_autodial_campaigns WHERE id = ?')->execute([$campaignId]);
        self::syncDialplan();
    }

    public static function resetCampaignNumbers(int $campaignId, bool $onlyFailed = true): int
    {
        $db = getDB();
        if ($onlyFailed) {
            $stmt = $db->prepare(
                "UPDATE plg_autodial_numbers
                 SET status = 'pending', attempts = 0, call_result = '', amd_status = '', amd_cause = '', next_attempt_at = NULL
                 WHERE campaign_id = ? AND status IN ('failed', 'dialing')"
            );
        } else {
            $stmt = $db->prepare(
                "UPDATE plg_autodial_numbers
                 SET status = 'pending', attempts = 0, call_result = '', amd_status = '', amd_cause = '', duration = 0, next_attempt_at = NULL
                 WHERE campaign_id = ?"
            );
        }
        $stmt->execute([$campaignId]);
        $db->prepare("UPDATE plg_autodial_campaigns SET status = 'paused' WHERE id = ?")->execute([$campaignId]);
        return $stmt->rowCount();
    }

    /**
     * Parse lines of `number[,contact_name[,notes]]` and insert into `plg_autodial_numbers`.
     */
    public static function importNumbersFromText(int $campaignId, string $rawText): int
    {
        $lines = preg_split('/\R/', $rawText) ?: [];
        $db = getDB();
        $stmt = $db->prepare(
            "INSERT INTO plg_autodial_numbers (campaign_id, phone_number, contact_name, notes, status)
             VALUES (?, ?, ?, ?, 'pending')"
        );
        $added = 0;
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $parts = str_getcsv($line, str_contains($line, ';') && !str_contains($line, ',') ? ';' : ',');
            $rawPhone = trim((string)($parts[0] ?? ''));
            // Skip CSV header row if present
            if ($added === 0 && preg_match('/^(phone|number|numara|telefon|tel)$/i', $rawPhone)) {
                continue;
            }
            $phone = preg_replace('/[^0-9+]/', '', $rawPhone);
            if ($phone === '' || strlen($phone) < 3) {
                continue;
            }
            $name = mb_substr(trim((string)($parts[1] ?? '')), 0, 120);
            $notes = mb_substr(trim((string)($parts[2] ?? '')), 0, 255);
            $stmt->execute([$campaignId, $phone, $name, $notes]);
            $added++;
        }
        return $added;
    }

    /**
     * List numbers for a campaign.
     *
     * @return list<array<string, mixed>>
     */
    public static function listNumbers(int $campaignId, int $limit = 200): array
    {
        $limit = max(1, min(1000, $limit));
        $stmt = getDB()->prepare(
            "SELECT * FROM plg_autodial_numbers WHERE campaign_id = ? ORDER BY id DESC LIMIT {$limit}"
        );
        $stmt->execute([$campaignId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Count available (logged-in, unpaused, not-in-use, not-ringing) agents in a queue,
     * minus any callers currently waiting in that queue.
     */
    public static function countAvailableQueueAgents(string $queueIdOrName): int
    {
        $db = getDB();
        $stmt = is_numeric($queueIdOrName)
            ? $db->prepare('SELECT queue_name FROM pbx_queues WHERE id = ?')
            : $db->prepare('SELECT queue_name FROM pbx_queues WHERE queue_name = ?');
        $stmt->execute([$queueIdOrName]);
        $queueName = (string)($stmt->fetchColumn() ?: $queueIdOrName);
        $queueName = preg_replace('/[^a-zA-Z0-9_-]/', '', $queueName);
        if ($queueName === '') {
            return 0;
        }

        // In unit-test mode without live Asterisk, return 1 so test ticks can proceed
        if (getenv('AIPBX_NO_ASTERISK') === '1') {
            return 1;
        }

        $res = AsteriskHelper::execCLI("queue show {$queueName}");
        $output = (string)($res['output'] ?? '');
        if ($output === '') {
            return 0;
        }

        $waitingCallers = 0;
        $freeAgents = 0;
        foreach (explode("\n", $output) as $line) {
            $clean = trim((string)preg_replace("/\x1b\[[0-9;]*[a-zA-Z]/", '', $line));
            if ($clean === '') {
                continue;
            }
            if (preg_match('/^[a-zA-Z0-9_-]+\s+has\s+(\d+)\s+calls/i', $clean, $m)) {
                $waitingCallers = (int)$m[1];
                continue;
            }
            if (preg_match('/^\d+\.\s/', $clean)) {
                continue;
            }
            if (preg_match('/(?:PJSIP|Local)\/([0-9a-zA-Z_-]+)/i', $clean)) {
                $isPaused = (bool)preg_match('/\(paused\b/i', $clean);
                $isUnavailable = stripos($clean, '(Unavailable)') !== false || stripos($clean, '(Invalid)') !== false;
                $isBusy = (bool)preg_match('/\((In use|Busy|Ring\+Inuse|On Hold)\)/i', $clean);
                $isRinging = !$isBusy && stripos($clean, '(Ringing)') !== false;
                if (!$isPaused && !$isUnavailable && !$isBusy && !$isRinging) {
                    $freeAgents++;
                }
            }
        }

        return max(0, $freeAgents - $waitingCallers);
    }

    /**
     * Run one dialer cycle across all running campaigns.
     *
     * @param array<string, mixed> $settings
     * @return int Number of calls originated in this tick
     */
    public static function tickCampaigns(array $settings = []): int
    {
        self::ensureSchema();
        $db = getDB();

        // 1. Recover stuck 'dialing' numbers older than max(90s, dial_timeout_sec + 30s)
        $dialTimeoutSec = max(10, min(120, (int)($settings['dial_timeout_sec'] ?? 30)));
        $stuckTimeoutSec = max(90, $dialTimeoutSec + 30);
        $stuckRows = $db->query(
            "SELECT n.id, n.campaign_id, n.attempts, c.max_retries, c.retry_interval_sec
             FROM plg_autodial_numbers n
             JOIN plg_autodial_campaigns c ON c.id = n.campaign_id
             WHERE n.status = 'dialing'
               AND n.last_attempt_at IS NOT NULL
               AND n.last_attempt_at < DATE_SUB(NOW(), INTERVAL {$stuckTimeoutSec} SECOND)"
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];

        foreach ($stuckRows as $st) {
            self::markAttemptFailed(
                (int)$st['id'],
                (int)$st['attempts'],
                (int)$st['max_retries'],
                (int)$st['retry_interval_sec'],
                'NOANSWER'
            );
        }

        // 2. Process running campaigns
        $campaigns = $db->query("SELECT * FROM plg_autodial_campaigns WHERE status = 'running' ORDER BY id ASC")
            ->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $originatedTotal = 0;

        foreach ($campaigns as $camp) {
            $campId = (int)$camp['id'];
            $maxConc = max(1, (int)$camp['max_concurrent']);

            // Queue Free-Agent Pacing: cap concurrency to available idle agents in the target queue
            if ($camp['dest_type'] === 'queue' && !empty($camp['queue_free_agent_pacing'])) {
                $freeAgents = self::countAvailableQueueAgents((string)$camp['dest_id']);
                $maxConc = min($maxConc, $freeAgents);
                if ($maxConc <= 0) {
                    continue;
                }
            }

            $activeStmt = $db->prepare("SELECT COUNT(*) FROM plg_autodial_numbers WHERE campaign_id = ? AND status = 'dialing'");
            $activeStmt->execute([$campId]);
            $activeDialing = (int)$activeStmt->fetchColumn();

            $slots = $maxConc - $activeDialing;
            if ($slots <= 0) {
                continue;
            }

            $dueStmt = $db->prepare(
                "SELECT * FROM plg_autodial_numbers
                 WHERE campaign_id = ?
                   AND status = 'pending'
                   AND (next_attempt_at IS NULL OR next_attempt_at <= NOW())
                 ORDER BY id ASC
                 LIMIT " . (int)$slots
            );
            $dueStmt->execute([$campId]);
            $dueNumbers = $dueStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            if (empty($dueNumbers)) {
                // Check if campaign has any remaining pending or dialing numbers at all
                $remStmt = $db->prepare("SELECT COUNT(*) FROM plg_autodial_numbers WHERE campaign_id = ? AND status IN ('pending', 'dialing')");
                $remStmt->execute([$campId]);
                if ((int)$remStmt->fetchColumn() === 0) {
                    $db->prepare("UPDATE plg_autodial_campaigns SET status = 'completed' WHERE id = ?")->execute([$campId]);
                }
                continue;
            }

            foreach ($dueNumbers as $numRow) {
                $numId = (int)$numRow['id'];
                $phone = preg_replace('/[^0-9+]/', '', (string)$numRow['phone_number']);
                if ($phone === '') {
                    $db->prepare("UPDATE plg_autodial_numbers SET status = 'failed', call_result = 'INVALID' WHERE id = ?")->execute([$numId]);
                    continue;
                }

                // Atomically mark as dialing and increment attempts
                $upd = $db->prepare(
                    "UPDATE plg_autodial_numbers
                     SET status = 'dialing', attempts = attempts + 1, last_attempt_at = NOW()
                     WHERE id = ? AND status = 'pending'"
                );
                $upd->execute([$numId]);
                if ($upd->rowCount() === 0) {
                    continue;
                }

                $cmd = sprintf(
                    'channel originate Local/dial_%d_%s@aipbx-autodial-out/n extension route_%d_%d@aipbx-autodial-amd',
                    $numId,
                    $phone,
                    $campId,
                    $numId
                );
                $res = AsteriskHelper::execCLI($cmd);
                if (!empty($res['success'])) {
                    $originatedTotal++;
                } else {
                    self::markAttemptFailed(
                        $numId,
                        (int)$numRow['attempts'] + 1,
                        (int)$camp['max_retries'],
                        (int)$camp['retry_interval_sec'],
                        'ORIGINATE_FAILED'
                    );
                }
            }
        }

        return $originatedTotal;
    }

    public static function markAttemptFailed(int $numId, int $attempts, int $maxRetries, int $retryIntervalSec, string $result): void
    {
        $db = getDB();
        // Total allowed tries = 1 initial + max_retries
        if ($attempts <= $maxRetries) {
            $stmt = $db->prepare(
                "UPDATE plg_autodial_numbers
                 SET status = 'pending',
                     call_result = ?,
                     next_attempt_at = DATE_ADD(NOW(), INTERVAL ? SECOND)
                 WHERE id = ?"
            );
            $stmt->execute([$result, max(30, $retryIntervalSec), $numId]);
        } else {
            $stmt = $db->prepare(
                "UPDATE plg_autodial_numbers
                 SET status = 'failed',
                     call_result = ?,
                     next_attempt_at = NULL
                 WHERE id = ?"
            );
            $stmt->execute([$result, $numId]);
        }
    }

    /**
     * Handle real-time dialplan callbacks from Asterisk (`/api/plugins/auto-dialer/callback`).
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public static function handleCallback(array $params): array
    {
        self::ensureSchema();
        $numId = (int)($params['num_id'] ?? 0);
        $event = trim((string)($params['event'] ?? ''));
        if ($numId <= 0 || $event === '') {
            return ['success' => false, 'error' => 'Missing num_id or event'];
        }

        $db = getDB();
        $stmt = $db->prepare(
            "SELECT n.*, c.max_retries, c.retry_interval_sec, c.amd_machine_action
             FROM plg_autodial_numbers n
             JOIN plg_autodial_campaigns c ON c.id = n.campaign_id
             WHERE n.id = ?"
        );
        $stmt->execute([$numId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return ['success' => false, 'error' => 'Number record not found'];
        }

        $uniqueId = substr(trim((string)($params['uniqueid'] ?? $row['uniqueid'] ?? '')), 0, 64);

        if ($event === 'dial_failed') {
            // Only mark failed if it wasn't already answered/completed
            if ($row['status'] === 'completed') {
                return ['success' => true, 'state' => 'already_completed'];
            }
            $dialStatus = strtoupper(trim((string)($params['dialstatus'] ?? 'NOANSWER')));
            if ($dialStatus === 'ANSWER') {
                return ['success' => true, 'state' => 'answered'];
            }
            self::markAttemptFailed(
                $numId,
                (int)$row['attempts'],
                (int)$row['max_retries'],
                (int)$row['retry_interval_sec'],
                $dialStatus !== '' ? $dialStatus : 'NOANSWER'
            );
            return ['success' => true, 'state' => 'retry_or_failed', 'dialstatus' => $dialStatus];
        }

        if ($event === 'answered') {
            $amdStatus = strtoupper(trim((string)($params['amd_status'] ?? 'HUMAN')));
            $amdCause = substr(trim((string)($params['amd_cause'] ?? '')), 0, 64);
            $callResult = 'ANSWERED';
            if ($amdStatus === 'MACHINE') {
                $callResult = ($row['amd_machine_action'] === 'play_sound') ? 'MACHINE_MSG' : 'MACHINE_HANGUP';
            }
            $upd = $db->prepare(
                "UPDATE plg_autodial_numbers
                 SET status = 'completed',
                     call_result = ?,
                     amd_status = ?,
                     amd_cause = ?,
                     uniqueid = ?,
                     next_attempt_at = NULL
                 WHERE id = ?"
            );
            $upd->execute([$callResult, $amdStatus, $amdCause, $uniqueId, $numId]);
            return ['success' => true, 'state' => 'completed', 'amd_status' => $amdStatus];
        }

        if ($event === 'hangup') {
            $duration = max(0, (int)($params['duration'] ?? 0));
            $upd = $db->prepare(
                "UPDATE plg_autodial_numbers
                 SET duration = GREATEST(duration, ?),
                     uniqueid = CASE WHEN uniqueid = '' THEN ? ELSE uniqueid END
                 WHERE id = ?"
            );
            $upd->execute([$duration, $uniqueId, $numId]);
            return ['success' => true, 'state' => 'hangup_recorded'];
        }

        return ['success' => false, 'error' => 'Unknown event'];
    }

    /**
     * Build and write `/etc/asterisk/pbx/extensions_plugin_auto-dialer.conf`.
     */
    public static function syncDialplan(?array $settings = null): void
    {
        self::ensureSchema();
        if ($settings === null && class_exists('PluginManager')) {
            $settings = PluginManager::getAllSettings('auto-dialer', false);
        }
        $settings = $settings ?? [];

        $defaultCid = preg_replace('/[^0-9+]/', '', trim((string)($settings['default_caller_id'] ?? '')));
        $dialTimeout = max(10, min(120, (int)($settings['dial_timeout_sec'] ?? 30)));
        $amdArgs = AsteriskSettingsService::amdDialplanArgs();

        $campaigns = getDB()->query('SELECT * FROM plg_autodial_campaigns ORDER BY id ASC')->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $lines = [
            '; Auto-generated by AiPBX Plugin: auto-dialer',
            '',
            '[aipbx-autodial-out]',
            'exten => _dial_X.,1,NoOp(AutoDialer Outbound Leg: ${EXTEN})',
            ' same => n,Set(__AUTODIAL_NUM_ID=${CUT(EXTEN,_,2)})',
            ' same => n,Set(__AUTODIAL_PHONE=${CUT(EXTEN,_,3)})',
            ' same => n,Set(CDR(direction)=outbound)',
        ];
        if ($defaultCid !== '') {
            $lines[] = " same => n,Set(CALLERID(num)={$defaultCid})";
        }
        $lines[] = " same => n,Dial(Local/\${AUTODIAL_PHONE}@from-internal-pbx/n,{$dialTimeout},g)";
        $lines[] = ' same => n,GotoIf($["${DIALSTATUS}" = "ANSWER"]?done)';
        $lines[] = ' same => n,Set(AD_CB=${CURL(http://127.0.0.1/api/plugins/auto-dialer/callback?num_id=${AUTODIAL_NUM_ID}&event=dial_failed&dialstatus=${DIALSTATUS}&uniqueid=${UNIQUEID})})';
        $lines[] = ' same => n(done),Hangup()';
        $lines[] = '';

        $lines[] = '[aipbx-autodial-amd]';
        $lines[] = 'exten => _route_X.,1,NoOp(AutoDialer Answered Leg: ${EXTEN})';
        $lines[] = ' same => n,Set(__AUTODIAL_CAMP_ID=${CUT(EXTEN,_,2)})';
        $lines[] = ' same => n,Set(__AUTODIAL_NUM_ID=${CUT(EXTEN,_,3)})';
        $lines[] = ' same => n,Goto(aipbx-autodial-camp-${AUTODIAL_CAMP_ID},s,1)';
        $lines[] = '';

        foreach ($campaigns as $c) {
            $cid = (int)$c['id'];
            $lines[] = "[aipbx-autodial-camp-{$cid}]";
            $lines[] = "exten => s,1,NoOp(AutoDialer Campaign #{$cid}: " . toCleanAscii((string)$c['name']) . ')';
            $lines[] = ' same => n,Answer()';
            if (!empty($c['caller_id'])) {
                $campCid = preg_replace('/[^0-9+]/', '', (string)$c['caller_id']);
                if ($campCid !== '') {
                    $lines[] = " same => n,Set(CALLERID(num)={$campCid})";
                }
            }

            if (!empty($c['amd_enabled'])) {
                $lines[] = " same => n,AMD({$amdArgs})";
                $lines[] = ' same => n,NoOp(AMD Result: status=${AMDSTATUS} cause=${AMDCAUSE})';
                $lines[] = ' same => n,Set(AD_CB=${CURL(http://127.0.0.1/api/plugins/auto-dialer/callback?num_id=${AUTODIAL_NUM_ID}&event=answered&amd_status=${AMDSTATUS}&amd_cause=${URIENCODE(${AMDCAUSE})}&uniqueid=${UNIQUEID})})';
                $lines[] = ' same => n,GotoIf($["${AMDSTATUS}" = "MACHINE"]?machine:human)';

                // MACHINE branch
                $lines[] = ' same => n(machine),NoOp(AutoDialer AMD detected MACHINE)';
                if (($c['amd_machine_action'] ?? 'hangup') === 'play_sound' && !empty($c['amd_machine_sound'])) {
                    $snd = preg_replace('/[^a-zA-Z0-9_\/-]/', '', (string)$c['amd_machine_sound']);
                    $sndPath = str_starts_with($snd, 'custom/') ? $snd : 'custom/' . $snd;
                    $lines[] = ' same => n,WaitForSilence(1500,1,15)';
                    $lines[] = " same => n,Playback({$sndPath})";
                }
                $lines[] = ' same => n,Hangup()';

                // HUMAN / NOTSURE branch
                $lines[] = ' same => n(human),NoOp(AutoDialer AMD detected HUMAN -> routing to destination)';
            } else {
                $lines[] = ' same => n,Set(AD_CB=${CURL(http://127.0.0.1/api/plugins/auto-dialer/callback?num_id=${AUTODIAL_NUM_ID}&event=answered&amd_status=BYPASSED&amd_cause=DISABLED&uniqueid=${UNIQUEID})})';
            }

            $destLines = buildDestinationLines((string)$c['dest_type'], (string)$c['dest_id']);
            foreach (explode("\n", (string)$destLines) as $dl) {
                if (trim($dl) !== '') {
                    $lines[] = $dl;
                }
            }
            $lines[] = ' same => n,Hangup()';
            $lines[] = 'exten => h,1,Set(AD_H=${CURL(http://127.0.0.1/api/plugins/auto-dialer/callback?num_id=${AUTODIAL_NUM_ID}&event=hangup&duration=${CDR(billsec)}&uniqueid=${UNIQUEID})})';
            $lines[] = '';
        }

        $content = implode("\n", $lines) . "\n";
        if (function_exists('writePBXConf')) {
            writePBXConf('extensions_plugin_auto-dialer.conf', $content);
            AsteriskHelper::reloadDialplan();
        }
    }
}
