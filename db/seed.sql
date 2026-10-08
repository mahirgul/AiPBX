/*M!999999\- enable the sandbox mode */ 

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

SET @OLD_AUTOCOMMIT=@@AUTOCOMMIT, @@AUTOCOMMIT=0;
LOCK TABLES `pbx_feature_codes` WRITE;
/*!40000 ALTER TABLE `pbx_feature_codes` DISABLE KEYS */;
-- No ids: the migrations insert rows into this table first, and fixed ids
-- collided with them, so INSERT IGNORE silently dropped seed rows (the unique
-- keys decide what already exists).
INSERT IGNORE INTO `pbx_feature_codes` (`feature_key`, `title`, `code`, `allowed_roles`, `is_active`, `created_at`) VALUES
('dnd_toggle','Do Not Disturb (DND) on/off','*78',NULL,1,'2026-08-19 20:56:03'),
('cf_set','Set call forwarding','*72',NULL,1,'2026-08-19 20:56:03'),
('cf_cancel','Cancel call forwarding','*73',NULL,1,'2026-08-19 20:56:03'),
('pickup_group','Group call pickup','*20',NULL,1,'2026-08-19 20:56:03'),
('pickup_directed','Directed call pickup','*21',NULL,1,'2026-08-19 20:56:03'),
('spy','Call listening (spy)','*90','admin,cc_manager',1,'2026-08-19 20:56:03'),
('queue_login','Queue login (with queue ID, *81<id>)','_*81.','admin,cc_manager,cc_agent',1,'2026-08-21 14:37:22'),
('queue_logout','Queue logout (with queue ID, *80<id>)','_*80.','admin,cc_manager,cc_agent',1,'2026-08-21 14:37:22'),
('queue_pause','Queue break (with break ID, *22<id>)','_*22.','admin,cc_manager,cc_agent,user',1,'2026-09-16 20:26:41'),
('queue_unpause','End queue break (*23)','*23','admin,cc_manager,cc_agent,user',1,'2026-09-16 20:26:41');
/*!40000 ALTER TABLE `pbx_feature_codes` ENABLE KEYS */;
UNLOCK TABLES;
COMMIT;
SET AUTOCOMMIT=@OLD_AUTOCOMMIT;

SET @OLD_AUTOCOMMIT=@@AUTOCOMMIT, @@AUTOCOMMIT=0;
LOCK TABLES `pbx_hangup_actions` WRITE;
/*!40000 ALTER TABLE `pbx_hangup_actions` DISABLE KEYS */;
INSERT IGNORE INTO `pbx_hangup_actions` VALUES
(1,'hangup','Hang up','hangup',NULL,1,'2026-08-10 14:53:18',NULL),
(2,'busy','Play busy tone','busy',NULL,1,'2026-08-10 14:53:18','1050'),
(3,'congestion','Network busy (congestion)','congestion',NULL,1,'2026-08-10 14:53:18',NULL);
/*!40000 ALTER TABLE `pbx_hangup_actions` ENABLE KEYS */;
UNLOCK TABLES;
COMMIT;
SET AUTOCOMMIT=@OLD_AUTOCOMMIT;

SET @OLD_AUTOCOMMIT=@@AUTOCOMMIT, @@AUTOCOMMIT=0;
LOCK TABLES `pbx_moh_classes` WRITE;
/*!40000 ALTER TABLE `pbx_moh_classes` DISABLE KEYS */;
INSERT IGNORE INTO `pbx_moh_classes` VALUES
(1,'default','/var/lib/asterisk/moh','files','alpha',1,'2026-08-10 11:54:57'),
(2,'custom','/var/lib/asterisk/moh/custom','files','alpha',1,'2026-08-10 11:54:57');
/*!40000 ALTER TABLE `pbx_moh_classes` ENABLE KEYS */;
UNLOCK TABLES;
COMMIT;
SET AUTOCOMMIT=@OLD_AUTOCOMMIT;

