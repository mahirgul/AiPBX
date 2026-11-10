#!/usr/bin/env php
<?php
/**
 * Privileged plugin schema runner for AiPBX.
 *
 * Invoked by root (via aipbx-priv plugin-schema apply|drop <slug>) to apply
 * or drop plugin tables (plg_*) with DDL privileges (MIGRATOR_DB_USER / MIGRATOR_DB_PASS)
 * read securely from /etc/ai-pbx-migrator.env (0600 root:root).
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

$action = $argv[1] ?? '';
$slug   = $argv[2] ?? '';

if (!in_array($action, ['apply', 'drop'], true) || !preg_match('/^[a-z0-9_-]{2,64}$/', $slug)) {
    fwrite(STDERR, "Usage: plugin_schema.php apply|drop <slug>\n");
    exit(64);
}

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/src/core/PluginManager.php';

try {
    if ($action === 'apply') {
        $manifests = PluginManager::discoverManifests();
        $m = $manifests[$slug] ?? null;
        if (!$m || empty($m['dir'])) {
            // Also check custom directory directly
            $customDir = PluginManager::customDir() . '/' . $slug;
            if (is_dir($customDir)) {
                $m = ['dir' => $customDir, 'slug' => $slug];
            } else {
                fwrite(STDERR, "Plugin manifest/directory not found: {$slug}\n");
                exit(1);
            }
        }
        $tables = PluginManager::applyPluginSchema($slug, (string)$m['dir']);
        echo "OK: applied " . count($tables) . " table(s)\n";
        exit(0);
    } elseif ($action === 'drop') {
        $manifests = PluginManager::discoverManifests();
        $m = $manifests[$slug] ?? ['dir' => PluginManager::customDir() . '/' . $slug, 'slug' => $slug];
        PluginManager::dropPluginTables($slug, $m);
        echo "OK: dropped tables for {$slug}\n";
        exit(0);
    }
} catch (\Throwable $e) {
    fwrite(STDERR, "ERROR: " . $e->getMessage() . "\n");
    exit(1);
}
