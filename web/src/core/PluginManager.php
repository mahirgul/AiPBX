<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/helpers.php';
require_once dirname(__DIR__) . '/secret_box.php';
require_once dirname(__DIR__) . '/services/ApiTokenService.php';

/**
 * First-Class Plugin Manager for AiPBX.
 *
 * Manages:
 * - Official Catalog plugins in `<repo>/plugins/<slug>/plugin.json`
 * - Custom uploaded plugins in `/var/lib/aipbx/plugins/<slug>/plugin.json`
 * - Database state in `sys_plugins`, `sys_plugin_settings`, `sys_plugin_kv`
 * - 7 Hook types: `admin_page`, `auth_provider`, `sync_domain`, `events`,
 *   `public_endpoints`, `ai_provider`, `worker`
 */
final class PluginManager
{
    /** @var array<string, array<string, mixed>>|null */
    private static ?array $dbCache = null;

    /** @var array<string, array<string, mixed>>|null */
    private static ?array $manifestCache = null;

    /**
     * Set when this request enabled, disabled, removed or installed a plugin:
     * the sidebar shows plugin pages, and an SPA form submission does not
     * redraw the sidebar, so the layout tells the browser to fetch a new one.
     */
    public static bool $menuChanged = false;

    public static function catalogDir(): string
    {
        return dirname(__DIR__, 3) . '/plugins';
    }

    public static function customDir(): string
    {
        $envDir = portalEnv('AIPBX_CUSTOM_PLUGINS_DIR', '');
        if ($envDir !== '') {
            return rtrim($envDir, '/');
        }
        return is_dir('/var/lib/aipbx') ? '/var/lib/aipbx/plugins' : sys_get_temp_dir() . '/aipbx_plugins';
    }

    public static function resetCache(): void
    {
        self::$dbCache = null;
        self::$manifestCache = null;
    }

    /**
     * Load all rows from `sys_plugins` keyed by slug.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function installedMap(): array
    {
        if (self::$dbCache !== null) {
            return self::$dbCache;
        }
        self::$dbCache = [];
        try {
            $rows = getDB()->query('SELECT * FROM sys_plugins ORDER BY name ASC')->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $r) {
                self::$dbCache[(string)$r['slug']] = $r;
            }
        } catch (\Throwable $e) {
            // Table may not exist yet before migration runs
        }
        return self::$dbCache;
    }

    /**
     * Discover all valid `plugin.json` manifests across both the official
     * GitHub catalog (`<repo>/plugins/`) and custom uploads (`/var/lib/aipbx/plugins/`).
     *
     * @return array<string, array<string, mixed>>
     */
    public static function discoverManifests(): array
    {
        if (self::$manifestCache !== null) {
            return self::$manifestCache;
        }
        self::$manifestCache = [];

        $sources = [
            'official' => self::catalogDir(),
            'custom'   => self::customDir(),
        ];

        foreach ($sources as $sourceType => $baseDir) {
            if (!is_dir($baseDir)) {
                continue;
            }
            foreach (glob($baseDir . '/*/plugin.json') ?: [] as $manifestFile) {
                $raw = @file_get_contents($manifestFile);
                if (!is_string($raw) || $raw === '') {
                    continue;
                }
                $manifest = json_decode($raw, true);
                if (!is_array($manifest)) {
                    continue;
                }
                $slug = (string)($manifest['slug'] ?? basename(dirname($manifestFile)));
                if (!preg_match('/^[a-z0-9][a-z0-9-]{1,48}$/', $slug)) {
                    continue;
                }
                $manifest['slug'] = $slug;
                $manifest['source'] = $sourceType;
                $manifest['dir'] = dirname($manifestFile);
                self::$manifestCache[$slug] = $manifest;
            }
        }

        return self::$manifestCache;
    }

    /**
     * Return unified list of all plugins (official catalog + custom uploaded)
     * merged with their installation/enabled status in `sys_plugins`.
     *
     * @return list<array<string, mixed>>
     */
    public static function allPlugins(): array
    {
        $manifests = self::discoverManifests();
        $installed = self::installedMap();
        $lang = function_exists('getUserLanguage') ? getUserLanguage() : 'en';

        $allSlugs = array_values(array_unique(array_merge(array_keys($manifests), array_keys($installed))));
        $out = [];

        foreach ($allSlugs as $slug) {
            $m = $manifests[$slug] ?? [];
            $dbRow = $installed[$slug] ?? null;

            $name = (string)($m['name_i18n'][$lang] ?? $m['name'] ?? $dbRow['name'] ?? $slug);
            $desc = (string)($m['description_i18n'][$lang] ?? $m['description'] ?? '');
            $category = (string)($m['category'] ?? 'integration');
            $icon = (string)($m['icon'] ?? 'fas fa-puzzle-piece');
            $iconStyle = (string)($m['icon_style'] ?? '');

            $hooks = is_array($m['hooks'] ?? null) ? $m['hooks'] : [];
            $adminPageHook = is_array($hooks['admin_page'] ?? null) ? $hooks['admin_page'] : null;
            $settingsUrl = null;
            if ($adminPageHook) {
                $settingsUrl = !empty($adminPageHook['route'])
                    ? (string)$adminPageHook['route']
                    : '/plugins/' . $slug;
            } elseif (!empty($m['settings_schema'])) {
                $settingsUrl = '/plugins/' . $slug;
            }

            // "Official" comes from where the plugin is installed, never from
            // its own manifest: an uploaded .zip could claim "type": "official"
            // and get the badge. A bundled plugin may still mark itself community.
            $source = (string)($m['source'] ?? $dbRow['source'] ?? 'official');
            $rawType = (string)($m['type'] ?? ($source === 'official' ? 'official' : 'community'));
            $type = ($source === 'official' && $rawType === 'official') ? 'official' : 'community';

            $rawAuthorUrl = trim((string)($m['author_url'] ?? ''));
            $authorUrl = (preg_match('#^https?://#i', $rawAuthorUrl) === 1) ? $rawAuthorUrl : '';

            $out[] = [
                'slug'            => $slug,
                'name'            => $name,
                'description'     => $desc,
                'version'         => (string)($m['version'] ?? $dbRow['version'] ?? '1.0.0'),
                'author'          => (string)($m['author'] ?? 'AiPBX Official Team'),
                'author_url'      => $authorUrl,
                'type'            => $type,
                'category'        => $category,
                'icon'            => $icon,
                'icon_style'      => $iconStyle,
                'source'          => (string)($m['source'] ?? $dbRow['source'] ?? 'official'),
                'installed'       => $dbRow !== null,
                'enabled'         => $dbRow !== null && !empty($dbRow['enabled']),
                'worker_enabled'  => $dbRow !== null && !empty($dbRow['worker_enabled']),
                'has_worker'      => !empty($hooks['worker']),
                'settings_url'    => $settingsUrl,
                'scopes'          => is_array($m['scopes'] ?? null) ? $m['scopes'] : [],
                'hooks'           => $hooks,
                'settings_schema' => is_array($m['settings_schema'] ?? null) ? $m['settings_schema'] : [],
                'installed_at'    => $dbRow['installed_at'] ?? null,
                'updated_at'      => $dbRow['updated_at'] ?? null,
            ];
        }

        return $out;
    }

