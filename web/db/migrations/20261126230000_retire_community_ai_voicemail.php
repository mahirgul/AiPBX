<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Voicemail AI is one page now (AI Apps → Voicemail AI: Settings and History). The community plugin of the same slug
 * showed a second page with the finished summaries and its own LLM settings; the core page does all of it with the Cloud AI
 * accounts. An installation that has the community copy keeps the plugin enabled, but as the bundled one: the copy in
 * /var/lib/aipbx/plugins/ai-voicemail, its settings and its stored summaries are removed.
 */
final class RetireCommunityAiVoicemail extends AbstractMigration
{
    public function up(): void
    {
        $row = $this->fetchRow("SELECT enabled FROM sys_plugins WHERE slug = 'ai-voicemail' AND source = 'custom'");
        if (!$row) {
            return;
        }
        $this->execute("UPDATE sys_plugins SET source = 'official', name = 'Voicemail AI', version = '1.0.0' WHERE slug = 'ai-voicemail'");
        $this->execute("DELETE FROM sys_plugin_settings WHERE plugin_slug = 'ai-voicemail'");
        $this->execute("DELETE FROM sys_plugin_kv WHERE plugin_slug = 'ai-voicemail'");
        $dir = '/var/lib/aipbx/plugins/ai-voicemail';
        if (!is_link($dir) && is_dir($dir)) {
            $manifest = is_file($dir . '/plugin.json') ? json_decode((string) file_get_contents($dir . '/plugin.json'), true) : null;
            if (is_array($manifest) && ($manifest['type'] ?? '') === 'community') {
                try {
                    $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
                    foreach ($items as $item) {
                        $item->isDir() && !$item->isLink() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
                    }
                    @rmdir($dir);
                } catch (\Throwable $e) {
                }
            }
        }
    }

    public function down(): void
    {
    }
}
