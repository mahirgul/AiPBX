<?php
/**
 * Loads a plugin's own PHP from the catalog, instead of from the portal tree.
 *
 * 1.34 can run a plugin's hook *files* (`admin/page.php`, `events/handler.php`,
 * `sync/*.php`) but not its library code, so today a package that ships a
 * service class or a CLI has to be copied into `web/` by hand. That copy is the
 * step this class removes: a manifest declares where its PHP is, and the
 * runtime makes those classes available without touching the portal tree.
 *
 * Two shapes are supported, because the existing packages need both:
 *
 *   "php": { "namespace": "EchoGuard\\Signaling\\", "path": "src/" }
 *       PSR-4. Nothing is required until a class is asked for: a page that
 *       never mentions the plugin costs nothing beyond one autoloader.
 *
 *   "php": { "files": { "src/SignalingCaptureService.php": ["SignalingCaptureService"] } }
 *       An explicit file -> the classes it declares, required once when the
 *       plugin is enabled. This is the migration path for the code we already
 *       have (global class names, `require_once` in the portal). The class list
 *       is not documentation: it is checked BEFORE the file is included, so a
 *       name collision is a recorded problem instead of a fatal redeclare that
 *       takes the whole portal down.
 *
 * Security is not "can this code run" — plugin PHP runs with the portal's own
 * privileges, and only the catalog's file permissions decide who can put code
 * there. What this class enforces:
 *
 *   - every path resolves INSIDE the plugin's own directory (realpath, so a
 *     symlink cannot climb out);
 *   - a plugin file or directory writable by group or other is refused (0666
 *     code in a root-owned catalog means anyone on the host can run PHP as the
 *     portal);
 *   - a namespace prefix can be claimed by one plugin only, and the class
 *     collision is reported rather than resolved by load order;
 *   - a manifest with a problem in `php`/`cron` is not half-booted: none of its
 *     PHP is loaded and none of its jobs run.
 *
 * The runtime is deliberately not the authorization point. Whether a plugin is
 * enabled is the loader's decision, passed in as a callable, so the two cannot
 * disagree.
 */

final class PluginRuntime
{
    /** @var list<string> the loader's catalogs, in its own precedence order */
    private array $catalogs;

    /** @var callable(string):bool */
    private $isEnabled;

    /** @var callable(string): mixed a loader that fails may return anything */
    private $settingsFor;

    /** @var array<string, array<string, mixed>> slug => manifest */
    private array $manifests = [];

    /** @var array<string, array<int, string>> slug => problems */
    private array $problems = [];

    /** @var array<string, string> namespace prefix => slug */
    private array $prefixes = [];

    private bool $discovered = false;
    private bool $booted = false;

    /** @var array{autoloaded: array<int, string>, included: array<int, string>, problems: array<int, string>} */
    private array $summary = ['autoloaded' => [], 'included' => [], 'problems' => []];

    /**
     * @param string|list<string> $catalog One catalog directory, or several in
     *        the loader's precedence order (the official catalog first, custom
     *        uploads last — a later root wins for the same slug, exactly as
     *        `PluginManager::discoverManifests()` merges them).
     */
    public function __construct(string|array $catalog, callable $isEnabled, ?callable $settingsFor = null)
    {
        $roots = is_array($catalog) ? $catalog : [$catalog];
        $this->catalogs = [];
        foreach ($roots as $root) {
            if ($root === '') {
                continue;
            }
            $root = rtrim($root, DIRECTORY_SEPARATOR);
            if (!in_array($root, $this->catalogs, true)) {
                $this->catalogs[] = $root;
            }
        }
        $this->isEnabled = $isEnabled;
        $this->settingsFor = $settingsFor ?? static fn(string $slug): array => [];
    }

    /** The first catalog root — the one a single-catalog caller means. */
    public function catalog(): string
    {
        return $this->catalogs[0] ?? '';
    }