SET @OLD_AUTOCOMMIT=@@AUTOCOMMIT, @@AUTOCOMMIT=0;
LOCK TABLES `pjsipsettings` WRITE;
/*!40000 ALTER TABLE `pjsipsettings` DISABLE KEYS */;
INSERT IGNORE INTO `pjsipsettings` VALUES
('bindaddr','0.0.0.0',1,0),
('bindport','5060',1,0),
('externip_val','',1,0),
('localnet_0','192.168.100.0',1,0),
('netmask_0','255.255.255.0',1,0),
('tcp_bindaddr','0.0.0.0',1,0),
('tcp_bindport','5060',1,0),
('tcp_enabled','1',1,0),
('tls_bindaddr','0.0.0.0',1,0),
('tls_bindport','5061',1,0),
('tls_cert_file','/etc/asterisk/keys/fullchain.pem',1,0),
('tls_enabled','1',1,0),
('tls_priv_key_file','/etc/asterisk/keys/privkey.pem',1,0),
('wss_bindaddr','0.0.0.0',1,0),
('wss_bindport','8089',1,0),
('wss_enabled','1',1,0),
('ws_bindaddr','0.0.0.0',1,0),
('ws_bindport','8088',1,0),
('ws_enabled','1',1,0);
/*!40000 ALTER TABLE `pjsipsettings` ENABLE KEYS */;
UNLOCK TABLES;
COMMIT;
SET AUTOCOMMIT=@OLD_AUTOCOMMIT;

SET @OLD_AUTOCOMMIT=@@AUTOCOMMIT, @@AUTOCOMMIT=0;
LOCK TABLES `sys_settings` WRITE;
/*!40000 ALTER TABLE `sys_settings` DISABLE KEYS */;
INSERT IGNORE INTO `sys_settings` VALUES
('brand_color_primary',''),
('brand_color_secondary',''),
('brand_sub','PBX & Call Center'),
('brand_title','AiPBX'),
('cc_auto_queue_login','1'),
('cc_break_reasons','Lunch,Short break,Training / Meeting,Paperwork,Technical issue'),
('fax_email_from_address',''),
('fax_email_from_name','AiPBX Fax'),
('fax_email_rx_attach_pdf','yes'),
('fax_email_rx_enabled','yes'),
('fax_email_tx_enabled','yes'),
('fax_header_info','AI PBX Fax Server'),
('fax_local_station_id','AiPBX'),
('fax_max_retries','3'),
('fax_retention_days','0'),
('fax_retry_time','60'),
('fax_wait_time','30'),
('pjsip_codecs','opus,alaw,ulaw,g722'),
('pjsip_direct_media','no'),
('pjsip_external_dial_timeout','60'),
('pjsip_external_ip',''),
('pjsip_internal_dial_timeout','30'),
('pjsip_local_net','192.168.100.0/24'),
('pjsip_qualify_frequency','60'),
('pjsip_qualify_frequency_mobile','0'),
('pjsip_rtp_symmetric','yes'),
('pjsip_udp_port','5060'),
('pjsip_user_agent','AI-PBX'),
('pjsip_wired_codecs','alaw,ulaw,g722'),
('pjsip_wss_port','8089'),
('pjsip_ws_path','/ws'),
('portal_email_from_address',''),
('portal_email_from_name','AiPBX Portal'),
('push_enabled','0'),
('push_fcm_api_key',''),
('push_fcm_app_id',''),
('push_fcm_project_id',''),
('push_fcm_sender_id',''),
('push_fcm_service_account',''),
('push_provider','none'),
('push_wait_seconds','8'),
('rtp_end','12999'),
('rtp_start','10000'),
('rtp_strict','yes'),
('site_favicon_url',''),
('site_logo_icon','fa-network-wired'),
('site_logo_image',''),
('site_logo_type','image'),
('site_title','AiPBX'),
('system_default_language','en'),
('teams_domain',''),
('teams_enabled','0'),
('teams_notify_cdr_summary','0'),
('teams_notify_fax','1'),
('teams_notify_missed_calls','1'),
('teams_notify_queue_alerts','1'),
('teams_notify_voicemail','1'),
('teams_sbc_name',''),
('teams_sip_port','5061'),
('teams_tls_cert_path','/etc/asterisk/keys/teams_cert.pem'),
('teams_tls_key_path','/etc/asterisk/keys/teams_key.pem'),
('teams_webhook_enabled','0'),
('teams_webhook_url',''),
('udptl_checksums','yes'),
('udptl_end','4999'),
('udptl_fec_entries','3'),
('udptl_fec_span','3'),
('udptl_start','4100'),
('video_calls_enabled','1'),
('video_max_framerate','30'),
('video_max_resolution','1280x720'),
('webrtc_ring_incoming','ring'),
('webrtc_ring_outgoing','rb'),
('webrtc_username_suffix','-webrtc');
/*!40000 ALTER TABLE `sys_settings` ENABLE KEYS */;
UNLOCK TABLES;
COMMIT;
SET AUTOCOMMIT=@OLD_AUTOCOMMIT;

