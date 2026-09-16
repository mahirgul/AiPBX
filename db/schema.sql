/*M!999999\- enable the sandbox mode */ 
-- MariaDB dump 10.19-11.8.6-MariaDB, for debian-linux-gnu (x86_64)
--
-- Host: localhost    Database: asterisk
-- ------------------------------------------------------
-- Server version	11.8.6-MariaDB-5ubuntu0.1 from Ubuntu

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*M!100616 SET @OLD_NOTE_VERBOSITY=@@NOTE_VERBOSITY, NOTE_VERBOSITY=0 */;

--
-- Table structure for table `asteriskcdr`
--

DROP TABLE IF EXISTS `asteriskcdr`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `asteriskcdr` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `calldate` datetime NOT NULL DEFAULT '2000-01-01 00:00:00',
  `clid` varchar(80) NOT NULL DEFAULT '',
  `src` varchar(80) NOT NULL DEFAULT '',
  `dst` varchar(80) NOT NULL DEFAULT '',
  `did` varchar(50) DEFAULT NULL,
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
  KEY `idx_cdr_dst_date` (`dst`,`calldate`),
  KEY `idx_did` (`did`),
  KEY `idx_calldate` (`calldate`)
) ENGINE=InnoDB AUTO_INCREMENT=1505 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `asteriskcel`
--

DROP TABLE IF EXISTS `asteriskcel`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `asteriskcel` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `eventtype` varchar(30) NOT NULL,
  `eventtime` datetime NOT NULL,
  `userdeftype` varchar(255) NOT NULL DEFAULT '',
  `cid_name` varchar(80) NOT NULL DEFAULT '',
  `cid_num` varchar(80) NOT NULL DEFAULT '',
  `cid_ani` varchar(80) NOT NULL DEFAULT '',
  `cid_rdnis` varchar(80) NOT NULL DEFAULT '',
  `cid_dnid` varchar(80) NOT NULL DEFAULT '',
  `exten` varchar(80) NOT NULL DEFAULT '',
  `context` varchar(80) NOT NULL DEFAULT '',
  `channame` varchar(80) NOT NULL DEFAULT '',
  `appname` varchar(80) NOT NULL DEFAULT '',
  `appdata` text NOT NULL,
  `amaflags` int(11) NOT NULL DEFAULT 0,
  `accountcode` varchar(20) NOT NULL DEFAULT '',
  `uniqueid` varchar(150) NOT NULL DEFAULT '',
  `linkedid` varchar(150) NOT NULL DEFAULT '',
  `peer` varchar(80) NOT NULL DEFAULT '',
  `userfield` varchar(255) NOT NULL DEFAULT '',
  `peeraccount` varchar(20) NOT NULL DEFAULT '',
  `extra` text NOT NULL,
  PRIMARY KEY (`id`),
  KEY `eventtime` (`eventtime`),
  KEY `uniqueid` (`uniqueid`),
  KEY `linkedid` (`linkedid`)
) ENGINE=InnoDB AUTO_INCREMENT=10454 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `asteriskqueue`
--

DROP TABLE IF EXISTS `asteriskqueue`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `asteriskqueue` (
  `id` bigint(25) unsigned NOT NULL AUTO_INCREMENT,
  `time` varchar(32) DEFAULT NULL,
  `callid` varchar(64) DEFAULT NULL,
  `queuename` varchar(64) DEFAULT NULL,
  `agent` varchar(64) DEFAULT NULL,
  `event` varchar(32) DEFAULT NULL,
  `data` varchar(255) DEFAULT NULL,
  `data1` varchar(64) DEFAULT NULL,
  `data2` varchar(64) DEFAULT NULL,
  `data3` varchar(64) DEFAULT NULL,
  `data4` varchar(64) DEFAULT NULL,
  `data5` varchar(64) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `time` (`time`),
  KEY `callid` (`callid`),
  KEY `queuename` (`queuename`),
  KEY `agent` (`agent`),
  KEY `event` (`event`)
) ENGINE=InnoDB AUTO_INCREMENT=515 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `callcenter_notes`
--

DROP TABLE IF EXISTS `callcenter_notes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
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
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `cc_pause_logs`
--

DROP TABLE IF EXISTS `cc_pause_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
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
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `cc_queue_logs`
--

