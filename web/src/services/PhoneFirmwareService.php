<?php
require_once dirname(__DIR__) . '/helpers.php';
require_once __DIR__ . '/phones/PhoneModels.php';
require_once __DIR__ . '/phones/CiscoFirmware.php';

/**
 * Switching a Cisco 7900-series phone from SCCP to Cisco's SIP firmware, as
 * part of provisioning instead of as a manual chore.
 *
 * How the switch works on the phone (verified: Cisco's firmware-upgrade
 * documentation and the 7941/7942 Asterisk guides): the phone reads its
 * `SEP<MAC>.cnf.xml`, compares the `<loadInformation>` in it with the load in
 * flash, downloads the one named if it differs, programs it and reboots. So the
 * server side of the switch is exactly two things — name a SIP load in the
 * phone's file, and have the load's files where the phone fetches them. The one
 * part no server can do is power the phone on the other side of that read: a
 * phone sitting on SCCP has to restart (power cycle, or a PoE port bounce) to
 * read the new file. Everything else is here.
 *
 * Deliberate limits, each of which would be a way to take a phone off the
 * network:
 *
 *  - Only the 7900 series is switched. The 8800 series' enterprise firmware is
 *    SIP already (Cisco's own firmware tables list no SCCP load for it), and
 *    enterprise↔MPP is a licensed migration with its own firmware, not a load
 *    name — never something to do because a settings field was filled in.
 *  - A load is armed only when the *model* has its own entry in
 *    `provision_cisco_loads`. The single `provision_cisco_load` field is what an
 *    administrator typed for the phone in front of them and is still written to
 *    every CallManager phone as before; it is not a statement about a model,
 *    and an automatic run must not point a 7941 at a 7962's load.
 *  - The load's file set is checked first (CiscoFirmware), and a missing file
 *    is recorded rather than repaired: the phone would stall at "Upgrading",
 *    and the fix is copying the rest of the package, not another config.
 *  - Nothing is sent to the phone. Arming is a file change plus a row; the
 *    phone acts on it at its next boot, and the row says whether it has.
 */
final class PhoneFirmwareService
{
    /** sys_settings key: one `model=load` per line (see PhoneProvisionService::SETTINGS). */
    public const SETTING = 'provision_cisco_loads';

    /** The load is in the phone's file; waiting for the phone to read it. */
    public const ARMED = 'armed';
    /** The phone has fetched its file since arming: it rebooted and took the config. */
    public const FETCHED = 'fetched';
    /** Armed, but the load's files are not on this server (see firmware_note). */
    public const INCOMPLETE = 'incomplete';

    /**
     * Is this a phone that can arrive running SCCP? The 7900 series can; the
     * 8800 series' enterprise firmware is SIP already.
     */
    public static function needsSwitch(string $model): bool
    {
        return PhoneModels::vendorOf($model) === 'cisco-cm' && !str_starts_with($model, 'cisco-cm-8');
    }

