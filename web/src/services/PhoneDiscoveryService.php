<?php
/**
 * Phones found on the network (PBX → Phones → *Discovered on the network*).
 *
 * The observation comes from the engine (`engine/aipbx_engine`, a Python
 * module the install puts on a 5-minute cron); the decision lives here, so
 * there is still exactly **one** piece of code that creates a phone:
 * `PhoneProvisionService::upsertPhone()`, the same one the +, the CSV import
 * and the waiting list use. Nothing here writes a phone file, a token or an
 * admin password of its own.
 *
 * Safety, in the order it is enforced:
 *
 *  1. A MAC that is already a phone (`pbx_phones`) is **never** provisioned
 *     again, whatever else is true — not by the automatic step and not by the
 *     Approve button, which refuses and says so. The engine records it with
 *     `status = 'provisioned'` and the page shows it as "on the network".
 *  2. The automatic step only ever touches rows with `status = 'new'` (an
 *     ignored row stays ignored for good) that were seen in the last
 *     `SEEN_WINDOW_HOURS`, and only when the administrator has named both an
 *     extension pool **and** a model for that phone's vendor. No pool or no
 *     model entry = discovery records, nothing is created.
 *  3. The extension is only taken from the pool when the user behind it is an
 *     active SIP extension that has no phone yet, so a pool cannot steal an
 *     existing phone's extension.
 *  4. A MAC whose prefix is not in the vendor table never reaches this table in
 *     the first place: the engine filters by `PhoneModels::OUI`.
 *
 * Deleting a phone does not resurrect it: the discovery row keeps
 * `status = 'provisioned'` (with `phone_id` cleared by the foreign key) and the
 * page shows "was a phone, now deleted". An administrator can Approve it again
 * deliberately; nothing does it on its own.
 */

require_once dirname(__DIR__) . '/helpers.php';
require_once dirname(__DIR__) . '/audit_log.php';
require_once __DIR__ . '/PhoneProvisionService.php';

class PhoneDiscoveryService
{
    /** sys_settings keys and their defaults — mirrored by engine/aipbx_engine/config.py. */
    public const SETTINGS = [
        // Discovery on or off (the engine's own switch; off = it reads nothing).
        'provision_discovery' => '1',
        // The ARP sweep: the only source that sends packets. Off until asked for.
        'provision_discovery_sweep' => '0',
        // Networks to sweep, one CIDR or address per line; empty = sweep nothing.
        'provision_discovery_networks' => '',
        // Extensions a discovered phone may take with no human in the loop
        // ("5000-5099" or "5000,5001"); empty = nothing is provisioned automatically.
        'provision_discovery_pool' => '',
        // Which model a phone of a vendor is provisioned as, one "vendor=model"
        // per line. A vendor that is not listed is never provisioned
        // automatically: the MAC says who made the phone, never which model.
        'provision_discovery_models' => '',
    ];

    /** Phones created per automatic run, at most (a whole fleet arriving at once). */
    public const AUTO_MAX_PER_RUN = 25;

    /** Only a phone seen this recently is provisioned automatically. */
    public const SEEN_WINDOW_HOURS = 24;

    /** Rows the page shows. */
    public const PAGE_LIMIT = 300;

    // -------------------------------------------------------------- settings

    public static function settings(): array
    {
        $out = [];
        foreach (self::SETTINGS as $key => $default) {
            $out[$key] = (string) getSystemSetting($key, $default);
        }
        return $out;
    }