DROP TABLE IF EXISTS `cc_queue_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
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
) ENGINE=InnoDB AUTO_INCREMENT=658 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Temporary table structure for view `cdrs`
--

DROP TABLE IF EXISTS `cdrs`;
/*!50001 DROP VIEW IF EXISTS `cdrs`*/;
SET @saved_cs_client     = @@character_set_client;
SET character_set_client = utf8mb4;
/*!50001 CREATE VIEW `cdrs` AS SELECT
 1 AS `id`,
  1 AS `call_id`,
  1 AS `caller_num`,
  1 AS `queue_name`,
  1 AS `agent_extension`,
  1 AS `agent_name`,
  1 AS `start_time`,
  1 AS `answer_time`,
  1 AS `end_time`,
  1 AS `duration`,
  1 AS `billsec`,
  1 AS `ring_sec`,
  1 AS `status`,
  1 AS `recording_path`,
  1 AS `created_at`,
  1 AS `channel`,
  1 AS `dstchannel`,
  1 AS `device_type` */;
SET character_set_client = @saved_cs_client;

--
-- Table structure for table `chat_conversations`
--

DROP TABLE IF EXISTS `chat_conversations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `chat_conversations` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `type` varchar(16) NOT NULL DEFAULT 'direct',
  `direct_key` varchar(50) DEFAULT NULL,
  `title` varchar(100) DEFAULT NULL,
  `created_by` varchar(20) NOT NULL,
  `last_message_text` varchar(255) DEFAULT NULL,
  `last_message_at` datetime DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `direct_key` (`direct_key`),
  KEY `last_message_at` (`last_message_at`)
) ENGINE=InnoDB AUTO_INCREMENT=30 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `chat_messages`
--

DROP TABLE IF EXISTS `chat_messages`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `chat_messages` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `conversation_id` int(11) NOT NULL,
  `sender_ext` varchar(20) NOT NULL,
  `msg_type` varchar(16) NOT NULL DEFAULT 'text',
  `message` text DEFAULT NULL,
  `attachment_url` varchar(255) DEFAULT NULL,
  `file_name` varchar(255) DEFAULT NULL,
  `file_size` int(11) NOT NULL DEFAULT 0,
  `mime_type` varchar(100) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `conversation_id` (`conversation_id`,`id`),
  KEY `sender_ext` (`sender_ext`),
  KEY `created_at` (`created_at`)
) ENGINE=InnoDB AUTO_INCREMENT=24 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `chat_participants`
--

DROP TABLE IF EXISTS `chat_participants`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `chat_participants` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `conversation_id` int(11) NOT NULL,
  `extension` varchar(20) NOT NULL,
  `last_read_message_id` bigint(20) NOT NULL DEFAULT 0,
  `is_muted` int(1) NOT NULL DEFAULT 0,
  `joined_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `conversation_id` (`conversation_id`,`extension`),
  KEY `extension` (`extension`)
) ENGINE=InnoDB AUTO_INCREMENT=34 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `fax_received`
--

DROP TABLE IF EXISTS `fax_received`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
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
) ENGINE=InnoDB AUTO_INCREMENT=216 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `fax_sent`
--

DROP TABLE IF EXISTS `fax_sent`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
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
) ENGINE=InnoDB AUTO_INCREMENT=223 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `pbx_announcements`
--

DROP TABLE IF EXISTS `pbx_announcements`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `pbx_announcements` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `title` varchar(100) NOT NULL,
  `audio_file` varchar(255) NOT NULL,
  `post_dest_type` varchar(50) DEFAULT 'hangup',
  `post_dest_id` varchar(100) DEFAULT '',
  `created_at` datetime DEFAULT current_timestamp(),
  `is_active` tinyint(1) DEFAULT 1,
  `internal_number` varchar(10) DEFAULT NULL COMMENT 'Dahili telefonlardan dogrudan aranabilen numara (istege bagli)',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_internal_number` (`internal_number`)
) ENGINE=InnoDB AUTO_INCREMENT=13 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `pbx_dids`
--

DROP TABLE IF EXISTS `pbx_dids`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
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
) ENGINE=InnoDB AUTO_INCREMENT=47 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `pbx_feature_codes`
--

