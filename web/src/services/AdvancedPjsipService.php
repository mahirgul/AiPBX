<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/helpers.php';
require_once dirname(__DIR__) . '/asterisk_helper.php';
require_once dirname(__DIR__) . '/asterisk_sync.php';

/**
 * Administrator's own PJSIP options on the sections AiPBX generates (/advanced-config). They are
 * added with `[name](+type=...)` by SyncAdvancedPjsip.php.
 *
 * Option names are checked against Asterisk's own documentation before saving, because a mistake
 * does not show when it happens: `pjsip reload` reports success, and at the next restart the whole
 * object is gone. Measured on Asterisk 22 with `bogus_option=1` on an endpoint.
 */
final class AdvancedPjsipService
{
    /** Types that can be extended => the module whose help lists their options. */
    public const OBJECT_TYPES = [
        'transport' => 'res_pjsip',
        'endpoint' => 'res_pjsip',
        'aor' => 'res_pjsip',
        'auth' => 'res_pjsip',
        'identify' => 'res_pjsip_endpoint_identifier_ip',
        'registration' => 'res_pjsip_outbound_registration',
    ];

    private const KEEP_VERSIONS = 50;

    /**
     * Options of a type in Asterisk's help text.
     *
     * @return list<string>
     */
    public static function parseHelpOptions(string $help): array
    {
        $help = (string) preg_replace('/\x1b\[[0-9;]*m/', '', $help);
        $at = strpos($help, '[Config Options]');
        if ($at === false) {
            return [];
        }
        preg_match_all('/^([A-Za-z0-9_]+)\s+-- /m', substr($help, $at), $m);
        return array_values(array_unique($m[1]));
    }

    /**
     * Options Asterisk knows for a type; empty when it cannot be asked.
     *
     * @return list<string>
     */
    public static function optionNames(string $type): array
    {
        static $cache = [];
        if (!isset(self::OBJECT_TYPES[$type])) {
            return [];
        }
        if (!isset($cache[$type])) {
            // Not by its success flag: the words of the help text can look like a failed command.
            $result = AsteriskHelper::execCLI('config show help ' . self::OBJECT_TYPES[$type] . ' ' . $type);
            $cache[$type] = self::parseHelpOptions((string) $result['output']);
        }
        return $cache[$type];
    }

    /**
     * Normalised option lines, or the problems found in them.
     *
     * @param list<string> $known option names; empty skips the name check
     * @return array{lines: list<string>, errors: list<string>}
     */
    public static function checkOptions(string $text, array $known): array
    {
        $lines = [];
        $errors = [];
        foreach (explode("\n", str_replace("\r", '', $text)) as $i => $raw) {
            $line = trim($raw);
            if ($line === '' || $line[0] === ';') {
                continue;
            }
            $at = $i + 1;
            // #include and #exec would read files or run programs, [ would start another section.
            if ($line[0] === '#' || $line[0] === '[' || preg_match('/[^\x20-\x7e]/', $line)) {
                $errors[] = sprintf(t('advanced_config.err_line'), $at);
                continue;
            }
            if (!preg_match('/^([A-Za-z0-9_]+)\s*=\s*(.*)$/', $line, $m)) {
                $errors[] = sprintf(t('advanced_config.err_line'), $at);
                continue;
            }
            $name = $m[1];
            if ($name === 'type') {
                $errors[] = sprintf(t('advanced_config.err_type_option'), $at);
                continue;
            }
            if ($known !== [] && !in_array($name, $known, true)) {
                $errors[] = sprintf(t('advanced_config.err_unknown_option'), $name, $at);
                continue;
            }
            $lines[] = $name . '=' . $m[2];
        }
        return ['lines' => $lines, 'errors' => $errors];
    }

