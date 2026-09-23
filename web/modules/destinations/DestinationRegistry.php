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
        $mod = self::getModule($destType);
        if (!$mod) return null;
        if (!isset($cache[$destType])) {
            $cache[$destType] = $mod->getOptions();
        }
        foreach ($cache[$destType] as $opt) {
            if ((string)$opt['id'] === (string)$destId) return $opt['name'];
        }
        return null;
    }

    /**
     * Consistent badge color per destination type, shared across all admin screens
     * (Gelen Rotalar, IVR, Zaman Koşulları) so the same target type always reads the
     * same color no matter which page it's shown on.
     */
    public static function badgeClassFor(?string $destType): string {
        static $map = [
            'queue'          => 'badge-success',
            'ivr'            => 'badge-primary',
            'extension'      => 'badge-info',
            'fax'            => 'badge-warning',
            'announcement'   => 'badge-purple',
            'time_condition' => 'badge-teal',
            'outbound_route' => 'badge-indigo',
            'hangup'         => 'badge-danger',
        ];
        return $map[$destType] ?? 'badge-info';
    }
}
