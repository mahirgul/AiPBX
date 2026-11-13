<?php
require_once __DIR__ . '/../db_helper.php';
require_once __DIR__ . '/../asterisk_sync.php';

/**
 * Fax settings (fax units / DID mapping) service
 */
class FaxSettingsService {
    public static function saveDidMapping(array $data): array
    {
        if (!verifyCSRFToken($data['csrf_token'] ?? '')) {
            return ['success' => false, 'error' => t('common.invalid_csrf')];
        }

        $did_id = intval($data['did_id'] ?? 0);
        $dept = trim($data['department_name'] ?? '');
        $email = trim($data['notification_email'] ?? '');
        $did_extension = trim($data['did_extension'] ?? '');
        $assigned_user_id = intval($data['assigned_user_id'] ?? 0) ?: null;
        $is_active = isset($data['is_active']) ? intval($data['is_active']) : 1;

        if (empty($dept)) {
            return ['success' => false, 'error' => t('srv_fax.err_dept')];
        }
        $error = self::didExtensionError($did_extension);
        if ($error !== null) {
            return ['success' => false, 'error' => $error];
        }

        try {
            $db = getDB();
            $uid = $_SESSION['user_id'] ?? null;
            if ($did_id > 0) {
                $stmt = $db->prepare('UPDATE sys_did_mappings SET department_name = ?, notification_email = ?, did_extension = ?, assigned_user_id = ?, is_active = ? WHERE id = ?');
                $stmt->execute([$dept, $email, $did_extension ?: null, $assigned_user_id, $is_active, $did_id]);
                markPendingSync('general_dialplan', 'did_mapping', $did_id, "Fax unit: {$dept}", 'update', $uid);
                return ['success' => true, 'message' => sprintf(t('srv_fax.dept_updated'), $dept)];
            }
            $stmt = $db->prepare('INSERT INTO sys_did_mappings (department_name, notification_email, did_extension, assigned_user_id, is_active) VALUES (?, ?, ?, ?, ?)');
            $stmt->execute([$dept, $email, $did_extension ?: null, $assigned_user_id, $is_active]);
            $new_id = $db->lastInsertId();
            markPendingSync('general_dialplan', 'did_mapping', $new_id, "Fax unit: {$dept}", 'create', $uid);
            return ['success' => true, 'message' => sprintf(t('srv_fax.dept_added'), $dept)];
        } catch (\PDOException $e) {
            return ['success' => false, 'error' => sprintf(t('common.error_colon'), $e->getMessage())];
        }
    }

    public static function deleteDidMapping($didId, $csrfToken): array
    {
        if (!verifyCSRFToken($csrfToken)) {
            return ['success' => false, 'error' => t('common.invalid_csrf')];
        }

        $did_id = intval($didId);
        if ($did_id > 0) {
            $dept = DBHelper::fetchColumn("SELECT department_name FROM sys_did_mappings WHERE id = ?", [$did_id]);
            DBHelper::delete('sys_did_mappings', 'id', $did_id);
            markPendingSync('general_dialplan', 'did_mapping', $did_id, "Fax unit: " . ($dept ?: $did_id) . " (deleted)", 'delete', $_SESSION['user_id'] ?? null);
            return ['success' => true, 'message' => t('srv_fax.dept_deleted')];
        }
        return ['success' => true, 'message' => ''];
    }

