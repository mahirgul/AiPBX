<?php
require_once __DIR__ . '/PhoneProvisionService.php';

/**
 * Regions of desk phones (PBX → Phones): a phone is put in one by hand, and gets the region's
 * addresses and settings in its files. An empty field takes the general provisioning value; "none"
 * in the proxy or syslog field means none for this region, as a phone outside cannot use an internal
 * one. The allowed networks stay general.
 */
final class PhoneRegionService
{
    public const NONE = 'none';

    /** Region field => the general setting it replaces. */
    private const FIELDS = [
        'sip_server' => 'provision_sip_server',
        'sip_proxy' => 'provision_sip_proxy',
        'base_url' => 'provision_base_url',
        'transport' => 'provision_transport',
        'srtp' => 'provision_srtp',
        'codecs' => 'provision_codecs',
        'ntp' => 'provision_ntp',
        'syslog' => 'provision_syslog',
        'timezone' => 'provision_timezone',
        'language' => 'provision_language',
    ];

    /** @var list<array<string, mixed>>|null */
    private static ?array $cache = null;

    /** @return list<array<string, mixed>> default first, then by name */
    public static function all(): array
    {
        if (self::$cache === null) {
            self::$cache = getDB()->query('SELECT * FROM pbx_phone_regions ORDER BY is_default DESC, name')->fetchAll(PDO::FETCH_ASSOC);
        }
        return self::$cache;
    }

    /** @return array<int, int> region id => phones in it; a phone without one counts in the default */
    public static function phoneCounts(): array
    {
        $counts = [];
        foreach (getDB()->query('SELECT region_id, COUNT(*) AS n FROM pbx_phones GROUP BY region_id')->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $id = (int) $row['region_id'] ?: self::defaultId();
            $counts[$id] = ($counts[$id] ?? 0) + (int) $row['n'];
        }
        return $counts;
    }

    public static function resetCache(): void
    {
        self::$cache = null;
    }

    public static function defaultId(): int
    {
        foreach (self::all() as $r) {
            if (!empty($r['is_default'])) {
                return (int) $r['id'];
            }
        }
        return 0;
    }

    /** @return array<string, mixed> the phone's region; a phone without one is in the default region */
    public static function forPhone(array $phone): array
    {
        $id = (int) ($phone['region_id'] ?? 0) ?: self::defaultId();
        foreach (self::all() as $r) {
            if ((int) $r['id'] === $id) {
                return $r;
            }
        }
        return [];
    }

    public static function exists(int $id): bool
    {
        foreach (self::all() as $r) {
            if ((int) $r['id'] === $id) {
                return true;
            }
        }
        return false;
    }

    /**
     * The general settings as this region's phones see them.
     *
     * @param array<string, string> $settings
     * @param array<string, mixed> $region
     * @return array<string, string>
     */
    public static function apply(array $settings, array $region): array
    {
        foreach (self::FIELDS as $field => $key) {
            $value = (string) ($region[$field] ?? '');
            if ($value !== '') {
                $settings[$key] = $value === self::NONE ? '' : $value;
            }
        }
        return $settings;
    }