    public static function getPlugin(string $slug): ?array
    {
        foreach (self::allPlugins() as $p) {
            if ($p['slug'] === $slug) {
                return $p;
            }
        }
        return null;
    }

    /**
     * Fast check if a plugin is currently installed and enabled.
     */
    public static function isEnabled(string $slug): bool
    {
        $installed = self::installedMap();
        return isset($installed[$slug]) && !empty($installed[$slug]['enabled']);
    }

    /**
     * Install and enable a plugin from the catalog or custom directory.
     *
     * @return array<string, mixed>
     */
    public static function enable(string $slug, string $csrfToken): array
    {
        return PBXHelper::handleAction($csrfToken, function () use ($slug) {
            $manifests = self::discoverManifests();
            $m = $manifests[$slug] ?? null;
            if (!$m) {
                throw new \Exception(sprintf(t('plugins.err_not_found', 'Plugin "%s" not found in catalog.'), $slug));
            }

            $db = getDB();
            $stmt = $db->prepare(
                "INSERT INTO sys_plugins (slug, name, version, source, enabled, worker_enabled, config_version, installed_at, updated_at)
                 VALUES (?, ?, ?, ?, 1, ?, 1, NOW(), NOW())
                 ON DUPLICATE KEY UPDATE name = VALUES(name), version = VALUES(version), enabled = 1, updated_at = NOW()"
            );
            $hasWorker = !empty($m['hooks']['worker']) ? 1 : 0;
            $stmt->execute([
                $slug,
                (string)($m['name'] ?? $slug),
                (string)($m['version'] ?? '1.0.0'),
                (string)($m['source'] ?? 'official'),
                $hasWorker,
            ]);

            // Seed default settings from settings_schema if not already set
            if (!empty($m['settings_schema']) && is_array($m['settings_schema'])) {
                foreach ($m['settings_schema'] as $field) {
                    $k = (string)($field['key'] ?? '');
                    if ($k === '' || !isset($field['default'])) {
                        continue;
                    }
                    $existing = DBHelper::fetchColumn(
                        'SELECT setting_value FROM sys_plugin_settings WHERE plugin_slug = ? AND setting_key = ?',
                        [$slug, $k]
                    );
                    if ($existing === false) {
                        self::setSetting($slug, $k, (string)$field['default'], !empty($field['secret']));
                    }
                }
            }

            // Issue scoped loopback API token if the plugin uses a background worker or scopes
            if ($hasWorker || !empty($m['scopes'])) {
                $scopes = is_array($m['scopes'] ?? null) ? $m['scopes'] : [];
                $rawToken = ApiTokenService::issuePluginToken($slug, (string)($m['name'] ?? $slug), $scopes);
                self::setSetting($slug, '_worker_api_token', $rawToken, true);
            }

            self::resetCache();

            // Apply plugin database schema (schema.sql -> plg_* tables only)
            if (!empty($m['dir'])) {
                self::applyPluginSchema($slug, (string)$m['dir']);
            }

            // If plugin defines an Asterisk sync_domain hook, mark it pending
            if (!empty($m['hooks']['sync_domain'])) {
                try {
                    require_once dirname(__DIR__) . '/asterisk_sync.php';
                    $dom = (string)$m['hooks']['sync_domain'];
                    $targetDom = defined('PENDING_SYNC_DOMAIN_MAP') && isset(PENDING_SYNC_DOMAIN_MAP[$dom]) ? $dom : 'general_dialplan';
                    markPendingSync($targetDom, 'plugin', $slug, "Plugin enabled: {$m['name']}", 'update', $_SESSION['user_id'] ?? null);
                } catch (\Throwable $e) {
                }
            }

            writeAuditLog('plugins', 'plugin', $slug, "Plugin enabled: {$m['name']} (v{$m['version']})", 'update', $_SESSION['user_id'] ?? null);
            return sprintf(t('plugins.msg_enabled', 'Plugin "%s" has been installed and enabled.'), (string)($m['name'] ?? $slug));
        });
    }

