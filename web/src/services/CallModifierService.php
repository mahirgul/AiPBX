<?php
declare(strict_types=1);

require_once __DIR__ . '/../helpers.php';
require_once dirname(__DIR__, 2) . '/modules/destinations/DestinationRegistry.php';

/**
 * Call Modifier Service: language switch or Caller ID name prefix before routing
 * to the next destination (`pbx_call_modifiers`).
 */
class CallModifierService
{
    public const TYPES = ['language', 'cid_prefix', 'queue_priority'];

    /** @return list<array<string, mixed>> */
    public static function all(): array
    {
        return getDB()->query('SELECT * FROM pbx_call_modifiers ORDER BY title ASC, id ASC')->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function saveModifier(array $data): array
    {
        return PBXHelper::handleAction($data['csrf_token'] ?? '', function () use ($data) {
            $id = (int) ($data['modifier_id'] ?? 0);
            $title = trim((string) ($data['title'] ?? ''));
            $modType = trim((string) ($data['mod_type'] ?? ''));
            $modValue = trim((string) ($data['mod_value'] ?? ''));
            $destType = sanitizeDestType((string) ($data['dest_type'] ?? 'hangup'));
            $destId = trim((string) ($data['dest_id'] ?? ''));
            $isActive = PBXHelper::boolFlag($data, 'is_active', 1);

            if ($title === '' || mb_strlen($title) > 120) {
                throw new \Exception(t('call_modifiers.err_title'));
            }
            if (!in_array($modType, self::TYPES, true)) {
                throw new \Exception(t('call_modifiers.err_type'));
            }
            if ($modType === 'language') {
                if ($modValue === '' || !in_array($modValue, getAvailableLanguages(), true)) {
                    throw new \Exception(t('call_modifiers.err_language'));
                }
            } elseif ($modType === 'queue_priority') {
                if (!preg_match('/^\d{1,3}$/', $modValue) || (int) $modValue > 100) {
                    throw new \Exception(t('call_modifiers.err_priority'));
                }
                $modValue = (string) (int) $modValue;
            } else {
                $modValue = dialplanText($modValue);
                if ($modValue === '' || mb_strlen($modValue) > 40) {
                    throw new \Exception(t('call_modifiers.err_prefix'));
                }
            }

            if ($destType === 'call_modifier') {
                self::assertNoCycle($id, (int) $destId);
            }

            $isNew = ($id <= 0);
            $savedId = DBHelper::save('pbx_call_modifiers', [
                'id' => $id,
                'title' => $title,
                'mod_type' => $modType,
                'mod_value' => $modValue,
                'dest_type' => $destType,
                'dest_id' => $destId,
                'is_active' => $isActive,
            ]);

            markCallModifierPendingSync((int) $savedId, "Call modifier: {$title}", $isNew ? 'create' : 'update', $_SESSION['user_id'] ?? null);
            return sprintf(t('call_modifiers.msg_saved'), $title);
        });
    }

    /** Rejects self-loops and multi-hop cycles when targeting another call modifier. */
    private static function assertNoCycle(int $sourceId, int $targetId): void
    {
        if ($targetId <= 0) {
            throw new \Exception(t('call_modifiers.err_dest_self'));
        }
        $visited = $sourceId > 0 ? [$sourceId => true] : [];
        $cur = $targetId;
        $st = getDB()->prepare('SELECT dest_type, dest_id FROM pbx_call_modifiers WHERE id = ?');
        while ($cur > 0) {
            if (isset($visited[$cur])) {
                throw new \Exception(t('call_modifiers.err_dest_self'));
            }
            $visited[$cur] = true;
            $st->execute([$cur]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if (!$row || ($row['dest_type'] ?? '') !== 'call_modifier') {
                break;
            }
            $cur = (int) ($row['dest_id'] ?? 0);
        }
    }

    public static function deleteModifier($id, string $csrfToken): array
    {
        return PBXHelper::handleAction($csrfToken, function () use ($id) {
            $id = (int) $id;
            $refs = \PBX\Destinations\DestinationRegistry::usageCount('call_modifier', (string) $id, ['pbx_call_modifiers' => ['id', $id]]);
            if ($refs > 0) {
                throw new \Exception(t('call_modifiers.err_in_use'));
            }
            $title = (string) (DBHelper::fetchColumn('SELECT title FROM pbx_call_modifiers WHERE id = ?', [$id]) ?? $id);
            DBHelper::delete('pbx_call_modifiers', 'id', $id);
            markCallModifierPendingSync($id, "Call modifier deleted: {$title}", 'delete', $_SESSION['user_id'] ?? null);
            return t('call_modifiers.msg_deleted');
        });
    }
}
