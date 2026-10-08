<?php
require_once dirname(__DIR__) . '/asterisk_helper.php';

/**
 * BLF lamps on desk phones beyond the extensions themselves: do-not-disturb,
 * call forwarding and queue login of each user. A phone watches them like an
 * extension, with the key value DND1001, CF1001 or QUEUE1001
 * (SyncGeneralDialplan writes the hints). The states are Asterisk custom
 * device states (Custom:DND1001 …):
 *
 *   DND   INUSE while do-not-disturb is on
 *   CF    INUSE while unconditional call forwarding is set
 *   QUEUE INUSE while logged in to a queue and not on a break
 *
 * Only changed lamps are sent (pbx_lamp_states keeps what was pushed last).
 */
class LampService
{
    const KINDS = ['DND', 'CF', 'QUEUE'];

    /** @return array<string, string> lamp name (e.g. DND1001) => INUSE | NOT_INUSE */
    public static function desired(): array
    {
        $users = getDB()->query(
            "SELECT extension, dnd_enabled, call_forward_number FROM sys_users
             WHERE extension IS NOT NULL AND extension <> '' AND is_active = 1 AND extension_type = 'sip'"
        )->fetchAll(PDO::FETCH_ASSOC);
        $inQueue = self::loggedInToQueues();
        $out = [];
        foreach ($users as $u) {
            $ext = preg_replace('/[^0-9]/', '', (string) $u['extension']);
            if ($ext === '') {
                continue;
            }
            $out["DND{$ext}"] = !empty($u['dnd_enabled']) ? 'INUSE' : 'NOT_INUSE';
            $out["CF{$ext}"] = trim((string) ($u['call_forward_number'] ?? '')) !== '' ? 'INUSE' : 'NOT_INUSE';
            $out["QUEUE{$ext}"] = isset($inQueue[$ext]) ? 'INUSE' : 'NOT_INUSE';
        }
        return $out;
    }

    /**
     * Extensions that are a member of at least one queue and not paused there,
     * read from `queue show` (dynamic logins live only in Asterisk).
     *
     * @return array<string, true>
     */
    public static function loggedInToQueues(?string $queueShow = null): array
    {
        if ($queueShow === null) {
            $res = AsteriskHelper::execCLI('queue show');
            $queueShow = (string) ($res['output'] ?? '');
        }
        $out = [];
        foreach (preg_split('/\r?\n/', $queueShow) as $line) {
            if (preg_match('#Local/(\d+)@from-internal-pbx(?:-ortak)?/n#', $line, $m) && stripos($line, '(paused') === false) {
                $out[$m[1]] = true;
            }
        }
        return $out;
    }

    /**
     * Pushes the lamps that changed since the last push (all of them with
     * $force, e.g. after an install when Asterisk's own store may be empty).
     *
     * @return int number of lamps sent
     */
    public static function refresh(bool $force = false): int
    {
        try {
            $db = getDB();
            $want = self::desired();
            $have = $force ? [] : $db->query('SELECT name, state FROM pbx_lamp_states')->fetchAll(PDO::FETCH_KEY_PAIR);
            $upsert = $db->prepare('INSERT INTO pbx_lamp_states (name, state, updated_at) VALUES (?, ?, NOW())
                                    ON DUPLICATE KEY UPDATE state = VALUES(state), updated_at = NOW()');
            $sent = 0;
            foreach ($want as $name => $state) {
                if (($have[$name] ?? null) === $state) {
                    continue;
                }
                $res = AsteriskHelper::execCLI("devstate change Custom:{$name} {$state}");
                if (!empty($res['success'])) {
                    $upsert->execute([$name, $state]);
                    $sent++;
                }
            }
            // Users that are gone: forget their lamps.
            $gone = array_diff(array_keys($have), array_keys($want));
            if ($gone) {
                $del = $db->prepare('DELETE FROM pbx_lamp_states WHERE name = ?');
                foreach ($gone as $name) {
                    $del->execute([$name]);
                }
            }
            return $sent;
        } catch (\Throwable $e) {
            // A lamp must never break saving a setting or a feature code.
            error_log('LampService::refresh: ' . $e->getMessage());
            return 0;
        }
    }
}
