<?php
namespace PBX\Destinations;

require_once __DIR__ . '/PBXDestinationInterface.php';

/**
 * Universal Plug-and-Play Destination Module Registry
 */
class DestinationRegistry {
    private static $modules = [];
    private static $initialized = false;

    public static function init() {
        if (self::$initialized) return;

        // Auto-load all module files in this directory
        $files = glob(__DIR__ . '/*Module.php');
        foreach ($files as $file) {
            require_once $file;
        }

        self::$initialized = true;
    }

    public static function register(PBXDestinationInterface $module) {
        self::$modules[$module->getKey()] = $module;
    }

    public static function getModules(): array {
        self::init();
        return self::$modules;
    }

    public static function getModule(string $key): ?PBXDestinationInterface {
        self::init();
        return self::$modules[$key] ?? null;
    }

    public static function getModuleList(): array {
        self::init();
        $list = [];
        foreach (self::$modules as $key => $mod) {
            $list[] = [
                'key' => $key,
                'name' => $mod->getName()
            ];
        }
        return $list;
    }

    public static function getOptionsFor(string $key): array {
        $mod = self::getModule($key);
        return $mod ? $mod->getOptions() : [];
    }

    /** Table => prefixes of its destination columns (<prefix>_type, <prefix>_id). */
    public const DEST_COLUMNS = [
        'pbx_dids' => ['dest'], 'pbx_ivr_entries' => ['dest'], 'pbx_ivrs' => ['timeout_dest', 'invalid_dest'],
        'pbx_time_conditions' => ['match_dest', 'nomatch_dest'], 'pbx_announcements' => ['post_dest'],
        'pbx_boss_secretary_groups' => ['fallback_dest'], 'pbx_ring_groups' => ['fallback_dest'],
        'pbx_ivr_data_steps' => ['found_dest', 'not_found_dest', 'timeout_dest'], 'pbx_web_widgets' => ['dest'],
        'pbx_ai_apps' => ['dest'], 'pbx_ai_agents' => ['handover_dest', 'fallback_dest'],
        'pbx_call_modifiers' => ['dest'],
    ];

    /**
     * How many places send calls to $type with one of $ids (a queue is stored
     * by name or by id): every destination column of DEST_COLUMNS and the rules
     * of time conditions. $except: table => null to leave it out (a caller that
     * lists those rows itself), or [column, value] to leave out the rows of the
     * thing being deleted (an IVR's own entries). A table of a feature that is
     * not installed counts as none.
     *
     * @param string|list<string> $ids
     * @param array<string, null|array{0: string, 1: int|string}> $except
     */
    public static function usageCount(string $type, $ids, array $except = []): int {
        $ids = array_values(array_unique(array_map('strval', (array) $ids)));
        $in = implode(',', array_fill(0, count($ids), '?'));
        $db = \getDB();
        $n = 0;
        $tables = self::DEST_COLUMNS + ['pbx_time_conditions#rules' => ['rules']];
        foreach ($tables as $key => $prefixes) {
            $table = explode('#', $key)[0];
            if (array_key_exists($table, $except) && $except[$table] === null) {
                continue;
            }
            [$skipSql, $skipArg] = isset($except[$table]) ? [' AND ' . $except[$table][0] . ' != ?', [$except[$table][1]]] : ['', []];
            foreach ($prefixes as $p) {
                try {
                    if ($p === 'rules') {
                        $alt = implode('|', array_map(fn($id) => preg_quote($id), $ids));
                        $st = $db->prepare("SELECT COUNT(*) FROM {$table} WHERE rules_json REGEXP ?{$skipSql}");
                        $st->execute(['"(match|nomatch)_dest_type":"' . preg_quote($type) . '","(match|nomatch)_dest_id":"?(' . $alt . ')"?[,}]', ...$skipArg]);
                    } else {
                        $st = $db->prepare("SELECT COUNT(*) FROM {$table} WHERE {$p}_type = ? AND {$p}_id IN ({$in}){$skipSql}");
                        $st->execute([$type, ...$ids, ...$skipArg]);
                    }
                    $n += (int) $st->fetchColumn();
                } catch (\Throwable $e) {
                }
            }
        }
        return $n;
    }

    /**
     * Resolve a stored dest_type/dest_id pair to its current human-readable name
     * (e.g. 'queue' + 'queue_cc' -> "CM (queue_cc)"). Returns null if the module is
     * unknown or the id no longer matches anything (e.g. the target was deleted) —
     * callers should treat null as "broken reference" rather than falling back to
     * the raw id silently.
     *
     * Pass the same $cache array (by reference) across repeated calls in a loop to
     * avoid re-querying getOptions() for every row of the same dest_type.
     */
    public static function resolveLabel(?string $destType, $destId, array &$cache = []): ?string {
        if (empty($destType)) return null;
        if ($destType === 'extension' && (string)$destId === '__did__') {
            self::init();
            return ExtensionModule::autoDidOption()['name'];
        }
        $mod = self::getModule($destType);
        if (!$mod) return null;
        if (!isset($cache[$destType])) {
            $cache[$destType] = $mod->getOptions();
        }
        foreach ($cache[$destType] as $opt) {
            if ((string)$opt['id'] === (string)$destId) return $opt['name'];
        }
        // A queue is listed by name, but the dialplan also accepts its numeric
        // id (DialplanDestinationBuilder), which is what the REST API and the
        // services store when given an id: the call reached the queue while
        // the IVR page said "not found".
        if ($destType === 'queue' && ctype_digit((string)$destId)) {
            $stmt = getDB()->prepare('SELECT queue_name FROM pbx_queues WHERE id = ?');
            $stmt->execute([(int)$destId]);
            $name = $stmt->fetchColumn();
            if ($name !== false) {
                foreach ($cache[$destType] as $opt) {
                    if ((string)$opt['id'] === (string)$name) return $opt['name'];
                }
            }
        }
        return null;
    }

    /**
     * Consistent badge color per destination type, shared across all admin screens
     * (inbound routes, IVR, time conditions) so the same target type always reads the
     * same color no matter which page it's shown on.
     */
    public static function badgeClassFor(?string $destType): string {
        static $map = [
            'queue'          => 'badge-success',
            'ivr'            => 'badge-primary',
            'extension'      => 'badge-info',
            'fax'            => 'badge-warning',
            'announcement'   => 'badge-purple',
            'call_modifier'  => 'badge-purple',
            'time_condition' => 'badge-teal',
            'outbound_route' => 'badge-indigo',
            'hangup'         => 'badge-danger',
        ];
        return $map[$destType] ?? 'badge-info';
    }
}