    /** @return list<string> */
    public function catalogs(): array
    {
        return $this->catalogs;
    }

    /** @return array<string, array<int, string>> slug => problems */
    public function problems(): array
    {
        $this->discover();
        return $this->problems;
    }

    /** @return array<string, array<string, mixed>> */
    public function manifests(): array
    {
        $this->discover();
        return $this->manifests;
    }

    /** @return array<string, mixed>|null */
    public function manifest(string $slug): ?array
    {
        $this->discover();
        return $this->manifests[$slug] ?? null;
    }

    /** @return array<int, string> slugs the loader says are enabled, sorted */
    public function enabledSlugs(): array
    {
        $this->discover();
        $enabled = [];
        foreach ($this->manifests as $slug => $manifest) {
            try {
                $on = (bool) ($this->isEnabled)($slug);
            } catch (Throwable $e) {
                $this->problem($slug, 'the loader could not answer whether this plugin is enabled: '
                    . $e->getMessage());
                $on = false;
            }
            if ($on) {
                $enabled[] = $slug;
            }
        }
        sort($enabled);
        return $enabled;
    }

    /**
     * Enables the PHP of every enabled plugin.
     *
     * Registered in the same boot path that already loads the plugin hooks, so
     * a portal page can use a plugin's class exactly where it uses a core one.
     *
     * @return array{autoloaded: array<int, string>, included: array<int, string>, problems: array<int, string>}
     */
    public function boot(): array
    {
        if ($this->booted) {
            return $this->summary;
        }
        $this->booted = true;
        $this->discover();

        foreach ($this->enabledSlugs() as $slug) {
            if ($this->problems[$slug] ?? []) {
                // The manifest already failed validation: loading half of it
                // would be worse than loading none.
                continue;
            }
            $manifest = $this->manifests[$slug];
            $php = $manifest['php'] ?? null;
            if (!is_array($php)) {
                continue;
            }
            if (isset($php['files'])) {
                $this->bootFiles($slug, $php);
            } elseif (isset($php['namespace'])) {
                $this->bootPsr4($slug, $php);
            }
        }
        return $this->summary;
    }

