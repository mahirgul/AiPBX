<?php
/**
 * The one place where the loader and the new runtime meet.
 *
 * Everything else in this draft takes its dependencies as arguments, so it can
 * be tested without a portal, a database or a loader. This file is the seam:
 * it answers "which catalog, which plugins are on, what are their settings,
 * where is the state" using the 1.34 loader's own API, and nothing else does.
 *
 * It is deliberately thin. If the loader moves a method or renames a table,
 * these four functions are the whole migration — not every plugin entry point.
 */

require_once __DIR__ . '/PluginCronSchedule.php';
require_once __DIR__ . '/PluginRuntime.php';
require_once __DIR__ . '/PluginCronStore.php';
require_once __DIR__ . '/PdoCronStore.php';
require_once __DIR__ . '/PluginProcessRunner.php';
require_once __DIR__ . '/PluginCron.php';

final class PluginBootstrap
{
    /** One runtime per process: boot() registers autoloaders, so a second
     *  instance would register them twice. */
    private static ?PluginRuntime $runtime = null;

    /**
     * The plugin catalogs, in the loader's own precedence order.
     *
     * These are `PluginManager`'s two answers, not a second guess at them: the
     * bundled catalog ships with the portal, custom uploads live in
     * `AIPBX_CUSTOM_PLUGINS_DIR` (or `/var/lib/aipbx/plugins`), and the loader
     * lets the latter replace a bundled plugin of the same slug. If the loader
     * ever moves a directory, this method moves with it and nothing else does.
     *
     * @return list<string>
     */
    public static function catalogs(): array
    {
        $manager = __DIR__ . '/PluginManager.php';
        if (is_file($manager)) {
            require_once $manager;
            // Reflection, not method_exists(): the class that is installed at
            // run time may be an older loader without these two methods, even
            // though this tree's copy has them.
            if (class_exists('PluginManager')
                    && (new ReflectionClass('PluginManager'))->hasMethod('catalogDir')
                    && (new ReflectionClass('PluginManager'))->hasMethod('customDir')) {
                return [PluginManager::catalogDir(), PluginManager::customDir()];
            }
        }
        // A loader that does not expose its catalogs (an older one, or a stub
        // in a test): the bundled catalog plus the documented custom location,
        // which is the same variable `customDir()` reads.
        $roots = [dirname(__DIR__, 3) . '/plugins'];
        $custom = getenv('AIPBX_CUSTOM_PLUGINS_DIR');
        if (is_string($custom) && $custom !== '') {
            $roots[] = rtrim($custom, DIRECTORY_SEPARATOR);
        }
        return $roots;
    }

    public static function runtime(): PluginRuntime
    {
        return self::$runtime ??= new PluginRuntime(
            self::catalogs(),
            [self::class, 'isEnabled'],
            [self::class, 'settings']
        );
    }

    /** Whether the loader has this plugin enabled. No loader, no plugin. */
    public static function isEnabled(string $slug): bool
    {
        $manager = __DIR__ . '/PluginManager.php';
        if (is_file($manager)) {
            require_once $manager;
            if (class_exists('PluginManager') && PluginManager::isEnabled($slug)) {
                return true;
            }
        }
        return false;
    }

    /**
     * A plugin's settings, with secrets in clear.
     *
     * `false` masks nothing, and that is deliberate: this is the same call the
     * loader's own `worker/tick.php` gets, and a job that talks to an SMTP
     * server or an API needs the real value. Nothing here is ever echoed —
     * `aipbx_plugin_cron.php` prints the job's output, and a job that prints a
     * secret is the job's bug, not the runner's.
     *
     * @return array<string, mixed>
     */
    public static function settings(string $slug): array
    {
        $manager = __DIR__ . '/PluginManager.php';
        if (is_file($manager)) {
            require_once $manager;
            if (class_exists('PluginManager')) {
                return PluginManager::getAllSettings($slug, false);
            }
        }
        return [];
    }

    public static function store(): PluginCronStore
    {
        return new PdoCronStore(getDB());
    }

    public static function runner(): PluginProcessRunner
    {
        return new PluginProcessRunner(PHP_BINARY, dirname(__DIR__, 2) . '/bin/plugin_job.php');
    }

    /**
     * Locks live on the host, not in the database: flock is released by the
     * kernel when a process dies, which a table row cannot promise.
     */
    public static function lockDir(): string
    {
        $dir = getenv('AIPBX_PLUGIN_LOCK_DIR');
        if (is_string($dir) && $dir !== '') {
            return rtrim($dir, DIRECTORY_SEPARATOR);
        }
        return '/var/lib/aipbx/plugin-locks';
    }
}