DROP TABLE IF EXISTS `pbx_feature_codes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
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
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `pbx_hangup_actions`
--

DROP TABLE IF EXISTS `pbx_hangup_actions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `pbx_hangup_actions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `action_key` varchar(50) NOT NULL,
  `title` varchar(100) NOT NULL,
  `action_type` varchar(30) NOT NULL DEFAULT 'hangup',
  `announcement_id` int(11) DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` datetime DEFAULT current_timestamp(),
  `internal_number` varchar(10) DEFAULT NULL COMMENT 'Dahili telefonlardan dogrudan aranabilen numara (istege bagli)',
  PRIMARY KEY (`id`),
  UNIQUE KEY `action_key` (`action_key`),
  UNIQUE KEY `uniq_internal_number` (`internal_number`),
  KEY `fk_hangup_announcement` (`announcement_id`),
  CONSTRAINT `fk_hangup_announcement` FOREIGN KEY (`announcement_id`) REFERENCES `pbx_announcements` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `pbx_ivr_entries`
--

DROP TABLE IF EXISTS `pbx_ivr_entries`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `pbx_ivr_entries` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `ivr_id` int(11) NOT NULL,
  `digit` varchar(5) NOT NULL,
  `dest_type` varchar(50) NOT NULL,
  `dest_id` varchar(100) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_ivr_digit` (`ivr_id`,`digit`),
  CONSTRAINT `pbx_ivr_entries_ibfk_1` FOREIGN KEY (`ivr_id`) REFERENCES `pbx_ivrs` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `pbx_ivrs`
--

DROP TABLE IF EXISTS `pbx_ivrs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
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
  `internal_number` varchar(10) DEFAULT NULL COMMENT 'Dahili telefonlardan dogrudan aranabilen numara (istege bagli)',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_internal_number` (`internal_number`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `pbx_moh_classes`
--

DROP TABLE IF EXISTS `pbx_moh_classes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
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
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `pbx_outbound_routes`
--

