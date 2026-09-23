<?php

use Phinx\Migration\AbstractMigration;

/**
 * Migration for Advanced PBX Modules:
 * 1. Calling Permission Groups & Rules (Arama Yetki Grupları)
 * 2. Voicemail Settings on sys_users (Sesli Posta)
 * 3. Boss-Secretary Groups (Şef - Sekreter Grupları)
 * 4. Ring Groups (Çalma Grupları)
 * 5. Conference Rooms (Konferans Odaları)
 * 6. Feature codes for Whisper, Barge, and Voicemail
 */
final class CreateAdvancedPbxModules extends AbstractMigration
{
    public function up(): void
    {
        // 1. Calling Permission Groups
        if (!$this->hasTable('pbx_permission_groups')) {
            $this->execute("CREATE TABLE `pbx_permission_groups` (
                `id` INT(11) NOT NULL AUTO_INCREMENT,
                `group_name` VARCHAR(100) NOT NULL,
                `description` VARCHAR(255) NULL,
                `default_action` ENUM('allow', 'deny') NOT NULL DEFAULT 'allow',
                `is_active` TINYINT(1) NOT NULL DEFAULT 1,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

            // Seed default group (Her Yöne Açık)
            $this->execute("INSERT INTO `pbx_permission_groups` (`id`, `group_name`, `description`, `default_action`, `is_active`) 
                VALUES (1, 'Her Yöne Açık (Varsayılan)', 'Tüm iç ve dış aramalara kısıtlama olmadan izin verir.', 'allow', 1)");
        }

        // 2. Calling Permission Rules
        if (!$this->hasTable('pbx_permission_rules')) {
            $this->execute("CREATE TABLE `pbx_permission_rules` (
                `id` INT(11) NOT NULL AUTO_INCREMENT,
                `group_id` INT(11) NOT NULL,
                `pattern` VARCHAR(64) NOT NULL,
                `pattern_type` ENUM('prefix', 'exact') NOT NULL DEFAULT 'prefix',
                `action` ENUM('deny', 'allow') NOT NULL DEFAULT 'deny',
                `priority` INT(11) NOT NULL DEFAULT 10,
                `description` VARCHAR(255) NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_perm_group_id` (`group_id`),
                CONSTRAINT `fk_perm_rules_group` FOREIGN KEY (`group_id`) REFERENCES `pbx_permission_groups` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
        }

        // 3. Boss-Secretary Groups
        if (!$this->hasTable('pbx_boss_secretary_groups')) {
            $this->execute("CREATE TABLE `pbx_boss_secretary_groups` (
                `id` INT(11) NOT NULL AUTO_INCREMENT,
                `group_number` INT(11) NOT NULL UNIQUE,
                `group_name` VARCHAR(100) NOT NULL,
                `boss_extension` VARCHAR(20) NOT NULL,
                `secretaries_json` TEXT NOT NULL,
                `ring_strategy` ENUM('ringall', 'sequential') NOT NULL DEFAULT 'ringall',
                `ring_timeout` INT(11) NOT NULL DEFAULT 20,
                `whitelist_extensions` VARCHAR(255) NULL,
                `fallback_dest_type` VARCHAR(50) NOT NULL DEFAULT 'hangup',
                `fallback_dest_id` VARCHAR(100) NOT NULL DEFAULT 'busy',
                `is_active` TINYINT(1) NOT NULL DEFAULT 1,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
        }

        // 4. Ring Groups
        if (!$this->hasTable('pbx_ring_groups')) {
            $this->execute("CREATE TABLE `pbx_ring_groups` (
                `id` INT(11) NOT NULL AUTO_INCREMENT,
                `group_number` VARCHAR(20) NOT NULL UNIQUE,
                `internal_number` VARCHAR(20) NULL,
                `name` VARCHAR(100) NOT NULL,
                `numbers_list` TEXT NOT NULL,
                `ring_strategy` ENUM('ringall', 'sequential', 'random') NOT NULL DEFAULT 'ringall',
                `ring_timeout` INT(11) NOT NULL DEFAULT 30,
                `cid_prefix` VARCHAR(30) NULL,
                `fallback_dest_type` VARCHAR(50) NOT NULL DEFAULT 'hangup',
                `fallback_dest_id` VARCHAR(100) NOT NULL DEFAULT 'busy',
                `record_call` TINYINT(1) NOT NULL DEFAULT 1,
                `is_active` TINYINT(1) NOT NULL DEFAULT 1,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
        }

        // 5. Conference Rooms (ConfBridge)
        if (!$this->hasTable('pbx_conferences')) {
            $this->execute("CREATE TABLE `pbx_conferences` (
                `id` INT(11) NOT NULL AUTO_INCREMENT,
                `room_number` VARCHAR(20) NOT NULL UNIQUE,
                `internal_number` VARCHAR(20) NULL,
                `title` VARCHAR(100) NOT NULL,
                `user_pin` VARCHAR(20) NULL DEFAULT NULL,
                `admin_pin` VARCHAR(20) NULL DEFAULT NULL,
                `wait_marked` TINYINT(1) NOT NULL DEFAULT 0,
                `end_marked` TINYINT(1) NOT NULL DEFAULT 0,
                `max_members` INT(11) NOT NULL DEFAULT 50,
                `announce_join_leave` TINYINT(1) NOT NULL DEFAULT 1,
                `announce_user_count` TINYINT(1) NOT NULL DEFAULT 1,
                `record_conference` TINYINT(1) NOT NULL DEFAULT 0,
                `mute_on_join` TINYINT(1) NOT NULL DEFAULT 0,
                `music_on_hold` VARCHAR(50) NOT NULL DEFAULT 'default',
                `is_active` TINYINT(1) NOT NULL DEFAULT 1,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
        }

        // 6. User columns for permission group, boss-secretary, and voicemail
        $userCols = [
            'permission_group_id'     => "INT(11) NULL DEFAULT 1 AFTER `outbound_group`",
            'boss_secretary_group_id' => "INT(11) NULL DEFAULT NULL AFTER `permission_group_id`",
            'boss_secretary_role'     => "ENUM('none', 'boss', 'secretary') NOT NULL DEFAULT 'none' AFTER `boss_secretary_group_id`",
            'voicemail_enabled'       => "TINYINT(1) NOT NULL DEFAULT 1 AFTER `boss_secretary_role`",
            'voicemail_pin'           => "VARCHAR(20) NULL DEFAULT NULL AFTER `voicemail_enabled`",
            'voicemail_email'         => "VARCHAR(120) NULL DEFAULT NULL AFTER `voicemail_pin`",
            'voicemail_attach_audio'  => "TINYINT(1) NOT NULL DEFAULT 1 AFTER `voicemail_email`",
            'vm_on_noanswer'          => "TINYINT(1) NOT NULL DEFAULT 0 AFTER `voicemail_attach_audio`",
            'vm_on_busy'              => "TINYINT(1) NOT NULL DEFAULT 0 AFTER `vm_on_noanswer`",
            'vm_on_unavail'           => "TINYINT(1) NOT NULL DEFAULT 0 AFTER `vm_on_busy`",
            'vm_always'               => "TINYINT(1) NOT NULL DEFAULT 0 AFTER `vm_on_unavail`",
        ];

        foreach ($userCols as $col => $def) {
            $exists = $this->query("SHOW COLUMNS FROM `sys_users` LIKE '{$col}'")->fetchAll();
            if (empty($exists)) {
                $this->execute("ALTER TABLE `sys_users` ADD COLUMN `{$col}` {$def}");
            }
        }

        // 7. Add Whisper, Barge, and Voicemail feature codes to pbx_feature_codes if not present
        $fCodes = [
            ['feature_key' => 'spy_whisper', 'title' => 'Çağrı Fısıldama (Whisper)', 'code' => '*91', 'allowed_roles' => 'admin,cc_manager'],
            ['feature_key' => 'spy_barge', 'title' => 'Çağrıya Dahil Olma (Barge)', 'code' => '*92', 'allowed_roles' => 'admin,cc_manager'],
            ['feature_key' => 'voicemail_my', 'title' => 'Kendi Sesli Postam', 'code' => '*97', 'allowed_roles' => 'admin,cc_manager,cc_agent,user'],
            ['feature_key' => 'voicemail_general', 'title' => 'Sesli Posta Girişi', 'code' => '*98', 'allowed_roles' => 'admin,cc_manager,cc_agent,user'],
        ];

        foreach ($fCodes as $fc) {
            $keyQuoted = "'" . addslashes($fc['feature_key']) . "'";
            $titleQuoted = "'" . addslashes($fc['title']) . "'";
            $codeQuoted = "'" . addslashes($fc['code']) . "'";
            $rolesQuoted = "'" . addslashes($fc['allowed_roles']) . "'";
            $ex = $this->query("SELECT id FROM `pbx_feature_codes` WHERE `feature_key` = {$keyQuoted}")->fetchAll();
            if (empty($ex)) {
                $this->execute("INSERT INTO `pbx_feature_codes` (`feature_key`, `title`, `code`, `allowed_roles`, `is_active`) VALUES ({$keyQuoted}, {$titleQuoted}, {$codeQuoted}, {$rolesQuoted}, 1)");
            }
        }
    }

    public function down(): void
    {
        $this->execute("DELETE FROM `pbx_feature_codes` WHERE `feature_key` IN ('spy_whisper', 'spy_barge', 'voicemail_my', 'voicemail_general')");

        $userCols = [
            'permission_group_id', 'boss_secretary_group_id', 'boss_secretary_role',
            'voicemail_enabled', 'voicemail_pin', 'voicemail_email', 'voicemail_attach_audio',
            'vm_on_noanswer', 'vm_on_busy', 'vm_on_unavail', 'vm_always'
        ];
        foreach ($userCols as $col) {
            $exists = $this->query("SHOW COLUMNS FROM `sys_users` LIKE '{$col}'")->fetchAll();
            if (!empty($exists)) {
                $this->execute("ALTER TABLE `sys_users` DROP COLUMN `{$col}`");
            }
        }

        if ($this->hasTable('pbx_conferences')) {
            $this->table('pbx_conferences')->drop()->save();
        }
        if ($this->hasTable('pbx_ring_groups')) {
            $this->table('pbx_ring_groups')->drop()->save();
        }
        if ($this->hasTable('pbx_boss_secretary_groups')) {
            $this->table('pbx_boss_secretary_groups')->drop()->save();
        }
        if ($this->hasTable('pbx_permission_rules')) {
            $this->table('pbx_permission_rules')->drop()->save();
        }
        if ($this->hasTable('pbx_permission_groups')) {
            $this->table('pbx_permission_groups')->drop()->save();
        }
    }
}
