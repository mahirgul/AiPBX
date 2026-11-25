<?php
declare(strict_types=1);

require_once __DIR__ . '/../db_helper.php';
require_once __DIR__ . '/../asterisk_helper.php';

/** Pending requests only: answered conversations remove them; a failed dial never does. */
final class QueueCallbackService
{
    public const MAX_AUTOMATIC_ATTEMPTS = 3;
    public const AUTO_AGENT_CONTEXT = 'aipbx-auto-callback-agent';
    public const AUTO_OUTBOUND_CONTEXT = 'aipbx-auto-callback-out';

    public static function phone(string $number): string
    {
        $number = trim($number);
        if (!preg_match('/^\+?[0-9]{3,20}$/D', $number)) {
            return '';
        }
        // + and 00 denote the same international prefix. Do not guess country codes.
        return str_starts_with($number, '00') ? substr($number, 2) : ltrim($number, '+');
    }

    public static function request(int $queueId, string $number): bool
    {
        $phone = self::phone($number);
        if ($phone === '') {
            return false;
        }
        $st = getDB()->prepare('INSERT INTO cc_callbacks (queue_id, phone, dial_number)
            SELECT id, ?, ? FROM pbx_queues WHERE id = ? AND is_active = 1 AND callback_enabled = 1
            ON DUPLICATE KEY UPDATE phone = VALUES(phone)');
        $st->execute([$phone, trim($number), $queueId]);
        // A duplicate is also a successful request; keep its original place in the list.
        return (bool) DBHelper::fetchColumn('SELECT 1 FROM cc_callbacks WHERE queue_id = ? AND phone = ?', [$queueId, $phone]);
    }

    /** @return list<int> */
    public static function queuesFor(string $extension): array
    {
        if ($extension === '') {
            return [];
        }
        $ids = [];
        foreach (getDB()->query('SELECT id, members_json FROM pbx_queues WHERE is_active = 1')->fetchAll(PDO::FETCH_ASSOC) as $q) {
            if (in_array($extension, array_map('strval', json_decode($q['members_json'] ?? '[]', true) ?: []), true)) {
                $ids[] = (int) $q['id'];
            }
        }
        return $ids;
    }

    /** @return list<array<string, mixed>> */
    public static function pending(string $extension): array
    {
        $ids = self::queuesFor($extension);
        if ($ids === []) {
            return [];
        }
        $st = getDB()->prepare('SELECT c.*, q.title AS queue_title,
            (c.claimed_until IS NOT NULL AND c.claimed_until > NOW()) AS calling,
            (q.callback_enabled = 1 AND q.callback_auto_enabled = 1) AS automatic_enabled
            FROM cc_callbacks c JOIN pbx_queues q ON q.id = c.queue_id
            WHERE c.queue_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')
            ORDER BY c.requested_at, c.id LIMIT 500');
        $st->execute($ids);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        $live = null;
        foreach ($rows as &$row) {
            $attempts = (int) $row['auto_attempts'];
            $row['automatic_remaining'] = max(0, self::MAX_AUTOMATIC_ATTEMPTS - $attempts);
            $row['next_auto_at'] = !empty($row['automatic_enabled']) && $row['automatic_remaining'] > 0
                && !empty($row['calling'])
                && ($row['last_auto_result'] ?? '') !== 'customer_answered'
                ? $row['claimed_until']
                : null;
            if ($attempts > 0 && empty($row['calling'])) {
                $live ??= self::activeAutomaticCalls();
                $row['calling'] = isset($live[(int) $row['id']]) || isset($live['unknown']);
            }
        }
        unset($row);
        return $rows;
    }

    /** Atomically reserve a pending request so simultaneous clicks cannot originate twice. */
    public static function claim(int $id, string $extension, bool $automatic = false): ?string
    {
        if (!preg_match('/^[0-9]{1,20}$/D', $extension)) {
            return null;
        }
        $ids = self::queuesFor($extension);
        if ($ids === []) {
            return null;
        }
        $db = getDB();
        $lock = 'aipbx-callback-agent-' . $extension;
        if ((int) DBHelper::fetchColumn('SELECT GET_LOCK(?, 0)', [$lock]) !== 1) {
            return null;
        }
        try {
            if (DBHelper::fetchColumn('SELECT 1 FROM cc_callbacks WHERE claimed_by = ? AND claimed_until > NOW() LIMIT 1', [$extension])) {
                return null;
            }
            $request = DBHelper::fetchOne('SELECT auto_attempts, (claimed_until > NOW()) AS calling FROM cc_callbacks WHERE id = ?', [$id]);
            if (!$request || !empty($request['calling'])
                || ((int) $request['auto_attempts'] > 0 && self::hasActiveAutomaticCall($id))) {
                return null;
            }

            $increment = $automatic ? ", c.auto_attempts = c.auto_attempts + 1, c.last_auto_attempt_at = NOW(), c.last_auto_result = 'starting'" : '';
            $autoCheck = $automatic
                ? ' AND q.callback_enabled = 1 AND q.callback_auto_enabled = 1 AND c.auto_attempts < ' . self::MAX_AUTOMATIC_ATTEMPTS
                : '';
            $st = $db->prepare('UPDATE cc_callbacks c JOIN pbx_queues q ON q.id = c.queue_id
                SET c.claimed_by = ?, c.claimed_until = DATE_ADD(NOW(), INTERVAL 2 MINUTE)' . $increment . '
                WHERE c.id = ? AND q.is_active = 1 AND (c.claimed_until IS NULL OR c.claimed_until <= NOW())
                AND c.queue_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')' . $autoCheck);
            $st->execute(array_merge([$extension, $id], $ids));
            if ($st->rowCount() !== 1) {
                return null;
            }
            $number = DBHelper::fetchColumn('SELECT dial_number FROM cc_callbacks WHERE id = ?', [$id]);
            return is_string($number) && self::phone($number) !== '' ? $number : null;
        } finally {
            DBHelper::fetchColumn('SELECT RELEASE_LOCK(?)', [$lock]);
        }
    }

    /** A Local /n channel remains visible throughout an answered callback. */
    private static function activeAutomaticCalls(): array
    {
        $channels = AsteriskHelper::execCLI('core show channels concise');
        if (!$channels['success']) {
            return ['unknown' => true];
        }
        preg_match_all('/^Local\/([0-9]+)-[0-9]+@' . self::AUTO_AGENT_CONTEXT . '-/m', $channels['output'], $matches);
        return array_fill_keys($matches[1], true);
    }

    private static function hasActiveAutomaticCall(int $id): bool
    {
        $live = self::activeAutomaticCalls();
        return isset($live[$id]) || isset($live['unknown']);
    }

    public static function automaticAttempt(int $id): int
    {
        return (int) DBHelper::fetchColumn('SELECT auto_attempts FROM cc_callbacks WHERE id = ?', [$id]);
    }

    /** A delayed signal from a previous attempt or earlier stage cannot overwrite the current result. */
    public static function recordAutomaticResult(int $id, int $attempt, string $result): void
    {
        $from = match ($result) {
            'queued', 'start_unconfirmed' => ['starting'],
            'agent_unanswered', 'calling_customer' => ['starting', 'queued', 'start_unconfirmed'],
            'customer_unanswered', 'customer_answered' => ['starting', 'queued', 'start_unconfirmed', 'calling_customer'],
            default => [],
        };
        if ($id <= 0 || $attempt <= 0 || !$from) {
            return;
        }
        $st = getDB()->prepare('UPDATE cc_callbacks SET last_auto_result = ? WHERE id = ? AND auto_attempts = ?
            AND last_auto_result IN (' . implode(',', array_fill(0, count($from), '?')) . ')');
        $st->execute([$result, $id, $attempt, ...$from]);
        if ($result === 'customer_answered' && $st->rowCount() > 0) {
            getDB()->prepare('DELETE FROM cc_callbacks WHERE id = ? AND auto_attempts = ?')->execute([$id, $attempt]);
        }
    }

    public static function release(int $id, string $extension): void
    {
        getDB()->prepare('UPDATE cc_callbacks SET claimed_until = NULL, claimed_by = NULL WHERE id = ? AND claimed_by = ?')
            ->execute([$id, $extension]);
    }

    /** Called only by the answered peer's hangup handler. Requests newer than the answer survive. */
    public static function complete(string $number, string $agent, int $answeredAt): void
    {
        $phone = self::phone($number);
        if ($phone === '' || $answeredAt <= 0 || self::queuesFor($agent) === []) {
            return;
        }
        getDB()->prepare('DELETE FROM cc_callbacks WHERE phone = ? AND requested_at <= FROM_UNIXTIME(?)')
            ->execute([$phone, $answeredAt]);
    }
}
