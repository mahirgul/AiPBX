<?php
declare(strict_types=1);

require_once __DIR__ . '/../db_helper.php';

/** Pending requests only: answered conversations remove them; a failed dial never does. */
final class QueueCallbackService
{
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
            (c.claimed_until IS NOT NULL AND c.claimed_until > NOW()) AS calling
            FROM cc_callbacks c JOIN pbx_queues q ON q.id = c.queue_id
            WHERE c.queue_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')
            ORDER BY c.requested_at, c.id LIMIT 500');
        $st->execute($ids);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Atomically reserve a pending request so simultaneous clicks cannot originate twice. */
    public static function claim(int $id, string $extension): ?string
    {
        $ids = self::queuesFor($extension);
        if ($ids === []) {
            return null;
        }
        $st = getDB()->prepare('UPDATE cc_callbacks SET claimed_by = ?, claimed_until = DATE_ADD(NOW(), INTERVAL 2 MINUTE)
            WHERE id = ? AND (claimed_until IS NULL OR claimed_until <= NOW())
            AND queue_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')');
        $st->execute(array_merge([$extension, $id], $ids));
        return $st->rowCount() === 1 ? (string) DBHelper::fetchColumn('SELECT dial_number FROM cc_callbacks WHERE id = ?', [$id]) : null;
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