    /**
     * Numbers from a field: separated by commas or spaces, first one first, no repeats.
     *
     * @return list<string>
     */
    public static function parseNumbers(string $text): array
    {
        $numbers = preg_split('/[\s,;]+/', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        return array_values(array_unique($numbers));
    }

    /**
     * Fax accounts for the Fax Units page: a fax user with all their numbers, or a number with no user.
     * The id says which: "u12" or "m5".
     *
     * @param list<array<string, mixed>> $users rows of sys_users with extension_type fax
     * @param list<array<string, mixed>> $mappings rows of sys_did_mappings
     * @param list<array{did_mapping_id: int|string, user_id: int|string, mail?: int|string}> $links who uses which number
     * @return list<array<string, mixed>>
     */
    public static function groupAccounts(array $users, array $mappings, array $links): array
    {
        $byMapping = [];
        foreach ($links as $link) {
            $byMapping[(int) $link['did_mapping_id']][] = (int) $link['user_id'];
        }
        /** @var array<int, true> $mailOff */
        $mailOff = [];
        foreach ($links as $link) {
            if (isset($link['mail']) && (int) $link['mail'] === 0) {
                $mailOff[(int) $link['user_id']] = true;
            }
        }
        $accounts = [];
        foreach ($users as $u) {
            $accounts[(int) $u['id']] = [
                'id' => 'u' . $u['id'], 'user_id' => (int) $u['id'], 'department_name' => (string) $u['full_name'],
                'numbers' => (string) $u['extension'] !== '' ? [(string) $u['extension']] : [], 'shared' => [], 'linked' => [], 'linked_mail' => 1,
                'notification_email' => (string) ($u['email'] ?? ''), 'username' => (string) $u['username'],
                'has_password' => (string) ($u['password_hash'] ?? '') !== '', 'fax_mail' => (int) ($u['fax_mail'] ?? 1),
                'role' => (string) ($u['role'] ?? 'fax_user'),
                'outbound_group' => (int) ($u['outbound_group'] ?? 1), 'permission_group_id' => (int) ($u['permission_group_id'] ?? 0),
                'cid_external' => (string) ($u['cid_external'] ?? ''), 'is_active' => (int) ($u['is_active'] ?? 1),
            ];
        }
        $loose = [];
        foreach ($mappings as $m) {
            $number = trim((string) ($m['did_extension'] ?? ''));
            $all = $byMapping[(int) $m['id']] ?? [];
            $members = array_values(array_filter($all, static fn(int $id): bool => isset($accounts[$id])));
            // People with an account of their own (a phone, an admin) on this number.
            $linked = array_values(array_diff($all, $members));
            $linkedMail = $linked === [] || array_filter($linked, static fn(int $id): bool => !isset($mailOff[$id])) !== [] ? 1 : 0;
            foreach ($members as $id) {
                $accounts[$id]['linked'] = array_values(array_unique(array_merge($accounts[$id]['linked'], $linked)));
                $accounts[$id]['linked_mail'] = $linkedMail;
                // The unit's name says more than the login's, which often only repeats the number.
                // A shared number names no one user.
                if (count($members) === 1) {
                    $accounts[$id]['department_name'] = (string) $m['department_name'];
                } elseif ($number !== '') {
                    $accounts[$id]['shared'][] = $number;
                }
                if ($number !== '' && !in_array($number, $accounts[$id]['numbers'], true)) {
                    $accounts[$id]['numbers'][] = $number;
                }
            }
            if ($members === []) {
                $loose[] = [
                    'id' => 'm' . $m['id'], 'user_id' => 0, 'department_name' => (string) $m['department_name'],
                    'numbers' => $number !== '' ? [$number] : [], 'shared' => [], 'linked' => $linked, 'linked_mail' => $linkedMail, 'notification_email' => (string) $m['notification_email'],
                    'username' => '', 'has_password' => false, 'fax_mail' => 1, 'role' => 'fax_user', 'outbound_group' => 1, 'permission_group_id' => 0,
                    'cid_external' => '', 'is_active' => (int) ($m['is_active'] ?? 1),
                ];
            }
        }
        $list = array_merge(array_values($accounts), $loose);
        usort($list, static fn(array $a, array $b): int => strcasecmp($a['department_name'], $b['department_name']));
        return $list;
    }

    /** @return list<array<string, mixed>> */
    public static function accounts(): array
    {
        $db = getDB();
        return self::groupAccounts(
            $db->query("SELECT id, username, full_name, email, extension, role, password_hash, fax_mail, outbound_group, permission_group_id, cid_external, is_active FROM sys_users WHERE extension_type = 'fax'")->fetchAll(PDO::FETCH_ASSOC),
            $db->query('SELECT id, department_name, notification_email, did_extension, assigned_user_id, is_active FROM sys_did_mappings ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
            $db->query('SELECT did_mapping_id, user_id, mail FROM pbx_fax_number_users')->fetchAll(PDO::FETCH_ASSOC)
        );
    }

    /** @return list<array{id: int|string, username: string, full_name: string}> people who can be put on a fax number */
    public static function linkableUsers(): array
    {
        return getDB()->query("SELECT id, username, full_name FROM sys_users WHERE is_active = 1 AND COALESCE(extension_type, 'sip') <> 'fax' ORDER BY full_name, username")->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return list<array{role_key: string, role_name: string}> roles a fax user can have: those that may open the fax inbox */
    public static function faxRoles(): array
    {
        return getDB()->query("SELECT r.role_key, r.role_name FROM sys_roles r JOIN sys_role_permissions p ON p.role_key = r.role_key
            WHERE p.module_key = 'fax_inbox' AND p.can_access = 1 AND r.role_key <> 'admin' ORDER BY r.role_name")->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * The login number of a fax user: their first number no other user has as theirs, or none when all
     * are shared. Two rows with one extension would look like one person everywhere else in AiPBX.
     *
     * @param list<string> $numbers
     * @param list<string> $takenByOthers
     */
    public static function loginNumber(array $numbers, array $takenByOthers): ?string
    {
        foreach ($numbers as $number) {
            if (!in_array($number, $takenByOthers, true)) {
                return $number;
            }
        }
        return null;
    }

    /** Adds or changes a fax user with their numbers. A number typed for two users is shared by them. */
    public static function saveAccount(array $data): array
    {
        return PBXHelper::handleAction($data['csrf_token'] ?? '', function () use ($data) {
            $key = (string) ($data['did_id'] ?? '');
            $userId = preg_match('/^u(\d+)$/D', $key, $m) === 1 ? (int) $m[1] : 0;
            $looseMapping = preg_match('/^m(\d+)$/D', $key, $m) === 1 ? (int) $m[1] : 0;
            $name = trim((string) ($data['department_name'] ?? ''));
            if ($name === '') {
                throw new \Exception(t('srv_fax.err_dept'));
            }
            $numbers = self::parseNumbers((string) ($data['did_extension'] ?? ''));
            if ($numbers === []) {
                throw new \Exception(t('fax_settings.err_no_number'));
            }
            $db = getDB();
            if ($userId > 0 && DBHelper::fetchColumn("SELECT extension_type FROM sys_users WHERE id = ?", [$userId]) !== 'fax') {
                throw new \Exception(t('fax_settings.err_missing'));
            }
            foreach ($numbers as $number) {
                $error = self::numberError($number);
                if ($error !== null) {
                    throw new \Exception($error);
                }
            }
            $email = trim((string) ($data['notification_email'] ?? ''));
            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                throw new \Exception(t('srv_user.err_email_invalid'));
            }
            $linked = array_values(array_intersect(array_map('intval', (array) ($data['linked_users'] ?? [])), array_map('intval', array_column(self::linkableUsers(), 'id'))));
            $linkedMail = empty($data['linked_mail']) ? 0 : 1;
            // A unit needs no login of its own when people with accounts use it.
            if ($userId === 0 && empty($data['create_login'])) {
                return self::saveUnit($looseMapping, $name, $numbers, $email, empty($data['is_active']) ? 0 : 1, $linked, $linkedMail);
            }
            $password = (string) ($data['password'] ?? '');
            if ($password !== '' && passwordTooShort($password)) {
                throw new \Exception(t('fax_settings.err_password'));
            }
            $username = trim((string) ($data['username'] ?? ''));
            if ($username === '') {
                $username = 'fax' . preg_replace('/[^0-9]/', '', $numbers[0]);
            }
            if (preg_match('/^[A-Za-z0-9._@-]{2,64}$/D', $username) !== 1) {
                throw new \Exception(t('fax_settings.err_username'));
            }
            if (DBHelper::fetchColumn('SELECT COUNT(*) FROM sys_users WHERE username = ? AND id != ?', [$username, $userId]) > 0) {
                throw new \Exception(sprintf(t('srv_user.err_username_taken'), $username));
            }
            // The address is where the unit's faxes go. A login's e-mail has to be unique, so one
            // already on someone's account is used for the faxes only.
            $loginEmail = $email !== '' && DBHelper::fetchColumn('SELECT COUNT(*) FROM sys_users WHERE LOWER(TRIM(email)) = ? AND id != ?', [mb_strtolower($email), $userId]) > 0 ? '' : $email;
            $role = (string) ($data['role'] ?? 'fax_user');
            if (!in_array($role, array_column(self::faxRoles(), 'role_key'), true)) {
                $role = 'fax_user';
            }
            $marks = implode(',', array_fill(0, count($numbers), '?'));
            $taken = $db->prepare("SELECT extension FROM sys_users WHERE id <> ? AND extension IN ({$marks})");
            $taken->execute(array_merge([$userId], $numbers));
            $active = empty($data['is_active']) ? 0 : 1;
            $fields = [
                'full_name' => $name, 'username' => $username, 'email' => $loginEmail, 'role' => $role, 'is_active' => $active,
                'extension' => self::loginNumber($numbers, array_map('strval', $taken->fetchAll(PDO::FETCH_COLUMN))),
                'fax_mail' => empty($data['fax_mail']) ? 0 : 1,
                'outbound_group' => max(1, (int) ($data['outbound_group'] ?? 1)),
                'permission_group_id' => (int) ($data['permission_group_id'] ?? 0) ?: null,
                'cid_external' => (string) preg_replace('/[^0-9+]/', '', (string) ($data['cid_external'] ?? '')),
            ];
            if ($password !== '') {
                $fields['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
            }
            $isNew = $userId === 0;
            $db->beginTransaction();
            try {
                if ($isNew) {
                    DBHelper::insert('sys_users', $fields + ['extension_type' => 'fax', 'password_hash' => '', 'sip_password' => '', 'created_at' => date('Y-m-d H:i:s')]);
                    $userId = (int) $db->lastInsertId();
                } else {
                    DBHelper::update('sys_users', $fields, 'id', $userId);
                }
                if ($looseMapping > 0) {
                    $db->prepare('INSERT IGNORE INTO pbx_fax_number_users (did_mapping_id, user_id) VALUES (?, ?)')->execute([$looseMapping, $userId]);
                }
                $mailOn = empty($data['fax_mail']) ? 0 : 1;
                self::setNumbers($userId, $numbers, $name, $mailOn ? $email : '', $active, $mailOn);
                self::setLinked($userId, $linked, $linkedMail);
                $db->commit();
            } catch (\Throwable $e) {
                $db->rollBack();
                throw $e;
            }
            $uid = $_SESSION['user_id'] ?? null;
            markPendingSync('general_dialplan', 'did_mapping', $userId, "Fax unit: {$name}", $isNew ? 'create' : 'update', $uid);
            writeAuditLog('fax', 'fax_account', $userId, "{$name}: " . implode(', ', $numbers), $isNew ? 'create' : 'update', $uid);
            return sprintf(t('fax_settings.account_saved'), $name);
        });
    }

    /**
     * Makes these the user's numbers. A number nobody uses any more goes; a number another user has
     * keeps its name and address, as those are not this user's alone.
     *
     * @param list<string> $numbers
     */
    private static function setNumbers(int $userId, array $numbers, string $name, string $email, int $active, int $mail = 1): void
    {
        $db = getDB();
        $current = $db->prepare('SELECT m.id, m.did_extension FROM sys_did_mappings m JOIN pbx_fax_number_users f ON f.did_mapping_id = m.id WHERE f.user_id = ?');
        $current->execute([$userId]);
        foreach ($current->fetchAll(PDO::FETCH_KEY_PAIR) as $mappingId => $number) {
            if (!in_array((string) $number, $numbers, true)) {
                $db->prepare('DELETE FROM pbx_fax_number_users WHERE did_mapping_id = ? AND user_id = ?')->execute([$mappingId, $userId]);
            }
        }
        foreach ($numbers as $number) {
            $mappingId = (int) DBHelper::fetchColumn('SELECT id FROM sys_did_mappings WHERE did_extension = ? LIMIT 1', [$number]);
            if ($mappingId === 0) {
                $db->prepare('INSERT INTO sys_did_mappings (department_name, notification_email, did_extension, assigned_user_id, is_active) VALUES (?, ?, ?, ?, ?)')
                    ->execute([$name, $email, $number, $userId, $active]);
                $mappingId = (int) $db->lastInsertId();
            }
            $db->prepare('INSERT INTO pbx_fax_number_users (did_mapping_id, user_id, mail) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE mail = VALUES(mail)')->execute([$mappingId, $userId, $mail]);
        }
        self::tidyNumbers($userId, $name, $email);
    }

    /**
     * The people with accounts of their own on a fax user's numbers.
     *
     * @param list<int> $linked
     */
    private static function setLinked(int $faxUserId, array $linked, int $mail): void
    {
        $db = getDB();
        $mine = $db->prepare('SELECT did_mapping_id FROM pbx_fax_number_users WHERE user_id = ?');
        $mine->execute([$faxUserId]);
        foreach (array_map('intval', $mine->fetchAll(PDO::FETCH_COLUMN)) as $mappingId) {
            self::setMappingLinked($mappingId, $linked, $mail);
        }
    }

    /** @param list<int> $linked */
    private static function setMappingLinked(int $mappingId, array $linked, int $mail): void
    {
        $db = getDB();
        $db->prepare("DELETE f FROM pbx_fax_number_users f JOIN sys_users u ON u.id = f.user_id WHERE f.did_mapping_id = ? AND COALESCE(u.extension_type, 'sip') <> 'fax'")->execute([$mappingId]);
        $add = $db->prepare('INSERT INTO pbx_fax_number_users (did_mapping_id, user_id, mail) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE mail = VALUES(mail)');
        foreach ($linked as $id) {
            $add->execute([$mappingId, $id, $mail]);
        }
    }

    /**
     * A fax unit with no login of its own: numbers, an address and the people who use them. Each number
     * is its own row, so a comma adds rows.
     *
     * @param list<string> $numbers
     * @param list<int> $linked
     */
    private static function saveUnit(int $mappingId, string $name, array $numbers, string $email, int $active, array $linked, int $linkedMail): string
    {
        $db = getDB();
        if ($mappingId > 0) {
            $first = array_shift($numbers);
            $db->prepare('UPDATE sys_did_mappings SET department_name = ?, notification_email = ?, did_extension = ?, is_active = ? WHERE id = ?')->execute([$name, $email, $first, $active, $mappingId]);
            self::setMappingLinked($mappingId, $linked, $linkedMail);
        }
        foreach ($numbers as $number) {
            $existing = (int) DBHelper::fetchColumn('SELECT id FROM sys_did_mappings WHERE did_extension = ? LIMIT 1', [$number]);
            if ($existing === 0) {
                $db->prepare('INSERT INTO sys_did_mappings (department_name, notification_email, did_extension, is_active) VALUES (?, ?, ?, ?)')->execute([$name, $email, $number, $active]);
                $existing = (int) $db->lastInsertId();
            }
            self::setMappingLinked($existing, $linked, $linkedMail);
        }
        $uid = $_SESSION['user_id'] ?? null;
        markPendingSync('general_dialplan', 'did_mapping', $mappingId, "Fax unit: {$name}", $mappingId > 0 ? 'update' : 'create', $uid);
        writeAuditLog('fax', 'fax_unit', $mappingId, $name, $mappingId > 0 ? 'update' : 'create', $uid);
        return sprintf(t('fax_settings.account_saved'), $name);
    }

    /** Numbers without users go; a number with one user follows that user's name and address. */
    private static function tidyNumbers(?int $userId = null, string $name = '', string $email = ''): void
    {
        $db = getDB();
        $db->exec('DELETE m FROM sys_did_mappings m LEFT JOIN pbx_fax_number_users f ON f.did_mapping_id = m.id WHERE f.user_id IS NULL AND m.assigned_user_id IS NOT NULL');
        // assigned_user_id keeps naming a user of the number, for what still reads it.
        $faxUsers = "SELECT %s FROM pbx_fax_number_users f JOIN sys_users u ON u.id = f.user_id AND u.extension_type = 'fax' WHERE f.did_mapping_id = m.id";
        $db->exec('UPDATE sys_did_mappings m SET m.assigned_user_id = (' . sprintf($faxUsers, 'MIN(f.user_id)') . ') WHERE EXISTS (SELECT 1 FROM pbx_fax_number_users f WHERE f.did_mapping_id = m.id)');
        $db->exec('UPDATE sys_did_mappings m SET m.is_active = EXISTS (' . sprintf($faxUsers, '1') . ' AND u.is_active = 1) WHERE EXISTS (' . sprintf($faxUsers, '1') . ')');
        if ($userId !== null) {
            $db->prepare('UPDATE sys_did_mappings m JOIN pbx_fax_number_users f ON f.did_mapping_id = m.id SET m.department_name = ?, m.notification_email = ?
                WHERE f.user_id = ? AND (SELECT COUNT(*) FROM pbx_fax_number_users g WHERE g.did_mapping_id = m.id) = 1')->execute([$name, $email, $userId]);
        }
    }

    /** Why a number cannot be a fax number, or null. Being another fax user's number is fine: they share it. */
    private static function numberError(string $number): ?string
    {
        if (preg_match('/^[0-9]{1,20}$/D', $number) !== 1) {
            return t('fax.err_did_digits');
        }
        if (DBHelper::fetchColumn('SELECT id FROM sys_did_mappings WHERE did_extension = ? LIMIT 1', [$number])) {
            return null;
        }
        return self::didExtensionError($number);
    }

    /** Turns an account on or off; a number stays on while another user of it is on. */
    public static function toggleAccount(array $data): array
    {
        return PBXHelper::handleAction($data['csrf_token'] ?? '', function () use ($data) {
            $key = (string) ($data['did_id'] ?? '');
            $db = getDB();
            if (preg_match('/^u(\d+)$/D', $key, $m) === 1) {
                $db->prepare("UPDATE sys_users SET is_active = 1 - is_active WHERE id = ? AND extension_type = 'fax'")->execute([(int) $m[1]]);
                self::tidyNumbers();
            } elseif (preg_match('/^m(\d+)$/D', $key, $m) === 1) {
                $db->prepare('UPDATE sys_did_mappings SET is_active = 1 - is_active WHERE id = ?')->execute([(int) $m[1]]);
            } else {
                throw new \Exception(t('fax_settings.err_missing'));
            }
            markPendingSync('general_dialplan', 'did_mapping', (int) $m[1], "Fax unit: {$key}", 'update', $_SESSION['user_id'] ?? null);
            return t('fax_settings.account_toggled');
        });
    }

    /** Deletes an account, and its numbers nobody else uses. Received and sent faxes stay. */
    public static function deleteAccount(array $data): array
    {
        $csrf = (string) ($data['csrf_token'] ?? '');
        $key = (string) ($data['did_id'] ?? '');
        if (preg_match('/^m(\d+)$/D', $key, $m) === 1) {
            return self::deleteDidMapping($m[1], $csrf);
        }
        if (!verifyCSRFToken($csrf) || preg_match('/^u(\d+)$/D', $key, $m) !== 1) {
            return ['success' => false, 'error' => t('fax_settings.err_missing')];
        }
        $userId = (int) $m[1];
        if (DBHelper::fetchColumn('SELECT extension_type FROM sys_users WHERE id = ?', [$userId]) !== 'fax') {
            return ['success' => false, 'error' => t('fax_settings.err_missing')];
        }
        require_once __DIR__ . '/UserService.php';
        $db = getDB();
        $db->prepare('DELETE FROM pbx_fax_number_users WHERE user_id = ?')->execute([$userId]);
        self::tidyNumbers();
        $db->prepare('UPDATE sys_did_mappings SET assigned_user_id = NULL WHERE assigned_user_id = ?')->execute([$userId]);
        $db->prepare('DELETE FROM pbx_fax_cover_templates WHERE user_id = ?')->execute([$userId]);
        markPendingSync('general_dialplan', 'did_mapping', $userId, 'Fax unit deleted', 'delete', $_SESSION['user_id'] ?? null);
        return UserService::deleteUser($userId, $csrf);
    }

    /**
     * A fax unit's number is dialled before the numbers of queues, IVRs and the like, so it
     * must not take one of theirs. A fax user with the same number is normal: that is the
     * unit's own mailbox.
     */
    public static function didExtensionError(string $number): ?string
    {
        if ($number === '') {
            return null;
        }
        if (preg_match('/^\+?[0-9]{1,20}$/D', $number) !== 1) {
            return t('fax.err_did_digits');
        }
        $u = getDB()->prepare("SELECT extension_type FROM sys_users WHERE extension = ? LIMIT 1");
        $u->execute([$number]);
        $type = $u->fetchColumn();
        if ($type === 'fax') {
            return null;
        }
        $owner = internalNumberOwner($number);
        return $owner === null ? null : sprintf(t('internal_number.err_used_choose'), $number, $owner);
    }
}
