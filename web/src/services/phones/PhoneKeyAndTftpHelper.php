<?php

declare(strict_types=1);

/**
 * DSS/BLF key layout validation/storage and TFTP/FTP file export helper
 * for PhoneProvisionService.
 */
final class PhoneKeyAndTftpHelper
{
    public static function getKeys(int $userId): array
    {
        $stmt = getDB()->prepare('SELECT page, position, key_type AS type, target, label FROM pbx_phone_keys WHERE user_id = ? ORDER BY page, position');
        $stmt->execute([$userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @param callable(int): ?array $userRowFn
     */
    public static function saveKeys(int $userId, array $keys, string $csrf, callable $userRowFn): array
    {
        return PBXHelper::handleAction($csrf, function () use ($userId, $keys, $userRowFn) {
            $user = $userRowFn($userId);
            if ($user === null) {
                throw new \Exception(t('phones.err_user'));
            }
            $rows = self::validateKeys($keys);
            self::writeKeys($userId, $rows);
            writeAuditLog('phones', 'key_layout', $userId, (string) $user['extension'], 'update', $_SESSION['user_id'] ?? null);
            PhoneProvisionService::exportTftpQuietly();
            return t('phones.keys_saved');
        });
    }

    /**
     * @param callable(int): ?array $userRowFn
     */
    public static function copyKeys(int $fromUser, array $toUsers, string $csrf, callable $userRowFn): array
    {
        return PBXHelper::handleAction($csrf, function () use ($fromUser, $toUsers, $userRowFn) {
            $rows = self::getKeys($fromUser);
            $n = 0;
            foreach (array_unique(array_map('intval', $toUsers)) as $to) {
                $targetUser = $userRowFn($to);
                if ($to === $fromUser || $targetUser === null) {
                    continue;
                }
                self::writeKeys($to, $rows);
                writeAuditLog('phones', 'key_layout', $to, (string) $targetUser['extension'], 'copy', $_SESSION['user_id'] ?? null);
                $n++;
            }
            if ($n === 0) {
                throw new \Exception(t('phones.err_copy_none'));
            }
            PhoneProvisionService::exportTftpQuietly();
            return sprintf(t('phones.keys_copied'), $n);
        });
    }

    /** @return list<array{page: int, position: int, type: string, target: string, label: string}> */
    public static function validateKeys(array $keys): array
    {
        $rows = [];
        $seen = [];
        foreach ($keys as $k) {
            $type = (string) ($k['type'] ?? '');
            if ($type === '') {
                continue;
            }
            if (!in_array($type, PhoneProvisionService::KEY_TYPES, true)) {
                throw new \Exception(t('phones.err_key_type'));
            }
            $page = (int) ($k['page'] ?? 0);
            $pos = (int) ($k['position'] ?? 0);
            if ($page < 0 || $page > 6 || $pos < 1 || $pos > 120) {
                throw new \Exception(t('phones.err_key_position'));
            }
            $target = trim((string) ($k['target'] ?? ''));
            if (in_array($type, ['blf', 'speeddial', 'park'], true)) {
                if (!preg_match('/^[0-9*#+]{1,32}$/', $target)) {
                    throw new \Exception(sprintf(t('phones.err_key_target'), $pos));
                }
            } else {
                $target = '';
            }
            $label = mb_substr(trim((string) preg_replace('/[\x00-\x1F\x7F]/u', '', (string) ($k['label'] ?? ''))), 0, 64);
            if (isset($seen["{$page}-{$pos}"])) {
                continue;
            }
            $seen["{$page}-{$pos}"] = true;
            $rows[] = ['page' => $page, 'position' => $pos, 'type' => $type, 'target' => $target, 'label' => $label];
        }
        return $rows;
    }

    private static function writeKeys(int $userId, array $rows): void
    {
        $db = getDB();
        $db->beginTransaction();
        try {
            $db->prepare('DELETE FROM pbx_phone_keys WHERE user_id = ?')->execute([$userId]);
            $ins = $db->prepare('INSERT INTO pbx_phone_keys (user_id, page, position, key_type, target, label) VALUES (?, ?, ?, ?, ?, ?)');
            foreach ($rows as $r) {
                $ins->execute([$userId, (int) $r['page'], (int) $r['position'], $r['type'], $r['target'], $r['label']]);
            }
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }
    }

    /**
     * @param callable(int): ?array $userRowFn
     * @return array{written: int, removed: int, files: int}
     */
    public static function exportTftp(?string $dir, callable $userRowFn): array
    {
        $dir = $dir ?? (getenv('AIPBX_TFTP_DIR') ?: '/var/lib/aipbx/tftp');
        $old = json_decode((string) getSystemSetting('provision_tftp_files', '[]'), true);
        $old = is_array($old) ? $old : [];
        $new = [];
        $written = 0;
        if (getSystemSetting('provision_tftp_export', '0') === '1' && is_dir($dir) && is_writable($dir)) {
            $phones = getDB()->query('SELECT * FROM pbx_phones WHERE user_id IS NOT NULL')->fetchAll(PDO::FETCH_ASSOC);
            foreach ($phones as $phone) {
                $tpl = PhoneTemplates::forModel((string) $phone['model']);
                $user = $userRowFn((int) $phone['user_id']);
                if ($tpl === null || $user === null || (int) $user['is_active'] !== 1) {
                    continue;
                }
                $ctx = PhoneProvisionService::withConfigVersion($phone, $tpl, PhoneProvisionService::buildContext($phone, $user), true);
                foreach ($tpl->exportFiles((string) $phone['mac']) as $name) {
                    if (!preg_match('/^[A-Za-z0-9._-]{1,120}$/', $name)) {
                        continue;
                    }
                    $new[] = $name;
                    $body = $tpl->renderFile($name, $ctx);
                    $path = $dir . '/' . $name;
                    if (is_file($path) && @file_get_contents($path) === $body) {
                        continue;
                    }
                    $tmp = $dir . '/.' . $name . '.tmp';
                    if (@file_put_contents($tmp, $body) !== false && @chmod($tmp, 0644) && @rename($tmp, $path)) {
                        $written++;
                    } else {
                        @unlink($tmp);
                    }
                }
            }
        }
        $removed = 0;
        foreach (array_diff($old, $new) as $name) {
            if (is_string($name) && preg_match('/^[A-Za-z0-9._-]{1,120}$/', $name) && @unlink($dir . '/' . $name)) {
                $removed++;
            }
        }
        if ($old !== $new) {
            getDB()->prepare('INSERT INTO sys_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)')
                ->execute(['provision_tftp_files', (string) json_encode(array_values(array_unique($new)))]);
        }
        return ['written' => $written, 'removed' => $removed, 'files' => count(array_unique($new))];
    }

    /**
     * @param list<string> $passwords
     * @return list<string>
     */
    public static function secretForms(array $passwords): array
    {
        $forms = [];
        foreach ($passwords as $p) {
            $p = trim((string) preg_replace('/[\x00-\x1F\x7F]/u', '', $p));
            if (strlen($p) < 4) {
                continue;
            }
            foreach ([$p, mb_substr($p, 0, 31)] as $v) {
                $forms[] = $v;
                $forms[] = htmlspecialchars($v, ENT_XML1 | ENT_QUOTES, 'UTF-8');
            }
        }
        $forms = array_values(array_unique($forms));
        usort($forms, fn($a, $b) => strlen($b) <=> strlen($a));
        return $forms;
    }
}
