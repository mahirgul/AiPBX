<?php
/**
 * AI → Assistant: turning a VALIDATED plan into a PBX change.
 *
 * The planner never touches the PBX by itself — an action is only ever
 * applied through the same services the portal's own pages use
 * (ExtensionService, RingGroupService, QueueService, TimeConditionService),
 * so an applied change behaves exactly like one made by hand: the same
 * validation, the same pending-sync queue, the same alarms.
 *
 * What this class adds on top:
 *  - the previous value is captured BEFORE the change, so undo is honest;
 *  - one row lands in pbx_ai_changes per applied change (who, when, what
 *    was asked, what was done, and the undo payload);
 *  - rollback() reverses the action with that payload, and records that
 *    it did.
 *
 * Nothing here parses language. It consumes the planner's output array
 * verbatim: if the array is wrong, the planner (and its tests) are wrong.
 */

require_once __DIR__ . '/AssistantPlanner.php';
require_once __DIR__ . '/../RingGroupService.php';
require_once __DIR__ . '/../QueueService.php';
require_once __DIR__ . '/../TimeConditionService.php';

class AssistantPlanService
{
    /** One plan → applied change. Returns the audit row id. */
    public static function apply(array $plan, string $request): int
    {
        if (($plan['ok'] ?? false) !== true) {
            throw new RuntimeException('refusing to apply a plan that is not ok');
        }
        $action = $plan['action'] ?? '';
        $arguments = $plan['arguments'] ?? [];
        $before = self::captureBefore($action, $arguments);

        switch ($action) {
            case 'extension.forward':
                self::applyForward($arguments);
                break;
            case 'extension.voicemail':
                self::applyVoicemail($arguments);
                break;
            case 'extension.rename':
                self::applyRename($arguments);
                break;
            case 'ringgroup.members':
            case 'ringgroup.timeout':
                self::applyRingGroup($arguments);
                break;
            case 'queue.members':
            case 'queue.timeout':
                self::applyQueue($arguments);
                break;
            case 'timecondition.create':
                self::applyTimeCondition($arguments);
                break;
            case 'voicemail.greeting':
                self::applyGreeting($arguments);
                break;
            default:
                throw new RuntimeException("no applier for action {$action}");
        }

        $summary = self::summary($action, $arguments);
        return self::record($request, $plan, $summary, $before, 'applied');
    }

    /** Undo one applied change from its recorded before-value. */
    public static function rollback(int $id): string
    {
        $row = self::get($id);
        if ($row === null) {
            throw new RuntimeException(t('ai_assistant.err_no_row'));
        }
        if (($row['status'] ?? '') !== 'applied' || $row['before_json'] === null || $row['before_json'] === '') {
            throw new RuntimeException(t('ai_assistant.err_not_undoable'));
        }
        $before = json_decode((string) $row['before_json'], true);
        if (!is_array($before) || ($before['undone'] ?? false)) {
            throw new RuntimeException(t('ai_assistant.err_not_undoable'));
        }
        $action = self::UNDO[(string) $row['action']] ?? '';
        if ($action === '') {
            throw new RuntimeException(t('ai_assistant.err_not_undoable'));
        }
        self::run($action, $before['arguments'] ?? []);
        getDB()->prepare("UPDATE pbx_ai_changes SET status = 'rolled_back', rolled_back_at = NOW() WHERE id = ?")
            ->execute([$id]);
        return (string) ($row['summary'] ?? '');
    }

