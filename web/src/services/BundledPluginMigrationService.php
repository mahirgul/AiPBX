<?php

declare(strict_types=1);

/** Offline transition for an already installed bundled plugin. Never enables a fresh install. */
final class BundledPluginMigrationService
{
    public static function migrateVoicemail(PDO $db, string $customDir): void
    {
        self::migrate($db, $customDir, 'ai-voicemail', '9ad926398581a2cf6752516fd9c00dedb613c05aa07238b0d49fd718ad56ccbc',
            ['plugin.json', 'admin/page.php', 'events/handler.php']);
    }

    public static function migrateAutoDialer(PDO $db, string $customDir): void
    {
        self::migrate($db, $customDir, 'auto-dialer', '9d2766c72faa418700aa756a0f42d29b964cbb7986af9bca7f40ba65be2da08f',
            ['plugin.json', 'schema.sql', 'AutoDialerService.php', 'admin/action.php', 'admin/page.php',
             'events/handler.php', 'public/handler.php', 'sync/Sync.php', 'worker/tick.php']);
    }

    /**
     * The PBX Assistant is the official `ai-assistant` plugin (plugins/ai-assistant). An older
     * installed `ai-assistant` from the community catalog is removed: its files, its settings and
     * its API token. The enabled flag stays, so the Assistant stays on or off as it was.
     */
    public static function retireCommunityAssistant(PDO $db, string $customDir): void
    {
        $target = rtrim($customDir, '/') . '/ai-assistant';
        if (is_link($customDir) || is_link($target)) { throw new RuntimeException('Unsafe plugin directory'); }
        if (is_dir($target)) {
            self::removeTree($target);
        }
        $q = $db->prepare("SELECT source FROM sys_plugins WHERE slug = 'ai-assistant'");
        $q->execute();
        $source = $q->fetchColumn();
        if ($source === false || $source === 'official') { return; }
        $db->prepare("UPDATE sys_api_tokens SET is_active = 0 WHERE plugin_slug = 'ai-assistant'")->execute();
        $db->prepare("DELETE FROM sys_plugin_settings WHERE plugin_slug = 'ai-assistant'")->execute();
        $db->prepare("DELETE FROM sys_plugin_kv WHERE plugin_slug = 'ai-assistant'")->execute();
        $db->prepare("UPDATE sys_plugins SET source = 'official', name = 'PBX Assistant', version = '2.0.0', updated_at = NOW() WHERE slug = 'ai-assistant'")
            ->execute();
    }

    private static function removeTree(string $dir): void
    {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
                     RecursiveIteratorIterator::CHILD_FIRST) as $f) {
            $f->isDir() && !$f->isLink() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($dir);
    }

    private static function migrate(PDO $db, string $customDir, string $slug, string $checksum, array $files): void
    {
        $q = $db->prepare("SELECT source FROM sys_plugins WHERE slug = ?");
        $q->execute([$slug]);
        if ($q->fetchColumn() !== 'official') { return; }
        $archive = dirname(__DIR__, 3) . '/conf/plugin-transitions/' . $slug . '-1.1.0.zip';
        $target = rtrim($customDir, '/') . '/' . $slug;
        if (is_link($customDir) || is_link($target)) { throw new RuntimeException('Unsafe plugin transition directory'); }
        if (!is_dir($target)) {
            if (!hash_equals($checksum, (string)hash_file('sha256', $archive))) {
                throw new RuntimeException('Plugin transition archive checksum mismatch');
            }
            if (!is_dir($customDir) && !mkdir($customDir, 0750, true)) { throw new RuntimeException('Cannot create plugin directory'); }
            $stage = $customDir . '/.' . $slug . '-transition-' . bin2hex(random_bytes(8));
            mkdir($stage, 0750);
            $zip = new ZipArchive();
            try {
                if ($zip->open($archive) !== true) { throw new RuntimeException('Cannot open transition archive'); }
                // Only unpack the files explicitly listed for this checksum-pinned snapshot.
                foreach ($files as $name) {
                    $content = $zip->getFromName($name);
                    if (!is_string($content)) { throw new RuntimeException('Incomplete transition archive'); }
                    $parent = dirname($stage . '/' . $name);
                    if (!is_dir($parent)) { mkdir($parent, 0750, true); }
                    if (file_put_contents($stage . '/' . $name, $content) === false) { throw new RuntimeException('Cannot write transition file'); }
                    chmod($stage . '/' . $name, 0640);
                }
                $zip->close();
                if (!rename($stage, $target)) { throw new RuntimeException('Cannot install transition plugin'); }
            } finally {
                foreach ($files as $name) {
                    if (is_file($stage . '/' . $name)) { unlink($stage . '/' . $name); }
                }
                foreach (['admin', 'events', 'public', 'sync', 'worker', 'lib', ''] as $dir) {
                    if (is_dir($stage . '/' . $dir)) { rmdir($stage . '/' . $dir); }
                }
            }
        }
        $manifest = json_decode((string)file_get_contents($target . '/plugin.json'), true);
        if (!is_array($manifest) || ($manifest['slug'] ?? '') !== $slug || empty($manifest['version'])) {
            throw new RuntimeException('Existing plugin is incomplete; refusing to overwrite it');
        }
        foreach ($files as $file) {
            if (!is_file($target . '/' . $file)) { throw new RuntimeException('Existing plugin is incomplete; refusing to overwrite it'); }
        }
        // Preserve enabled/safe-mode/worker flags, settings, encrypted secrets, KV and API tokens.
        $db->prepare("UPDATE sys_plugins SET source = 'custom', version = ?, updated_at = NOW() WHERE slug = ? AND source = 'official'")
            ->execute([(string)$manifest['version'], $slug]);
    }
}
