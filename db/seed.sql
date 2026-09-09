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
-- Dumping data for table `pbx_feature_codes`
--

SET @OLD_AUTOCOMMIT=@@AUTOCOMMIT, @@AUTOCOMMIT=0;
LOCK TABLES `pbx_feature_codes` WRITE;
/*!40000 ALTER TABLE `pbx_feature_codes` DISABLE KEYS */;
INSERT INTO `pbx_feature_codes` VALUES
(1,'dnd_toggle','Rahatsız Etme (DND) Aç/Kapa','*78',NULL,1,'2026-08-19 20:56:03'),
(2,'cf_set','Çağrı Yönlendirme Ayarla','*72',NULL,1,'2026-08-19 20:56:03'),
(3,'cf_cancel','Çağrı Yönlendirme İptal','*73',NULL,1,'2026-08-19 20:56:03'),
(4,'pickup_group','Gruptan Çağrı Çekme','*20',NULL,1,'2026-08-19 20:56:03'),
(5,'pickup_directed','Belirli Dahiliden Çağrı Çekme','*21',NULL,1,'2026-08-19 20:56:03'),
(6,'spy','Çağrı Dinleme (Spy)','*90','admin,cc_manager',1,'2026-08-19 20:56:03'),
(7,'queue_login','Kuyruğa Giriş (Kuyruk ID ile, *81<id>)','_*81.','admin,cc_manager,cc_agent',1,'2026-08-21 14:37:22'),
(8,'queue_logout','Kuyruktan Çıkış (Kuyruk ID ile, *80<id>)','_*80.','admin,cc_manager,cc_agent',1,'2026-08-21 14:37:22');
/*!40000 ALTER TABLE `pbx_feature_codes` ENABLE KEYS */;
UNLOCK TABLES;
COMMIT;
SET AUTOCOMMIT=@OLD_AUTOCOMMIT;

--
-- Dumping data for table `pbx_hangup_actions`
--

SET @OLD_AUTOCOMMIT=@@AUTOCOMMIT, @@AUTOCOMMIT=0;
LOCK TABLES `pbx_hangup_actions` WRITE;
/*!40000 ALTER TABLE `pbx_hangup_actions` DISABLE KEYS */;
INSERT INTO `pbx_hangup_actions` VALUES
(1,'hangup','mdisi','hangup',7,1,'2026-08-10 14:53:18',NULL),
(2,'busy','Meşgul Tonu Ver','busy',NULL,1,'2026-08-10 14:53:18','1050'),
(3,'congestion','Şebeke Meşgul','congestion',NULL,1,'2026-08-10 14:53:18',NULL);
/*!40000 ALTER TABLE `pbx_hangup_actions` ENABLE KEYS */;
UNLOCK TABLES;
COMMIT;
SET AUTOCOMMIT=@OLD_AUTOCOMMIT;

--
-- Dumping data for table `sys_settings`
--

SET @OLD_AUTOCOMMIT=@@AUTOCOMMIT, @@AUTOCOMMIT=0;
LOCK TABLES `sys_settings` WRITE;
/*!40000 ALTER TABLE `sys_settings` DISABLE KEYS */;
INSERT INTO `sys_settings` VALUES
('brand_color_primary',''),
('brand_color_secondary',''),
('brand_sub','İletişim Sistemi'),
('brand_title','KBÜ'),
('cc_auto_queue_login','1'),
('cc_break_reasons','Yemek Molası,Kısa Dinlenme,Eğitim / Toplantı,Evrak / İdari İşler,Teknik Problem'),
('fax_email_from_address','fax@karabuk.edu.tr'),
('fax_email_from_name','KBÜ Faks Sistemi'),
('fax_email_rx_attach_pdf','yes'),
('fax_email_rx_enabled','yes'),
('fax_email_tx_enabled','yes'),
('fax_header_info','Karabük Üniversitesi'),
('fax_local_station_id','0370418'),
('fax_max_retries','3'),
('fax_retention_days','0'),
('fax_retry_time','60'),
('fax_wait_time','30'),
('pjsip_codecs','opus,alaw,ulaw,g722'),
('pjsip_direct_media','no'),
('pjsip_external_dial_timeout','60'),
('pjsip_external_ip','193.140.8.49'),
('pjsip_internal_dial_timeout','30'),
('pjsip_local_net','10.23.3.0/24'),
('pjsip_qualify_frequency','60'),
('pjsip_qualify_frequency_mobile','0'),
('pjsip_rtp_symmetric','yes'),
('pjsip_udp_port','5060'),
('pjsip_user_agent','AI-PBX'),
('pjsip_wired_codecs','alaw,ulaw,g722'),
('pjsip_wss_port','8089'),
('pjsip_ws_path','/ws'),
('portal_email_from_address','no-reply@karabuk.edu.tr'),
('portal_email_from_name','KBÜ İletişim Sistemi Portalı'),
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
('site_favicon_url','/favicon.ico'),
('site_logo_icon','fa-network-wired'),
('site_logo_image','/assets/images/karabuk_logo.png'),
('site_logo_type','image'),
('site_title','KBÜ İletişim Sistemi'),
('system_default_language','tr'),
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