    /**
     * The settings the plugin's own code should see.
     *
     * The value is fetched per call rather than cached: a cron job must read
     * the settings of its own run, not the ones a web request happened to load.
     *
     * @return array<string, mixed>
     */
    public function settings(string $slug): array
    {
        try {
            $settings = ($this->settingsFor)($slug);
            return is_array($settings) ? $settings : [];
        } catch (Throwable $e) {
            $this->problem($slug, 'settings unavailable: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * The declared cron jobs of one plugin, invalid ones removed.
     *
     * @return array<int, array<string, mixed>>
     */
    public function jobs(string $slug): array
    {
        $manifest = $this->manifest($slug);
        $jobs = $manifest['cron'] ?? null;
        if (!is_array($jobs) || ($this->problems[$slug] ?? [])) {
            return [];
        }
        return array_values($jobs);
    }

    /** @return array<string, mixed>|null */
    public function job(string $slug, string $name): ?array
    {
        foreach ($this->jobs($slug) as $job) {
            if (($job['job'] ?? null) === $name) {
                return $job;
            }
        }
        return null;
    }

    /**
     * The human commands a plugin offers, e.g. `report` or `apply`.
     *
     * They are separate from `cron` because a schedule is meaningless for a
     * command a person types, and because they need no lock or timeout: the
     * person watching is the supervisor.
     *
     * @return array<string, array<string, mixed>>
     */
    public function commands(string $slug): array
    {
        $manifest = $this->manifest($slug);
        $commands = $manifest['cli'] ?? null;
        if (!is_array($commands) || ($this->problems[$slug] ?? [])) {
            return [];
        }
        return $commands;
    }

    /** @return array<string, mixed>|null */
    public function command(string $slug, string $name): ?array
    {
        $commands = $this->commands($slug);
        $command = $commands[$name] ?? null;
        return is_array($command) ? $command : null;
    }

    /**
     * Resolves a declared entry to something callable with (settings, slug).
     *
     * `class` is preferred: it goes through the namespace map and reports a
     * missing method precisely. `file` is the escape hatch for ports; the file
     * must return a callable, like the existing `events/handler.php` does.
     */
    public function entry(string $slug, array $job): ?callable
    {
        $this->boot();
        if (!in_array($slug, $this->enabledSlugs(), true)) {
            // A stored manifest is not permission to run its code.
            $this->problem($slug, 'job entry requested for a plugin the loader has not enabled');
            return null;
        }
        $manifest = $this->manifest($slug);
        if ($manifest === null) {
            return null;
        }
        $dir = $this->pluginDirectory($slug);
        if ($dir === null) {
            return null;
        }

        if (isset($job['class']) && is_string($job['class'])) {
            $parts = explode('::', $job['class'], 2);
            $class = $parts[0];
            $method = $parts[1] ?? '__invoke';
            if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\\\\[A-Za-z_][A-Za-z0-9_]*)*$/', $class)
                    || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $method)) {
                $this->problem($slug, 'cron entry is not Class::method: ' . $job['class']);
                return null;
            }
            if (!class_exists($class)) {
                $this->problem($slug, 'cron entry class does not exist: ' . $class);
                return null;
            }
            if (!is_callable([$class, $method])) {
                $this->problem($slug, sprintf('cron entry %s::%s is not callable', $class, $method));
                return null;
            }
            // The third argument is for the CLI runner; a cron entry that
            // declares only (settings, slug) simply ignores it, as PHP allows.
            return static function (array $settings, string $slug, array $argv = []) use ($class, $method) {
                return $class::$method($settings, $slug, $argv);
            };
        }

        if (isset($job['file']) && is_string($job['file'])) {
            $path = $this->inside($dir, $job['file']);
            if ($path === null) {
                $this->problem($slug, 'cron file is outside the plugin or writable by others: '
                    . $job['file']);
                return null;
            }
            return static function (array $settings, string $slug, array $argv = []) use ($path) {
                // require, not require_once: a job that runs twice in a process
                // (a manual run after a scheduled one) gets a fresh closure and
                // no state carried over in the file's variables.
                $callable = require $path;
                if (!is_callable($callable)) {
                    throw new RuntimeException('plugin entry file did not return a callable: ' . $path);
                }
                return $callable($settings, $slug, $argv);
            };
        }

        return null;
    }

    // ------------------------------------------------------------- discovery

    private function discover(): void
    {
        if ($this->discovered) {
            return;
        }
        $this->discovered = true;

        $found = false;
        foreach ($this->catalogs as $root) {
            $catalog = realpath($root);
            if ($catalog === false) {
                // A missing custom-upload directory is the normal state on a
                // fresh install; only a run with no readable catalog at all
                // is worth reporting.
                continue;
            }
            $found = true;
            foreach (glob($catalog . '/*/plugin.json') ?: [] as $file) {
                $dir = dirname($file);
                $slug = basename($dir);
                $real = realpath($dir);
                if ($real === false || strpos($real, $catalog . DIRECTORY_SEPARATOR) !== 0) {
                    $this->problem($slug, 'plugin directory is not inside the catalog');
                    continue;
                }
                if ($this->sharedWritable($real)) {
                    $this->problem($slug, 'plugin directory is writable by group or other');
                    continue;
                }
                if ($this->sharedWritable($file)) {
                    $this->problem($slug, 'plugin.json is writable by group or other');
                    continue;
                }
                $raw = @file_get_contents($file);
                $data = is_string($raw) ? json_decode($raw, true) : null;
                if (!is_array($data)) {
                    $this->problem($slug, 'plugin.json is not a JSON object');
                    continue;
                }
                if (($data['slug'] ?? null) !== $slug) {
                    $this->problem($slug, 'slug in plugin.json does not match the folder name');
                    continue;
                }
                // Root order is the loader's precedence: a later catalog (an
                // uploaded plugin) replaces the same slug from the bundled
                // one — including the problems its earlier manifest had.
                if (isset($this->manifests[$slug])) {
                    unset($this->problems[$slug]);
                }
                $this->manifests[$slug] = $data;
                foreach ($this->validateRuntime($data, $slug) as $message) {
                    $this->problem($slug, $message);
                }
            }
        }
        if (!$found) {
            $this->problems['*'][] = 'no plugin catalog exists: ' . implode(', ', $this->catalogs);
        }
        ksort($this->manifests);
    }