    /**
     * Disable an active plugin without deleting its saved settings.
     *
     * @return array<string, mixed>
     */
    public static function disable(string $slug, string $csrfToken): array
    {
        return PBXHelper::handleAction($csrfToken, function () use ($slug) {
            $p = self::getPlugin($slug);
            if (!$p) {
                throw new \Exception(t('common.not_found', 'Plugin not found.'));
            }

            getDB()->prepare('UPDATE sys_plugins SET enabled = 0, worker_enabled = 0, updated_at = NOW() WHERE slug = ?')
                ->execute([$slug]);
            getDB()->prepare("DELETE FROM sys_plugin_settings WHERE plugin_slug = ? AND setting_key = '_worker_api_token'")
                ->execute([$slug]);
            ApiTokenService::revokePluginTokens($slug);
            self::cleanupPluginAsteriskConf($slug);
            self::resetCache();

            if (!empty($p['hooks']['sync_domain'])) {
                try {
                    require_once dirname(__DIR__) . '/asterisk_sync.php';
                    $dom = (string)$p['hooks']['sync_domain'];
                    $targetDom = defined('PENDING_SYNC_DOMAIN_MAP') && isset(PENDING_SYNC_DOMAIN_MAP[$dom]) ? $dom : 'general_dialplan';
                    markPendingSync($targetDom, 'plugin', $slug, "Plugin disabled: {$p['name']}", 'update', $_SESSION['user_id'] ?? null);
                } catch (\Throwable $e) {
                }
            }

            writeAuditLog('plugins', 'plugin', $slug, "Plugin disabled: {$p['name']}", 'update', $_SESSION['user_id'] ?? null);
            return sprintf(t('plugins.msg_disabled', 'Plugin "%s" has been disabled.'), $p['name']);
        });
    }

    /**
     * Uninstall a plugin: removes DB registration, settings, KV store, declared
     * `plg_*` tables, and (if custom uploaded) its directory on disk. Official
     * catalog plugins stay available in the catalog for future re-installation.
     *
     * @return array<string, mixed>
     */
    public static function uninstall(string $slug, string $csrfToken): array
    {
        return PBXHelper::handleAction($csrfToken, function () use ($slug) {
            $p = self::getPlugin($slug);
            if (!$p) {
                throw new \Exception(t('common.not_found', 'Plugin not found.'));
            }

            $manifests = self::discoverManifests();
            $m = $manifests[$slug] ?? [];

            $db = getDB();
            $db->prepare('DELETE FROM sys_plugins WHERE slug = ?')->execute([$slug]);
            $db->prepare('DELETE FROM sys_plugin_settings WHERE plugin_slug = ?')->execute([$slug]);
            $db->prepare('DELETE FROM sys_plugin_kv WHERE plugin_slug = ?')->execute([$slug]);
            $db->prepare('DELETE FROM sys_webhook_queue WHERE plugin_slug = ?')->execute([$slug]);
            ApiTokenService::revokePluginTokens($slug);
            self::cleanupPluginAsteriskConf($slug);
            self::dropPluginTables($slug, $m);

            if ($p['source'] === 'custom') {
                $customPath = self::customDir() . '/' . $slug;
                if (is_dir($customPath)) {
                    self::removeDirectoryRecursive($customPath);
                }
            }

            self::resetCache();
            writeAuditLog('plugins', 'plugin', $slug, "Plugin uninstalled: {$p['name']}", 'delete', $_SESSION['user_id'] ?? null);
            return sprintf(t('plugins.msg_uninstalled', 'Plugin "%s" has been uninstalled.'), $p['name']);
        });
    }