    /** @return list<array<string, mixed>> */
    public static function all(): array
    {
        return getDB()->query('SELECT * FROM pbx_advanced_pjsip ORDER BY object_type, object_name')->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return list<array<string, mixed>> */
    public static function versions(): array
    {
        return getDB()->query('SELECT v.id, v.label, v.created_at, u.username FROM pbx_advanced_pjsip_versions v LEFT JOIN sys_users u ON u.id = v.created_by ORDER BY v.id DESC LIMIT ' . self::KEEP_VERSIONS)->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function save(array $data): array
    {
        return PBXHelper::handleAction($data['csrf_token'] ?? '', function () use ($data) {
            $id = (int) ($data['id'] ?? 0);
            $type = (string) ($data['object_type'] ?? '');
            $name = trim((string) ($data['object_name'] ?? ''));
            if (!isset(self::OBJECT_TYPES[$type]) || !preg_match('/^[A-Za-z0-9_.+-]{1,80}$/D', $name)) {
                throw new \Exception(t('advanced_config.err_object'));
            }
            $check = self::checkOptions((string) ($data['options'] ?? ''), self::optionNames($type));
            if ($check['errors'] !== []) {
                throw new \Exception(implode(' ', $check['errors']));
            }
            if ($check['lines'] === []) {
                throw new \Exception(t('advanced_config.err_no_options'));
            }
            $db = getDB();
            $same = $db->prepare('SELECT id FROM pbx_advanced_pjsip WHERE object_type = ? AND object_name = ? AND id <> ?');
            $same->execute([$type, $name, $id]);
            if ($same->fetchColumn() !== false) {
                throw new \Exception(sprintf(t('advanced_config.err_exists'), $type, $name));
            }
            self::keepVersion("{$type} {$name}");
            $row = [
                implode("\n", $check['lines']),
                mb_substr(trim((string) ($data['note'] ?? '')), 0, 200),
                empty($data['is_active']) ? 0 : 1,
                $type,
                $name,
            ];
            if ($id > 0) {
                $row[] = $id;
                $db->prepare('UPDATE pbx_advanced_pjsip SET options = ?, note = ?, is_active = ?, object_type = ?, object_name = ?, updated_at = NOW() WHERE id = ?')->execute($row);
            } else {
                $db->prepare('INSERT INTO pbx_advanced_pjsip (options, note, is_active, object_type, object_name, created_at) VALUES (?, ?, ?, ?, ?, NOW())')->execute($row);
            }
            self::markChanged("{$type} {$name}", $id > 0 ? 'update' : 'create');
            return t('advanced_config.saved');
        });
    }

    public static function delete(array $data): array
    {
        return PBXHelper::handleAction($data['csrf_token'] ?? '', function () use ($data) {
            $db = getDB();
            $stmt = $db->prepare('SELECT object_type, object_name FROM pbx_advanced_pjsip WHERE id = ?');
            $stmt->execute([(int) ($data['id'] ?? 0)]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                throw new \Exception(t('advanced_config.err_object'));
            }
            $label = "{$row['object_type']} {$row['object_name']}";
            self::keepVersion($label);
            $db->prepare('DELETE FROM pbx_advanced_pjsip WHERE id = ?')->execute([(int) $data['id']]);
            self::markChanged($label, 'delete');
            return t('advanced_config.deleted');
        });
    }

    /** Puts the list back as it was before a change. The present list becomes a version too. */
    public static function restore(array $data): array
    {
        return PBXHelper::handleAction($data['csrf_token'] ?? '', function () use ($data) {
            $db = getDB();
            $stmt = $db->prepare('SELECT snapshot, created_at FROM pbx_advanced_pjsip_versions WHERE id = ?');
            $stmt->execute([(int) ($data['version_id'] ?? 0)]);
            $version = $stmt->fetch(PDO::FETCH_ASSOC);
            $rows = $version ? json_decode((string) $version['snapshot'], true) : null;
            if (!is_array($rows)) {
                throw new \Exception(t('advanced_config.err_version'));
            }
            $label = sprintf(t('advanced_config.restored_label'), (string) $version['created_at']);
            self::keepVersion($label);
            $db->beginTransaction();
            try {
                $db->exec('DELETE FROM pbx_advanced_pjsip');
                $insert = $db->prepare('INSERT INTO pbx_advanced_pjsip (object_type, object_name, options, note, is_active, created_at) VALUES (?, ?, ?, ?, ?, NOW())');
                foreach ($rows as $r) {
                    $insert->execute([$r['object_type'], $r['object_name'], $r['options'], $r['note'] ?? null, (int) ($r['is_active'] ?? 1)]);
                }
                $db->commit();
            } catch (\Throwable $e) {
                $db->rollBack();
                throw $e;
            }
            self::markChanged($label, 'update');
            return t('advanced_config.restored');
        });
    }

    private static function keepVersion(string $label): void
    {
        $db = getDB();
        $rows = $db->query('SELECT object_type, object_name, options, note, is_active FROM pbx_advanced_pjsip')->fetchAll(PDO::FETCH_ASSOC);
        $db->prepare('INSERT INTO pbx_advanced_pjsip_versions (snapshot, label, created_by) VALUES (?, ?, ?)')
            ->execute([json_encode($rows), mb_substr($label, 0, 200), $_SESSION['user_id'] ?? null]);
        $db->exec('DELETE FROM pbx_advanced_pjsip_versions WHERE id NOT IN (SELECT id FROM (SELECT id FROM pbx_advanced_pjsip_versions ORDER BY id DESC LIMIT ' . self::KEEP_VERSIONS . ') keep)');
    }

    private static function markChanged(string $label, string $action): void
    {
        $uid = $_SESSION['user_id'] ?? null;
        markPendingSync('advanced_pjsip', 'advanced_pjsip', 0, $label, $action, $uid);
        writeAuditLog('advanced_pjsip', 'advanced_pjsip', 0, $label, $action, $uid);
    }
}