DROP TABLE IF EXISTS `pbx_outbound_routes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
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
  `route_group` int(11) NOT NULL DEFAULT 1 COMMENT 'Bu rotayı hangi kullanıcı grubu kullanabilir',
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_route_group` (`route_group`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `pbx_queues`
--

DROP TABLE IF EXISTS `pbx_queues`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
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
  `internal_number` varchar(10) DEFAULT NULL COMMENT 'Dahili telefonlardan dogrudan aranabilen numara (istege bagli)',
  PRIMARY KEY (`id`),
  UNIQUE KEY `queue_name` (`queue_name`),
  UNIQUE KEY `uniq_internal_number` (`internal_number`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `pbx_time_conditions`
--

DROP TABLE IF EXISTS `pbx_time_conditions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
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
  `internal_number` varchar(10) DEFAULT NULL COMMENT 'Dahili telefonlardan dogrudan aranabilen numara (istege bagli)',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_internal_number` (`internal_number`),
  KEY `time_group_id` (`time_group_id`),
  CONSTRAINT `pbx_time_conditions_ibfk_1` FOREIGN KEY (`time_group_id`) REFERENCES `pbx_time_groups` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `pbx_time_groups`
--

DROP TABLE IF EXISTS `pbx_time_groups`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
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
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `pbx_trunks`
--

DROP TABLE IF EXISTS `pbx_trunks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `pbx_trunks` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `trunk_name` varchar(50) NOT NULL,
  `title` varchar(100) NOT NULL,
  `ip_address` varchar(100) NOT NULL,
  `port` int(11) DEFAULT 5060,
  `outbound_proxy` varchar(255) DEFAULT NULL,
  `match_hosts` varchar(255) DEFAULT NULL,
  `transport` varchar(20) DEFAULT 'udp',
  `connection_mode` varchar(16) NOT NULL DEFAULT 'ip' COMMENT 'ip = IP tabanli (identify), register = karsi taraf bize kaydolur',
  `codecs` varchar(50) DEFAULT 'alaw,ulaw',
  `t38_support` tinyint(1) DEFAULT 1,
  `t38_udptl_ec` varchar(20) DEFAULT 'redundancy',
  `t38_udptl_nat` varchar(10) DEFAULT 'yes',
  `t38_udptl_maxdatagram` int(11) DEFAULT 400,
  `fax_detect` tinyint(1) DEFAULT 1,
  `fax_detect_timeout` int(11) DEFAULT 30,
  `qualify_frequency` int(11) DEFAULT 60,
  `send_caller_name` tinyint(1) DEFAULT 0,
  `auth_username` varchar(100) DEFAULT NULL,
  `auth_password` varchar(100) DEFAULT NULL,
  `registration_enabled` tinyint(1) DEFAULT 0,
  `registration_expiration` int(11) DEFAULT 3600,
  `registration_retry_interval` int(11) DEFAULT 60,
  `max_contacts` int(11) DEFAULT 1,
  `from_user` varchar(100) DEFAULT NULL,
  `from_domain` varchar(150) DEFAULT NULL,
  `outbound_caller_id` varchar(50) DEFAULT NULL,
  `dtmf_mode` varchar(20) DEFAULT 'rfc4733',
  `context` varchar(100) DEFAULT 'from-trunk-inbound',
  `max_channels` int(11) DEFAULT 0,
  `direct_media` varchar(10) DEFAULT 'no',
  `rtp_symmetric` varchar(10) DEFAULT 'yes',
  `rewrite_contact` varchar(10) DEFAULT 'yes',
  `force_rport` varchar(10) DEFAULT 'yes',
  `timers` varchar(10) DEFAULT 'yes',
  `send_pai` tinyint(1) DEFAULT 0,
  `send_rpid` tinyint(1) DEFAULT 0,
  `custom_pjsip_params` text DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `trunk_name` (`trunk_name`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `phinx_migrations`
--

DROP TABLE IF EXISTS `phinx_migrations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `phinx_migrations` (
  `version` bigint(20) NOT NULL,
  `migration_name` varchar(100) DEFAULT NULL,
  `start_time` timestamp NULL DEFAULT NULL,
  `end_time` timestamp NULL DEFAULT NULL,
  `breakpoint` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`version`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `pjsipsettings`
--

DROP TABLE IF EXISTS `pjsipsettings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `pjsipsettings` (
  `keyword` varchar(50) NOT NULL,
  `data` varchar(255) NOT NULL DEFAULT '',
  `seq` tinyint(1) NOT NULL DEFAULT 1,
  `type` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`keyword`,`seq`,`type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `queues_details`
--

DROP TABLE IF EXISTS `queues_details`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `queues_details` (
  `id` varchar(50) NOT NULL COMMENT 'Queue name (e.g. queue_cc, queue_100)',
  `keyword` varchar(50) NOT NULL COMMENT 'Queue directive key (e.g. strategy, timeout, wrapuptime, ringinuse)',
  `data` varchar(255) NOT NULL DEFAULT '' COMMENT 'Directive value',
  `flags` int(11) NOT NULL DEFAULT 0 COMMENT 'Sorting / priority flag',
  PRIMARY KEY (`id`,`keyword`),
  KEY `idx_id` (`id`),
  KEY `idx_keyword` (`keyword`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `sip`
--

DROP TABLE IF EXISTS `sip`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `sip` (
  `id` varchar(50) NOT NULL COMMENT 'Extension number or Trunk ID (e.g., 101, trunk_main)',
  `keyword` varchar(50) NOT NULL COMMENT 'PJSIP directive key (e.g., secret, context, transport, allow)',
  `data` varchar(255) NOT NULL DEFAULT '' COMMENT 'PJSIP directive value',
  `flags` int(11) NOT NULL DEFAULT 0 COMMENT 'Sorting / Grouping order flag',
  PRIMARY KEY (`id`,`keyword`),
  KEY `idx_id` (`id`),
  KEY `idx_keyword` (`keyword`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `sys_audit_log`
--

DROP TABLE IF EXISTS `sys_audit_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `sys_audit_log` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `domain` varchar(50) DEFAULT NULL,
  `entity_type` varchar(50) NOT NULL,
  `entity_id` varchar(100) DEFAULT NULL,
  `entity_label` varchar(255) NOT NULL,
  `action` varchar(20) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `username` varchar(150) DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `created_at` (`created_at`),
  KEY `domain` (`domain`),
  KEY `user_id` (`user_id`)
) ENGINE=InnoDB AUTO_INCREMENT=488 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `sys_did_mappings`
--

DROP TABLE IF EXISTS `sys_did_mappings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `sys_did_mappings` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `did_extension` varchar(20) DEFAULT NULL,
  `department_name` varchar(100) NOT NULL,
  `notification_email` varchar(120) DEFAULT '',
  `header_info` varchar(100) DEFAULT 'AI PBX Fax Server',
  `assigned_user_id` int(11) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `is_active` tinyint(1) DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `did_extension` (`did_extension`),
  KEY `fk_did_assigned_user` (`assigned_user_id`),
  CONSTRAINT `fk_did_assigned_user` FOREIGN KEY (`assigned_user_id`) REFERENCES `sys_users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=42 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `sys_login_logs`
--

DROP TABLE IF EXISTS `sys_login_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `sys_login_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `ip_address` varchar(45) NOT NULL,
  `username` varchar(100) NOT NULL,
  `status` enum('SUCCESS','FAILED') NOT NULL,
  `user_agent` text DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_ip_status` (`ip_address`,`status`,`created_at`)
) ENGINE=InnoDB AUTO_INCREMENT=259 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `sys_mobile_devices`
--

DROP TABLE IF EXISTS `sys_mobile_devices`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `sys_mobile_devices` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `extension` varchar(20) NOT NULL,
  `fcm_token` varchar(512) DEFAULT NULL,
  `device_id` varchar(128) DEFAULT NULL,
  `device_name` varchar(128) DEFAULT NULL,
  `platform` varchar(32) NOT NULL DEFAULT 'android',
  `push_type` varchar(32) NOT NULL DEFAULT 'none',
  `app_version` varchar(32) DEFAULT NULL,
  `is_active` int(1) NOT NULL DEFAULT 1,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  KEY `extension` (`extension`),
  KEY `fcm_token` (`fcm_token`(255)),
  KEY `device_id` (`device_id`)
) ENGINE=InnoDB AUTO_INCREMENT=10 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `sys_pending_sync`
--

DROP TABLE IF EXISTS `sys_pending_sync`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `sys_pending_sync` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `domain` varchar(50) NOT NULL,
  `entity_type` varchar(50) NOT NULL,
  `entity_id` varchar(100) DEFAULT NULL,
  `entity_label` varchar(255) NOT NULL,
  `action` varchar(20) NOT NULL,
  `changed_by` int(11) DEFAULT NULL,
  `changed_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_pending_entity` (`domain`,`entity_type`,`entity_id`),
  KEY `domain` (`domain`)
) ENGINE=InnoDB AUTO_INCREMENT=259 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `sys_role_permissions`
--

DROP TABLE IF EXISTS `sys_role_permissions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
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
) ENGINE=InnoDB AUTO_INCREMENT=352 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `sys_roles`
--

DROP TABLE IF EXISTS `sys_roles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `sys_roles` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `role_key` varchar(64) NOT NULL,
  `role_name` varchar(128) NOT NULL,
  `description` varchar(255) DEFAULT '',
  `is_system` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `role_key` (`role_key`)
) ENGINE=InnoDB AUTO_INCREMENT=10 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `sys_settings`
--

DROP TABLE IF EXISTS `sys_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `sys_settings` (
  `setting_key` varchar(50) NOT NULL,
  `setting_value` text NOT NULL,
  PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `sys_users`
--

DROP TABLE IF EXISTS `sys_users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `sys_users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(50) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `full_name` varchar(100) NOT NULL,
  `email` varchar(120) DEFAULT '',
  `extension` varchar(20) DEFAULT '',
  `sip_password` varchar(100) DEFAULT NULL,
  `sip_auth_digest` int(1) NOT NULL DEFAULT 1 COMMENT 'SIP kimlik doğrulama (Auth Digest) yapılsın mı: 1=evet, 0=hayır',
  `role` varchar(20) NOT NULL DEFAULT 'fax_user',
  `extension_type` enum('sip','fax') NOT NULL DEFAULT 'sip',
  `outbound_group` int(11) NOT NULL DEFAULT 1 COMMENT 'Kullanıcının kullanabileceği giden rota grubu',
  `can_listen_recordings` tinyint(1) DEFAULT 0,
  `can_view_all_cdrs` tinyint(1) DEFAULT 0,
  `can_view_queue_monitor` tinyint(1) DEFAULT 0,
  `theme_preference` varchar(10) DEFAULT 'light',
  `language_preference` varchar(5) NOT NULL DEFAULT 'tr',
  `last_seen_at` datetime DEFAULT NULL,
  `allowed_phone_mode` varchar(100) DEFAULT 'web,mobil,sip,video',
  `pickup_group` varchar(20) DEFAULT NULL,
  `dnd_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `call_forward_number` varchar(20) DEFAULT NULL,
  `cf_busy_number` varchar(20) DEFAULT NULL,
  `cf_noanswer_number` varchar(20) DEFAULT NULL,
  `cf_noanswer_timeout` int(11) NOT NULL DEFAULT 20,
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
) ENGINE=InnoDB AUTO_INCREMENT=86 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Final view structure for view `cdrs`
--

/*!50001 DROP VIEW IF EXISTS `cdrs`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_general_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 DEFINER=`aipbx_migrator`@`localhost` SQL SECURITY DEFINER */
/*!50001 VIEW `cdrs` AS select `c`.`id` AS `id`,`c`.`uniqueid` AS `call_id`,`c`.`src` AS `caller_num`,case when `c`.`lastapp` = 'ReceiveFAX' then 'Gelen Faks' when `c`.`lastapp` = 'SendFAX' then 'Giden Faks' when `c`.`lastapp` = 'Queue' then substring_index(`c`.`lastdata`,',',1) when `d`.`title` is not null then `d`.`title` when `c`.`dcontext` = 'from-internal-pbx' then 'Dahili Görüşme' else concat('Gelen Rota: ',coalesce(nullif(`c`.`did`,''),`c`.`dst`)) end AS `queue_name`,case when `c`.`accountcode` is not null and `c`.`accountcode` <> '' and `c`.`accountcode` <> `c`.`src` then `c`.`accountcode` when nullif(`c`.`did`,'') is not null then `c`.`did` when `c`.`dst` is not null and `c`.`dst` <> '' and `c`.`dst` <> '0' and `c`.`dst` <> `c`.`src` then `c`.`dst` else NULL end AS `agent_extension`,`u`.`full_name` AS `agent_name`,`c`.`calldate` AS `start_time`,`c`.`calldate` + interval greatest(`c`.`duration` - `c`.`billsec`,0) second AS `answer_time`,`c`.`calldate` + interval `c`.`duration` second AS `end_time`,greatest(`c`.`duration`,`c`.`billsec`) AS `duration`,`c`.`billsec` AS `billsec`,greatest(`c`.`duration`,`c`.`billsec`) - `c`.`billsec` AS `ring_sec`,`c`.`disposition` AS `status`,case when `c`.`userfield` is not null and `c`.`userfield` <> '' then `c`.`userfield` when `c`.`lastapp` = 'ReceiveFAX' then substring_index(`c`.`lastdata`,',',1) else '' end AS `recording_path`,`c`.`calldate` AS `created_at`,`c`.`channel` AS `channel`,`c`.`dstchannel` AS `dstchannel`,case when `c`.`dstchannel` like '%-mob-webrtc%' then 'mobil' when `c`.`dstchannel` like '%-webrtc%' then 'webrtc' when `c`.`dstchannel` like '%-sip%' then 'sip' when `c`.`channel` like '%-mob-webrtc%' then 'mobil' when `c`.`channel` like '%-webrtc%' then 'webrtc' when `c`.`channel` like '%-sip%' then 'sip' else '' end AS `device_type` from ((`asteriskcdr` `c` left join `sys_users` `u` on(`u`.`extension` = case when `c`.`accountcode` is not null and `c`.`accountcode` <> '' and `c`.`accountcode` <> `c`.`src` then `c`.`accountcode` when `c`.`dst` is not null and `c`.`dst` <> '' and `c`.`dst` <> '0' and `c`.`dst` <> `c`.`src` then `c`.`dst` else NULL end)) left join `pbx_dids` `d` on(`d`.`did_number` = coalesce(nullif(`c`.`did`,''),`c`.`dst`))) */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*M!100616 SET NOTE_VERBOSITY=@OLD_NOTE_VERBOSITY */;

-- Dump completed on 2026-09-09  6:29:28