    /**
     * Upload and install a custom `.zip` plugin package.
     *
     * @param array<string, mixed> $fileEntry `$_FILES['plugin_zip']`
     * @return array<string, mixed>
     */
    public static function installFromZip(array $fileEntry, string $csrfToken): array
    {
        return PBXHelper::handleAction($csrfToken, function () use ($fileEntry) {
            if (($fileEntry['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || empty($fileEntry['tmp_name'])) {
                throw new \Exception(t('plugins.err_upload_failed', 'Please select a valid .zip plugin archive.'));
            }
            if (($fileEntry['size'] ?? 0) > 25 * 1024 * 1024) {
                throw new \Exception(t('plugins.err_zip_too_large', 'Plugin archive must not exceed 25 MB.'));
            }
            if (!class_exists('ZipArchive')) {
                throw new \Exception('PHP ZipArchive extension is required to extract plugin packages.');
            }

            $zip = new \ZipArchive();
            if ($zip->open($fileEntry['tmp_name']) !== true) {
                throw new \Exception(t('plugins.err_invalid_zip', 'Could not open the uploaded .zip archive.'));
            }

            // Security inspection: block path traversal (Zip-Slip), absolute paths, and locate plugin.json
            $manifestContent = null;
            $prefixDir = '';
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                $entryName = (string)($stat['name'] ?? '');
                if ($entryName === '' || str_contains($entryName, '..') || str_starts_with($entryName, '/') || str_starts_with($entryName, '\\')) {
                    $zip->close();
                    throw new \Exception(t('plugins.err_unsafe_zip', 'Unsafe path detected inside plugin archive.'));
                }
                if ($entryName === 'plugin.json') {
                    $manifestContent = $zip->getFromIndex($i);
                    $prefixDir = '';
                } elseif (preg_match('#^([a-zA-Z0-9_-]+/)plugin\.json$#', $entryName, $m) && $manifestContent === null) {
                    $manifestContent = $zip->getFromIndex($i);
                    $prefixDir = $m[1];
                }
            }

            if (!is_string($manifestContent) || $manifestContent === '') {
                $zip->close();
                throw new \Exception(t('plugins.err_missing_manifest', 'Archive does not contain a valid plugin.json manifest.'));
            }

            $manifest = json_decode($manifestContent, true);
            if (!is_array($manifest)) {
                $zip->close();
                throw new \Exception(t('plugins.err_invalid_manifest', 'plugin.json is not valid JSON.'));
            }

            $slug = trim((string)($manifest['slug'] ?? ''));
            $name = trim((string)($manifest['name'] ?? ''));
            $version = trim((string)($manifest['version'] ?? '1.0.0'));
            if (!preg_match('/^[a-z0-9][a-z0-9-]{1,48}$/', $slug) || $name === '') {
                $zip->close();
                throw new \Exception(t('plugins.err_manifest_fields', 'plugin.json must define a valid "slug" (lowercase alphanumeric + hyphens) and "name".'));
            }

            // Prevent overwriting official catalog plugins via custom upload
            if (is_dir(self::catalogDir() . '/' . $slug)) {
                $zip->close();
                throw new \Exception(sprintf(t('plugins.err_reserved_slug', 'Slug "%s" is reserved by an official catalog plugin.'), $slug));
            }

            // Extract to a staging directory and syntax-check all PHP files before moving into place.
            // The staging directory sits inside the plugins directory: Apache runs with
            // PrivateTmp, and rename() cannot move a directory from that /tmp to another
            // mount (EXDEV), so every upload failed with "Failed to move extracted plugin".
            // glob('*') in discoverManifests() skips the dot directory.
            $targetBase = self::customDir();
            if (!is_dir($targetBase)) {
                @mkdir($targetBase, 0750, true);
            }
            $stageDir = $targetBase . '/.stage-' . bin2hex(random_bytes(8));
            @mkdir($stageDir, 0700, true);

            try {
                for ($i = 0; $i < $zip->numFiles; $i++) {
                    $entryName = (string)$zip->getNameIndex($i);
                    if ($prefixDir !== '' && str_starts_with($entryName, $prefixDir)) {
                        $relPath = substr($entryName, strlen($prefixDir));
                    } else {
                        $relPath = $entryName;
                    }
                    if ($relPath === '' || str_ends_with($relPath, '/')) {
                        continue;
                    }
                    $destFile = $stageDir . '/' . $relPath;
                    $destParent = dirname($destFile);
                    if (!is_dir($destParent)) {
                        @mkdir($destParent, 0750, true);
                    }
                    $stream = $zip->getStream($entryName);
                    if ($stream === false) {
                        continue;
                    }
                    file_put_contents($destFile, stream_get_contents($stream));
                    fclose($stream);

                    if (str_ends_with(strtolower($destFile), '.php')) {
                        $lintOut = [];
                        $lintCode = 0;
                        exec('php -l ' . escapeshellarg($destFile) . ' 2>&1', $lintOut, $lintCode);
                        if ($lintCode !== 0) {
                            throw new \Exception("PHP syntax error in {$relPath}: " . implode(' ', $lintOut));
                        }
                    }
                }
                $zip->close();

                $targetDir = $targetBase . '/' . $slug;
                if (is_dir($targetDir)) {
                    self::removeDirectoryRecursive($targetDir);
                }
                if (!@rename($stageDir, $targetDir)) {
                    throw new \Exception('Failed to move extracted plugin into plugins directory.');
                }
            } catch (\Throwable $e) {
                self::removeDirectoryRecursive($stageDir);
                throw $e;
            }

            self::resetCache();

            // Register and enable the custom plugin in sys_plugins
            $db = getDB();
            $hasWorker = !empty($manifest['hooks']['worker']) ? 1 : 0;
            $db->prepare(
                "INSERT INTO sys_plugins (slug, name, version, source, enabled, worker_enabled, config_version, installed_at, updated_at)
                 VALUES (?, ?, ?, 'custom', 1, ?, 1, NOW(), NOW())
                 ON DUPLICATE KEY UPDATE name = VALUES(name), version = VALUES(version), source = 'custom', enabled = 1, updated_at = NOW()"
            )->execute([$slug, $name, $version, $hasWorker]);

            if (!empty($manifest['settings_schema']) && is_array($manifest['settings_schema'])) {
                foreach ($manifest['settings_schema'] as $field) {
                    $k = (string)($field['key'] ?? '');
                    if ($k === '' || !isset($field['default'])) {
                        continue;
                    }
                    $existing = DBHelper::fetchColumn(
                        'SELECT setting_value FROM sys_plugin_settings WHERE plugin_slug = ? AND setting_key = ?',
                        [$slug, $k]
                    );
                    if ($existing === false) {
                        self::setSetting($slug, $k, (string)$field['default'], !empty($field['secret']));
                    }
                }
            }

            if ($hasWorker || !empty($manifest['scopes'])) {
                $scopes = is_array($manifest['scopes'] ?? null) ? $manifest['scopes'] : [];
                $rawToken = ApiTokenService::issuePluginToken($slug, $name, $scopes);
                self::setSetting($slug, '_worker_api_token', $rawToken, true);
            }

            // Apply plugin database schema (schema.sql -> plg_* tables only)
            self::applyPluginSchema($slug, $targetDir);

            if (!empty($manifest['hooks']['sync_domain'])) {
                try {
                    require_once dirname(__DIR__) . '/asterisk_sync.php';
                    $dom = (string)$manifest['hooks']['sync_domain'];
                    $targetDom = defined('PENDING_SYNC_DOMAIN_MAP') && isset(PENDING_SYNC_DOMAIN_MAP[$dom]) ? $dom : 'general_dialplan';
                    markPendingSync($targetDom, 'plugin', $slug, "Custom plugin uploaded & enabled: {$name}", 'update', $_SESSION['user_id'] ?? null);
                } catch (\Throwable $e) {
                }
            }

            self::resetCache();
            writeAuditLog('plugins', 'plugin', $slug, "Custom plugin uploaded & enabled: {$name} (v{$version})", 'create', $_SESSION['user_id'] ?? null);
            return sprintf(t('plugins.msg_uploaded', 'Custom plugin "%s" (v%s) uploaded and enabled.'), $name, $version);
        });
    }

    /**
     * Save settings for a plugin from its settings form or REST API.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public static function savePluginSettings(string $slug, array $input, string $csrfToken): array
    {
        return PBXHelper::handleAction($csrfToken, function () use ($slug, $input) {
            $p = self::getPlugin($slug);
            if (!$p) {
                throw new \Exception(t('common.not_found', 'Plugin not found.'));
            }

            $schema = $p['settings_schema'] ?? [];
            foreach ($schema as $field) {
                $key = (string)($field['key'] ?? '');
                if ($key === '') {
                    continue;
                }
                $type = (string)($field['type'] ?? 'text');
                $isSecret = !empty($field['secret']);

                if ($type === 'boolean' || $type === 'checkbox') {
                    $val = (string)PBXHelper::boolFlag($input, $key, 0);
                    self::setSetting($slug, $key, $val, false);
                    continue;
                }

                if (!array_key_exists($key, $input)) {
                    continue;
                }
                $rawVal = trim((string)$input[$key]);
                if ($isSecret && $rawVal === '') {
                    // Empty password/secret field on form submit preserves existing secret unless clear_secret is requested
                    if (!empty($input['clear_' . $key])) {
                        self::setSetting($slug, $key, '', true);
                    }
                    continue;
                }
                self::setSetting($slug, $key, $rawVal, $isSecret);
            }

            getDB()->prepare('UPDATE sys_plugins SET config_version = config_version + 1, updated_at = NOW() WHERE slug = ?')
                ->execute([$slug]);

            if (!empty($p['hooks']['sync_domain'])) {
                try {
                    markPendingSync('general_dialplan', 'plugin', $slug, "Plugin settings updated: {$p['name']}", 'update', $_SESSION['user_id'] ?? null);
                } catch (\Throwable $e) {
                }
            }

            writeAuditLog('plugins', 'plugin_settings', $slug, "Plugin settings saved: {$p['name']}", 'update', $_SESSION['user_id'] ?? null);
            return sprintf(t('plugins.msg_settings_saved', 'Settings for "%s" saved.'), $p['name']);
        });
    }

    public static function getSetting(string $slug, string $key, string $default = ''): string
    {
        try {
            $row = DBHelper::fetchOne(
                'SELECT setting_value, is_secret FROM sys_plugin_settings WHERE plugin_slug = ? AND setting_key = ?',
                [$slug, $key]
            );
            if (!$row || $row['setting_value'] === null || $row['setting_value'] === '') {
                return $default;
            }
            if (!empty($row['is_secret'])) {
                $dec = SecretBox::decrypt((string)$row['setting_value']);
                return $dec !== '' ? $dec : $default;
            }
            return (string)$row['setting_value'];
        } catch (\Throwable $e) {
            return $default;
        }
    }

    public static function setSetting(string $slug, string $key, string $value, bool $isSecret = false): void
    {
        $stored = ($isSecret && $value !== '') ? SecretBox::encrypt($value) : $value;
        $stmt = getDB()->prepare(
            "INSERT INTO sys_plugin_settings (plugin_slug, setting_key, setting_value, is_secret, updated_at)
             VALUES (?, ?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), is_secret = VALUES(is_secret), updated_at = NOW()"
        );
        $stmt->execute([$slug, $key, $stored, $isSecret ? 1 : 0]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function getAllSettings(string $slug, bool $maskSecrets = false): array
    {
        $p = self::getPlugin($slug);
        $out = [];
        if ($p && !empty($p['settings_schema'])) {
            foreach ($p['settings_schema'] as $f) {
                $k = (string)($f['key'] ?? '');
                if ($k !== '') {
                    $out[$k] = $f['default'] ?? '';
                }
            }
        }
        try {
            $rows = DBHelper::fetchAll(
                'SELECT setting_key, setting_value, is_secret FROM sys_plugin_settings WHERE plugin_slug = ?',
                [$slug]
            );
            foreach ($rows as $r) {
                $k = (string)$r['setting_key'];
                if (str_starts_with($k, '_')) {
                    continue;
                }
                if (!empty($r['is_secret'])) {
                    $dec = SecretBox::decrypt((string)$r['setting_value']);
                    $out[$k] = $maskSecrets ? ($dec !== '' ? SecretBox::mask($dec) : '') : $dec;
                } else {
                    $out[$k] = (string)$r['setting_value'];
                }
            }
        } catch (\Throwable $e) {
        }
        return $out;
    }

    // ------------------------------------------------------------------ KV Store

    /**
     * @return array<string, mixed>|null
     */
    public static function kvGet(string $slug, string $collection, string $itemKey): ?array
    {
        $row = DBHelper::fetchOne(
            'SELECT payload_json, updated_at FROM sys_plugin_kv WHERE plugin_slug = ? AND collection = ? AND item_key = ?',
            [$slug, $collection, $itemKey]
        );
        if (!$row) {
            return null;
        }
        return [
            'key'        => $itemKey,
            'value'      => json_decode((string)$row['payload_json'], true),
            'updated_at' => $row['updated_at'],
        ];
    }

    /**
     * @param mixed $value
     */
    public static function kvSet(string $slug, string $collection, string $itemKey, mixed $value): void
    {
        $col = mb_substr($collection, 0, 64);
        $key = mb_substr($itemKey, 0, 128);
        $json = (string)json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $db = getDB();
        $stmt = $db->prepare(
            "INSERT INTO sys_plugin_kv (plugin_slug, collection, item_key, payload_json, updated_at)
             VALUES (?, ?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE payload_json = VALUES(payload_json), updated_at = NOW()"
        );
        $stmt->execute([$slug, $col, $key, $json]);

        // Cap collection size at 500 items per (plugin_slug, collection)
        $cutoffStmt = $db->prepare(
            "SELECT updated_at FROM sys_plugin_kv
             WHERE plugin_slug = ? AND collection = ?
             ORDER BY updated_at DESC
             LIMIT 1 OFFSET 500"
        );
        $cutoffStmt->execute([$slug, $col]);
        $cutoff = $cutoffStmt->fetchColumn();
        if ($cutoff !== false && $cutoff !== null) {
            $pruneStmt = $db->prepare(
                "DELETE FROM sys_plugin_kv
                 WHERE plugin_slug = ? AND collection = ? AND updated_at <= ?
                 ORDER BY updated_at ASC
                 LIMIT 100"
            );
            $pruneStmt->execute([$slug, $col, (string)$cutoff]);
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function kvList(string $slug, string $collection, int $limit = 100): array
    {
        $limit = max(1, min(500, $limit));
        $rows = DBHelper::fetchAll(
            "SELECT item_key, payload_json, updated_at FROM sys_plugin_kv WHERE plugin_slug = ? AND collection = ? ORDER BY updated_at DESC LIMIT {$limit}",
            [$slug, $collection]
        );
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'key'        => $r['item_key'],
                'value'      => json_decode((string)$r['payload_json'], true),
                'updated_at' => $r['updated_at'],
            ];
        }
        return $out;
    }

    public static function kvDelete(string $slug, string $collection, string $itemKey): bool
    {
        $stmt = getDB()->prepare('DELETE FROM sys_plugin_kv WHERE plugin_slug = ? AND collection = ? AND item_key = ?');
        $stmt->execute([$slug, $collection, $itemKey]);
        return $stmt->rowCount() > 0;
    }

    // ------------------------------------------------------------------ Hooks

    /**
     * Return all enabled plugins that subscribe to `$eventName` in `hooks.events`.
     *
     * @return list<string>
     */
    public static function pluginsSubscribedToEvent(string $eventName): array
    {
        $slugs = [];
        foreach (self::allPlugins() as $p) {
            if (!$p['enabled']) {
                continue;
            }
            $evList = $p['hooks']['events'] ?? [];
            if (is_array($evList) && (in_array('*', $evList, true) || in_array($eventName, $evList, true))) {
                $slugs[] = (string)$p['slug'];
            }
        }
        return $slugs;
    }

    /**
     * Deliver a queued event to a plugin's `events/handler.php` if present.
     *
     * @param array<string, mixed> $envelope
     */
    public static function deliverEventToPlugin(string $slug, string $eventName, array $envelope): void
    {
        if (!self::isEnabled($slug)) {
            return;
        }
        $manifests = self::discoverManifests();
        $m = $manifests[$slug] ?? null;
        if (!$m || empty($m['dir'])) {
            return;
        }
        $handlerFile = $m['dir'] . '/events/handler.php';
        if (is_file($handlerFile)) {
            try {
                $pluginSettings = self::getAllSettings($slug, false);
                $fn = require $handlerFile;
                if (is_callable($fn)) {
                    $fn($eventName, $envelope, $pluginSettings);
                }
                self::recordPluginSuccess($slug);
            } catch (\Throwable $e) {
                self::recordPluginError($slug, 'event:' . $eventName, $e);
            }
        }
    }

    /**
     * Run `sync/Sync.php` for all enabled plugins that register a custom `sync_domain`.
     * Fault-isolated per plugin so a broken third-party plugin never aborts core PBX sync.
     */
    public static function syncAllEnabledPlugins(): void
    {
        require_once dirname(__DIR__) . '/asterisk_sync.php';
        $manifests = self::discoverManifests();
        foreach (self::allPlugins() as $p) {
            if (!$p['enabled']) {
                continue;
            }
            $slug = (string)$p['slug'];
            $m = $manifests[$slug] ?? null;
            if (!$m || empty($m['dir'])) {
                continue;
            }
            $syncFile = $m['dir'] . '/sync/Sync.php';
            if (is_file($syncFile)) {
                try {
                    $settings = self::getAllSettings($slug, false);
                    $fn = require $syncFile;
                    if (is_callable($fn)) {
                        $fn($slug, $settings);
                    }
                    self::recordPluginSuccess($slug);
                } catch (\Throwable $e) {
                    self::recordPluginError($slug, 'sync', $e);
                }
            }
        }
    }

    private static function recordPluginSuccess(string $slug): void
    {
        try {
            getDB()->prepare("DELETE FROM sys_plugin_settings WHERE plugin_slug = ? AND setting_key = '_error_streak'")
                ->execute([$slug]);
        } catch (\Throwable $e) {
        }
    }

    /**
     * Circuit Breaker (Safe Mode): logs plugin runtime errors and automatically
     * disables a plugin if it throws 5 consecutive unhandled exceptions.
     */
    private static function recordPluginError(string $slug, string $context, \Throwable $e): void
    {
        try {
            $streak = (int)self::getSetting($slug, '_error_streak', '0') + 1;
            self::setSetting($slug, '_error_streak', (string)$streak, false);
            $msg = mb_substr("[{$context}] " . $e->getMessage(), 0, 220);
            writeAuditLog('plugins', 'plugin_error', $slug, "Plugin error ({$streak}/5): {$msg}", 'error', null);

            if ($streak >= 5) {
                getDB()->prepare('UPDATE sys_plugins SET enabled = 0, worker_enabled = 0, updated_at = NOW() WHERE slug = ?')
                    ->execute([$slug]);
                getDB()->prepare("DELETE FROM sys_plugin_settings WHERE plugin_slug = ? AND setting_key = '_worker_api_token'")
                    ->execute([$slug]);
                ApiTokenService::revokePluginTokens($slug);
                self::cleanupPluginAsteriskConf($slug);
                self::resetCache();
                writeAuditLog('plugins', 'plugin_safe_mode', $slug, "Plugin auto-disabled (Safe Mode) after 5 consecutive errors: {$msg}", 'update', null);
            }
        } catch (\Throwable $ignore) {
        }
    }

    /**
     * Serve `/api/plugins/<slug>/<subPath>` for an enabled plugin that provides `public/handler.php`.
     */
    public static function servePublicEndpoint(string $slug, string $subPath = ''): void
    {
        header('Cache-Control: no-store');
        if (!self::isEnabled($slug)) {
            http_response_code(404);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => false, 'error' => 'Plugin not found or disabled']);
            return;
        }
        $manifests = self::discoverManifests();
        $m = $manifests[$slug] ?? null;
        $handlerFile = ($m && !empty($m['dir'])) ? $m['dir'] . '/public/handler.php' : '';
        if ($handlerFile === '' || !is_file($handlerFile)) {
            http_response_code(404);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => false, 'error' => 'Public endpoint handler not defined']);
            return;
        }
        try {
            $settings = self::getAllSettings($slug, false);
            $fn = require $handlerFile;
            if (is_callable($fn)) {
                $fn($subPath, $settings);
            }
            self::recordPluginSuccess($slug);
        } catch (\Throwable $e) {
            self::recordPluginError($slug, 'public_endpoint', $e);
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => false, 'error' => 'Plugin endpoint error']);
        }
    }

    /**
     * Return dynamic sidebar items for enabled custom/catalog plugins that do
     * not already have a dedicated built-in route in `sidebar_menu.php`.
     *
     * @return list<array<string, mixed>>
     */
    public static function dynamicSidebarItems(bool $isAdmin, string $targetGroup = 'integrations'): array
    {
        if (!$isAdmin) {
            return [];
        }
        $items = [];
        foreach (self::allPlugins() as $p) {
            if (!$p['enabled']) {
                continue;
            }
            $adminHook = $p['hooks']['admin_page'] ?? null;
            // Built-in routes (/google-integration, /ms-teams, /web-widgets, /ai-*) are already placed in their respective groups
            if (is_array($adminHook) && !empty($adminHook['route'])) {
                continue;
            }
            $pluginGroup = is_array($adminHook) && !empty($adminHook['group'])
                ? (string)$adminHook['group']
                : ($p['category'] === 'ai' ? 'ai' : 'integrations');
            if ($pluginGroup !== $targetGroup) {
                continue;
            }
            if ($p['settings_url'] === '/plugins/' . $p['slug']) {
                $items[] = [
                    'href'       => '/plugins/' . $p['slug'],
                    'icon'       => $p['icon'],
                    'icon_style' => $p['icon_style'] !== '' ? $p['icon_style'] : null,
                    'label'      => ['plugin.' . $p['slug'], $p['name']],
                    'show'       => true,
                    'pages'      => ['plugin_' . $p['slug'] . '.php'],
                    'uri'        => '/plugins/' . $p['slug'],
                ];
            }
        }
        return $items;
    }

    /**
     * Return a PDO connection capable of running validated `plg_*` DDL statements.
     * Uses `MIGRATOR_DB_USER` / `MIGRATOR_DB_PASS` from `/etc/ai-pbx.env` when configured
     * (since runtime `aipbx_portal` is restricted to DML: SELECT/INSERT/UPDATE/DELETE),
     * falling back to `getDB()` in test environments.
     */
    private static function getSchemaDB(): \PDO
    {
        $migUser = function_exists('portalEnv') ? (string)portalEnv('MIGRATOR_DB_USER', '') : '';
        $migPass = function_exists('portalEnv') ? (string)portalEnv('MIGRATOR_DB_PASS', '') : '';
        if ($migUser !== '' && $migPass !== '') {
            try {
                // Same server and port as the portal: a plugin schema applied
                // through the migrator user must reach the deployment's MariaDB,
                // which is not necessarily on 3306 (DB_PORT).
                $dsn = 'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4';
                return new \PDO($dsn, $migUser, $migPass, [
                    \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
                    \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                    \PDO::ATTR_EMULATE_PREPARES   => false,
                ]);
            } catch (\Throwable $e) {
                // Fall back to getDB() if migrator connection fails
            }
        }
        return getDB();
    }

    /**
     * Validate and apply `schema.sql` (or `migrations/install.sql`) for a plugin.
     * Security rule: Plugins may ONLY create tables prefixed with `plg_` (`^plg_[a-z0-9_]{2,58}$`).
     *
     * @return list<string> Names of `plg_*` tables created/verified
     */
    public static function applyPluginSchema(string $slug, string $pluginDir): array
    {
        $schemaFile = '';
        foreach ([$pluginDir . '/schema.sql', $pluginDir . '/migrations/install.sql'] as $candidate) {
            if (is_file($candidate)) {
                $schemaFile = $candidate;
                break;
            }
        }
        if ($schemaFile === '') {
            return [];
        }

        $rawSql = (string)@file_get_contents($schemaFile);
        if (trim($rawSql) === '') {
            return [];
        }

        $statements = self::parseAndValidatePluginSchemaSql($rawSql);
        $db = self::getSchemaDB();
        $tables = [];
        foreach ($statements as $item) {
            $db->exec($item['sql']);
            $tables[] = $item['table'];
        }
        return $tables;
    }

    /**
     * Apply `schema.sql` (`plg_*` tables) for all currently enabled plugins.
     * Invoked during `install.sh --upgrade` (`aipbx-update`) so background workers
     * have all required plugin tables before any admin page is visited.
     */
    public static function syncAllEnabledPluginSchemas(): void
    {
        $manifests = self::discoverManifests();
        foreach (self::allPlugins() as $p) {
            if (!$p['enabled']) {
                continue;
            }
            $slug = (string)$p['slug'];
            $m = $manifests[$slug] ?? null;
            if (!$m || empty($m['dir'])) {
                continue;
            }
            try {
                self::applyPluginSchema($slug, (string)$m['dir']);
            } catch (\Throwable $e) {
                self::recordPluginError($slug, 'schema_sync', $e);
                if (defined('STDERR')) {
                    @fwrite(STDERR, "[WARN] Plugin schema sync failed for '{$slug}': " . $e->getMessage() . "\n");
                }
            }
        }
    }

    /**
     * Parse raw SQL from a plugin's `schema.sql` and enforce the `plg_` table sandbox.
     * Only `CREATE TABLE [IF NOT EXISTS] plg_<name> (...)` statements are permitted.
     *
     * @return list<array{table: string, sql: string}>
     */
    public static function parseAndValidatePluginSchemaSql(string $rawSql): array
    {
        // Strip SQL comments (-- ... and /* ... */)
        $clean = (string)preg_replace('~/\*.*?\*/~s', '', $rawSql);
        $lines = preg_split('/\R/', $clean) ?: [];
        $filteredLines = [];
        foreach ($lines as $line) {
            $trimmed = ltrim($line);
            if (str_starts_with($trimmed, '--') || str_starts_with($trimmed, '#')) {
                continue;
            }
            $filteredLines[] = $line;
        }
        $clean = implode("\n", $filteredLines);

        $parts = array_filter(array_map('trim', explode(';', $clean)), fn($s) => $s !== '');
        $validated = [];

        foreach ($parts as $stmt) {
            if (!preg_match('/^CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?(plg_[a-z0-9_]{2,58})`?\s*\(.+\)\s*(?:ENGINE\s*=\s*[A-Za-z0-9_]+)?(?:\s+DEFAULT\s+CHARSET\s*=\s*[A-Za-z0-9_]+)?(?:\s+COLLATE\s*=\s*[A-Za-z0-9_]+)?$/is', $stmt, $m)) {
                throw new \Exception('Plugin schema security violation: only CREATE TABLE [IF NOT EXISTS] plg_<name> (...) statements are allowed in schema.sql.');
            }
            $tableName = strtolower($m[1]);
            // Block any subquery/reference to core tables or destructive DDL keywords inside the statement
            if (preg_match('/\b(DROP|TRUNCATE|ALTER|GRANT|REVOKE|INTO\s+OUTFILE|LOAD_FILE|sys_users|sys_settings|sys_api_tokens|sys_plugins)\b/i', $stmt)) {
                throw new \Exception("Plugin schema security violation in table {$tableName}: forbidden SQL keyword or core table reference.");
            }
            $validated[] = [
                'table' => $tableName,
                'sql'   => $stmt,
            ];
        }

        return $validated;
    }

    /**
     * Drop `plg_*` tables owned by a plugin when it is uninstalled.
     *
     * @param array<string, mixed> $manifest
     */
    public static function dropPluginTables(string $slug, array $manifest): void
    {
        $tables = [];
        if (!empty($manifest['tables']) && is_array($manifest['tables'])) {
            foreach ($manifest['tables'] as $t) {
                $tName = strtolower(trim((string)$t));
                if (preg_match('/^plg_[a-z0-9_]{2,58}$/', $tName)) {
                    $tables[$tName] = true;
                }
            }
        }
        $pluginDir = (string)($manifest['dir'] ?? '');
        if ($pluginDir !== '') {
            foreach ([$pluginDir . '/schema.sql', $pluginDir . '/migrations/install.sql'] as $candidate) {
                if (is_file($candidate)) {
                    try {
                        foreach (self::parseAndValidatePluginSchemaSql((string)@file_get_contents($candidate)) as $st) {
                            $tables[$st['table']] = true;
                        }
                    } catch (\Throwable $e) {
                    }
                }
            }
        }

        if (empty($tables)) {
            return;
        }

        $db = self::getSchemaDB();
        foreach (array_keys($tables) as $tableName) {
            if (preg_match('/^plg_[a-z0-9_]{2,58}$/', $tableName)) {
                try {
                    $db->exec("DROP TABLE IF EXISTS `{$tableName}`");
                } catch (\Throwable $e) {
                }
            }
        }
    }

    /**
     * Execute `worker/tick.php` for all enabled plugins that declare `hooks.worker`.
     * Called periodically by `web/bin/webhook_worker.php`.
     */
    public static function tickAllEnabledWorkers(): void
    {
        $manifests = self::discoverManifests();
        foreach (self::allPlugins() as $p) {
            if (!$p['enabled'] || empty($p['worker_enabled'])) {
                continue;
            }
            $slug = (string)$p['slug'];
            $m = $manifests[$slug] ?? null;
            if (!$m || empty($m['dir'])) {
                continue;
            }
            $tickFile = $m['dir'] . '/worker/tick.php';
            if (is_file($tickFile)) {
                try {
                    $settings = self::getAllSettings($slug, false);
                    $fn = require $tickFile;
                    if (is_callable($fn)) {
                        $fn($slug, $settings);
                    }
                    self::recordPluginSuccess($slug);
                } catch (\Throwable $e) {
                    self::recordPluginError($slug, 'worker', $e);
                }
            }
        }
    }

    private static function cleanupPluginAsteriskConf(string $slug): void
    {
        $safe = preg_replace('/[^a-z0-9_-]/', '', $slug);
        if ($safe === '' || !defined('ASTERISK_PBX_DIR')) {
            return;
        }
        // Reload what was removed: without it a disabled plugin's dialplan and
        // endpoints stayed live in Asterisk until the next Apply.
        $reloads = [
            "pjsip_plugin_{$safe}.conf"      => 'pjsip reload',
            "extensions_plugin_{$safe}.conf" => 'dialplan reload',
            "hep_plugin_{$safe}.conf"        => 'module reload res_hep.so',
        ];
        foreach ($reloads as $f => $reload) {
            $path = ASTERISK_PBX_DIR . '/' . $f;
            if (is_file($path) && @unlink($path)) {
                require_once dirname(__DIR__) . '/asterisk_helper.php';
                AsteriskHelper::execCLI($reload);
            }
        }
    }

    private static function removeDirectoryRecursive(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir) ?: [];
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_dir($path) && !is_link($path)) {
                self::removeDirectoryRecursive($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