    /** Stores the five discovery settings after checking them. */
    public static function saveDiscoverySettings(array $data): void
    {
        $pool = self::parsePool((string) ($data['provision_discovery_pool'] ?? ''), $badPool);
        if ($badPool !== []) {
            throw new \Exception(sprintf(t('phonedisc.err_pool'), implode(', ', $badPool)));
        }
        $models = self::parseModels((string) ($data['provision_discovery_models'] ?? ''), $badModels);
        if ($badModels !== []) {
            throw new \Exception(sprintf(t('phonedisc.err_models'), implode(', ', $badModels)));
        }
        $networks = PhoneProvisionService::parseNetworks((string) ($data['provision_discovery_networks'] ?? ''), $badNetworks);
        if ($badNetworks !== []) {
            throw new \Exception(sprintf(t('phones.err_networks'), implode(', ', $badNetworks)));
        }
        $values = [
            'provision_discovery' => !empty($data['provision_discovery']) ? '1' : '0',
            'provision_discovery_sweep' => !empty($data['provision_discovery_sweep']) ? '1' : '0',
            'provision_discovery_networks' => implode("\n", $networks),
            'provision_discovery_pool' => implode(',', $pool),
            'provision_discovery_models' => implode("\n", array_map(
                fn($vendor, $model) => $vendor . '=' . $model, array_keys($models), array_values($models))),
        ];
        $stmt = getDB()->prepare('INSERT INTO sys_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)');
        foreach ($values as $key => $value) {
            $stmt->execute([$key, $value]);
        }
        writeAuditLog('phones', 'settings', 0, 'Phone discovery', 'update', $_SESSION['user_id'] ?? null);
    }

    /** Controller wrapper of saveDiscoverySettings(): CSRF check + layout notice array. */
    public static function saveSettingsAction(array $data, string $csrf): array
    {
        return PBXHelper::handleAction($csrf, function () use ($data) {
            self::saveDiscoverySettings($data);
            return t('phonedisc.settings_saved');
        });
    }

    /**
     * Extension pool from a text: ranges ("5000-5099") and single extensions,
     * separated by commas, spaces or lines. Returns the numbers in order.
     *
     * @param string[]|null $bad the entries that are not a range or an extension
     * @return string[]
     */
    public static function parsePool(string $text, ?array &$bad = null): array
    {
        $bad = [];
        $out = [];
        foreach (preg_split('/[\s,]+/', trim($text)) ?: [] as $entry) {
            if ($entry === '') {
                continue;
            }
            if (preg_match('/^(\d{2,6})-(\d{2,6})$/', $entry, $m)) {
                [$from, $to] = [(int) $m[1], (int) $m[2]];
                if ($to < $from || $to - $from > 999) {
                    $bad[] = $entry;
                    continue;
                }
                for ($n = $from; $n <= $to; $n++) {
                    $out[] = (string) $n;
                }
                continue;
            }
            if (preg_match('/^\d{2,6}$/', $entry)) {
                $out[] = $entry;
                continue;
            }
            $bad[] = $entry;
        }
        $out = array_values(array_unique($out));
        if (count($out) > 1000) {
            $out = array_slice($out, 0, 1000);
        }
        return $out;
    }

    /**
     * vendor=model lines. A model must be in the catalog.
     *
     * @param string[]|null $bad
     * @return array<string, string> vendor => model
     */
    public static function parseModels(string $text, ?array &$bad = null): array
    {
        $bad = [];
        $out = [];
        foreach (preg_split('/[\s,]+/', trim($text)) ?: [] as $entry) {
            if ($entry === '') {
                continue;
            }
            [$vendor, $model] = array_pad(explode('=', $entry, 2), 2, '');
            $vendor = strtolower(trim($vendor));
            $model = trim($model);
            if (!isset(PhoneModels::VENDORS[$vendor])) {
                $bad[] = $entry;
                continue;
            }
            // The model has to exist *and* belong to that vendor: a MAC prefix
            // says who made the phone, and "cisco=yealink-t46u" would write a
            // Yealink file for a Cisco phone on the next automatic run.
            if (PhoneModels::get($model) === null || PhoneModels::vendorOf($model) !== $vendor) {
                $bad[] = $entry;
                continue;
            }
            $out[$vendor] = $model;
        }
        return $out;
    }

    // ------------------------------------------------------------ the record

    /**
     * What is on the network, newest first, with what the portal knows about it.
     *
     * @return list<array<string, mixed>>
     */
    public static function listDiscovered(int $limit = self::PAGE_LIMIT): array
    {
        $rows = getDB()->query(
            'SELECT d.*, p.id AS live_phone_id, u.extension, u.full_name
             FROM pbx_phone_discoveries d
             LEFT JOIN pbx_phones p ON p.mac = d.mac
             LEFT JOIN sys_users u ON u.id = p.user_id
             ORDER BY (d.status = \'new\') DESC, d.last_seen_at DESC, d.mac
             LIMIT ' . max(1, min(self::PAGE_LIMIT, $limit))
        )->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            $row['state'] = self::stateOf($row);
        }
        unset($row);
        return $rows;
    }

