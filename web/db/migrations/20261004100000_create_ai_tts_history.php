<?php

use Phinx\Migration\AbstractMigration;

/**
 * AI → Cloud TTS: every synthesized text (who, which provider/voice, how
 * many characters — cloud TTS is billed per character) and where its MP3
 * and, if saved, its Asterisk announcement went. Plus the module's role
 * rows: admin only by default (it spends money on the cloud account).
 */
final class CreateAiTtsHistory extends AbstractMigration
{
    public function up(): void
    {
        if (!$this->hasTable('ai_tts_history')) {
            $this->execute("
                CREATE TABLE `ai_tts_history` (
                  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
                  `provider` varchar(32) NOT NULL,
                  `voice` varchar(128) NOT NULL,
                  `language` varchar(16) NOT NULL DEFAULT '',
                  `speed` decimal(3,2) NOT NULL DEFAULT 1.00,
                  `text` mediumtext NOT NULL,
                  `chars` int(11) NOT NULL DEFAULT 0,
                  `bytes` int(11) NOT NULL DEFAULT 0,
                  `duration_ms` int(11) DEFAULT NULL,
                  `announcement` varchar(100) DEFAULT NULL,
                  `created_by` int(11) DEFAULT NULL,
                  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
                  PRIMARY KEY (`id`),
                  KEY `created_at` (`created_at`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        }
        $this->execute(
            "INSERT INTO sys_role_permissions (role_key, module_key, can_view, can_access, can_edit, can_delete)
             SELECT 'admin', 'ai_tts', 1, 1, 1, 1 FROM DUAL
             WHERE NOT EXISTS (SELECT 1 FROM sys_role_permissions WHERE role_key = 'admin' AND module_key = 'ai_tts')
               AND EXISTS (SELECT 1 FROM sys_roles WHERE role_key = 'admin')"
        );
    }

    public function down(): void
    {
        $this->execute("DELETE FROM sys_role_permissions WHERE module_key = 'ai_tts'");
        $this->execute('DROP TABLE IF EXISTS `ai_tts_history`');
    }
}