    /**
     * Only the parts this runtime consumes. The loader's own validator decides
     * whether a manifest may be installed at all; this decides what may run.
     *
     * @param array<string, mixed> $manifest
     * @return array<int, string>
     */
    private function validateRuntime(array $manifest, string $slug): array
    {
        $problems = [];

        $php = $manifest['php'] ?? null;
        if ($php !== null) {
            if (!is_array($php)) {
                $problems[] = 'php must be an object';
            } else {
                foreach (array_keys($php) as $key) {
                    if (!in_array($key, ['namespace', 'path', 'files'], true)) {
                        $problems[] = sprintf('php.%s is not a recognised key', $key);
                    }
                }
                $psr4 = isset($php['namespace']) || isset($php['path']);
                $files = isset($php['files']);
                if ($psr4 === $files) {
                    $problems[] = 'php needs either namespace+path or files, not both';
                } elseif ($psr4) {
                    $prefix = $php['namespace'] ?? null;
                    $path = $php['path'] ?? null;
                    if (!is_string($prefix) || !preg_match(
                            '/^[A-Za-z_][A-Za-z0-9_]*(\\\\[A-Za-z_][A-Za-z0-9_]*)+\\\\$/',
                            $prefix)) {
                        $problems[] = 'php.namespace must be a vendor\\package\\ prefix ending in \\';
                    }
                    if (!is_string($path) || trim($path) === '') {
                        $problems[] = 'php.path is missing';
                    }
                } else {
                    $map = $php['files'];
                    if (!is_array($map) || $map === []) {
                        $problems[] = 'php.files must map a file to the classes it declares';
                    } else {
                        foreach ($map as $relative => $classes) {
                            if (!is_string($relative) || $relative === '') {
                                $problems[] = 'php.files has a file without a path';
                                continue;
                            }
                            if (!is_array($classes) || $classes === []) {
                                $problems[] = sprintf('php.files["%s"] must list the classes it declares', $relative);
                                continue;
                            }
                            foreach ($classes as $class) {
                                if (!is_string($class) || $class === '') {
                                    $problems[] = sprintf('php.files["%s"] has an empty class name', $relative);
                                }
                            }
                        }
                    }
                }
            }
        }

        $cron = $manifest['cron'] ?? null;
        if ($cron !== null) {
            if (!is_array($cron) || !array_is_list($cron)) {
                $problems[] = 'cron must be a list of jobs';
            } else {
                $seen = [];
                foreach ($cron as $index => $job) {
                    if (!is_array($job)) {
                        $problems[] = sprintf('cron[%d] is not an object', $index);
                        continue;
                    }
                    foreach (array_keys($job) as $key) {
                        if (!in_array($key, ['job', 'schedule', 'class', 'file', 'timeout'], true)) {
                            $problems[] = sprintf('cron[%d].%s is not a recognised key', $index, $key);
                        }
                    }
                    $name = $job['job'] ?? null;
                    if (!is_string($name) || !preg_match('/^[a-z0-9][a-z0-9_-]{0,31}$/', $name)) {
                        $problems[] = sprintf('cron[%d] needs a job name (a-z, 0-9, -, _; at most 32)', $index);
                    } elseif (isset($seen[$name])) {
                        $problems[] = sprintf('cron job "%s" is declared twice', $name);
                    } else {
                        $seen[$name] = true;
                    }
                    $schedule = $job['schedule'] ?? null;
                    if (!is_string($schedule)) {
                        $problems[] = sprintf('cron[%d] needs a schedule', $index);
                    } else {
                        foreach (PluginCronSchedule::validate($schedule) as $message) {
                            $problems[] = sprintf('cron[%d].schedule: %s', $index, $message);
                        }
                    }
                    $hasClass = isset($job['class']);
                    $hasFile = isset($job['file']);
                    if ($hasClass === $hasFile) {
                        $problems[] = sprintf('cron[%d] needs exactly one of class or file', $index);
                    }
                    if ($hasClass && (!is_string($job['class'])
                            || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\\\\[A-Za-z_][A-Za-z0-9_]*)*(::[A-Za-z_][A-Za-z0-9_]*)?$/',
                                $job['class']))) {
                        $problems[] = sprintf('cron[%d].class is not Class or Class::method', $index);
                    }
                    if ($hasFile && (!is_string($job['file']) || trim($job['file']) === '')) {
                        $problems[] = sprintf('cron[%d].file is empty', $index);
                    }
                    if (isset($job['timeout'])) {
                        if (!is_int($job['timeout']) || $job['timeout'] < 1 || $job['timeout'] > 3600) {
                            $problems[] = sprintf('cron[%d].timeout must be 1-3600 seconds', $index);
                        }
                    }
                }
            }
        }

        $cli = $manifest['cli'] ?? null;
        if ($cli !== null) {
            if (!is_array($cli) || array_is_list($cli)) {
                $problems[] = 'cli must map a command name to an entry';
            } else {
                foreach ($cli as $name => $entry) {
                    if (!is_string($name) || !preg_match('/^[a-z0-9][a-z0-9_-]{0,31}$/', $name)) {
                        $problems[] = sprintf('cli command names are a-z, 0-9, -, _ (at most 32): %s',
                            (string) $name);
                        continue;
                    }
                    if (!is_array($entry)) {
                        $problems[] = sprintf('cli.%s is not an object', $name);
                        continue;
                    }
                    foreach (array_keys($entry) as $key) {
                        if (!in_array($key, ['class', 'file'], true)) {
                            $problems[] = sprintf('cli.%s.%s is not a recognised key', $name, $key);
                        }
                    }
                    $hasClass = isset($entry['class']);
                    $hasFile = isset($entry['file']);
                    if ($hasClass === $hasFile) {
                        $problems[] = sprintf('cli.%s needs exactly one of class or file', $name);
                    }
                    if ($hasClass && (!is_string($entry['class'])
                            || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\\\\[A-Za-z_][A-Za-z0-9_]*)*(::[A-Za-z_][A-Za-z0-9_]*)?$/',
                                $entry['class']))) {
                        $problems[] = sprintf('cli.%s.class is not Class or Class::method', $name);
                    }
                    if ($hasFile && (!is_string($entry['file']) || trim($entry['file']) === '')) {
                        $problems[] = sprintf('cli.%s.file is empty', $name);
                    }
                }
            }
        }

        return $problems;
    }

    // ------------------------------------------------------------------- boot

    /** @param array<string, mixed> $php */
    private function bootFiles(string $slug, array $php): void
    {
        $dir = $this->pluginDirectory($slug);
        if ($dir === null) {
            return;
        }
        foreach ((array) $php['files'] as $relative => $classes) {
            $path = $this->inside($dir, (string) $relative);
            if ($path === null) {
                $this->problem($slug, 'php file is outside the plugin or writable by others: ' . $relative);
                continue;
            }
            $clash = null;
            foreach ((array) $classes as $class) {
                if (class_exists($class, false) || interface_exists($class, false)
                        || trait_exists($class, false)) {
                    $clash = (string) $class;
                    break;
                }
            }
            if ($clash !== null) {
                // Including the file now would be a fatal redeclare that takes
                // every page down. The plugin loses; the portal keeps working.
                $this->problem($slug, sprintf(
                    'php file %s declares %s, which is already defined; not loaded',
                    $relative, $clash));
                continue;
            }
            require_once $path;
            $this->summary['included'][] = $slug . ':' . $relative;
        }
    }

    /** @param array<string, mixed> $php */
    private function bootPsr4(string $slug, array $php): void
    {
        $dir = $this->pluginDirectory($slug);
        if ($dir === null) {
            return;
        }
        $prefix = (string) $php['namespace'];
        $base = $this->inside($dir, (string) $php['path']);
        if ($base === null || !is_dir($base)) {
            $this->problem($slug, 'php.path is not a directory inside the plugin: ' . $php['path']);
            return;
        }
        if (isset($this->prefixes[$prefix])) {
            $this->problem($slug, sprintf(
                'namespace %s is already claimed by %s; not registered',
                $prefix, $this->prefixes[$prefix]));
            return;
        }
        $this->prefixes[$prefix] = $slug;
        $length = strlen($prefix);

        spl_autoload_register(static function (string $class) use ($prefix, $base, $length): void {
            if (strncmp($class, $prefix, $length) !== 0) {
                return;
            }
            $relative = str_replace('\\', '/', substr($class, $length)) . '.php';
            $file = $base . '/' . $relative;
            if (!is_file($file)) {
                return;
            }
            $real = realpath($file);
            if ($real === false || strpos($real, $base . DIRECTORY_SEPARATOR) !== 0) {
                return; // a symlink out of the plugin: not our class
            }
            if ((@fileperms($real) & 0022) !== 0) {
                return; // writable by someone else: refuse to execute it
            }
            require_once $real;
        });
        $this->summary['autoloaded'][] = $prefix . ' => ' . $base;
    }

    // --------------------------------------------------------------- helpers

    private function pluginDirectory(string $slug): ?string
    {
        $this->discover();
        if (!preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/', $slug)) {
            return null;
        }
        // Last catalog first: it is the one whose manifest discover() kept.
        foreach (array_reverse($this->catalogs) as $root) {
            $catalog = realpath($root);
            if ($catalog === false || !is_dir($catalog . DIRECTORY_SEPARATOR . $slug)) {
                continue;
            }
            $dir = realpath($catalog . DIRECTORY_SEPARATOR . $slug);
            if ($dir === false || strpos($dir, $catalog . DIRECTORY_SEPARATOR) !== 0) {
                return null;
            }
            return $dir;
        }
        return null;
    }

    /**
     * A declared relative path -> the real path, or null when it is not safely
     * inside $base. Refuses traversal, absolute paths and shared-writable
     * files; the containment check happens after realpath, so symlinks are
     * resolved before they are judged.
     */
    private function inside(string $base, string $relative): ?string
    {
        $relative = str_replace('\\', '/', trim($relative));
        if ($relative === '' || $relative[0] === '/') {
            return null;
        }
        foreach (explode('/', $relative) as $segment) {
            if ($segment === '..') {
                return null;
            }
        }
        $root = realpath($base);
        $path = realpath($base . DIRECTORY_SEPARATOR . $relative);
        if ($root === false || $path === false) {
            return null;
        }
        if ($path !== $root && strpos($path, $root . DIRECTORY_SEPARATOR) !== 0) {
            return null;
        }
        if ($this->sharedWritable($path)) {
            return null;
        }
        return $path;
    }

    private function sharedWritable(string $path): bool
    {
        $perms = @fileperms($path);
        if ($perms === false) {
            return true;
        }
        return ($perms & 0022) !== 0;
    }

    private function problem(string $slug, string $message): void
    {
        $this->problems[$slug][] = $message;
        $this->summary['problems'][] = $slug . ': ' . $message;
    }
}