    public static function get(int $id): ?array
    {
        $st = getDB()->prepare('SELECT * FROM pbx_ai_changes WHERE id = ?');
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /** Recent audit rows for the Dialplan editor page. */
    public static function recent(int $limit = 30): array
    {
        $st = getDB()->prepare('SELECT id, ts, user_id, request, action, summary, source, status,
                                       rolled_back_at IS NOT NULL AS rolled_back
                                FROM pbx_ai_changes ORDER BY ts DESC, id DESC LIMIT ' . max(1, min(100, $limit)));
        $st->execute();
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    // ------------------------------------------------------------ appliers

    /** Previous forward state of an extension, for undo. */
    private static function captureBefore(string $action, array $a): ?array
    {
        // Ring groups / queues: capture the exact field this action changes
        // (timeout seconds, membership list) from the current row, so undo
        // re-applies the SAME action with the previous value.
        if (isset($a['group'])) {
            $st = getDB()->prepare('SELECT ring_timeout, numbers_list FROM pbx_ring_groups WHERE id = ?');
            $st->execute([(int) $a['group']]);
            $g = $st->fetch(PDO::FETCH_ASSOC);
            if (!$g) {
                return null;
            }
            $args = ['group' => (int) $a['group']];
            if (isset($a['seconds'])) {
                $args['seconds'] = (int) $g['ring_timeout'];
            }
            if (isset($a['extensions'])) {
                $args['extensions'] = array_values(array_filter(array_map('trim', explode(',', (string) $g['numbers_list']))));
            }
            // Same NESTED shape as the extension path: rollback() reads
            // $before['arguments'] — not the raw field list.
            return ['action' => $action, 'arguments' => $args];
        }
        if (isset($a['queue'])) {
            $st = getDB()->prepare('SELECT timeout, members_json FROM pbx_queues WHERE id = ?');
            $st->execute([(int) $a['queue']]);
            $q = $st->fetch(PDO::FETCH_ASSOC);
            if (!$q) {
                return null;
            }
            $args = ['queue' => (int) $a['queue']];
            if (isset($a['seconds'])) {
                $args['seconds'] = (int) $q['timeout'];
            }
            if (isset($a['extensions'])) {
                $args['extensions'] = array_values(array_filter(array_map('trim', (array) (json_decode((string) ($q['members_json'] ?? '[]'), true) ?: []))));
            }
            // Same NESTED shape as the extension path: rollback() reads
            // $before['arguments'] — not the raw field list.
            return ['action' => $action, 'arguments' => $args];
        }
        $ext = $a['extension'] ?? null;
        if ($ext === null) {
            return null;
        }
        $st = getDB()->prepare('SELECT id, call_forward_number, dnd_enabled, voicemail_enabled, full_name FROM sys_users WHERE extension = ?');
        $st->execute([(string) $ext]);
        $u = $st->fetch(PDO::FETCH_ASSOC);
        if (!$u) {
            return null;
        }
        // One action, one state. Forward and voicemail read different columns,
        // and an action with no undo state (rename, ...) records nothing, so
        // the UI never offers an Undo that cannot work.
        if ($action === 'extension.forward') {
            return [
                'action'    => 'extension.forward',
                'arguments' => [
                    'extension' => $a['extension'],
                    'target'    => (string) ($u['call_forward_number'] ?? ''),
                    'enabled'   => trim((string) ($u['call_forward_number'] ?? '')) !== '',
                ],
            ];
        }
        if ($action === 'extension.voicemail') {
            return [
                'action'    => 'extension.voicemail',
                'arguments' => [
                    'extension' => $a['extension'],
                    'enabled'   => (int) ($u['voicemail_enabled'] ?? 0) === 1,
                ],
            ];
        }
        return null;
    }

    private static function applyForward(array $a): void
    {
        // Mirrors MyPhoneRepository::updatePhoneSettings for the always-on
        // forward case, and through the same dialplan sync path: the value
        // lands in sys_users and DialplanBuilders regenerates the dialplan.
        $st = getDB()->prepare('SELECT id FROM sys_users WHERE extension = ?');
        $st->execute([(string) $a['extension']]);
        $uid = $st->fetchColumn();
        if (!$uid) {
            throw new RuntimeException(t('ai_assistant.err_missing', ['field' => 'extension']));
        }
        getDB()->prepare('UPDATE sys_users SET call_forward_number = ? WHERE id = ?')
            ->execute([$a['enabled'] ? (string) $a['target'] : null, (int) $uid]);
        markPendingSync('extensions', 'extension', (string) $a['extension'],
            'Assistant: forward ' . $a['extension'] . ($a['enabled'] ? ' to ' . $a['target'] : ' off'),
            'update', $_SESSION['user_id'] ?? null);
        markPendingSync('general_dialplan', 'general_dialplan', 'dialplan',
            'Assistant: extension dialplan (' . $a['extension'] . ')', 'update', $_SESSION['user_id'] ?? null);
    }

    private static function applyVoicemail(array $a): void
    {
        $st = getDB()->prepare('SELECT id FROM sys_users WHERE extension = ?');
        $st->execute([(string) $a['extension']]);
        $uid = $st->fetchColumn();
        if (!$uid) {
            throw new RuntimeException(t('ai_assistant.err_missing', ['field' => 'extension']));
        }
        getDB()->prepare('UPDATE sys_users SET voicemail_enabled = ? WHERE id = ?')
            ->execute([$a['enabled'] ? 1 : 0, (int) $uid]);
        // Per-extension voicemail boxes and the dialplan routing for them are
        // generated by the 'extensions' and 'general_dialplan' domains; the
        // 'voicemail' domain only regenerates the general voicemail.conf.
        markPendingSync('extensions', 'extension', (string) $a['extension'],
            'Assistant: voicemail ' . $a['extension'] . ($a['enabled'] ? ' on' : ' off'),
            'update', $_SESSION['user_id'] ?? null);
        markPendingSync('general_dialplan', 'general_dialplan', 'dialplan',
            'Assistant: extension dialplan (' . $a['extension'] . ')', 'update', $_SESSION['user_id'] ?? null);
    }

    private static function applyRename(array $a): void
    {
        $st = getDB()->prepare('SELECT id FROM sys_users WHERE extension = ?');
        $st->execute([(string) $a['extension']]);
        $uid = $st->fetchColumn();
        if (!$uid) {
            throw new RuntimeException(t('ai_assistant.err_missing', ['field' => 'extension']));
        }
        getDB()->prepare('UPDATE sys_users SET full_name = ? WHERE id = ?')
            ->execute([(string) $a['name'], (int) $uid]);
        markPendingSync('extensions', 'extension', (string) $a['extension'],
            'Assistant: renamed extension ' . $a['extension'], 'update', $_SESSION['user_id'] ?? null);
    }

    private static function applyRingGroup(array $a): void
    {
        $st = getDB()->prepare('SELECT * FROM pbx_ring_groups WHERE id = ?');
        $st->execute([(int) $a['group']]);
        $group = $st->fetch(PDO::FETCH_ASSOC);
        if (!$group) {
            throw new RuntimeException(t('ai_assistant.err_missing', ['field' => 'group']));
        }
        // The change rides on the CURRENT record: saveRingGroup writes every
        // column it reads from the form, so a partial payload would reset
        // everything else. Only the changed fields are replaced.
        $data = $group;
        $data['csrf_token'] = self::csrf();
        if (isset($a['seconds'])) {
            $data['ring_timeout'] = (int) $a['seconds'];
        }
        if (isset($a['extensions'])) {
            $data['numbers_list'] = implode(',', (array) $a['extensions']);
        }
        self::assertApplied(RingGroupService::saveRingGroup($data));
    }

    private static function applyQueue(array $a): void
    {
        $st = getDB()->prepare('SELECT * FROM pbx_queues WHERE id = ?');
        $st->execute([(int) $a['queue']]);
        $q = $st->fetch(PDO::FETCH_ASSOC);
        if (!$q) {
            throw new RuntimeException(t('ai_assistant.err_missing', ['field' => 'queue']));
        }
        // Same rule as the ring group: build from the CURRENT record so only
        // what was asked for changes. members are decoded from members_json;
        // a new member_mode array marks every member static, matching how
        // the page's own multi-select posts them.
        $members = is_array(json_decode($q['members_json'] ?? '[]', true))
            ? json_decode($q['members_json'] ?: '[]', true) : [];
        $data = $q;
        // QueueService::saveQueue keys UPDATE vs INSERT on the FORM field
        // `queue_id`, not on the row's `id` — without this the assistant's
        // edit turns into an INSERT and dies with a duplicate queue_name.
        $data['queue_id'] = (int) $q['id'];
        $data['csrf_token'] = self::csrf();
        if (isset($a['seconds'])) {
            $data['timeout'] = (int) $a['seconds'];
        }
        if (isset($a['extensions'])) {
            // Membership is a SET, not a merge: exactly like the page's own
            // multi-select (and ring groups), so the undo's before-value can
            // actually REMOVE members. "add the queue member X" plans carry
            // the final list — the planner merges the current members in.
            $members = array_values(array_unique(array_map('strval', (array) $a['extensions'])));
            $data['members_json'] = null; // recomputed by saveQueue
            $mode = [];
            foreach ($members as $m) {
                $mode[(string) $m] = 'static';
            }
            $data['member_mode'] = $mode;
        }
        self::assertApplied(QueueService::saveQueue($data));
    }

    private static function applyTimeCondition(array $a): void
    {
        self::assertApplied(TimeConditionService::saveTimeCondition([
            'csrf_token' => self::csrf(),
            'title' => $a['title'],
            'time_group_id' => '',
            'open_hour' => $a['open'],
            'close_hour' => $a['close'],
            'days' => implode(',', (array) $a['days']),
        ]));
    }

    private static function applyGreeting(array $a): void
    {
        $st = getDB()->prepare('SELECT id FROM sys_users WHERE extension = ?');
        $st->execute([(string) $a['extension']]);
        $uid = $st->fetchColumn();
        if (!$uid) {
            throw new RuntimeException(t('ai_assistant.err_missing', ['field' => 'extension']));
        }
        // The portal already has a greeting renderer for TTS text. When the
        // column or the renderer is absent on this build, the honest answer is
        // a refusal — not a language change parked in a column that does not
        // exist.
        $col = getDB()->query("SHOW COLUMNS FROM sys_users LIKE 'voicemail_greeting_text'")->fetch();
        if (!$col) {
            throw new RuntimeException(t('ai_assistant.err_not_undoable'));
        }
        getDB()->prepare('UPDATE sys_users SET voicemail_greeting_text = ? WHERE id = ?')
            ->execute([$a['text'], (int) $uid]);
        markPendingSync('voicemail', 'voicemail', 'all', 'Assistant: greeting for ' . $a['extension'],
            'update', $_SESSION['user_id'] ?? null);
    }

    private static function run(string $action, array $a): void
    {
        switch ($action) {
            case 'extension.forward':    self::applyForward($a); return;
            case 'extension.voicemail':  self::applyVoicemail($a); return;
            case 'ringgroup.timeout':
            case 'ringgroup.members':    self::applyRingGroup($a); return;
            case 'queue.timeout':
            case 'queue.members':        self::applyQueue($a); return;
        }
        throw new RuntimeException(t('ai_assistant.err_not_undoable'));
    }

    // ------------------------------------------------------------ plumbing

    /** The portal's own form handlers wrap results in PBXHelper::handleAction():
     *  a failure there is a RETURNED error array, not an exception — surface it
     *  instead of letting an audit row claim victory over a change that never
     *  happened. */
    private static function assertApplied($res): void
    {
        if (!is_array($res) || empty($res['success'])) {
            throw new RuntimeException(sprintf(t('common.error_colon'), (string) ($res['error'] ?? 'unknown')));
        }
    }

    /** A valid CSRF token for service calls (api-side sessions carry a token). */
    private static function csrf(): string
    {
        $tok = $_SESSION['csrf_token'] ?? '';
        return $tok !== '' ? $tok : '';
    }

    private static function record(string $request, array $plan, string $summary, ?array $before, string $status): int
    {
        getDB()->prepare(
            'INSERT INTO pbx_ai_changes
             (ts, user_id, request, action, arguments, summary, source, status, applied_at, before_json)
             VALUES (NOW(), ?, ?, ?, ?, ?, ?, ?, NOW(), ?)'
        )->execute([
            (int) ($_SESSION['user_id'] ?? 0),
            mb_substr($request, 0, 300),
            (string) $plan['action'],
            (string) json_encode($plan['arguments'], JSON_UNESCAPED_UNICODE),
            mb_substr($summary, 0, 300),
            (string) ($plan['source'] ?? 'rules'),
            $status,
            $before !== null ? (string) json_encode($before, JSON_UNESCAPED_UNICODE) : null,
        ]);
        return (int) getDB()->lastInsertId();
    }

    /** Actions undoable via rollback(): action → the action that reverses it. */
    private const UNDO = [
        'extension.forward'   => 'extension.forward',
        'extension.voicemail' => 'extension.voicemail',
        'ringgroup.timeout'   => 'ringgroup.timeout',
        'ringgroup.members'   => 'ringgroup.members',
        'queue.timeout'       => 'queue.timeout',
        'queue.members'       => 'queue.members',
    ];

    /** Human summary for the audit trail. */
    private static function summary(string $action, array $a): string
    {
        switch ($action) {
            case 'extension.forward':
                return $a['enabled']
                    ? 'Forward ' . $a['extension'] . ' → ' . $a['target']
                    : 'Forward off for ' . $a['extension'];
            case 'extension.voicemail':
                return 'Voicemail ' . ($a['enabled'] ? 'on' : 'off') . ' for ' . $a['extension'];
            case 'extension.rename':
                return 'Renamed ' . $a['extension'] . ' to ' . $a['name'];
            case 'ringgroup.members':
                return 'Ring group ' . $a['group'] . ' members: ' . implode(', ', (array) $a['extensions']);
            case 'ringgroup.timeout':
                return 'Ring group ' . $a['group'] . ' timeout: ' . $a['seconds'] . 's';
            case 'queue.members':
                return 'Queue ' . $a['queue'] . ' members: ' . implode(', ', (array) $a['extensions']);
            case 'queue.timeout':
                return 'Queue ' . $a['queue'] . ' timeout: ' . $a['seconds'] . 's';
            case 'timecondition.create':
                return 'Time condition ' . $a['title'];
            case 'voicemail.greeting':
                return 'Greeting for ' . $a['extension'];
        }
        return 'Applied ' . $action;
    }
}