    /**
     * `model=load` lines from the settings form.
     *
     * A model that does not exist, is not a CallManager phone or cannot be
     * switched, and a load name that is not a file name, are returned in $bad
     * (the caller turns them into one message) instead of being dropped: a typo
     * here would otherwise mean a phone that is silently never switched.
     *
     * @param-out array<int, string> $bad
     * @return array<string, string> model => load
     */
    public static function parseLoads(string $text, ?array &$bad = null): array
    {
        $bad = [];
        $out = [];
        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $parts = array_map('trim', explode('=', $line, 2));
            if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === ''
                || !self::needsSwitch($parts[0]) || !CiscoFirmware::validLoad($parts[1])) {
                $bad[] = $line;
                continue;
            }
            $out[$parts[0]] = $parts[1];
        }
        return $out;
    }

    /** @return array<string, string> model => load, as stored */
    public static function loads(): array
    {
        return self::parseLoads((string) getSystemSetting(self::SETTING, ''));
    }

    /**
     * The load written into a phone's file: its model's entry, else the single
     * `provision_cisco_load` field (unchanged behaviour of the older setting).
     */
    public static function loadForModel(string $model): string
    {
        $loads = self::loads();
        if (isset($loads[$model])) {
            return $loads[$model];
        }
        return trim((string) getSystemSetting('provision_cisco_load', ''));
    }

    /** The load the automatic switch may use for a model ('' = this model is not switched automatically). */
    public static function loadForSwitch(string $model): string
    {
        $loads = self::loads();
        return $loads[$model] ?? '';
    }

    /**
     * Arms the switch for one phone: resolves the load, checks its files, and
     * records the outcome. Called on every phone create/update, so it is
     * idempotent — a phone already armed for the same load is left alone.
     *
     * @return array{state: string, load: string, model: string, missing: list<string>, changed: bool}
     *         state is '' when this phone is not switched automatically (not a
     *         7900, or no load configured for its model).
     */
    public static function arm(int $phoneId, ?string $dir = null): array
    {
        $out = ['state' => '', 'load' => '', 'model' => '', 'missing' => [], 'changed' => false];
        $stmt = getDB()->prepare('SELECT id, model, firmware_load, firmware_state, last_fetch_at FROM pbx_phones WHERE id = ?');
        $stmt->execute([$phoneId]);
        $phone = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$phone) {
            return $out;
        }
        $model = (string) $phone['model'];
        $out['model'] = $model;
        $load = self::needsSwitch($model) ? self::loadForSwitch($model) : '';
        if ($load === '') {
            return $out;
        }
        $out['load'] = $load;

        // Already pointed at this load and not waiting on a file set: nothing to do.
        if ((string) $phone['firmware_load'] === $load
            && in_array((string) $phone['firmware_state'], [self::ARMED, self::FETCHED], true)) {
            $out['state'] = (string) $phone['firmware_state'];
            return $out;
        }

        $check = CiscoFirmware::check($load, $dir);
        $missing = $check['missing'];
        $state = $check['state'] === 'ready' ? self::ARMED : self::INCOMPLETE;
        $note = match ($check['state']) {
            'ready' => '',
            'unreadable' => 'unreadable',
            default => implode(', ', $missing),
        };
        if (mb_strlen($note) > 255) {
            $note = mb_substr($note, 0, 252) . '...';
        }
        getDB()->prepare(
            'UPDATE pbx_phones SET firmware_load = ?, firmware_state = ?, firmware_note = ?, firmware_at = NOW() WHERE id = ?'
        )->execute([$load, $state, $note, $phoneId]);

        $out['state'] = $state;
        $out['missing'] = $missing;
        $out['changed'] = true;
        return $out;
    }

    /**
     * arm() for a caller that must not fail because of it: a phone is created
     * whether or not its firmware can be sorted out, and a broken TFTP folder
     * is a note in the log, not a 500 on the page.
     */
    public static function armQuietly(int $phoneId): void
    {
        try {
            self::arm($phoneId);
        } catch (\Throwable $e) {
            error_log('phones: firmware switch not armed for phone ' . $phoneId . ': ' . $e->getMessage());
        }
    }

    /**
     * The phone read its file after the switch was armed: it has rebooted onto
     * (or at least taken) the configuration. Called from the provisioning
     * request handler, so it is a single conditional UPDATE, no read.
     */
    public static function noteFetched(int $phoneId): void
    {
        try {
            getDB()->prepare("UPDATE pbx_phones SET firmware_state = ? WHERE id = ? AND firmware_state = ?")
                ->execute([self::FETCHED, $phoneId, self::ARMED]);
        } catch (\Throwable $e) {
            error_log('phones: firmware fetch not recorded for phone ' . $phoneId . ': ' . $e->getMessage());
        }
    }

    /**
     * What the switch currently says about one phone.
     *
     * @return array{state: string, load: string, note: string, at: ?string, model: string, fetched: bool}
     */
    public static function state(int $phoneId): array
    {
        $stmt = getDB()->prepare('SELECT model, firmware_load, firmware_state, firmware_note, firmware_at, last_fetch_at FROM pbx_phones WHERE id = ?');
        $stmt->execute([$phoneId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        return [
            'state' => (string) ($row['firmware_state'] ?? ''),
            'load' => (string) ($row['firmware_load'] ?? ''),
            'note' => (string) ($row['firmware_note'] ?? ''),
            'at' => $row['firmware_at'] ?? null,
            'model' => (string) ($row['model'] ?? ''),
            'fetched' => ($row['last_fetch_at'] ?? null) !== null,
        ];
    }

    /**
     * Arms every CallManager phone that is already provisioned. The retrofit
     * for a fleet that existed before the automatic switch: `provision` only
     * arms phones it creates.
     *
     * @return list<array{mac: string, state: string, load: string, missing: list<string>}>
     */
    public static function armAll(?string $dir = null): array
    {
        $rows = getDB()->query("SELECT id, mac, model FROM pbx_phones WHERE model LIKE 'cisco-cm-%' ORDER BY mac")
            ->fetchAll(PDO::FETCH_ASSOC);
        $out = [];
        foreach ($rows as $row) {
            if (!self::needsSwitch((string) $row['model'])) {
                continue;
            }
            $res = self::arm((int) $row['id'], $dir);
            $out[] = [
                'mac' => PhoneModels::formatMac((string) $row['mac']),
                'state' => $res['state'],
                'load' => $res['load'],
                'missing' => $res['missing'],
            ];
        }
        return $out;
    }

    public static function armAllQuietly(?string $dir = null): void
    {
        try {
            self::armAll($dir);
        } catch (\Throwable $e) {
            error_log('phones: firmware switch not armed for fleet: ' . $e->getMessage());
        }
    }
}