    /**
     * The region fields of a form, checked. Throws on the first bad one.
     *
     * @return array<string, string>
     */
    public static function validate(array $data): array
    {
        $host = static function (string $field, string $err, bool $none = false) use ($data): string {
            $v = trim((string) ($data[$field] ?? ''));
            if ($v !== '' && !($none && $v === self::NONE) && !preg_match('/^[A-Za-z0-9]([A-Za-z0-9.-]{0,253}[A-Za-z0-9])?$/D', $v)) {
                throw new \Exception(t($err));
            }
            return $v;
        };
        $out = [
            'sip_server' => $host('sip_server', 'phones.err_sip_server'),
            'sip_proxy' => $host('sip_proxy', 'phones.err_sip_proxy', true),
            'ntp' => $host('ntp', 'phones.err_ntp'),
        ];
        $syslog = trim((string) ($data['syslog'] ?? ''));
        if ($syslog !== '' && $syslog !== self::NONE && filter_var($syslog, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            throw new \Exception(t('phones.err_syslog'));
        }
        $out['syslog'] = $syslog;
        $baseUrl = rtrim(trim((string) ($data['base_url'] ?? '')), '/');
        if ($baseUrl !== '' && !preg_match('~^(https?://)?[A-Za-z0-9]([A-Za-z0-9.-]{0,253}[A-Za-z0-9])?(:[0-9]{1,5})?$~iD', $baseUrl)) {
            throw new \Exception(t('phones.err_base_url'));
        }
        $out['base_url'] = $baseUrl;
        $transport = (string) ($data['transport'] ?? '');
        $out['transport'] = in_array($transport, ['udp', 'tls'], true) ? $transport : '';
        $srtp = (string) ($data['srtp'] ?? '');
        $out['srtp'] = in_array($srtp, ['0', '1', '2'], true) ? $srtp : '';
        // In the order of CODECS, as in the general settings; none ticked takes the general list.
        $out['codecs'] = implode(',', array_intersect(PhoneProvisionService::CODECS, (array) ($data['codecs'] ?? [])));
        $tz = (string) ($data['timezone'] ?? '');
        if ($tz !== '' && !in_array($tz, \DateTimeZone::listIdentifiers(), true)) {
            throw new \Exception(t('phones.err_timezone'));
        }
        $out['timezone'] = $tz;
        $lang = (string) ($data['language'] ?? '');
        $out['language'] = isset(UI_LANGUAGES[$lang]) ? $lang : '';
        return $out;
    }

    public static function save(array $data): array
    {
        return PBXHelper::handleAction($data['csrf_token'] ?? '', function () use ($data) {
            $id = (int) ($data['region_id'] ?? 0);
            $name = trim((string) ($data['name'] ?? ''));
            if ($name === '' || mb_strlen($name) > 50 || preg_match('/[\x00-\x1F\x7F]/', $name) === 1) {
                throw new \Exception(t('phones.region_err_name'));
            }
            $fields = self::validate($data);
            $db = getDB();
            $same = $db->prepare('SELECT id FROM pbx_phone_regions WHERE name = ? AND id <> ?');
            $same->execute([$name, $id]);
            if ($same->fetchColumn() !== false) {
                throw new \Exception(t('phones.region_err_taken'));
            }
            if ($id > 0 && !self::exists($id)) {
                throw new \Exception(t('phones.region_err_missing'));
            }
            $columns = array_keys($fields);
            if ($id > 0) {
                $set = implode(', ', array_map(static fn(string $c): string => "{$c} = ?", $columns));
                $db->prepare("UPDATE pbx_phone_regions SET name = ?, {$set} WHERE id = ?")->execute([$name, ...array_values($fields), $id]);
            } else {
                $marks = implode(', ', array_fill(0, count($columns), '?'));
                $db->prepare('INSERT INTO pbx_phone_regions (name, ' . implode(', ', $columns) . ") VALUES (?, {$marks})")->execute([$name, ...array_values($fields)]);
                $id = (int) $db->lastInsertId();
            }
            self::resetCache();
            writeAuditLog('phones', 'phone_region', $id, $name, !empty($data['region_id']) ? 'update' : 'create', $_SESSION['user_id'] ?? null);
            PhoneProvisionService::exportTftpQuietly();
            return sprintf(t('phones.region_saved'), $name);
        });
    }

    /** The default region and a region with phones stay, so no phone is moved without a choice. */
    public static function delete(array $data): array
    {
        return PBXHelper::handleAction($data['csrf_token'] ?? '', function () use ($data) {
            $id = (int) ($data['region_id'] ?? 0);
            $region = self::forPhone(['region_id' => $id]);
            if ($region === [] || (int) $region['id'] !== $id) {
                throw new \Exception(t('phones.region_err_missing'));
            }
            if (!empty($region['is_default'])) {
                throw new \Exception(t('phones.region_err_default'));
            }
            $db = getDB();
            $count = $db->prepare('SELECT COUNT(*) FROM pbx_phones WHERE region_id = ?');
            $count->execute([$id]);
            $phones = (int) $count->fetchColumn();
            if ($phones > 0) {
                throw new \Exception(sprintf(t('phones.region_err_used'), $phones));
            }
            $db->prepare('DELETE FROM pbx_phone_regions WHERE id = ?')->execute([$id]);
            self::resetCache();
            writeAuditLog('phones', 'phone_region', $id, (string) $region['name'], 'delete', $_SESSION['user_id'] ?? null);
            return t('phones.region_deleted');
        });
    }
}