--
-- Dumping data for table `sys_roles`
--

SET @OLD_AUTOCOMMIT=@@AUTOCOMMIT, @@AUTOCOMMIT=0;
LOCK TABLES `sys_roles` WRITE;
/*!40000 ALTER TABLE `sys_roles` DISABLE KEYS */;
INSERT INTO `sys_roles` VALUES
(1,'admin','Yönetici','Tam yetkili sistem yöneticisi',1,'2026-08-12 13:15:29'),
(2,'read_only_admin','İzleyici','Tüm panelleri görüntüleyebilir fakat düzenleme/silme yapamaz',1,'2026-08-12 13:15:29'),
(3,'cc_agent','Temsilci','Temsilci ekranı, mola yönetimi ve arama kayıtları erişimi',1,'2026-08-12 13:15:29'),
(4,'fax_user','Faks','Gelen/giden faks yönetimi ve faks gönderimi',1,'2026-08-12 13:15:29'),
(5,'cc_manager','Kuyruk Yönetici','Çağrı merkezi canlı takip, kuyruk yöneticisi, temsilciler, molalar ve raporlar yetkilisi',1,'2026-08-14 00:35:09');
/*!40000 ALTER TABLE `sys_roles` ENABLE KEYS */;
UNLOCK TABLES;
COMMIT;
SET AUTOCOMMIT=@OLD_AUTOCOMMIT;

--
-- Dumping data for table `sys_role_permissions`
--