SET @OLD_AUTOCOMMIT=@@AUTOCOMMIT, @@AUTOCOMMIT=0;
LOCK TABLES `sys_roles` WRITE;
/*!40000 ALTER TABLE `sys_roles` DISABLE KEYS */;
INSERT IGNORE INTO `sys_roles` VALUES
(1,'admin','Admin','Full-access system administrator',1,'2026-08-12 13:15:29'),
(2,'read_only_admin','Viewer','Can view every panel but cannot edit or delete',1,'2026-08-12 13:15:29'),
(3,'cc_agent','Agent','Agent desk, break management and call recordings',1,'2026-08-12 13:15:29'),
(4,'fax_user','Fax','Incoming/outgoing fax management and sending faxes',1,'2026-08-12 13:15:29'),
(5,'cc_manager','Queue Manager','Call centre live monitoring, queues, agents, breaks and reports',1,'2026-08-14 00:35:09'),
(6,'user','User','My Phone, directory, chat and own call history (no fax or call centre access)',1,'2026-09-30 00:00:00');
/*!40000 ALTER TABLE `sys_roles` ENABLE KEYS */;
UNLOCK TABLES;
COMMIT;
SET AUTOCOMMIT=@OLD_AUTOCOMMIT;

SET @OLD_AUTOCOMMIT=@@AUTOCOMMIT, @@AUTOCOMMIT=0;
LOCK TABLES `sys_role_permissions` WRITE;
/*!40000 ALTER TABLE `sys_role_permissions` DISABLE KEYS */;
-- No ids: the migrations insert rows into this table first, and fixed ids
-- collided with them, so INSERT IGNORE silently dropped seed rows (the unique
-- keys decide what already exists).
INSERT IGNORE INTO `sys_role_permissions` (`role_key`, `module_key`, `can_view`, `can_access`, `can_edit`, `can_delete`, `updated_at`) VALUES
('admin','dashboard',1,1,1,1,'2026-08-12 13:15:31'),
('admin','trunks',1,1,1,1,'2026-08-12 13:15:31'),
('admin','did_routes',1,1,1,1,'2026-08-12 13:15:31'),
('admin','outbound_routes',1,1,1,1,'2026-08-12 13:15:31'),
('admin','time_conditions',1,1,1,1,'2026-08-12 13:15:31'),
('admin','ivrs',1,1,1,1,'2026-08-12 13:15:31'),
('admin','extensions',1,1,1,1,'2026-08-12 13:15:31'),
('admin','queues',1,1,1,1,'2026-08-12 13:15:31'),
('admin','sounds',1,1,1,1,'2026-08-12 13:15:31'),
('admin','end_call',1,1,1,1,'2026-08-12 13:15:31'),
('admin','asterisk_settings',1,1,1,1,'2026-08-12 13:15:31'),
('admin','fax_settings',1,1,1,1,'2026-08-12 13:15:31'),
('admin','system_users',1,1,1,1,'2026-08-12 13:15:31'),
('admin','roles',1,1,1,1,'2026-08-12 13:15:31'),
('admin','fax_inbox',1,1,1,1,'2026-08-12 13:15:31'),
('admin','fax_send',1,1,1,1,'2026-08-12 13:15:31'),
('admin','fax_sent',1,1,1,1,'2026-08-12 13:15:31'),
('admin','cc_agent',1,1,1,1,'2026-08-21 10:54:38'),
('admin','pause_reports',1,1,1,1,'2026-08-12 13:15:31'),
('admin','queue_logs',1,1,1,1,'2026-08-12 13:15:31'),
('read_only_admin','dashboard',1,1,0,0,'2026-08-12 13:15:31'),
('read_only_admin','trunks',1,1,0,0,'2026-08-12 13:15:31'),
('read_only_admin','did_routes',1,1,0,0,'2026-08-12 13:15:31'),
('read_only_admin','outbound_routes',1,1,0,0,'2026-08-12 13:15:31'),
('read_only_admin','time_conditions',1,1,0,0,'2026-08-12 13:15:31'),
('read_only_admin','ivrs',1,1,0,0,'2026-08-12 13:15:31'),
('read_only_admin','extensions',1,1,0,0,'2026-08-12 13:15:31'),
('read_only_admin','queues',1,1,0,0,'2026-08-12 13:15:31'),
('read_only_admin','sounds',1,1,0,0,'2026-08-12 13:15:31'),
('read_only_admin','end_call',1,1,0,0,'2026-08-12 13:15:31'),
('read_only_admin','asterisk_settings',1,1,0,0,'2026-08-12 13:15:31'),
('read_only_admin','fax_settings',1,1,0,0,'2026-08-12 13:15:31'),
('read_only_admin','system_users',0,0,0,0,'2026-08-21 19:46:48'),
('read_only_admin','roles',0,0,0,0,'2026-08-21 19:46:48'),
('read_only_admin','fax_inbox',1,1,0,0,'2026-08-12 13:15:31'),
('read_only_admin','fax_send',1,1,0,0,'2026-08-12 13:15:31'),
('read_only_admin','fax_sent',1,1,0,0,'2026-08-12 13:15:31'),
('read_only_admin','cc_agent',1,1,0,0,'2026-08-12 13:15:31'),
('read_only_admin','pause_reports',1,1,0,0,'2026-08-12 13:15:31'),
('read_only_admin','queue_logs',1,1,0,0,'2026-08-12 13:15:31'),
('cc_agent','dashboard',0,0,0,0,'2026-08-18 22:34:13'),
('cc_agent','cc_agent',1,1,1,0,'2026-08-12 13:15:31'),
('cc_agent','pause_reports',1,1,0,0,'2026-08-12 13:15:31'),
('fax_user','dashboard',0,0,0,0,'2026-08-18 22:34:44'),
('fax_user','fax_inbox',1,1,1,1,'2026-08-12 13:15:31'),
('fax_user','fax_send',1,1,1,0,'2026-08-12 13:15:31'),
('fax_user','fax_sent',1,1,1,1,'2026-08-12 13:15:31'),
('cc_agent','trunks',0,0,0,0,'2026-08-12 13:18:05'),
('cc_agent','did_routes',0,0,0,0,'2026-08-12 13:18:05'),
('cc_agent','outbound_routes',0,0,0,0,'2026-08-12 13:18:05'),
('cc_agent','time_conditions',0,0,0,0,'2026-08-12 13:18:05'),
('cc_agent','ivrs',0,0,0,0,'2026-08-12 13:18:05'),
('cc_agent','extensions',0,0,0,0,'2026-08-12 13:18:05'),
('cc_agent','queues',0,0,0,0,'2026-08-12 13:18:05'),
('cc_agent','sounds',0,0,0,0,'2026-08-12 13:18:05'),
('cc_agent','end_call',0,0,0,0,'2026-08-12 13:18:05'),
('cc_agent','system_users',0,0,0,0,'2026-08-21 19:47:02'),
('cc_agent','roles',0,0,0,0,'2026-08-12 13:18:05'),
('cc_agent','asterisk_settings',0,0,0,0,'2026-08-12 13:18:05'),
('cc_agent','fax_settings',0,0,0,0,'2026-08-12 13:18:05'),
('cc_agent','fax_inbox',0,0,0,0,'2026-08-12 13:18:05'),
('cc_agent','fax_send',0,0,0,0,'2026-08-12 13:18:05'),
('cc_agent','fax_sent',0,0,0,0,'2026-08-12 13:18:05'),
('cc_agent','queue_logs',1,1,0,0,'2026-08-12 13:18:05'),
('cc_manager','dashboard',0,0,0,0,'2026-08-18 22:34:29'),
('cc_manager','cc_agent',1,1,1,0,'2026-08-14 11:34:58'),
('cc_manager','queue_logs',1,1,0,0,'2026-08-14 11:34:58'),
('cc_manager','pause_reports',1,1,0,0,'2026-08-14 11:34:58'),
('admin','fax_mail_settings',1,1,1,1,'2026-08-17 20:11:08'),
('read_only_admin','fax_mail_settings',1,1,0,0,'2026-08-17 20:11:08'),
('cc_agent','fax_mail_settings',0,0,0,0,'2026-08-17 20:11:08'),
('admin','cdr_reports',1,1,1,1,'2026-08-17 20:21:45'),
('read_only_admin','cdr_reports',1,1,0,0,'2026-08-17 20:21:45'),
('cc_agent','cdr_reports',0,0,0,0,'2026-08-17 20:21:45'),
('cc_agent','queue_monitor',1,1,0,0,'2026-08-18 22:34:13'),
('cc_manager','trunks',0,0,0,0,'2026-08-18 22:34:29'),
('cc_manager','did_routes',0,0,0,0,'2026-08-18 22:34:29'),
('cc_manager','outbound_routes',0,0,0,0,'2026-08-18 22:34:29'),
('cc_manager','time_conditions',0,0,0,0,'2026-08-18 22:34:29'),
('cc_manager','ivrs',0,0,0,0,'2026-08-18 22:34:29'),
('cc_manager','extensions',0,0,0,0,'2026-08-18 22:34:29'),
('cc_manager','queues',0,0,0,0,'2026-08-18 22:34:29'),
('cc_manager','sounds',0,0,0,0,'2026-08-18 22:34:29'),
('cc_manager','end_call',0,0,0,0,'2026-08-18 22:34:29'),
('cc_manager','cdr_reports',0,0,0,0,'2026-08-18 22:34:29'),
('cc_manager','system_users',0,0,0,0,'2026-08-18 22:34:29'),
('cc_manager','roles',0,0,0,0,'2026-08-18 22:34:29'),
('cc_manager','asterisk_settings',0,0,0,0,'2026-08-18 22:34:29'),
('cc_manager','fax_mail_settings',0,0,0,0,'2026-08-18 22:34:29'),
('cc_manager','fax_settings',0,0,0,0,'2026-08-18 22:34:29'),
('cc_manager','fax_inbox',0,0,0,0,'2026-08-18 22:34:29'),
('cc_manager','fax_send',0,0,0,0,'2026-08-18 22:34:29'),
('cc_manager','fax_sent',0,0,0,0,'2026-08-18 22:34:29'),
('cc_manager','queue_monitor',1,1,1,0,'2026-08-18 22:34:29'),
('fax_user','trunks',0,0,0,0,'2026-08-18 22:34:44'),
('fax_user','did_routes',0,0,0,0,'2026-08-18 22:34:44'),
('fax_user','outbound_routes',0,0,0,0,'2026-08-18 22:34:44'),
('fax_user','time_conditions',0,0,0,0,'2026-08-18 22:34:44'),
('fax_user','ivrs',0,0,0,0,'2026-08-18 22:34:44'),
('fax_user','extensions',0,0,0,0,'2026-08-18 22:34:44'),
('fax_user','queues',0,0,0,0,'2026-08-18 22:34:44'),
('fax_user','sounds',0,0,0,0,'2026-08-18 22:34:44'),
('fax_user','end_call',0,0,0,0,'2026-08-18 22:34:44'),
('fax_user','cdr_reports',0,0,0,0,'2026-08-18 22:34:44'),
('fax_user','system_users',0,0,0,0,'2026-08-18 22:34:44'),
('fax_user','roles',0,0,0,0,'2026-08-18 22:34:44'),
('fax_user','asterisk_settings',0,0,0,0,'2026-08-18 22:34:44'),
('fax_user','fax_mail_settings',0,0,0,0,'2026-08-18 22:34:44'),
('fax_user','fax_settings',0,0,0,0,'2026-08-18 22:34:45'),
('fax_user','cc_agent',0,0,0,0,'2026-08-18 22:34:45'),
('fax_user','queue_monitor',0,0,0,0,'2026-08-18 22:34:45'),
('fax_user','pause_reports',0,0,0,0,'2026-08-18 22:34:45'),
('fax_user','queue_logs',0,0,0,0,'2026-08-18 22:34:45'),
('read_only_admin','queue_monitor',1,1,0,0,'2026-08-18 22:34:57'),
('admin','feature_codes',1,1,1,1,'2026-08-19 18:00:26'),
('read_only_admin','feature_codes',1,1,0,0,'2026-08-19 18:00:26'),
('cc_agent','feature_codes',0,0,0,0,'2026-08-19 18:00:26'),
('cc_manager','feature_codes',0,0,0,0,'2026-08-19 18:00:26'),
('fax_user','feature_codes',0,0,0,0,'2026-08-19 18:00:26'),
('cc_agent','cc_board',1,1,0,0,'2026-08-21 08:35:49'),
('cc_manager','cc_board',1,1,1,0,'2026-08-21 08:35:49'),
('read_only_admin','cc_board',1,1,0,0,'2026-08-21 08:35:49'),
('fax_user','cc_board',0,0,0,0,'2026-08-21 08:35:49'),
('admin','brand_settings',1,1,1,1,'2026-08-21 10:23:37'),
('admin','queue_monitor',1,1,1,1,'2026-08-21 10:23:37'),
('admin','cc_board',1,1,1,1,'2026-08-21 10:23:37'),
('admin','push_settings',1,1,1,1,'2026-09-05 23:16:30'),
('admin','my_phone',1,1,1,1,'2026-09-15 11:17:54'),
('admin','chat',1,1,1,1,'2026-09-15 11:17:54'),
('read_only_admin','my_phone',1,1,0,0,'2026-09-15 11:17:54'),
('read_only_admin','chat',1,1,0,0,'2026-09-15 11:17:54'),
('admin','ms_teams',1,1,1,1,'2026-09-18 07:30:29'),
('read_only_admin','ms_teams',1,1,0,0,'2026-09-18 07:30:29'),
('admin','pending_sync',1,1,1,1,'2026-09-18 08:10:51'),
('admin','audit_log',1,1,1,1,'2026-09-18 08:10:51'),
('admin','firewall',1,1,1,1,'2026-09-18 07:48:47'),
('admin','fail2ban',1,1,1,1,'2026-09-18 07:48:47'),
('user','my_phone',1,1,1,0,'2026-09-30 00:00:00'),
('user','chat',1,1,1,0,'2026-09-30 00:00:00'),
('admin','queue_reports',1,1,1,1,'2026-10-04 00:00:00'),
('read_only_admin','queue_reports',1,1,0,0,'2026-10-04 00:00:00'),
('cc_manager','queue_reports',1,1,0,0,'2026-10-04 00:00:00'),
('admin','ai_tts',1,1,1,1,'2026-10-04 00:00:00');
/*!40000 ALTER TABLE `sys_role_permissions` ENABLE KEYS */;
UNLOCK TABLES;
COMMIT;
SET AUTOCOMMIT=@OLD_AUTOCOMMIT;

