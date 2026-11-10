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

    public static function migrateAssistant(PDO $db, string $customDir): void
    {
        $q = $db->prepare("SELECT source FROM sys_plugins WHERE slug = 'ai-assistant'");
        $q->execute();
        if ($q->fetchColumn() !== 'official') { return; }
        // Queue APIs have their own scope. Retain token identity and do not exceed its old PBX rights.
        $tokens = $db->query("SELECT id, scopes_json FROM sys_api_tokens WHERE plugin_slug = 'ai-assistant' AND is_active = 1");
        foreach ($tokens->fetchAll(PDO::FETCH_ASSOC) as $token) {
            $scopes = json_decode((string)$token['scopes_json'], true);
            if (!is_array($scopes)) { continue; }
            $queueScope = in_array('pbx:write', $scopes, true) ? 'queues:write' :
                (in_array('pbx:read', $scopes, true) ? 'queues:read' : null);
            if ($queueScope !== null && !in_array($queueScope, $scopes, true)) {
                $scopes[] = $queueScope;
                $db->prepare("UPDATE sys_api_tokens SET scopes_json = ? WHERE id = ? AND plugin_slug = 'ai-assistant'")
                    ->execute([json_encode($scopes, JSON_THROW_ON_ERROR), (int)$token['id']]);
            }
        }
        self::migrate($db, $customDir, 'ai-assistant', '607dd8b30e03803d8f0b94dcc705fdf5acb04dc94c7c7945eb264cbd6d44dc05',
            ['plugin.json', 'admin/page.php', 'lib/AssistantApi.php']);
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