SET @OLD_AUTOCOMMIT=@@AUTOCOMMIT, @@AUTOCOMMIT=0;
LOCK TABLES `sys_role_permissions` WRITE;
/*!40000 ALTER TABLE `sys_role_permissions` DISABLE KEYS */;
INSERT INTO `sys_role_permissions` VALUES
(1,'admin','dashboard',1,1,1,1,'2026-08-12 13:15:31'),
(2,'admin','trunks',1,1,1,1,'2026-08-12 13:15:31'),
(3,'admin','did_routes',1,1,1,1,'2026-08-12 13:15:31'),
(4,'admin','outbound_routes',1,1,1,1,'2026-08-12 13:15:31'),
(5,'admin','time_conditions',1,1,1,1,'2026-08-12 13:15:31'),
(6,'admin','ivrs',1,1,1,1,'2026-08-12 13:15:31'),
(7,'admin','extensions',1,1,1,1,'2026-08-12 13:15:31'),
(8,'admin','queues',1,1,1,1,'2026-08-12 13:15:31'),
(9,'admin','sounds',1,1,1,1,'2026-08-12 13:15:31'),
(10,'admin','end_call',1,1,1,1,'2026-08-12 13:15:31'),
(11,'admin','asterisk_settings',1,1,1,1,'2026-08-12 13:15:31'),
(12,'admin','fax_settings',1,1,1,1,'2026-08-12 13:15:31'),
(13,'admin','system_users',1,1,1,1,'2026-08-12 13:15:31'),
(14,'admin','roles',1,1,1,1,'2026-08-12 13:15:31'),
(15,'admin','fax_inbox',1,1,1,1,'2026-08-12 13:15:31'),
(16,'admin','fax_send',1,1,1,1,'2026-08-12 13:15:31'),
(17,'admin','fax_sent',1,1,1,1,'2026-08-12 13:15:31'),
(18,'admin','cc_agent',1,1,1,1,'2026-08-21 10:54:38'),
(20,'admin','pause_reports',1,1,1,1,'2026-08-12 13:15:31'),
(21,'admin','queue_logs',1,1,1,1,'2026-08-12 13:15:31'),
(22,'read_only_admin','dashboard',1,1,0,0,'2026-08-12 13:15:31'),
(23,'read_only_admin','trunks',1,1,0,0,'2026-08-12 13:15:31'),
(24,'read_only_admin','did_routes',1,1,0,0,'2026-08-12 13:15:31'),
(25,'read_only_admin','outbound_routes',1,1,0,0,'2026-08-12 13:15:31'),
(26,'read_only_admin','time_conditions',1,1,0,0,'2026-08-12 13:15:31'),
(27,'read_only_admin','ivrs',1,1,0,0,'2026-08-12 13:15:31'),
(28,'read_only_admin','extensions',1,1,0,0,'2026-08-12 13:15:31'),
(29,'read_only_admin','queues',1,1,0,0,'2026-08-12 13:15:31'),
(30,'read_only_admin','sounds',1,1,0,0,'2026-08-12 13:15:31'),
(31,'read_only_admin','end_call',1,1,0,0,'2026-08-12 13:15:31'),
(32,'read_only_admin','asterisk_settings',1,1,0,0,'2026-08-12 13:15:31'),
(33,'read_only_admin','fax_settings',1,1,0,0,'2026-08-12 13:15:31'),
(34,'read_only_admin','system_users',0,0,0,0,'2026-08-21 19:46:48'),
(35,'read_only_admin','roles',0,0,0,0,'2026-08-21 19:46:48'),
(36,'read_only_admin','fax_inbox',1,1,0,0,'2026-08-12 13:15:31'),
(37,'read_only_admin','fax_send',1,1,0,0,'2026-08-12 13:15:31'),
(38,'read_only_admin','fax_sent',1,1,0,0,'2026-08-12 13:15:31'),
(39,'read_only_admin','cc_agent',1,1,0,0,'2026-08-12 13:15:31'),
(41,'read_only_admin','pause_reports',1,1,0,0,'2026-08-12 13:15:31'),
(42,'read_only_admin','queue_logs',1,1,0,0,'2026-08-12 13:15:31'),
(43,'cc_agent','dashboard',0,0,0,0,'2026-08-18 22:34:13'),
(44,'cc_agent','cc_agent',1,1,1,0,'2026-08-12 13:15:31'),
(46,'cc_agent','pause_reports',1,1,0,0,'2026-08-12 13:15:31'),
(47,'fax_user','dashboard',0,0,0,0,'2026-08-18 22:34:44'),
(48,'fax_user','fax_inbox',1,1,1,1,'2026-08-12 13:15:31'),
(49,'fax_user','fax_send',1,1,1,0,'2026-08-12 13:15:31'),
(50,'fax_user','fax_sent',1,1,1,1,'2026-08-12 13:15:31'),
(94,'cc_agent','trunks',0,0,0,0,'2026-08-12 13:18:05'),
(95,'cc_agent','did_routes',0,0,0,0,'2026-08-12 13:18:05'),
(96,'cc_agent','outbound_routes',0,0,0,0,'2026-08-12 13:18:05'),
(97,'cc_agent','time_conditions',0,0,0,0,'2026-08-12 13:18:05'),
(98,'cc_agent','ivrs',0,0,0,0,'2026-08-12 13:18:05'),
(99,'cc_agent','extensions',0,0,0,0,'2026-08-12 13:18:05'),
(100,'cc_agent','queues',0,0,0,0,'2026-08-12 13:18:05'),
(101,'cc_agent','sounds',0,0,0,0,'2026-08-12 13:18:05'),
(102,'cc_agent','end_call',0,0,0,0,'2026-08-12 13:18:05'),
(103,'cc_agent','system_users',0,0,0,0,'2026-08-21 19:47:02'),
(104,'cc_agent','roles',0,0,0,0,'2026-08-12 13:18:05'),
(105,'cc_agent','asterisk_settings',0,0,0,0,'2026-08-12 13:18:05'),
(106,'cc_agent','fax_settings',0,0,0,0,'2026-08-12 13:18:05'),
(107,'cc_agent','fax_inbox',0,0,0,0,'2026-08-12 13:18:05'),
(108,'cc_agent','fax_send',0,0,0,0,'2026-08-12 13:18:05'),
(109,'cc_agent','fax_sent',0,0,0,0,'2026-08-12 13:18:05'),
(113,'cc_agent','queue_logs',1,1,0,0,'2026-08-12 13:18:05'),
(114,'cc_manager','dashboard',0,0,0,0,'2026-08-18 22:34:29'),
(115,'cc_manager','cc_agent',1,1,1,0,'2026-08-14 11:34:58'),
(117,'cc_manager','queue_logs',1,1,0,0,'2026-08-14 11:34:58'),
(118,'cc_manager','pause_reports',1,1,0,0,'2026-08-14 11:34:58'),
(119,'admin','fax_mail_settings',1,1,1,1,'2026-08-17 20:11:08'),
(120,'read_only_admin','fax_mail_settings',1,1,0,0,'2026-08-17 20:11:08'),
(121,'cc_agent','fax_mail_settings',0,0,0,0,'2026-08-17 20:11:08'),
(122,'admin','cdr_reports',1,1,1,1,'2026-08-17 20:21:45'),
(123,'read_only_admin','cdr_reports',1,1,0,0,'2026-08-17 20:21:45'),
(124,'cc_agent','cdr_reports',0,0,0,0,'2026-08-17 20:21:45'),
(145,'cc_agent','queue_monitor',1,1,0,0,'2026-08-18 22:34:13'),
(149,'cc_manager','trunks',0,0,0,0,'2026-08-18 22:34:29'),
(150,'cc_manager','did_routes',0,0,0,0,'2026-08-18 22:34:29'),
(151,'cc_manager','outbound_routes',0,0,0,0,'2026-08-18 22:34:29'),
(152,'cc_manager','time_conditions',0,0,0,0,'2026-08-18 22:34:29'),
(153,'cc_manager','ivrs',0,0,0,0,'2026-08-18 22:34:29'),
(154,'cc_manager','extensions',0,0,0,0,'2026-08-18 22:34:29'),
(155,'cc_manager','queues',0,0,0,0,'2026-08-18 22:34:29'),
(156,'cc_manager','sounds',0,0,0,0,'2026-08-18 22:34:29'),
(157,'cc_manager','end_call',0,0,0,0,'2026-08-18 22:34:29'),
(158,'cc_manager','cdr_reports',0,0,0,0,'2026-08-18 22:34:29'),
(159,'cc_manager','system_users',0,0,0,0,'2026-08-18 22:34:29'),
(160,'cc_manager','roles',0,0,0,0,'2026-08-18 22:34:29'),
(161,'cc_manager','asterisk_settings',0,0,0,0,'2026-08-18 22:34:29'),
(162,'cc_manager','fax_mail_settings',0,0,0,0,'2026-08-18 22:34:29'),
(163,'cc_manager','fax_settings',0,0,0,0,'2026-08-18 22:34:29'),
(164,'cc_manager','fax_inbox',0,0,0,0,'2026-08-18 22:34:29'),
(165,'cc_manager','fax_send',0,0,0,0,'2026-08-18 22:34:29'),
(166,'cc_manager','fax_sent',0,0,0,0,'2026-08-18 22:34:29'),
(168,'cc_manager','queue_monitor',1,1,1,0,'2026-08-18 22:34:29'),
(172,'fax_user','trunks',0,0,0,0,'2026-08-18 22:34:44'),
(173,'fax_user','did_routes',0,0,0,0,'2026-08-18 22:34:44'),
(174,'fax_user','outbound_routes',0,0,0,0,'2026-08-18 22:34:44'),
(175,'fax_user','time_conditions',0,0,0,0,'2026-08-18 22:34:44'),
(176,'fax_user','ivrs',0,0,0,0,'2026-08-18 22:34:44'),
(177,'fax_user','extensions',0,0,0,0,'2026-08-18 22:34:44'),
(178,'fax_user','queues',0,0,0,0,'2026-08-18 22:34:44'),
(179,'fax_user','sounds',0,0,0,0,'2026-08-18 22:34:44'),
(180,'fax_user','end_call',0,0,0,0,'2026-08-18 22:34:44'),
(181,'fax_user','cdr_reports',0,0,0,0,'2026-08-18 22:34:44'),
(182,'fax_user','system_users',0,0,0,0,'2026-08-18 22:34:44'),
(183,'fax_user','roles',0,0,0,0,'2026-08-18 22:34:44'),
(184,'fax_user','asterisk_settings',0,0,0,0,'2026-08-18 22:34:44'),
(185,'fax_user','fax_mail_settings',0,0,0,0,'2026-08-18 22:34:44'),
(186,'fax_user','fax_settings',0,0,0,0,'2026-08-18 22:34:45'),
(190,'fax_user','cc_agent',0,0,0,0,'2026-08-18 22:34:45'),
(191,'fax_user','queue_monitor',0,0,0,0,'2026-08-18 22:34:45'),
(192,'fax_user','pause_reports',0,0,0,0,'2026-08-18 22:34:45'),
(193,'fax_user','queue_logs',0,0,0,0,'2026-08-18 22:34:45'),
(214,'read_only_admin','queue_monitor',1,1,0,0,'2026-08-18 22:34:57'),
(263,'admin','feature_codes',1,1,1,1,'2026-08-19 18:00:26'),
(264,'read_only_admin','feature_codes',1,1,0,0,'2026-08-19 18:00:26'),
(265,'cc_agent','feature_codes',0,0,0,0,'2026-08-19 18:00:26'),
(266,'cc_manager','feature_codes',0,0,0,0,'2026-08-19 18:00:26'),
(267,'fax_user','feature_codes',0,0,0,0,'2026-08-19 18:00:26'),
(268,'cc_agent','cc_board',1,1,0,0,'2026-08-21 08:35:49'),
(269,'cc_manager','cc_board',1,1,1,0,'2026-08-21 08:35:49'),
(270,'read_only_admin','cc_board',1,1,0,0,'2026-08-21 08:35:49'),
(271,'fax_user','cc_board',0,0,0,0,'2026-08-21 08:35:49'),
(287,'admin','brand_settings',1,1,1,1,'2026-08-21 10:23:37'),
(294,'admin','queue_monitor',1,1,1,1,'2026-08-21 10:23:37'),
(295,'admin','cc_board',1,1,1,1,'2026-08-21 10:23:37'),
(351,'admin','push_settings',1,1,1,1,'2026-09-05 23:16:30');
/*!40000 ALTER TABLE `sys_role_permissions` ENABLE KEYS */;
UNLOCK TABLES;
COMMIT;
SET AUTOCOMMIT=@OLD_AUTOCOMMIT;

--
-- Dumping data for table `phinx_migrations`
--

SET @OLD_AUTOCOMMIT=@@AUTOCOMMIT, @@AUTOCOMMIT=0;
LOCK TABLES `phinx_migrations` WRITE;
/*!40000 ALTER TABLE `phinx_migrations` DISABLE KEYS */;
INSERT INTO `phinx_migrations` VALUES
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
(20260907140000,'ExpandAllowedPhoneMode','2026-09-07 07:25:06','2026-09-07 07:25:06',0);
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

-- Dump completed on 2026-09-09  6:29:28