SET @OLD_AUTOCOMMIT=@@AUTOCOMMIT, @@AUTOCOMMIT=0;
LOCK TABLES `phinx_migrations` WRITE;
/*!40000 ALTER TABLE `phinx_migrations` DISABLE KEYS */;
INSERT IGNORE INTO `phinx_migrations` VALUES
(20260822043221,'BaselineSchema','2026-08-22 01:37:52','2026-08-22 01:37:52',0),
(20260822044405,'AddLanguagePreferenceToUsers','2026-08-22 01:44:31','2026-08-22 01:44:31',0),
(20260824095141,'CreateSysPendingSync','2026-08-24 03:52:00','2026-08-24 03:52:00',0),
(20260824100610,'CreateSysAuditLog','2026-08-24 04:06:25','2026-08-24 04:06:26',0),
(20260824102517,'AddLastSeenAtToUsers','2026-08-24 04:25:29','2026-08-24 04:25:29',0),
(20260831130800,'AddSendCallerNameToTrunks','2026-08-31 07:26:05','2026-08-31 07:26:06',0),
(20260901004600,'AddAdvancedTrunkSettings','2026-08-31 18:44:26','2026-08-31 18:44:26',0),
(20260901005300,'AddRemainingTrunkAndFaxSettings','2026-08-31 18:51:28','2026-08-31 18:51:28',0),
(20260901123000,'AddTrunkConnectionMode','2026-09-01 07:54:32','2026-09-01 07:54:32',0),
(20260901230000,'CdrDidColumnAndView','2026-09-01 16:55:38','2026-09-01 16:55:38',0),
(20260901233000,'CdrRingAndTalkDurations','2026-09-01 16:57:42','2026-09-01 16:57:42',0),
(20260902013000,'AddFaxDetectAndT38SettingsToTrunks','2026-09-01 19:17:16','2026-09-01 19:17:16',0),
(20260902030000,'OutboundRouteGroups','2026-09-01 20:40:01','2026-09-01 20:40:02',0),
(20260903113500,'AddSipAuthDigestToUsers','2026-09-03 05:34:10','2026-09-03 05:34:10',0),
(20260904120000,'CreateSysMobileDevices','2026-09-04 05:46:42','2026-09-04 05:46:42',0),
(20260904153000,'AddDeviceTypeToCdrsView','2026-09-04 09:04:45','2026-09-04 09:04:45',0),
(20260904160000,'AddCallForwardingOptionsToUsers','2026-09-04 09:09:12','2026-09-04 09:09:12',0),
(20260905180000,'AddInternalNumberToPbxEntities','2026-09-04 20:54:54','2026-09-04 20:54:58',0),
(20260906170000,'CreateChatTables','2026-09-06 10:31:19','2026-09-06 10:31:19',0),
(20260906210000,'UpdateSysMobileDevicesFcmAndPushType','2026-09-06 14:53:27','2026-09-06 14:53:27',0),
(20260907140000,'ExpandAllowedPhoneMode','2026-09-07 07:25:06','2026-09-07 07:25:06',0),
(20260915031500,'AddMyPhoneAndChatToRolePermissions','2026-09-15 11:17:54','2026-09-15 11:17:54',0),
(20260916090000,'AddChatGroupSupport','2026-09-15 11:19:13','2026-09-15 11:19:13',0),
(20260916130000,'AddDigitTimeoutToPbxIvrs','2026-09-16 12:40:28','2026-09-16 12:40:28',0),
(20260916160000,'AddStaticMembersToPbxQueues','2026-09-16 15:36:51','2026-09-16 15:36:51',0),
(20260916210000,'AddQueuePauseFeatureCodes','2026-09-16 20:26:41','2026-09-16 20:26:41',0),
(20260918080000,'CreateTeamsIntegrationTables','2026-09-18 07:30:29','2026-09-18 07:30:29',0),
(20260918123500,'CreateTwoFactorAndPasskeyTables','2026-09-18 12:25:38','2026-09-18 12:25:38',0),
(20260923090000,'AddDidTrimAndTransitToTrunks','2026-09-23 09:00:00','2026-09-23 09:00:00',0);
/*!40000 ALTER TABLE `phinx_migrations` ENABLE KEYS */;
UNLOCK TABLES;
COMMIT;
SET AUTOCOMMIT=@OLD_AUTOCOMMIT;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*M!100616 SET NOTE_VERBOSITY=@OLD_NOTE_VERBOSITY */;

