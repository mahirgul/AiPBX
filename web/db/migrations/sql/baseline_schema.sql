SET FOREIGN_KEY_CHECKS=0;

CREATE TABLE `callcenter_notes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `call_id` varchar(64) DEFAULT NULL,
  `agent_extension` varchar(20) DEFAULT NULL,
  `customer_name` varchar(128) DEFAULT NULL,
  `phone` varchar(64) DEFAULT NULL,
  `disposition` varchar(64) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_agent_pending` (`agent_extension`,`call_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `cc_pause_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `agent_extension` varchar(20) NOT NULL,
  `agent_name` varchar(100) DEFAULT NULL,
  `pause_reason` varchar(100) NOT NULL DEFAULT 'Mola',
  `start_time` datetime NOT NULL,
  `end_time` datetime DEFAULT NULL,
  `duration` int(11) DEFAULT 0,
  `status` varchar(20) NOT NULL DEFAULT 'PAUSED',
  PRIMARY KEY (`id`),
  KEY `idx_agent` (`agent_extension`),
  KEY `idx_start_time` (`start_time`),
  KEY `idx_agent_status` (`agent_extension`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `cc_queue_logs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `time_id` int(10) unsigned NOT NULL DEFAULT 0,
  `call_id` varchar(128) NOT NULL DEFAULT '',
  `queue_name` varchar(128) NOT NULL DEFAULT '',
  `agent` varchar(128) NOT NULL DEFAULT '',
  `event` varchar(64) NOT NULL DEFAULT '',
  `data1` varchar(128) DEFAULT '',
  `data2` varchar(128) DEFAULT '',
  `data3` varchar(128) DEFAULT '',
  `data4` varchar(128) DEFAULT '',
  `data5` varchar(128) DEFAULT '',
  PRIMARY KEY (`id`),
  KEY `idx_time_id` (`time_id`),
  KEY `idx_created_at` (`created_at`),
  KEY `idx_call_id` (`call_id`),
  KEY `idx_queue_name` (`queue_name`),
  KEY `idx_event` (`event`),
  KEY `idx_agent` (`agent`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `fax_received` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `did_extension` varchar(20) NOT NULL,
  `caller_id` varchar(50) DEFAULT '',
  `pages` int(11) DEFAULT 1,
  `tif_path` varchar(255) NOT NULL,
  `pdf_path` varchar(255) NOT NULL,
  `file_size` int(11) DEFAULT 0,
  `status` varchar(50) DEFAULT 'SUCCESS',
  `is_read` tinyint(1) DEFAULT 0,
  `email_sent` tinyint(1) DEFAULT 0,
  `received_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_did` (`did_extension`),
  KEY `idx_received_at` (`received_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `fax_sent` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `sender_extension` varchar(20) NOT NULL,
  `destination_number` varchar(50) NOT NULL,
  `pdf_path` varchar(255) NOT NULL,
  `tif_path` varchar(255) NOT NULL,
  `pages` int(11) DEFAULT 0,
  `status` varchar(50) DEFAULT 'PENDING',
  `error_message` text DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `completed_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_user` (`user_id`),
  KEY `idx_status` (`status`),
  KEY `idx_user_created` (`user_id`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `pbx_announcements` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `title` varchar(100) NOT NULL,
  `audio_file` varchar(255) NOT NULL,
  `post_dest_type` varchar(50) DEFAULT 'hangup',
  `post_dest_id` varchar(100) DEFAULT '',
  `created_at` datetime DEFAULT current_timestamp(),
  `is_active` tinyint(1) DEFAULT 1,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `pbx_dids` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `did_number` varchar(50) NOT NULL,
  `cid_pattern` varchar(50) DEFAULT NULL,
  `title` varchar(100) NOT NULL,
  `dest_type` varchar(50) NOT NULL,
  `dest_id` varchar(100) NOT NULL,
  `language` varchar(10) DEFAULT NULL,
  `record_call` tinyint(1) DEFAULT 0,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `did_number` (`did_number`),
  KEY `dest_type` (`dest_type`,`dest_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `pbx_feature_codes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `feature_key` varchar(50) NOT NULL,
  `title` varchar(100) NOT NULL,
  `code` varchar(10) NOT NULL,
  `allowed_roles` varchar(150) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `feature_key` (`feature_key`),
  UNIQUE KEY `code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `pbx_hangup_actions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `action_key` varchar(50) NOT NULL,
  `title` varchar(100) NOT NULL,
  `action_type` varchar(30) NOT NULL DEFAULT 'hangup',
  `announcement_id` int(11) DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `action_key` (`action_key`),
  KEY `fk_hangup_announcement` (`announcement_id`),
  CONSTRAINT `fk_hangup_announcement` FOREIGN KEY (`announcement_id`) REFERENCES `pbx_announcements` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `pbx_ivr_entries` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `ivr_id` int(11) NOT NULL,
  `digit` varchar(5) NOT NULL,
  `dest_type` varchar(50) NOT NULL,
  `dest_id` varchar(100) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_ivr_digit` (`ivr_id`,`digit`),
  CONSTRAINT `pbx_ivr_entries_ibfk_1` FOREIGN KEY (`ivr_id`) REFERENCES `pbx_ivrs` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `pbx_ivrs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `title` varchar(100) NOT NULL,
  `prompt_file` varchar(255) NOT NULL DEFAULT 'custom/welcome',
  `language` varchar(10) DEFAULT NULL,
  `timeout_seconds` int(11) DEFAULT 10,
  `max_failures` int(11) DEFAULT 3,
  `allow_direct_dial` tinyint(1) DEFAULT 1,
  `timeout_dest_type` varchar(50) NOT NULL DEFAULT 'queue',
  `timeout_dest_id` varchar(100) NOT NULL DEFAULT 'queue_cc',
  `invalid_dest_type` varchar(50) NOT NULL DEFAULT 'hangup',
  `invalid_dest_id` varchar(100) NOT NULL DEFAULT '',
  `created_at` datetime DEFAULT current_timestamp(),
  `is_active` tinyint(1) DEFAULT 1,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `pbx_moh_classes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(50) NOT NULL,
  `directory` varchar(255) NOT NULL,
  `mode` varchar(20) DEFAULT 'files',
  `sort` varchar(20) DEFAULT 'alpha',
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `pbx_outbound_routes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `route_name` varchar(100) NOT NULL,
  `match_pattern` varchar(50) NOT NULL,
  `prepend` varchar(20) DEFAULT '',
  `append` varchar(20) DEFAULT '',
  `strip_front` int(11) DEFAULT 0,
  `strip_back` int(11) DEFAULT 0,
  `trunks_json` text DEFAULT NULL,
  `is_internal` tinyint(1) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `pbx_queues` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `queue_name` varchar(50) NOT NULL,
  `title` varchar(100) NOT NULL,
  `language` varchar(10) DEFAULT NULL,
  `strategy` varchar(30) DEFAULT 'rrmemory',
  `timeout` int(11) DEFAULT 15,
  `retry` int(11) DEFAULT 5,
  `wrapuptime` int(11) DEFAULT 10,
  `max_wait_seconds` int(11) DEFAULT 300,
  `fallback_action` varchar(20) DEFAULT 'hangup',
  `fallback_target` varchar(20) DEFAULT NULL,
  `record_enabled` tinyint(1) DEFAULT 1,
  `record_format` varchar(10) DEFAULT 'wav',
  `maxlen` int(11) DEFAULT 0,
  `announce_frequency` int(11) DEFAULT 30,
  `announce_holdtime` varchar(10) DEFAULT 'yes',
  `joinempty` varchar(10) DEFAULT 'yes',
  `leavewhenempty` varchar(10) DEFAULT 'no',
  `ringinuse` varchar(10) DEFAULT 'no',
  `musicclass` varchar(50) DEFAULT 'default',
  `members_json` text DEFAULT NULL,
  `supervisors_json` text DEFAULT NULL,
  `supervisor_extension` varchar(50) DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `queue_name` (`queue_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `pbx_time_conditions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `title` varchar(100) NOT NULL,
  `time_group_id` int(11) NOT NULL,
  `match_dest_type` varchar(50) NOT NULL,
  `match_dest_id` varchar(100) NOT NULL,
  `nomatch_dest_type` varchar(50) NOT NULL,
  `nomatch_dest_id` varchar(100) NOT NULL,
  `rules_json` text DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `time_group_id` (`time_group_id`),
  CONSTRAINT `pbx_time_conditions_ibfk_1` FOREIGN KEY (`time_group_id`) REFERENCES `pbx_time_groups` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `pbx_time_groups` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `title` varchar(100) NOT NULL,
  `time_start` time DEFAULT '08:30:00',
  `time_end` time DEFAULT '17:30:00',
  `days_of_week` varchar(20) DEFAULT '1,2,3,4,5',
  `holidays_json` text DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `is_active` tinyint(1) DEFAULT 1,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `pbx_trunks` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `trunk_name` varchar(50) NOT NULL,
  `title` varchar(100) NOT NULL,
  `ip_address` varchar(100) NOT NULL,
  `port` int(11) DEFAULT 5060,
  `transport` varchar(20) DEFAULT 'udp',
  `codecs` varchar(50) DEFAULT 'alaw,ulaw',
  `t38_support` tinyint(1) DEFAULT 1,
  `qualify_frequency` int(11) DEFAULT 60,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `trunk_name` (`trunk_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `pjsipsettings` (
  `keyword` varchar(50) NOT NULL,
  `data` varchar(255) NOT NULL DEFAULT '',
  `seq` tinyint(1) NOT NULL DEFAULT 1,
  `type` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`keyword`,`seq`,`type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `queues_details` (
  `id` varchar(50) NOT NULL COMMENT 'Queue name (e.g. queue_cc, queue_100)',
  `keyword` varchar(50) NOT NULL COMMENT 'Queue directive key (e.g. strategy, timeout, wrapuptime, ringinuse)',
  `data` varchar(255) NOT NULL DEFAULT '' COMMENT 'Directive value',
  `flags` int(11) NOT NULL DEFAULT 0 COMMENT 'Sorting / priority flag',
  PRIMARY KEY (`id`,`keyword`),
  KEY `idx_id` (`id`),
  KEY `idx_keyword` (`keyword`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `sip` (
  `id` varchar(50) NOT NULL COMMENT 'Extension number or Trunk ID (e.g., 101, trunk_sip)',
  `keyword` varchar(50) NOT NULL COMMENT 'PJSIP directive key (e.g., secret, context, transport, allow)',
  `data` varchar(255) NOT NULL DEFAULT '' COMMENT 'PJSIP directive value',
  `flags` int(11) NOT NULL DEFAULT 0 COMMENT 'Sorting / Grouping order flag',
  PRIMARY KEY (`id`,`keyword`),
  KEY `idx_id` (`id`),
  KEY `idx_keyword` (`keyword`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `sys_did_mappings` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `did_extension` varchar(20) DEFAULT NULL,
  `department_name` varchar(100) NOT NULL,
  `notification_email` varchar(120) DEFAULT '',
  `header_info` varchar(100) DEFAULT 'AI PBX Fax',
  `assigned_user_id` int(11) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `is_active` tinyint(1) DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `did_extension` (`did_extension`),
  KEY `fk_did_assigned_user` (`assigned_user_id`),
  CONSTRAINT `fk_did_assigned_user` FOREIGN KEY (`assigned_user_id`) REFERENCES `sys_users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `sys_login_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `ip_address` varchar(45) NOT NULL,
  `username` varchar(100) NOT NULL,
  `status` enum('SUCCESS','FAILED') NOT NULL,
  `user_agent` text DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_ip_status` (`ip_address`,`status`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `sys_role_permissions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `role_key` varchar(64) NOT NULL,
  `module_key` varchar(64) NOT NULL,
  `can_view` tinyint(1) DEFAULT 1,
  `can_access` tinyint(1) DEFAULT 1,
  `can_edit` tinyint(1) DEFAULT 1,
  `can_delete` tinyint(1) DEFAULT 1,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `role_module` (`role_key`,`module_key`),
  KEY `role_key` (`role_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `sys_roles` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `role_key` varchar(64) NOT NULL,
  `role_name` varchar(128) NOT NULL,
  `description` varchar(255) DEFAULT '',
  `is_system` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `role_key` (`role_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `sys_settings` (
  `setting_key` varchar(50) NOT NULL,
  `setting_value` text NOT NULL,
  PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `sys_users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(50) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `full_name` varchar(100) NOT NULL,
  `email` varchar(120) DEFAULT '',
  `extension` varchar(20) DEFAULT '',
  `sip_password` varchar(100) DEFAULT NULL,
  `role` varchar(20) NOT NULL DEFAULT 'fax_user',
  `extension_type` enum('sip','fax') NOT NULL DEFAULT 'sip',
  `can_listen_recordings` tinyint(1) DEFAULT 0,
  `can_view_all_cdrs` tinyint(1) DEFAULT 0,
  `can_view_queue_monitor` tinyint(1) DEFAULT 0,
  `theme_preference` varchar(10) DEFAULT 'light',
  `allowed_phone_mode` varchar(20) DEFAULT 'both',
  `pickup_group` varchar(20) DEFAULT NULL,
  `dnd_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `call_forward_number` varchar(20) DEFAULT NULL,
  `cid_internal` varchar(50) DEFAULT '',
  `cid_external` varchar(50) DEFAULT '',
  `is_active` tinyint(1) DEFAULT 1,
  `must_reset_password` tinyint(1) NOT NULL DEFAULT 0,
  `reset_token` varchar(64) DEFAULT NULL,
  `reset_token_expires` datetime DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`),
  UNIQUE KEY `uq_reset_token` (`reset_token`),
  KEY `idx_extension` (`extension`),
  KEY `fk_users_role` (`role`),
  CONSTRAINT `fk_users_role` FOREIGN KEY (`role`) REFERENCES `sys_roles` (`role_key`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;



-- asteriskcdr: Asterisk'in kendi realtime CDR tablosu (cdr_adaptive_odbc.conf ile
-- otomatik oluşturulur/genişletilir) — bu migration'a SADECE `cdrs` view'ı fresh bir
-- ortamda oluşturulabilsin diye eklendi. Gerçek Asterisk kurulumunda bu tabloyu
-- Asterisk kendi yönetir, uygulama migration'ları BU TABLOYU DEĞİŞTİRMEMELİDİR.
CREATE TABLE `asteriskcdr` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `calldate` datetime NOT NULL DEFAULT '2000-01-01 00:00:00',
  `clid` varchar(80) NOT NULL DEFAULT '',
  `src` varchar(80) NOT NULL DEFAULT '',
  `dst` varchar(80) NOT NULL DEFAULT '',
  `dcontext` varchar(80) NOT NULL DEFAULT '',
  `channel` varchar(80) NOT NULL DEFAULT '',
  `dstchannel` varchar(80) NOT NULL DEFAULT '',
  `lastapp` varchar(80) NOT NULL DEFAULT '',
  `lastdata` varchar(80) NOT NULL DEFAULT '',
  `duration` int(11) NOT NULL DEFAULT 0,
  `billsec` int(11) NOT NULL DEFAULT 0,
  `disposition` varchar(45) NOT NULL DEFAULT '',
  `amaflags` int(11) NOT NULL DEFAULT 0,
  `accountcode` varchar(20) NOT NULL DEFAULT '',
  `uniqueid` varchar(150) NOT NULL DEFAULT '',
  `userfield` varchar(255) NOT NULL DEFAULT '',
  `peeraccount` varchar(20) NOT NULL DEFAULT '',
  `linkedid` varchar(150) NOT NULL DEFAULT '',
  PRIMARY KEY (`id`),
  KEY `calldate` (`calldate`),
  KEY `dst` (`dst`),
  KEY `accountcode` (`accountcode`),
  KEY `uniqueid` (`uniqueid`),
  KEY `idx_cdr_date_disp` (`calldate`,`disposition`),
  KEY `idx_cdr_src_date` (`src`,`calldate`),
  KEY `idx_cdr_dst_date` (`dst`,`calldate`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- cdrs view (agent_extension caller/callee doğru atama mantığıyla, bkz. proje geçmişi)
-- DEFINER bilerek belirtilmedi — oluşturan kullanıcı (CURRENT_USER) otomatik atanır,
-- taşınabilirlik için (canlıda root@localhost, fresh bir ortamda farklı olabilir).
CREATE ALGORITHM=UNDEFINED SQL SECURITY DEFINER VIEW `cdrs` AS select `c`.`id` AS `id`,`c`.`uniqueid` AS `call_id`,`c`.`src` AS `caller_num`,case when `c`.`lastapp` = 'ReceiveFAX' then 'Gelen Faks' when `c`.`lastapp` = 'SendFAX' then 'Giden Faks' when `c`.`lastapp` = 'Queue' then substring_index(`c`.`lastdata`,',',1) when `d`.`title` is not null then `d`.`title` when `c`.`dcontext` = 'from-internal-pbx' then 'Dahili Görüşme' else concat('Gelen Rota: ',`c`.`dst`) end AS `queue_name`,case when `c`.`accountcode` is not null and `c`.`accountcode` <> '' and `c`.`accountcode` <> `c`.`src` then `c`.`accountcode` when `c`.`dst` is not null and `c`.`dst` <> '' and `c`.`dst` <> '0' and `c`.`dst` <> `c`.`src` then `c`.`dst` else NULL end AS `agent_extension`,`u`.`full_name` AS `agent_name`,`c`.`calldate` AS `start_time`,`c`.`calldate` AS `answer_time`,`c`.`calldate` + interval `c`.`duration` second AS `end_time`,`c`.`duration` AS `duration`,`c`.`billsec` AS `billsec`,`c`.`disposition` AS `status`,case when `c`.`userfield` is not null and `c`.`userfield` <> '' then `c`.`userfield` when `c`.`lastapp` = 'ReceiveFAX' then substring_index(`c`.`lastdata`,',',1) else '' end AS `recording_path`,`c`.`calldate` AS `created_at` from ((`asteriskcdr` `c` left join `sys_users` `u` on(`u`.`extension` = case when `c`.`accountcode` is not null and `c`.`accountcode` <> '' and `c`.`accountcode` <> `c`.`src` then `c`.`accountcode` when `c`.`dst` is not null and `c`.`dst` <> '' and `c`.`dst` <> '0' and `c`.`dst` <> `c`.`src` then `c`.`dst` else NULL end)) left join `pbx_dids` `d` on(`d`.`did_number` = `c`.`dst`));

SET FOREIGN_KEY_CHECKS=1;