    /** One row of the page in words: new / ignored / on the network / was deleted. */
    public static function stateOf(array $row): string
    {
        if (!empty($row['live_phone_id'])) {
            return 'provisioned';
        }
        if (($row['status'] ?? '') === 'ignored') {
            return 'ignored';
        }
        return ($row['status'] ?? '') === 'provisioned' ? 'deleted' : 'new';
    }

    /**
     * @return array<string, int> rows per state, for the panel's badges
     *
     * The joined phone has to be selected under the name stateOf() reads —
     * the same `live_phone_id` listDiscovered() uses. Under any other alias the
     * column is missing from every row, a row with a phone falls through to
     * its status, and this count disagrees with the badges inside the table.
     */
    public static function summary(): array
    {
        $counts = ['new' => 0, 'ignored' => 0, 'provisioned' => 0, 'deleted' => 0];
        foreach (getDB()->query('SELECT d.status, p.id AS live_phone_id FROM pbx_phone_discoveries d LEFT JOIN pbx_phones p ON p.mac = d.mac')->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $counts[self::stateOf($row)]++;
        }
        return $counts;
    }

    public static function get(int $id): ?array
    {
        $stmt = getDB()->prepare('SELECT * FROM pbx_phone_discoveries WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private static function touch(int $id, string $action): void
    {
        writeAuditLog('phones', 'phone_discovery', $id, '', $action, $_SESSION['user_id'] ?? null);
    }

    // ------------------------------------------------------------ the actions

    /** Ignore: never provisioned automatically again, however often it is seen. */
    public static function ignore(int $id, string $csrf): array
    {
        return PBXHelper::handleAction($csrf, function () use ($id) {
            $row = self::get($id);
            if ($row === null) {
                throw new \Exception(t('phones.err_not_found'));
            }
            getDB()->prepare("UPDATE pbx_phone_discoveries SET status = 'ignored' WHERE id = ?")->execute([$id]);
            self::touch($id, 'ignore');
            return t('phonedisc.ignored');
        });
    }

    /** Back to "new": the automatic step may look at it again. */
    public static function restore(int $id, string $csrf): array
    {
        return PBXHelper::handleAction($csrf, function () use ($id) {
            $row = self::get($id);
            if ($row === null) {
                throw new \Exception(t('phones.err_not_found'));
            }
            if ($row['status'] !== 'ignored') {
                throw new \Exception(t('phonedisc.err_not_ignored'));
            }
            getDB()->prepare("UPDATE pbx_phone_discoveries SET status = 'new' WHERE id = ?")->execute([$id]);
            self::touch($id, 'restore');
            return t('phonedisc.restored');
        });
    }

    /**
     * Approve: the one manual path, and it goes through the provisioning
     * service like every other way of adding a phone.
     */
    public static function approve(int $id, string $model, int $userId, string $csrf): array
    {
        return PBXHelper::handleAction($csrf, function () use ($id, $model, $userId) {
            $row = self::get($id);
            if ($row === null) {
                throw new \Exception(t('phones.err_not_found'));
            }
            if (self::isProvisioned((string) $row['mac'])) {
                throw new \Exception(t('phonedisc.err_already_provisioned'));
            }
            if (PhoneModels::get($model) === null) {
                throw new \Exception(t('phones.err_model'));
            }
            // May throw its own errors (unknown model, user without a SIP
            // extension); nothing is written to this table when it does.
            $phoneId = PhoneProvisionService::upsertPhone(0, (string) $row['mac'], $model, $userId);
            self::markProvisioned($id, $phoneId, (int) ($_SESSION['user_id'] ?? 0));
            PhoneProvisionService::exportTftpQuietly();
            return sprintf(t('phones.saved'), PhoneModels::formatMac((string) $row['mac']));
        });
    }

    private static function isProvisioned(string $mac): bool
    {
        return self::phoneIdForMac($mac) > 0;
    }

    /** The id of the phone with this MAC, or 0 when there is none. */
    private static function phoneIdForMac(string $mac): int
    {
        $stmt = getDB()->prepare('SELECT id FROM pbx_phones WHERE mac = ?');
        $stmt->execute([$mac]);
        return (int) ($stmt->fetchColumn() ?: 0);
    }

    private static function markProvisioned(int $id, int $phoneId, ?int $userId = null): void
    {
        getDB()->prepare(
            "UPDATE pbx_phone_discoveries SET status = 'provisioned', phone_id = ?, approved_at = NOW(),
             approved_by = ? WHERE id = ?"
        )->execute([$phoneId, $userId ?: null, $id]);
        self::touch($id, 'provision');
    }

    // ------------------------------------------------- the automatic step

    /**
     * Provisions the phones the administrator has allowed, and nothing else.
     *
     * Runs as the second half of the engine's cron entry
     * (`bin/aipbx_phone_discovery.php provision`), after the engine has written
     * what it saw. Everything it decides comes from the settings, so a box that
     * has never been configured records phones and creates none.
     *
     * @return array{created: list<string>, skipped: list<string>, reason: string, reconciled: int}
     */
    public static function provisionFromPool(int $limit = self::AUTO_MAX_PER_RUN): array
    {
        $out = ['created' => [], 'skipped' => [], 'reason' => '', 'reconciled' => 0];
        $settings = self::settings();
        if (!self::isOn($settings['provision_discovery'])) {
            $out['reason'] = t('phonedisc.off');
            return $out;
        }

        // The table's invariant, restored before anything is decided on it:
        // status = 'provisioned' exactly when a phone exists for that MAC. A
        // phone added by hand (or by the CSV import) after the engine recorded
        // the MAC would otherwise sit here as "new" for good.
        $out['reconciled'] = self::reconcile();

        $pool = self::parsePool($settings['provision_discovery_pool']);
        if ($pool === []) {
            $out['reason'] = t('phonedisc.no_pool');
            return $out;
        }
        $models = self::parseModels($settings['provision_discovery_models']);
        if ($models === []) {
            $out['reason'] = t('phonedisc.no_models');
            return $out;
        }

        $limit = max(1, min(self::AUTO_MAX_PER_RUN, $limit));
        $rows = self::candidates($limit);

        foreach ($rows as $row) {
            $mac = (string) $row['mac'];
            $vendor = strtolower((string) $row['vendor']);

            // Belt and braces: the query above already excludes a MAC that is a
            // phone, and this check runs again right before anything is written.
            if (($existing = self::phoneIdForMac($mac)) > 0) {
                self::markProvisioned((int) $row['id'], $existing);
                $out['skipped'][] = PhoneModels::formatMac($mac) . ': ' . t('phonedisc.already_provisioned');
                continue;
            }
            if (!isset($models[$vendor])) {
                $out['skipped'][] = PhoneModels::formatMac($mac) . ': ' . sprintf(t('phonedisc.no_model_for_vendor'), $row['vendor']);
                continue;
            }
            $user = self::nextPoolUser($pool);
            if ($user === null) {
                $out['skipped'][] = sprintf(t('phonedisc.pool_exhausted'), count($out['created']));
                break;
            }
            $phoneId = PhoneProvisionService::upsertPhone(0, $mac, $models[$vendor], (int) $user['id']);
            self::markProvisioned((int) $row['id'], $phoneId);
            $out['created'][] = PhoneModels::formatMac($mac) . ' → ' . $user['extension']
                . ' (' . (PhoneModels::get($models[$vendor])['label'] ?? $models[$vendor]) . ')';
        }
        if ($out['created'] !== []) {
            PhoneProvisionService::exportTftpQuietly();
        }
        return $out;
    }

    /**
     * Phones worth provisioning automatically: new, seen recently, not a phone,
     * oldest first so the queue does not starve.
     *
     * @return list<array<string, mixed>>
     */
    private static function candidates(int $limit): array
    {
        // $limit is an int this class clamped itself, so it is safe to inline
        // (a placeholder in LIMIT is not portable across the two PDO modes).
        return getDB()->query(
            "SELECT d.id, d.mac, d.vendor, d.ip
             FROM pbx_phone_discoveries d
             WHERE d.status = 'new'
               AND d.last_seen_at >= (NOW() - INTERVAL " . self::SEEN_WINDOW_HOURS . " HOUR)
               AND NOT EXISTS (SELECT 1 FROM pbx_phones p WHERE p.mac = d.mac)
             ORDER BY d.first_seen_at, d.id
             LIMIT " . $limit
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Marks the rows whose MAC is a phone. Returns how many rows changed.
     *
     * Runs before the candidates are picked, so a phone that was added by hand
     * (a + form, the CSV import, the waiting list) stops being an automatic
     * candidate the moment it exists — not only when the engine next sees it.
     */
    public static function reconcile(): int
    {
        return (int) getDB()->exec(
            "UPDATE pbx_phone_discoveries d
               JOIN pbx_phones p ON p.mac = d.mac
                SET d.status = 'provisioned', d.phone_id = p.id
              WHERE d.status <> 'provisioned' OR d.phone_id IS NULL OR d.phone_id <> p.id"
        );
    }

    /**
     * The first extension in the pool that is an active SIP user without a
     * phone, or null when there is none left.
     */
    private static function nextPoolUser(array $pool)
    {
        $db = getDB();
        $placeholders = implode(',', array_fill(0, count($pool), '?'));
        $stmt = $db->prepare(
            "SELECT u.id, u.extension FROM sys_users u
             WHERE u.extension IN ($placeholders)
               AND u.is_active = 1 AND u.extension_type = 'sip'
               AND NOT EXISTS (SELECT 1 FROM pbx_phones p WHERE p.user_id = u.id)
             ORDER BY u.extension + 0, u.extension LIMIT 1"
        );
        $stmt->execute($pool);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        return $user ?: null;
    }

    /**
     * The model a phone of this vendor would be provisioned as: the configured
     * one, else the first model of that vendor in the catalog. Used only to
     * pre-select the Approve form — nothing is provisioned from it.
     */
    public static function defaultModelForVendor(string $vendor): string
    {
        $vendor = strtolower($vendor);
        $models = self::parseModels(self::settings()['provision_discovery_models']);
        if (isset($models[$vendor])) {
            return $models[$vendor];
        }
        foreach (PhoneModels::MODELS as $key => $model) {
            if ($model['vendor'] === $vendor) {
                return $key;
            }
        }
        return '';
    }

    /** How many pool extensions are still free (shown on the page). */
    public static function poolFree(?array $pool = null): int
    {
        $pool = $pool ?? self::parsePool(self::settings()['provision_discovery_pool']);
        if ($pool === []) {
            return 0;
        }
        $db = getDB();
        $placeholders = implode(',', array_fill(0, count($pool), '?'));
        $stmt = $db->prepare(
            "SELECT COUNT(*) FROM sys_users u
             WHERE u.extension IN ($placeholders)
               AND u.is_active = 1 AND u.extension_type = 'sip'
               AND NOT EXISTS (SELECT 1 FROM pbx_phones p WHERE p.user_id = u.id)"
        );
        $stmt->execute($pool);
        return (int) $stmt->fetchColumn();
    }

    // -------------------------------------------------------------- the engine

    /** Where the engine keeps its log and its state (read-only for the page). */
    public static function engineDir(): string
    {
        return getenv('AIPBX_ENGINE_STATE') ?: '/var/lib/aipbx/engine';
    }

    /**
     * What the last engine run saw: its counters and its log tail, for the
     * panel. Empty when the engine has never run (a fresh install, or the
     * cron entry removed).
     *
     * @return array{state: array, log: list<string>}
     */
    public static function engineStatus(int $logLines = 5): array
    {
        $dir = self::engineDir();
        $state = [];
        $log = [];
        $statePath = $dir . '/state.json';
        if (is_readable($statePath)) {
            $decoded = json_decode((string) @file_get_contents($statePath), true);
            if (is_array($decoded)) {
                $state = $decoded;
            }
        }
        $logPath = $dir . '/engine.log';
        if (is_readable($logPath)) {
            $lines = @file($logPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
            $log = array_slice($lines, -max(1, $logLines));
        }
        return ['state' => $state, 'log' => $log];
    }

    public static function isOn(string $value): bool
    {
        return in_array(strtolower(trim($value)), ['1', 'yes', 'true', 'on'], true);
    }
}
