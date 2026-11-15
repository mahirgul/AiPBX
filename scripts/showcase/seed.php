<?php
/**
 * Showcase seed: a made-up company ("Northwind Logistics") with 4-5 records on every page, for the
 * static demo on aipbx.bid/demo. Names are invented; numbers are fictional ranges (+1 555 01xx,
 * +44 7700 900xxx); addresses are documentation ranges (203.0.113.x, 198.51.100.x, 192.0.2.x).
 * Runs as root inside the showcase container: php seed.php
 */
chdir('/opt/aipbx/web');
require 'vendor/autoload.php';
require 'config.php';
require_once 'auth.php';
foreach (glob('src/services/*.php') as $f) {
    require_once $f;
}
require_once 'src/core/PluginManager.php';
@session_start();
$_SESSION['csrf_token'] = 't';
$_SESSION['user_id'] = 1;
$_SESSION['user_role'] = 'admin';
$db = getDB();
$now = time();
$ago = static fn(int $sec): string => date('Y-m-d H:i:s', $now - $sec);
$ins = static function (string $table, array $rows) use ($db): void {
    foreach ($rows as $r) {
        $cols = array_keys($r);
        $db->prepare("INSERT INTO {$table} (" . implode(',', $cols) . ') VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')')->execute(array_values($r));
    }
};
$check = static function ($res, string $what): void {
    if (empty($res['success'])) {
        fwrite(STDERR, "FAIL {$what}: " . json_encode($res) . "\n");
    }
};

// --- people: the admin and 8 extensions, one fax unit ---
$db->exec("UPDATE sys_users SET full_name = 'Demo Admin', email = 'admin@northwind.example' WHERE id = 1");
$people = [
    ['1001', 'Anna Weber', 'user'], ['1002', 'Mehmet Kaya', 'cc_agent'], ['1003', 'John Carter', 'cc_agent'],
    ['1004', 'Elif Demir', 'cc_manager'], ['1005', 'Lukas Braun', 'user'], ['1006', 'Sofia Rossi', 'cc_agent'],
    ['1007', 'David Chen', 'user'], ['1008', 'Nora Lindqvist', 'read_only_admin'],
];
foreach ($people as [$ext, $name, $role]) {
    if (!$db->query("SELECT 1 FROM sys_users WHERE extension = '{$ext}'")->fetchColumn()) {
        $check(ExtensionService::saveExtension(['csrf_token' => 't', 'user_id' => 0, 'extension' => $ext, 'full_name' => $name,
            'sip_password' => 'Demo-' . bin2hex(random_bytes(6)), 'is_active' => 1]), "extension {$ext}");
    }
    $mail = strtolower(str_replace(' ', '.', $name)) . '@northwind.example';
    $db->prepare('UPDATE sys_users SET role = ?, email = ?, voicemail_enabled = 1, last_seen_at = ? WHERE extension = ?')
        ->execute([$role, $mail, $ago(rand(60, 7200)), $ext]);
}
$uid = $db->query('SELECT extension, id FROM sys_users')->fetchAll(PDO::FETCH_KEY_PAIR);
$db->exec("UPDATE sys_users SET dnd_enabled = 1 WHERE extension = '1005'");
$db->exec("UPDATE sys_users SET call_forward_number = '+15550142' WHERE extension = '1007'");
if (!$db->query("SELECT 1 FROM sys_users WHERE extension = '2001'")->fetchColumn()) {
    $check(ExtensionService::saveExtension(['csrf_token' => 't', 'user_id' => 0, 'extension' => '2001', 'full_name' => 'Accounting Fax',
        'extension_type' => 'fax', 'is_active' => 1]), 'fax 2001');
}

// --- trunks ---
$ins('pbx_trunks', [
    ['trunk_name' => 'carrier_main', 'title' => 'Main carrier (SIP)', 'ip_address' => 'sip.carrier-one.example', 'port' => 5060, 'transport' => 'udp', 'connection_mode' => 'register', 'codecs' => 'alaw,ulaw,g722', 'auth_username' => 'northwind01', 'registration_enabled' => 1, 'max_channels' => 30, 'is_active' => 1, 'sort_order' => 1],
    ['trunk_name' => 'carrier_backup', 'title' => 'Backup carrier', 'ip_address' => '203.0.113.20', 'port' => 5060, 'transport' => 'udp', 'connection_mode' => 'ip', 'codecs' => 'alaw,ulaw', 'max_channels' => 10, 'is_active' => 1, 'sort_order' => 2],
    ['trunk_name' => 'sbc_tls', 'title' => 'SBC over TLS', 'ip_address' => 'sbc.carrier-two.example', 'port' => 5061, 'transport' => 'tls', 'connection_mode' => 'register', 'codecs' => 'opus,g722,alaw', 'auth_username' => 'nw-tls', 'registration_enabled' => 1, 'is_active' => 1, 'sort_order' => 3],
    ['trunk_name' => 'old_pbx', 'title' => 'Old PBX (tie line)', 'ip_address' => '192.0.2.10', 'port' => 5060, 'transport' => 'tcp', 'connection_mode' => 'ip', 'codecs' => 'alaw', 'is_active' => 0, 'sort_order' => 4],
]);

// --- IVR, queues, ring groups, conferences, time, announcements ---
$ins('pbx_announcements', [
    ['title' => 'Welcome to Northwind', 'audio_file' => 'custom/welcome', 'post_dest_type' => 'ivr', 'post_dest_id' => '1', 'is_active' => 1],
    ['title' => 'Holiday closure', 'audio_file' => 'custom/holiday', 'post_dest_type' => 'hangup', 'post_dest_id' => '', 'is_active' => 1],
    ['title' => 'All agents busy', 'audio_file' => 'custom/busy', 'post_dest_type' => 'voicemail', 'post_dest_id' => '1001', 'is_active' => 1],
    ['title' => 'After hours', 'audio_file' => 'custom/closed', 'post_dest_type' => 'voicemail', 'post_dest_id' => '1004', 'is_active' => 1],
]);
$ins('pbx_ivrs', [
    ['title' => 'Main menu', 'prompt_file' => 'custom/main-menu', 'language' => 'en', 'timeout_seconds' => 8, 'max_failures' => 3, 'allow_direct_dial' => 1, 'timeout_dest_type' => 'queue', 'timeout_dest_id' => '1', 'invalid_dest_type' => 'queue', 'invalid_dest_id' => '1', 'is_active' => 1, 'internal_number' => '7001'],
    ['title' => 'Support menu', 'prompt_file' => 'custom/support-menu', 'language' => 'en', 'timeout_seconds' => 6, 'max_failures' => 2, 'timeout_dest_type' => 'queue', 'timeout_dest_id' => '2', 'invalid_dest_type' => 'hangup', 'invalid_dest_id' => '', 'is_active' => 1, 'internal_number' => '7002'],
    ['title' => 'Menü (Türkçe)', 'prompt_file' => 'custom/menu-tr', 'language' => 'tr', 'timeout_seconds' => 8, 'max_failures' => 3, 'timeout_dest_type' => 'queue', 'timeout_dest_id' => '1', 'invalid_dest_type' => 'queue', 'invalid_dest_id' => '1', 'is_active' => 1, 'internal_number' => '7003'],
    ['title' => 'Menü (Deutsch)', 'prompt_file' => 'custom/menu-de', 'language' => 'de', 'timeout_seconds' => 8, 'max_failures' => 3, 'timeout_dest_type' => 'queue', 'timeout_dest_id' => '1', 'invalid_dest_type' => 'queue', 'invalid_dest_id' => '1', 'is_active' => 1, 'internal_number' => '7004'],
]);
$ins('pbx_ivr_entries', [
    ['ivr_id' => 1, 'digit' => '1', 'dest_type' => 'queue', 'dest_id' => '1'], ['ivr_id' => 1, 'digit' => '2', 'dest_type' => 'ivr', 'dest_id' => '2'],
    ['ivr_id' => 1, 'digit' => '3', 'dest_type' => 'ring_group', 'dest_id' => '1'], ['ivr_id' => 1, 'digit' => '9', 'dest_type' => 'ivr', 'dest_id' => '3'],
    ['ivr_id' => 1, 'digit' => '0', 'dest_type' => 'extension', 'dest_id' => '1001'],
    ['ivr_id' => 2, 'digit' => '1', 'dest_type' => 'queue', 'dest_id' => '2'], ['ivr_id' => 2, 'digit' => '2', 'dest_type' => 'voicemail', 'dest_id' => '1004'],
]);
$members = static fn(array $exts): string => json_encode($exts);
$trunks = static fn(string ...$names): string => json_encode(array_map(static fn($n) => ['trunk_name' => $n, 'callerid_override' => ''], $names));
$ins('pbx_queues', [
    ['queue_name' => 'sales', 'title' => 'Sales', 'strategy' => 'rrmemory', 'timeout' => 20, 'wrapuptime' => 10, 'max_wait_seconds' => 300, 'announce_position' => 'yes', 'members_json' => $members(['1002', '1003', '1006']), 'supervisor_extension' => '1004', 'is_active' => 1, 'internal_number' => '6001'],
    ['queue_name' => 'support', 'title' => 'Technical support', 'strategy' => 'leastrecent', 'timeout' => 25, 'wrapuptime' => 15, 'max_wait_seconds' => 600, 'announce_position' => 'yes', 'members_json' => $members(['1002', '1006']), 'supervisor_extension' => '1004', 'is_active' => 1, 'internal_number' => '6002'],
    ['queue_name' => 'billing', 'title' => 'Billing', 'strategy' => 'ringall', 'timeout' => 20, 'max_wait_seconds' => 240, 'members_json' => $members(['1001', '1005']), 'is_active' => 1, 'internal_number' => '6003'],
    ['queue_name' => 'vip', 'title' => 'Key accounts', 'strategy' => 'fewestcalls', 'timeout' => 30, 'max_wait_seconds' => 120, 'members_json' => $members(['1003']), 'supervisor_extension' => '1004', 'is_active' => 1, 'internal_number' => '6004'],
]);
$ins('pbx_ring_groups', [
    ['group_number' => '600', 'internal_number' => '600', 'name' => 'Reception', 'numbers_list' => '1001,1007', 'ring_strategy' => 'ringall', 'ring_timeout' => 25, 'fallback_dest_type' => 'voicemail', 'fallback_dest_id' => '1001', 'is_active' => 1],
    ['group_number' => '601', 'internal_number' => '601', 'name' => 'Warehouse', 'numbers_list' => '1005,1007', 'ring_strategy' => 'sequential', 'ring_timeout' => 30, 'fallback_dest_type' => 'hangup', 'fallback_dest_id' => '', 'is_active' => 1],
    ['group_number' => '602', 'internal_number' => '602', 'name' => 'Management', 'numbers_list' => '1004,1008', 'ring_strategy' => 'ringall', 'ring_timeout' => 20, 'fallback_dest_type' => 'voicemail', 'fallback_dest_id' => '1004', 'is_active' => 1],
    ['group_number' => '603', 'internal_number' => '603', 'name' => 'Night shift', 'numbers_list' => '1003,1006', 'ring_strategy' => 'random', 'ring_timeout' => 25, 'fallback_dest_type' => 'queue', 'fallback_dest_id' => '2', 'is_active' => 0],
]);
$ins('pbx_conferences', [
    ['room_number' => '800', 'internal_number' => '800', 'title' => 'Daily stand-up', 'user_pin' => '1234', 'admin_pin' => '9876', 'max_members' => 20, 'is_active' => 1],
    ['room_number' => '801', 'internal_number' => '801', 'title' => 'Sales weekly', 'user_pin' => '2468', 'max_members' => 15, 'record_conference' => 1, 'is_active' => 1],
    ['room_number' => '802', 'internal_number' => '802', 'title' => 'Customer review', 'user_pin' => '1357', 'admin_pin' => '7531', 'max_members' => 10, 'is_active' => 1],
    ['room_number' => '803', 'internal_number' => '803', 'title' => 'Board room', 'max_members' => 8, 'is_active' => 1],
]);
$ins('pbx_time_groups', [
    ['title' => 'Office hours', 'time_start' => '08:30', 'time_end' => '18:00', 'days_of_week' => '1,2,3,4,5', 'is_active' => 1],
    ['title' => 'Saturday morning', 'time_start' => '09:00', 'time_end' => '13:00', 'days_of_week' => '6', 'is_active' => 1],
    ['title' => 'Support hours', 'time_start' => '07:00', 'time_end' => '22:00', 'days_of_week' => '1,2,3,4,5,6', 'is_active' => 1],
    ['title' => 'Public holidays', 'holidays_json' => json_encode(['2026-12-25', '2026-12-26', '2027-01-01']), 'is_active' => 1],
]);
$ins('pbx_time_conditions', [
    ['title' => 'Main line: open?', 'time_group_id' => 1, 'match_dest_type' => 'ivr', 'match_dest_id' => '1', 'nomatch_dest_type' => 'announcement', 'nomatch_dest_id' => '4', 'is_active' => 1, 'internal_number' => '7101'],
    ['title' => 'Support line: open?', 'time_group_id' => 3, 'match_dest_type' => 'queue', 'match_dest_id' => '2', 'nomatch_dest_type' => 'voicemail', 'nomatch_dest_id' => '1004', 'is_active' => 1, 'internal_number' => '7102'],
    ['title' => 'Saturday desk', 'time_group_id' => 2, 'match_dest_type' => 'ring_group', 'match_dest_id' => '1', 'nomatch_dest_type' => 'announcement', 'nomatch_dest_id' => '4', 'is_active' => 1, 'internal_number' => '7103'],
    ['title' => 'Holiday check', 'time_group_id' => 4, 'match_dest_type' => 'announcement', 'match_dest_id' => '2', 'nomatch_dest_type' => 'time_condition', 'nomatch_dest_id' => '1', 'is_active' => 1, 'internal_number' => '7104'],
]);

// --- routes, normalization, blacklist, permissions ---
$ins('pbx_dids', [
    ['did_number' => '+15550100', 'title' => 'Main number', 'dest_type' => 'time_condition', 'dest_id' => '1', 'language' => 'en', 'is_active' => 1],
    ['did_number' => '+15550101', 'title' => 'Support line', 'dest_type' => 'time_condition', 'dest_id' => '2', 'language' => 'en', 'record_call' => 1, 'is_active' => 1],
    ['did_number' => '+15550102', 'title' => 'Sales direct', 'dest_type' => 'queue', 'dest_id' => '1', 'language' => 'en', 'is_active' => 1],
    ['did_number' => '+15550109', 'title' => 'Accounting fax', 'dest_type' => 'fax', 'dest_id' => '2001', 'is_active' => 1],
    ['did_number' => '+447700900100', 'title' => 'UK office', 'dest_type' => 'ivr', 'dest_id' => '1', 'language' => 'en', 'fax_detect' => 1, 'fax_detect_seconds' => 10, 'fax_target' => '2001', 'is_active' => 1],
]);
$ins('pbx_outbound_routes', [
    ['route_name' => 'Emergency', 'match_pattern' => '911', 'trunks_json' => $trunks('carrier_main', 'carrier_backup'), 'is_emergency' => 1, 'is_active' => 1, 'sort_order' => 1],
    ['route_name' => 'National', 'match_pattern' => '0[1-9]XXXXXXXX', 'trunks_json' => $trunks('carrier_main', 'carrier_backup'), 'is_active' => 1, 'sort_order' => 2],
    ['route_name' => 'Mobile', 'match_pattern' => '05XXXXXXXXX', 'trunks_json' => $trunks('carrier_main'), 'is_active' => 1, 'sort_order' => 3],
    ['route_name' => 'International', 'match_pattern' => '00.', 'trunks_json' => $trunks('sbc_tls'), 'is_active' => 1, 'sort_order' => 4],
    ['route_name' => 'Old PBX', 'match_pattern' => '3XXX', 'trunks_json' => $trunks('old_pbx'), 'is_internal' => 1, 'is_active' => 1, 'sort_order' => 5],
]);
$ins('pbx_inbound_norm_rules', [
    ['trunk_name' => '', 'target' => 'caller', 'rule_name' => 'National to E.164', 'match_pattern' => '0', 'strip_front' => 1, 'prepend' => '+1', 'is_active' => 1],
    ['trunk_name' => 'carrier_backup', 'target' => 'caller', 'rule_name' => 'International prefix', 'match_pattern' => '00', 'strip_front' => 2, 'prepend' => '+', 'is_active' => 1],
    ['trunk_name' => 'carrier_main', 'target' => 'did', 'rule_name' => 'Short DID', 'match_pattern' => '555', 'strip_front' => 0, 'prepend' => '+1', 'is_active' => 1],
    ['trunk_name' => '', 'target' => 'caller', 'rule_name' => 'Plus sign', 'match_pattern' => '1', 'strip_front' => 0, 'prepend' => '+', 'is_active' => 0],
]);
$ins('pbx_dialplan_rules', [
    ['client_type' => 'all', 'rule_name' => 'Local to national', 'match_pattern' => 'NXXXXXX', 'prepend' => '0212', 'sort_order' => 1, 'is_active' => 1],
    ['client_type' => 'all', 'rule_name' => 'E.164 to national', 'match_pattern' => '+1', 'strip_front' => 2, 'prepend' => '0', 'sort_order' => 2, 'is_active' => 1],
    ['client_type' => 'mobile', 'rule_name' => 'Mobile app plus', 'match_pattern' => '+', 'strip_front' => 1, 'prepend' => '00', 'sort_order' => 3, 'is_active' => 1],
    ['client_type' => 'all', 'rule_name' => 'Trailing hash', 'match_pattern' => 'X.#', 'strip_back' => 1, 'sort_order' => 4, 'is_active' => 1],
]);
$ins('pbx_inbound_blacklist', [
    ['number' => '+15550199', 'title' => 'Robocall', 'block_action' => 'drop', 'is_active' => 1, 'hit_count' => 14, 'last_blocked_at' => $ago(3600 * 5)],
    ['number' => '+15550198', 'title' => 'Survey calls', 'block_action' => 'busy', 'is_active' => 1, 'hit_count' => 6, 'last_blocked_at' => $ago(86400)],
    ['number' => '+447700900999', 'title' => 'Spam', 'block_action' => 'drop', 'is_active' => 1, 'hit_count' => 3, 'last_blocked_at' => $ago(86400 * 3)],
    ['number' => 'anonymous', 'title' => 'Hidden numbers', 'block_action' => 'announcement', 'is_active' => 0, 'hit_count' => 0],
]);
$ins('pbx_permission_groups', [
    ['group_name' => 'No international', 'description' => 'Local and mobile only', 'default_action' => 'allow', 'is_active' => 1],
    ['group_name' => 'Internal only', 'description' => 'Extensions only, for lobby phones', 'default_action' => 'deny', 'is_active' => 1],
    ['group_name' => 'Call center', 'description' => 'No premium numbers', 'default_action' => 'allow', 'is_active' => 1],
]);
$gid = (int) $db->query("SELECT id FROM pbx_permission_groups WHERE group_name = 'No international'")->fetchColumn();
$ins('pbx_permission_rules', [
    ['group_id' => $gid, 'pattern' => '00', 'pattern_type' => 'prefix', 'action' => 'deny', 'priority' => 10, 'description' => 'International'],
    ['group_id' => $gid, 'pattern' => '0900', 'pattern_type' => 'prefix', 'action' => 'deny', 'priority' => 20, 'description' => 'Premium'],
    ['group_id' => $gid + 1, 'pattern' => '1', 'pattern_type' => 'prefix', 'action' => 'allow', 'priority' => 10, 'description' => 'Extensions'],
    ['group_id' => $gid + 2, 'pattern' => '0900', 'pattern_type' => 'prefix', 'action' => 'deny', 'priority' => 10, 'description' => 'Premium'],
]);

// --- custom dialplan, boss/secretary, meetings, web widgets, fax units ---
$ins('pbx_custom_dialplans', [
    ['title' => 'Weather line', 'context_name' => 'custom-weather', 'dialplan_content' => "exten => 7800,1,Answer()\n same => n,Playback(custom/weather)\n same => n,Hangup()", 'description' => 'Plays the daily recording', 'is_active' => 1],
    ['title' => 'Door opener', 'context_name' => 'custom-door', 'dialplan_content' => "exten => 7900,1,Answer()\n same => n,SendDTMF(5)\n same => n,Hangup()", 'description' => 'Opens the warehouse gate', 'is_active' => 1],
    ['title' => 'Echo test', 'context_name' => 'custom-echo', 'dialplan_content' => "exten => 7999,1,Answer()\n same => n,Echo()", 'include_in_internal' => 1, 'is_active' => 1],
]);
$ins('pbx_boss_secretary_groups', [
    ['group_number' => '900', 'group_name' => 'CEO office', 'boss_extension' => '1004', 'secretaries_json' => json_encode(['1001']), 'whitelist_extensions' => '1008', 'is_active' => 1],
    ['group_number' => '901', 'group_name' => 'Logistics lead', 'boss_extension' => '1007', 'secretaries_json' => json_encode(['1005']), 'is_active' => 1],
    ['group_number' => '902', 'group_name' => 'Finance', 'boss_extension' => '1008', 'secretaries_json' => json_encode(['1001', '1005']), 'is_active' => 0],
]);
$meet = static fn(string $title, int $host, int $start, int $len, string $status) => ['meeting_token' => bin2hex(random_bytes(8)), 'title' => $title, 'description' => '', 'host_user_id' => $host, 'room_number' => (string) rand(8100, 8199), 'scheduled_start' => date('Y-m-d H:i:s', $now + $start), 'scheduled_end' => date('Y-m-d H:i:s', $now + $start + $len), 'max_participants' => 12, 'allow_guests' => 1, 'status' => $status === 'active' ? 'live' : $status];
$ins('pbx_meetings', [
    $meet('Quarterly review', $uid['1004'], 86400, 3600, 'scheduled'), $meet('Customer onboarding: Acme', $uid['1003'], 7200, 1800, 'scheduled'),
    $meet('Team sync', $uid['1001'], -1800, 3600, 'active'), $meet('Carrier negotiation', $uid['1008'], -86400 * 2, 2700, 'ended'),
    $meet('Warehouse planning', $uid['1007'], 86400 * 3, 3600, 'scheduled'),
]);
$ins('pbx_web_widgets', [
    ['public_id' => bin2hex(random_bytes(8)), 'name' => 'Website: Contact us', 'number' => '5001', 'dest_type' => 'queue', 'dest_id' => '1', 'allowed_origins' => 'https://www.northwind.example', 'call_enabled' => 1, 'callback_enabled' => 1, 'button_text' => 'Call us', 'color' => '#2563eb', 'position' => 'right', 'language' => 'en', 'sip_secret' => bin2hex(random_bytes(12)), 'is_active' => 1],
    ['public_id' => bin2hex(random_bytes(8)), 'name' => 'Shop: Order help', 'number' => '5002', 'dest_type' => 'queue', 'dest_id' => '3', 'allowed_origins' => 'https://shop.northwind.example', 'call_enabled' => 1, 'button_text' => 'Talk to us', 'color' => '#16a34a', 'position' => 'left', 'language' => 'en', 'sip_secret' => bin2hex(random_bytes(12)), 'is_active' => 1],
    ['public_id' => bin2hex(random_bytes(8)), 'name' => 'Support portal', 'number' => '5003', 'dest_type' => 'queue', 'dest_id' => '2', 'allowed_origins' => 'https://help.northwind.example', 'callback_enabled' => 1, 'button_text' => 'Call me back', 'color' => '#9333ea', 'position' => 'right', 'language' => 'de', 'sip_secret' => bin2hex(random_bytes(12)), 'is_active' => 0],
]);
$ins('sys_did_mappings', [
    ['did_extension' => '2001', 'department_name' => 'Accounting', 'notification_email' => 'accounting@northwind.example', 'header_info' => 'Northwind Accounting', 'assigned_user_id' => $uid['1001'], 'is_active' => 1],
]);

// --- phones ---
$phones = [['00:15:65:a1:b2:01', 'yealink-t54w', '1001'], ['00:15:65:a1:b2:02', 'yealink-t46u', '1002'], ['c0:74:ad:11:22:03', 'grandstream-gxp2160', '1003'],
    ['0c:38:3e:44:55:04', 'fanvil-x5u', '1004'], ['00:04:13:66:77:05', 'snom-d785', '1005']];
foreach ($phones as $i => [$mac, $model, $ext]) {
    $ins('pbx_phones', [['mac' => str_replace(':', '', $mac), 'model' => $model, 'user_id' => $uid[$ext], 'token' => bin2hex(random_bytes(16)), 'last_fetch_at' => $ago(600 + $i * 3600), 'last_ip' => '192.0.2.' . (31 + $i), 'last_user_agent' => ucfirst(explode('-', $model)[0]) . ' ' . strtoupper(explode('-', $model)[1]), 'region_id' => $i === 4 ? 2 : 1]]);
    $ins('pbx_phone_fetch_log', [['phone_id' => $i + 1, 'mac' => str_replace(':', '', $mac), 'file' => str_replace(':', '', $mac) . '.cfg', 'result' => 'ok', 'ip' => '192.0.2.' . (31 + $i), 'user_agent' => 'phone', 'created_at' => $ago(600 + $i * 3600)]]);
}

// --- call history: 45 calls over 6 days plus dedicated activity for 1000 (/my-phone) ---
$exts = array_merge(['1000'], array_column($people, 0));
$outside = ['+15550142', '+15550155', '+15550163', '+15550171', '+447700900123', '+447700900456', '+15550188', '+15550120'];
$cdr = [];
$today = max(1800, $now - strtotime('today'));
for ($i = 0; $i < 45; $i++) {
    // Two in five today, so the reports' default "Today" view is full too.
    $t = $now - ($i % 5 < 2 ? rand(120, $today) : rand(86400, 86400 * 6));
    $kind = $i % 3;
    $ext = $exts[array_rand($exts)];
    $ext2 = $exts[array_rand($exts)];
    $num = $outside[array_rand($outside)];
    $disp = ['ANSWERED', 'ANSWERED', 'ANSWERED', 'ANSWERED', 'NO ANSWER', 'BUSY'][rand(0, 5)];
    $dur = rand(8, 600);
    $bill = $disp === 'ANSWERED' ? max(3, $dur - rand(3, 15)) : 0;
    [$src, $dst, $ctx, $did] = match ($kind) {
        0 => [$num, $ext, 'from-trunk', '+15550100'],
        1 => [$ext, preg_replace('/^\+1/', '0', $num), 'from-internal', ''],
        default => [$ext, $ext2, 'from-internal', ''],
    };
    $u = sprintf('%d.%d', $t, $i);
    $cdr[] = ['calldate' => date('Y-m-d H:i:s', $t), 'clid' => "\"{$src}\" <{$src}>", 'src' => $src, 'dst' => $dst, 'did' => $did, 'dcontext' => $ctx,
        'channel' => "PJSIP/{$src}-0000" . dechex($i), 'dstchannel' => "PJSIP/{$dst}-0001" . dechex($i), 'lastapp' => 'Dial', 'lastdata' => "PJSIP/{$dst}",
        'duration' => $dur, 'billsec' => $bill, 'disposition' => $disp, 'amaflags' => 3, 'uniqueid' => $u, 'linkedid' => $u];
}
$demo1000 = [
    ['+15550142', '1000', 'from-trunk', '+15550100', 'ANSWERED', 185, 179, 900],
    ['1000', '05550155', 'from-internal', '', 'ANSWERED', 242, 234, 2100],
    ['1002', '1000', 'from-internal', '', 'ANSWERED', 95, 90, 3800],
    ['+447700900123', '1000', 'from-trunk', '+447700900100', 'NO ANSWER', 24, 0, 5400],
    ['1000', '1004', 'from-internal', '', 'ANSWERED', 140, 134, 86400 + 1800],
    ['1000', '05550163', 'from-internal', '', 'ANSWERED', 310, 302, 86400 * 2 + 1200],
];
foreach ($demo1000 as $idx => [$src, $dst, $ctx, $did, $disp, $dur, $bill, $age]) {
    $t = $now - $age;
    $u = sprintf('%d.%d', $t, 45 + $idx);
    $cdr[] = ['calldate' => date('Y-m-d H:i:s', $t), 'clid' => "\"{$src}\" <{$src}>", 'src' => $src, 'dst' => $dst, 'did' => $did, 'dcontext' => $ctx,
        'channel' => "PJSIP/{$src}-0000" . dechex(45 + $idx), 'dstchannel' => "PJSIP/{$dst}-0001" . dechex(45 + $idx), 'lastapp' => 'Dial', 'lastdata' => "PJSIP/{$dst}",
        'duration' => $dur, 'billsec' => $bill, 'disposition' => $disp, 'amaflags' => 3, 'uniqueid' => $u, 'linkedid' => $u];
}
$ins('asteriskcdr', $cdr);

// --- queue log: calls through the queues ---
$ql = [];
foreach (['sales', 'support', 'billing', 'vip'] as $q) {
    for ($i = 0; $i < 6; $i++) {
        $t = $now - rand(600, 86400 * 5);
        $call = "q{$q}{$i}.{$t}";
        $agent = 'PJSIP/' . ['1002', '1003', '1006'][rand(0, 2)];
        $wait = rand(4, 90);
        $ql[] = ['time_id' => $t, 'created_at' => date('Y-m-d H:i:s', $t), 'call_id' => $call, 'queue_name' => $q, 'agent' => 'NONE', 'event' => 'ENTERQUEUE', 'data1' => '', 'data2' => $outside[array_rand($outside)], 'data3' => '1'];
        if ($i === 4) {
            $ql[] = ['time_id' => $t + $wait, 'created_at' => date('Y-m-d H:i:s', $t + $wait), 'call_id' => $call, 'queue_name' => $q, 'agent' => 'NONE', 'event' => 'ABANDON', 'data1' => '1', 'data2' => '1', 'data3' => (string) $wait];
            continue;
        }
        $talk = rand(40, 500);
        $ql[] = ['time_id' => $t + $wait, 'created_at' => date('Y-m-d H:i:s', $t + $wait), 'call_id' => $call, 'queue_name' => $q, 'agent' => $agent, 'event' => 'CONNECT', 'data1' => (string) $wait, 'data2' => $call, 'data3' => '3'];
        $ql[] = ['time_id' => $t + $wait + $talk, 'created_at' => date('Y-m-d H:i:s', $t + $wait + $talk), 'call_id' => $call, 'queue_name' => $q, 'agent' => $agent, 'event' => 'COMPLETECALLER', 'data1' => (string) $wait, 'data2' => (string) $talk, 'data3' => '1'];
    }
}
$ins('cc_queue_logs', $ql);
$ins('cc_pause_logs', [
    ['agent_extension' => '1002', 'agent_name' => 'Mehmet Kaya', 'pause_reason' => 'Lunch', 'start_time' => $ago(86400 + 3600), 'end_time' => $ago(86400 + 1800), 'duration' => 1800, 'status' => 'ended'],
    ['agent_extension' => '1003', 'agent_name' => 'John Carter', 'pause_reason' => 'Coffee', 'start_time' => $ago(7200), 'end_time' => $ago(6600), 'duration' => 600, 'status' => 'ended'],
    ['agent_extension' => '1006', 'agent_name' => 'Sofia Rossi', 'pause_reason' => 'Training', 'start_time' => $ago(86400 * 2), 'end_time' => $ago(86400 * 2 - 3600), 'duration' => 3600, 'status' => 'ended'],
    ['agent_extension' => '1002', 'agent_name' => 'Mehmet Kaya', 'pause_reason' => 'After-call work', 'start_time' => $ago(900), 'end_time' => $ago(780), 'duration' => 120, 'status' => 'ended'],
]);
$ins('callcenter_notes', [
    ['call_id' => 'qsales0', 'agent_extension' => '1002', 'customer_name' => 'Acme Freight', 'phone' => '+15550142', 'disposition' => 'sale', 'notes' => 'Ordered 3 pallets, delivery Friday.'],
    ['call_id' => 'qsupport1', 'agent_extension' => '1006', 'customer_name' => 'Bluebird Retail', 'phone' => '+15550155', 'disposition' => 'resolved', 'notes' => 'Tracking number resent by e-mail.'],
    ['call_id' => 'qsales2', 'agent_extension' => '1003', 'customer_name' => 'Kestrel Foods', 'phone' => '+447700900123', 'disposition' => 'callback', 'notes' => 'Wants a quote for weekly pickups.'],
    ['call_id' => 'qsupport3', 'agent_extension' => '1002', 'customer_name' => 'Orchid Labs', 'phone' => '+15550163', 'disposition' => 'resolved', 'notes' => 'Address corrected on order 4471.'],
]);

// --- fax, voicemail, chat ---
@mkdir('/var/spool/asterisk/fax/demo', 0755, true);
foreach ([['+15550142', 2, 2 * 3600], ['+447700900123', 1, 26 * 3600], ['+15550171', 4, 50 * 3600], ['+15550120', 3, 80 * 3600]] as $i => [$cid, $pages, $sec]) {
    $ins('fax_received', [['did_extension' => '2001', 'caller_id' => $cid, 'pages' => $pages, 'tif_path' => "/var/spool/asterisk/fax/demo/in{$i}.tif", 'pdf_path' => "/var/spool/asterisk/fax/demo/in{$i}.pdf", 'file_size' => 40000 * $pages, 'status' => 'received', 'is_read' => $i > 0 ? 1 : 0, 'email_sent' => 1, 'received_at' => $ago($sec)]]);
}
foreach ([['+15550155', 2, 'sent', 3 * 3600], ['+15550188', 1, 'sent', 30 * 3600], ['+447700900456', 5, 'failed', 54 * 3600], ['+15550142', 1, 'sent', 75 * 3600]] as $i => [$dst, $pages, $st, $sec]) {
    $ins('fax_sent', [['user_id' => $uid['2001'] ?? 1, 'sender_extension' => '2001', 'destination_number' => $dst, 'pdf_path' => "/var/spool/asterisk/fax/demo/out{$i}.pdf", 'tif_path' => "/var/spool/asterisk/fax/demo/out{$i}.tif", 'pages' => $pages, 'status' => $st, 'error_message' => $st === 'failed' ? 'No answer from the remote fax' : null, 'created_at' => $ago($sec), 'completed_at' => $ago($sec - 90)]]);
}
$ins('chat_conversations', [
    ['type' => 'group', 'title' => 'Sales team', 'created_by' => '1004', 'last_message_text' => 'Great, thanks!', 'last_message_at' => $ago(300)],
    ['type' => 'direct', 'direct_key' => '1001:1007', 'created_by' => '1001', 'last_message_text' => 'The truck is at gate 3.', 'last_message_at' => $ago(1500)],
    ['type' => 'group', 'title' => 'Warehouse', 'created_by' => '1007', 'last_message_text' => 'Inventory done.', 'last_message_at' => $ago(7200)],
]);
foreach ([[1, ['1004', '1002', '1003', '1006', '1000']], [2, ['1001', '1007']], [3, ['1007', '1005', '1001', '1000']]] as [$cid, $members2]) {
    foreach ($members2 as $m) {
        $ins('chat_participants', [['conversation_id' => $cid, 'extension' => $m, 'role' => $m === $members2[0] ? 'admin' : 'member', 'added_by' => $members2[0]]]);
    }
}
$ins('chat_messages', [
    ['conversation_id' => 1, 'sender_ext' => '1004', 'message' => 'Morning! Target today: 20 calls each.', 'created_at' => $ago(9000)],
    ['conversation_id' => 1, 'sender_ext' => '1002', 'message' => 'Acme ordered 3 pallets 🎉', 'created_at' => $ago(1200)],
    ['conversation_id' => 1, 'sender_ext' => '1004', 'message' => 'Great, thanks!', 'created_at' => $ago(300)],
    ['conversation_id' => 2, 'sender_ext' => '1001', 'message' => 'Is the Kestrel delivery out?', 'created_at' => $ago(2000)],
    ['conversation_id' => 2, 'sender_ext' => '1007', 'message' => 'The truck is at gate 3.', 'created_at' => $ago(1500)],
    ['conversation_id' => 3, 'sender_ext' => '1007', 'message' => 'Inventory done.', 'created_at' => $ago(7200)],
]);

// --- admin side: API keys, webhooks, external DBs, scheduled tasks, AI apps, audit, logins, notices ---
foreach ([['CRM sync', ['extensions:read', 'calls:read', 'cdr:read']], ['Wallboard', ['queues:read', 'calls:read']], ['Provisioning script', ['phones:write', 'extensions:read']], ['Billing export', ['cdr:read']]] as [$name, $scopes]) {
    $raw = 'apbx_' . bin2hex(random_bytes(20));
    $ins('sys_api_tokens', [['name' => $name, 'token_prefix' => substr($raw, 0, 12), 'token_hash' => hash('sha256', $raw), 'scopes_json' => json_encode($scopes), 'rate_limit_per_min' => 120, 'created_by' => 1, 'is_active' => 1, 'last_used_at' => $ago(rand(60, 86400)), 'last_used_ip' => '198.51.100.' . rand(2, 250)]]);
}
$ins('sys_webhooks', [
    ['name' => 'CRM: call events', 'target_url' => 'https://crm.northwind.example/hooks/pbx', 'events_json' => json_encode(['call.started', 'call.ended']), 'timeout_sec' => 5, 'max_retries' => 3, 'is_active' => 1, 'last_status_code' => 200, 'last_triggered_at' => $ago(240)],
    ['name' => 'Slack: missed calls', 'target_url' => 'https://hooks.chat.example/T000/B000', 'events_json' => json_encode(['call.missed']), 'timeout_sec' => 5, 'max_retries' => 3, 'is_active' => 1, 'last_status_code' => 200, 'last_triggered_at' => $ago(5400)],
    ['name' => 'Ticketing: voicemail', 'target_url' => 'https://helpdesk.northwind.example/api/voicemail', 'events_json' => json_encode(['voicemail.received']), 'timeout_sec' => 10, 'max_retries' => 5, 'is_active' => 1, 'last_status_code' => 201, 'last_triggered_at' => $ago(86400)],
    ['name' => 'Archive: faxes', 'target_url' => 'https://archive.northwind.example/fax', 'events_json' => json_encode(['fax.received', 'fax.sent']), 'timeout_sec' => 10, 'max_retries' => 3, 'is_active' => 0, 'last_status_code' => 503, 'last_error' => 'Service Unavailable', 'failure_streak' => 4],
]);
$ins('pbx_external_dbs', [
    ['name' => 'CRM (MySQL)', 'driver' => 'mysql', 'host' => 'crm-db.northwind.example', 'port' => 3306, 'database_name' => 'crm', 'username' => 'pbx_ro', 'timeout_sec' => 3, 'is_active' => 1, 'last_test_status' => 'ok', 'last_tested_at' => $ago(3600)],
    ['name' => 'ERP (PostgreSQL)', 'driver' => 'pgsql', 'host' => 'erp.northwind.example', 'port' => 5432, 'database_name' => 'erp', 'username' => 'pbx', 'timeout_sec' => 3, 'is_active' => 1, 'last_test_status' => 'ok', 'last_tested_at' => $ago(7200)],
    ['name' => 'Orders (MSSQL)', 'driver' => 'mysql', 'host' => '192.0.2.50', 'port' => 3306, 'database_name' => 'orders', 'username' => 'ivr', 'timeout_sec' => 5, 'is_active' => 0, 'last_test_status' => 'fail', 'last_test_message' => 'Connection timed out'],
]);
$ins('pbx_scheduled_tasks', [
    ['name' => 'Nightly CDR export', 'description' => 'Sends yesterday\'s calls to the billing system', 'is_active' => 1, 'cron_expression' => '15 2 * * *', 'script_type' => 'python', 'script_content' => "print('export')", 'timeout_sec' => 120, 'last_run_at' => $ago(30000), 'last_status' => 'ok', 'created_by' => 1],
    ['name' => 'Holiday mode on', 'description' => 'Switches the main line to the holiday message', 'is_active' => 1, 'cron_expression' => '0 18 24 12 *', 'script_type' => 'shell', 'script_content' => 'echo on', 'timeout_sec' => 30, 'created_by' => 1],
    ['name' => 'Weekly queue report', 'description' => 'Mails the queue statistics to the managers', 'is_active' => 1, 'cron_expression' => '0 8 * * 1', 'script_type' => 'python', 'script_content' => "print('report')", 'timeout_sec' => 300, 'last_run_at' => $ago(86400 * 4), 'last_status' => 'ok', 'created_by' => 1],
    ['name' => 'Clean old recordings', 'description' => 'Deletes recordings older than 90 days', 'is_active' => 0, 'cron_expression' => '30 3 * * 0', 'script_type' => 'shell', 'script_content' => 'echo clean', 'timeout_sec' => 600, 'created_by' => 1],
]);
$ins('pbx_ai_apps', [
    ['title' => 'Order status bot', 'app_type' => 'announcement', 'internal_number' => '7501', 'text_template' => 'Your order {order} is {status}.', 'lookup_url' => 'https://erp.northwind.example/api/order', 'dest_type' => 'queue', 'dest_id' => '2', 'max_concurrent' => 4, 'is_active' => 1],
    ['title' => 'After-hours receptionist', 'app_type' => 'speech_routing', 'internal_number' => '7502', 'text_template' => 'Thank you for calling Northwind.', 'dest_type' => 'voicemail', 'dest_id' => '1001', 'max_concurrent' => 2, 'is_active' => 1],
    ['title' => 'Callback taker', 'app_type' => 'voice_requests', 'internal_number' => '7503', 'text_template' => 'Tell us your name and number.', 'dest_type' => 'hangup', 'dest_id' => '', 'max_concurrent' => 2, 'is_active' => 1],
]);
$audit = [['extensions', 'extension', '1006', 'Extension: 1006 (Sofia Rossi)', 'create'], ['queues', 'queue', 'sales', 'Queue: Sales', 'update'], ['trunks', 'trunk', 'sbc_tls', 'Trunk: SBC over TLS', 'create'],
    ['did_routes', 'did', '+15550101', 'Inbound: Support line', 'update'], ['ivrs', 'ivr', '1', 'IVR: Main menu', 'update'], ['system', 'apply', '', 'Apply: 6 changes', 'update'], ['phones', 'phone', '00:15:65:a1:b2:01', 'Phone: Yealink T54W (1001)', 'create']];
foreach ($audit as $i => [$d, $type, $id, $label, $act]) {
    $ins('sys_audit_log', [['domain' => $d, 'entity_type' => $type, 'entity_id' => $id, 'entity_label' => $label, 'action' => $act, 'user_id' => 1, 'username' => 'admin', 'ip_address' => '203.0.113.' . (10 + $i), 'created_at' => $ago(3600 * ($i + 1) * 3)]]);
}
foreach ([['admin', 'success', 600], ['ext1004', 'success', 4000], ['ext1002', 'success', 9000], ['admin', 'failed', 20000], ['ext1008', 'success', 40000], ['unknown', 'failed', 60000]] as [$u, $st, $sec]) {
    $ins('sys_login_logs', [['ip_address' => '198.51.100.' . rand(2, 250), 'username' => $u, 'status' => $st, 'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/130.0', 'created_at' => $ago($sec)]]);
}

// --- phonebook (/phonebook) ---
$ins('phonebook_contacts', [
    ['source' => 'manual', 'external_id' => null, 'display_name' => 'Acme Freight Dispatch', 'first_name' => 'Claire', 'last_name' => 'Mercer', 'company' => 'Acme Freight', 'phone_work' => '+15550142', 'phone_mobile' => '+15550143', 'phone_other' => '', 'email' => 'dispatch@acmefreight.example'],
    ['source' => 'manual', 'external_id' => null, 'display_name' => 'Bluebird Retail Purchasing', 'first_name' => 'Marcus', 'last_name' => 'Vance', 'company' => 'Bluebird Retail', 'phone_work' => '+15550155', 'phone_mobile' => '', 'phone_other' => '+15550156', 'email' => 'purchasing@bluebird.example'],
    ['source' => 'manual', 'external_id' => null, 'display_name' => 'Kestrel Foods Logistics', 'first_name' => 'Hannah', 'last_name' => 'Brooks', 'company' => 'Kestrel Foods', 'phone_work' => '+447700900123', 'phone_mobile' => '+447700900124', 'phone_other' => '', 'email' => 'h.brooks@kestrel.example'],
    ['source' => 'manual', 'external_id' => null, 'display_name' => 'Orchid Labs Support', 'first_name' => 'Kenji', 'last_name' => 'Takahashi', 'company' => 'Orchid Labs', 'phone_work' => '+15550163', 'phone_mobile' => '+15550164', 'phone_other' => '', 'email' => 'support@orchidlabs.example'],
    ['source' => 'ldap-sync', 'external_id' => 'ldap-emp-901', 'display_name' => 'Martin Lindholm (Warehouse)', 'first_name' => 'Martin', 'last_name' => 'Lindholm', 'company' => 'Northwind Logistics', 'phone_work' => '+15550171', 'phone_mobile' => '+15550172', 'phone_other' => '', 'email' => 'martin.lindholm@northwind.example'],
    ['source' => 'ldap-sync', 'external_id' => 'ldap-emp-902', 'display_name' => 'Elena Novak (Customs)', 'first_name' => 'Elena', 'last_name' => 'Novak', 'company' => 'Northwind Logistics', 'phone_work' => '+447700900456', 'phone_mobile' => '+447700900457', 'phone_other' => '', 'email' => 'elena.novak@northwind.example'],
]);
PhonebookService::saveLdapSettings(true, false);

// --- signaling, call quality & SIP pcaps ---
setSystemSetting('signaling_capture', '1');
setSystemSetting('sip_capture_files', '1');
setSystemSetting('sip_capture_days', '10');
setSystemSetting('sip_capture_max_mb', '2048');
setSystemSetting('signaling_retention_days', '30');

$sigEvents = [
    [$ago(2800), 'call-1002-01@northwind.example', 'INVITE', 'sip', 'UDP', '203.0.113.20:5060', '192.0.2.1:5060', 'sip:+15550142@203.0.113.20', 'sip:+15550102@192.0.2.1', '102 INVITE', '', 'INVITE sip:+15550102@192.0.2.1:5060 SIP/2.0', 'INVITE sip:+15550102@192.0.2.1:5060 SIP/2.0 | From: +15550142 | To: +15550102'],
    [$ago(2800), 'call-1002-01@northwind.example', 'INVITE', 'sip', 'UDP', '192.0.2.1:5060', '192.0.2.32:5060', 'sip:+15550142@192.0.2.1', 'sip:1002@192.0.2.32', '102 INVITE', '', 'INVITE sip:1002@192.0.2.32:5060 SIP/2.0', 'INVITE sip:1002@192.0.2.32:5060 SIP/2.0 | From: +15550142 | To: 1002'],
    [$ago(2798), 'call-1002-01@northwind.example', 'INVITE', 'sip', 'UDP', '192.0.2.32:5060', '192.0.2.1:5060', 'sip:+15550142@192.0.2.1', 'sip:1002@192.0.2.32', '102 INVITE', '180', 'SIP/2.0 180 Ringing', 'SIP/2.0 180 Ringing | Call-ID: call-1002-01@northwind.example'],
    [$ago(2794), 'call-1002-01@northwind.example', 'INVITE', 'sip', 'UDP', '192.0.2.32:5060', '192.0.2.1:5060', 'sip:+15550142@192.0.2.1', 'sip:1002@192.0.2.32', '102 INVITE', '200', 'SIP/2.0 200 OK', 'SIP/2.0 200 OK | Call-ID: call-1002-01@northwind.example'],
    [$ago(2794), 'call-1002-01@northwind.example', 'ACK', 'sip', 'UDP', '203.0.113.20:5060', '192.0.2.1:5060', 'sip:+15550142@203.0.113.20', 'sip:+15550102@192.0.2.1', '102 ACK', '', 'ACK sip:+15550102@192.0.2.1:5060 SIP/2.0', 'ACK sip:+15550102@192.0.2.1:5060 SIP/2.0'],
    [$ago(2610), 'call-1002-01@northwind.example', 'BYE', 'sip', 'UDP', '192.0.2.32:5060', '192.0.2.1:5060', 'sip:+15550142@192.0.2.1', 'sip:1002@192.0.2.32', '103 BYE', '', 'BYE sip:+15550142@192.0.2.1:5060 SIP/2.0', 'BYE sip:+15550142@192.0.2.1:5060 SIP/2.0'],
    [$ago(1900), 'call-1003-02@northwind.example', 'INVITE', 'sip', 'UDP', '192.0.2.33:5060', '192.0.2.1:5060', 'sip:1003@pbx.northwind.example', 'sip:+15550163@pbx.northwind.example', '201 INVITE', '', 'INVITE sip:+15550163@pbx.northwind.example SIP/2.0', 'INVITE sip:+15550163@pbx.northwind.example SIP/2.0'],
    [$ago(1900), 'call-1003-02@northwind.example', 'INVITE', 'sip', 'TLS', '192.0.2.1:5061', '198.51.100.40:5061', 'sip:+15550100@pbx.northwind.example', 'sip:+15550163@sbc.carrier-two.example', '201 INVITE', '', 'INVITE sip:+15550163@sbc.carrier-two.example SIP/2.0', 'INVITE sip:+15550163@sbc.carrier-two.example SIP/2.0'],
    [$ago(1897), 'call-1003-02@northwind.example', 'INVITE', 'sip', 'TLS', '198.51.100.40:5061', '192.0.2.1:5061', 'sip:+15550100@pbx.northwind.example', 'sip:+15550163@sbc.carrier-two.example', '201 INVITE', '183', 'SIP/2.0 183 Session Progress', 'SIP/2.0 183 Session Progress'],
    [$ago(1892), 'call-1003-02@northwind.example', 'INVITE', 'sip', 'TLS', '198.51.100.40:5061', '192.0.2.1:5061', 'sip:+15550100@pbx.northwind.example', 'sip:+15550163@sbc.carrier-two.example', '201 INVITE', '200', 'SIP/2.0 200 OK', 'SIP/2.0 200 OK'],
    [$ago(1650), 'call-1003-02@northwind.example', 'BYE', 'sip', 'UDP', '192.0.2.33:5060', '192.0.2.1:5060', 'sip:1003@pbx.northwind.example', 'sip:+15550163@pbx.northwind.example', '202 BYE', '', 'BYE sip:+15550163@pbx.northwind.example SIP/2.0', 'BYE sip:+15550163@pbx.northwind.example SIP/2.0'],
    [$ago(600), 'reg-1001@northwind.example', 'REGISTER', 'sip', 'UDP', '192.0.2.31:5060', '192.0.2.1:5060', 'sip:1001-sip@pbx.northwind.example', 'sip:1001-sip@pbx.northwind.example', '41 REGISTER', '', 'REGISTER sip:pbx.northwind.example SIP/2.0', 'REGISTER sip:pbx.northwind.example SIP/2.0'],
    [$ago(600), 'reg-1001@northwind.example', 'REGISTER', 'sip', 'UDP', '192.0.2.1:5060', '192.0.2.31:5060', 'sip:1001-sip@pbx.northwind.example', 'sip:1001-sip@pbx.northwind.example', '41 REGISTER', '200', 'SIP/2.0 200 OK', 'SIP/2.0 200 OK | Expires: 3600'],
];
$rtcpEvents = [
    [$ago(2700), 'cq-carrier-01@northwind.example', '192.0.2.1:10401', '203.0.113.20:18201', '0x11a0c001', 0, 1, 24],
    [$ago(2650), 'cq-carrier-01@northwind.example', '203.0.113.20:18201', '192.0.2.1:10401', '0x11a0c002', 1, 0, 32],
    [$ago(1700), 'cq-sbc-02@northwind.example', '192.0.2.1:10421', '198.51.100.40:21001', '0x22b0d001', 42, 19, 196],
    [$ago(1660), 'cq-sbc-02@northwind.example', '198.51.100.40:21001', '192.0.2.1:10421', '0x22b0d002', 6, 3, 64],
    [$ago(3500), 'cq-1001-03@northwind.example', '192.0.2.1:10441', '192.0.2.31:11781', '0x33c0e001', 0, 0, 18],
    [$ago(1100), 'cq-1002-04@northwind.example', '192.0.2.32:11821', '192.0.2.1:10461', '0x44d0f001', 9, 6, 88],
    [$ago(950), 'cq-1002-04@northwind.example', '192.0.2.1:10461', '192.0.2.32:11821', '0x44d0f002', 2, 2, 44],
    [$ago(5200), 'cq-1003-05@northwind.example', '192.0.2.1:10481', '192.0.2.33:11901', '0x55e01001', 0, 0, 14],
];
$sigRows = [];
foreach ($sigEvents as $e) {
    $sigRows[] = ['ts' => $e[0], 'call_id' => $e[1], 'method' => $e[2], 'proto' => $e[3], 'transport' => $e[4],
        'src' => $e[5], 'dst' => $e[6], 'from_uri' => $e[7], 'to_uri' => $e[8], 'cseq' => $e[9], 'status_code' => $e[10],
        'first_line' => $e[11], 'line' => $e[12], 'capture_id' => 'pbx-showcase', 'rtcp_ssrc' => '',
        'rtcp_lost' => null, 'rtcp_fraction_lost' => null, 'rtcp_jitter' => null];
}
foreach ($rtcpEvents as $r) {
    $sigRows[] = ['ts' => $r[0], 'call_id' => $r[1], 'method' => 'RTCP', 'proto' => 'rtcp', 'transport' => 'UDP',
        'src' => $r[2], 'dst' => $r[3], 'from_uri' => '', 'to_uri' => '', 'cseq' => '', 'status_code' => '',
        'first_line' => 'RTCP SR/RR', 'line' => "RTCP ssrc={$r[4]} lost={$r[5]} frac={$r[6]} jitter={$r[7]}",
        'capture_id' => 'pbx-showcase', 'rtcp_ssrc' => $r[4], 'rtcp_lost' => $r[5], 'rtcp_fraction_lost' => $r[6], 'rtcp_jitter' => $r[7]];
}
$ins('pbx_signaling_events', $sigRows);
$maxSigId = (string) ((int) $db->query('SELECT COALESCE(MAX(id), 0) FROM pbx_signaling_events')->fetchColumn());
setSystemSetting('call_quality_watermark', $maxSigId);
setSystemSetting('call_quality_last_pass', json_encode(['at' => $now - 30, 'reports' => count($rtcpEvents), 'legs' => 5, 'named' => 5, 'alerts' => 2]));

$ins('pbx_call_quality_legs', [
    ['call_id' => 'cq-carrier-01@northwind.example', 'first_ts' => $ago(2760), 'last_ts' => $ago(2400), 'leg_type' => 'trunk', 'leg_name' => 'carrier_main', 'peer' => '203.0.113.20', 'reports_in' => 14, 'reports_out' => 14, 'bad_in' => 0, 'bad_out' => 0, 'loss_in_max' => 0.39, 'loss_out_max' => 0.00, 'loss_in_sum' => 1.17, 'loss_out_sum' => 0.00, 'jitter_in_max' => 32, 'jitter_out_max' => 24, 'level' => 'good'],
    ['call_id' => 'cq-sbc-02@northwind.example', 'first_ts' => $ago(1800), 'last_ts' => $ago(1400), 'leg_type' => 'trunk', 'leg_name' => 'sbc_tls', 'peer' => '198.51.100.40', 'reports_in' => 18, 'reports_out' => 16, 'bad_in' => 3, 'bad_out' => 0, 'loss_in_max' => 7.42, 'loss_out_max' => 1.17, 'loss_in_sum' => 28.50, 'loss_out_sum' => 4.68, 'jitter_in_max' => 196, 'jitter_out_max' => 64, 'level' => 'poor'],
    ['call_id' => 'cq-1001-03@northwind.example', 'first_ts' => $ago(3600), 'last_ts' => $ago(3300), 'leg_type' => 'extension', 'leg_name' => '1001', 'peer' => '192.0.2.31', 'reports_in' => 12, 'reports_out' => 12, 'bad_in' => 0, 'bad_out' => 0, 'loss_in_max' => 0.00, 'loss_out_max' => 0.39, 'loss_in_sum' => 0.00, 'loss_out_sum' => 0.78, 'jitter_in_max' => 18, 'jitter_out_max' => 22, 'level' => 'good'],
    ['call_id' => 'cq-1002-04@northwind.example', 'first_ts' => $ago(1200), 'last_ts' => $ago(900), 'leg_type' => 'extension', 'leg_name' => '1002', 'peer' => '192.0.2.32', 'reports_in' => 16, 'reports_out' => 15, 'bad_in' => 0, 'bad_out' => 1, 'loss_in_max' => 0.78, 'loss_out_max' => 2.34, 'loss_in_sum' => 2.34, 'loss_out_sum' => 8.58, 'jitter_in_max' => 44, 'jitter_out_max' => 88, 'level' => 'fair'],
    ['call_id' => 'cq-1003-05@northwind.example', 'first_ts' => $ago(5400), 'last_ts' => $ago(5100), 'leg_type' => 'extension', 'leg_name' => '1003', 'peer' => '192.0.2.33', 'reports_in' => 10, 'reports_out' => 10, 'bad_in' => 0, 'bad_out' => 0, 'loss_in_max' => 0.00, 'loss_out_max' => 0.00, 'loss_in_sum' => 0.00, 'loss_out_sum' => 0.00, 'jitter_in_max' => 14, 'jitter_out_max' => 16, 'level' => 'good'],
]);

$sipDir = '/var/lib/aipbx/sip-capture';
@mkdir($sipDir, 0750, true);
@chown($sipDir, 'asterisk');
@chgrp($sipDir, 'www-data');
@chmod($sipDir, 0750);
foreach (glob($sipDir . '/sip-*.pcap') ?: [] as $oldPcap) {
    @unlink($oldPcap);
}
$pcapHeader = pack('VvvVVVV', 0xa1b2c3d4, 2, 4, 0, 0, 65535, 101);
$pcapRec = static function (int $sec, int $usec, string $srcIp, int $srcPort, string $dstIp, int $dstPort, string $sip): string {
    $udp = pack('nnnn', $srcPort, $dstPort, 8 + strlen($sip), 0) . $sip;
    $ip = pack('CCnnnCCn', 0x45, 0, 20 + strlen($udp), 1, 0, 64, 17, 0) . inet_pton($srcIp) . inet_pton($dstIp) . $udp;
    return pack('VVVV', $sec, $usec, strlen($ip), strlen($ip)) . $ip;
};
$sipMsg = static fn(string $first, array $hdrs, string $body = ''): string =>
    $first . "\r\n" . implode("\r\n", $hdrs) . "\r\nContent-Length: " . strlen($body) . "\r\n\r\n" . $body;

$sdpAcme = "v=0\r\no=carrier 1201 1201 IN IP4 203.0.113.20\r\ns=-\r\nc=IN IP4 203.0.113.20\r\nt=0 0\r\nm=audio 18200 RTP/AVP 0 8 101\r\n";
$sdpPbx = "v=0\r\no=Asterisk 1202 1202 IN IP4 192.0.2.1\r\ns=-\r\nc=IN IP4 192.0.2.1\r\nt=0 0\r\nm=audio 10400 RTP/AVP 9 8 0 101\r\n";
$sdpPhone = "v=0\r\no=1002 1203 1203 IN IP4 192.0.2.32\r\ns=-\r\nc=IN IP4 192.0.2.32\r\nt=0 0\r\nm=audio 11820 RTP/AVP 8 0 101\r\n";

$t0 = $now - 7200;
$closedBytes = $pcapHeader
    . $pcapRec($t0, 100000, '192.0.2.31', 5060, '192.0.2.1', 5060, $sipMsg('REGISTER sip:pbx.northwind.example SIP/2.0', [
        'Via: SIP/2.0/UDP 192.0.2.31:5060;branch=z9hG4bK-reg0',
        'From: "Anna Weber" <sip:1001-sip@pbx.northwind.example>;tag=r0a',
        'To: <sip:1001-sip@pbx.northwind.example>',
        'Call-ID: reg-1005@northwind.example',
        'CSeq: 12 REGISTER',
        'User-Agent: Yealink SIP-T54W 96.86.0.145',
    ]))
    . $pcapRec($t0, 145000, '192.0.2.1', 5060, '192.0.2.31', 5060, $sipMsg('SIP/2.0 200 OK', [
        'Via: SIP/2.0/UDP 192.0.2.31:5060;branch=z9hG4bK-reg0',
        'From: "Anna Weber" <sip:1001-sip@pbx.northwind.example>;tag=r0a',
        'To: <sip:1001-sip@pbx.northwind.example>;tag=pbx0',
        'Call-ID: reg-1005@northwind.example',
        'CSeq: 12 REGISTER',
        'Expires: 3600',
    ]))
    . $pcapRec($t0 + 400, 10000, '192.0.2.31', 5060, '192.0.2.1', 5060, $sipMsg('INVITE sip:1004@pbx.northwind.example SIP/2.0', [
        'Via: SIP/2.0/UDP 192.0.2.31:5060;branch=z9hG4bK-c00',
        'From: "Anna Weber" <sip:1001@pbx.northwind.example>;tag=c00a',
        'To: <sip:1004@pbx.northwind.example>',
        'Call-ID: call-1001-00@northwind.example',
        'CSeq: 1 INVITE',
        'User-Agent: Yealink SIP-T54W 96.86.0.145',
    ]))
    . $pcapRec($t0 + 402, 200000, '192.0.2.1', 5060, '192.0.2.31', 5060, $sipMsg('SIP/2.0 200 OK', [
        'Via: SIP/2.0/UDP 192.0.2.31:5060;branch=z9hG4bK-c00',
        'From: "Anna Weber" <sip:1001@pbx.northwind.example>;tag=c00a',
        'To: <sip:1004@pbx.northwind.example>;tag=c00b',
        'Call-ID: call-1001-00@northwind.example',
        'CSeq: 1 INVITE',
    ]));
file_put_contents($sipDir . '/sip-20261114-090000_20261114-120000.pcap', $closedBytes);
@chown($sipDir . '/sip-20261114-090000_20261114-120000.pcap', 'asterisk');
@chgrp($sipDir . '/sip-20261114-090000_20261114-120000.pcap', 'www-data');
@chmod($sipDir . '/sip-20261114-090000_20261114-120000.pcap', 0640);

$t1 = $now - 2800;
$c1 = 'call-1002-01@northwind.example';
$c2 = 'call-1003-02@northwind.example';
$r1 = 'reg-1001@northwind.example';
$openBytes = $pcapHeader
    // Dialog 1: 3-column call-1002-01@northwind.example (203.0.113.20:5060 -> 192.0.2.1:5060 -> 192.0.2.32:5060)
    . $pcapRec($t1, 10000, '203.0.113.20', 5060, '192.0.2.1', 5060, $sipMsg('INVITE sip:+15550102@192.0.2.1:5060 SIP/2.0', [
        'Via: SIP/2.0/UDP 203.0.113.20:5060;branch=z9hG4bK-tr1',
        'From: "Acme Freight" <sip:+15550142@203.0.113.20>;tag=tr100',
        'To: <sip:+15550102@192.0.2.1>',
        "Call-ID: {$c1}",
        'CSeq: 102 INVITE',
        'Contact: <sip:+15550142@203.0.113.20:5060>',
        'User-Agent: CarrierOne-SBC/4.2',
        'Content-Type: application/sdp',
    ], $sdpAcme))
    . $pcapRec($t1, 25000, '192.0.2.1', 5060, '203.0.113.20', 5060, $sipMsg('SIP/2.0 100 Trying', [
        'Via: SIP/2.0/UDP 203.0.113.20:5060;branch=z9hG4bK-tr1',
        'From: "Acme Freight" <sip:+15550142@203.0.113.20>;tag=tr100',
        'To: <sip:+15550102@192.0.2.1>',
        "Call-ID: {$c1}",
        'CSeq: 102 INVITE',
        'Server: AiPBX/22.0',
    ]))
    . $pcapRec($t1, 60000, '192.0.2.1', 5060, '192.0.2.32', 5060, $sipMsg('INVITE sip:1002@192.0.2.32:5060 SIP/2.0', [
        'Via: SIP/2.0/UDP 192.0.2.1:5060;branch=z9hG4bK-pbx1',
        'From: "Acme Freight" <sip:+15550142@192.0.2.1>;tag=pbx100',
        'To: <sip:1002@192.0.2.32>',
        "Call-ID: {$c1}",
        'CSeq: 102 INVITE',
        'Contact: <sip:+15550142@192.0.2.1:5060>',
        'User-Agent: AiPBX/22.0',
        'Content-Type: application/sdp',
    ], $sdpPbx))
    . $pcapRec($t1, 80000, '192.0.2.32', 5060, '192.0.2.1', 5060, $sipMsg('SIP/2.0 100 Trying', [
        'Via: SIP/2.0/UDP 192.0.2.1:5060;branch=z9hG4bK-pbx1',
        'From: "Acme Freight" <sip:+15550142@192.0.2.1>;tag=pbx100',
        'To: <sip:1002@192.0.2.32>',
        "Call-ID: {$c1}",
        'CSeq: 102 INVITE',
    ]))
    . $pcapRec($t1 + 1, 150000, '192.0.2.32', 5060, '192.0.2.1', 5060, $sipMsg('SIP/2.0 180 Ringing', [
        'Via: SIP/2.0/UDP 192.0.2.1:5060;branch=z9hG4bK-pbx1',
        'From: "Acme Freight" <sip:+15550142@192.0.2.1>;tag=pbx100',
        'To: <sip:1002@192.0.2.32>;tag=yl46u',
        "Call-ID: {$c1}",
        'CSeq: 102 INVITE',
        'User-Agent: Yealink SIP-T46U 108.86.0.45',
    ]))
    . $pcapRec($t1 + 1, 165000, '192.0.2.1', 5060, '203.0.113.20', 5060, $sipMsg('SIP/2.0 180 Ringing', [
        'Via: SIP/2.0/UDP 203.0.113.20:5060;branch=z9hG4bK-tr1',
        'From: "Acme Freight" <sip:+15550142@203.0.113.20>;tag=tr100',
        'To: <sip:+15550102@192.0.2.1>;tag=pbx200',
        "Call-ID: {$c1}",
        'CSeq: 102 INVITE',
    ]))
    . $pcapRec($t1 + 6, 40000, '192.0.2.32', 5060, '192.0.2.1', 5060, $sipMsg('SIP/2.0 200 OK', [
        'Via: SIP/2.0/UDP 192.0.2.1:5060;branch=z9hG4bK-pbx1',
        'From: "Acme Freight" <sip:+15550142@192.0.2.1>;tag=pbx100',
        'To: <sip:1002@192.0.2.32>;tag=yl46u',
        "Call-ID: {$c1}",
        'CSeq: 102 INVITE',
        'Contact: <sip:1002@192.0.2.32:5060>',
        'User-Agent: Yealink SIP-T46U 108.86.0.45',
        'Content-Type: application/sdp',
    ], $sdpPhone))
    . $pcapRec($t1 + 6, 55000, '192.0.2.1', 5060, '203.0.113.20', 5060, $sipMsg('SIP/2.0 200 OK', [
        'Via: SIP/2.0/UDP 203.0.113.20:5060;branch=z9hG4bK-tr1',
        'From: "Acme Freight" <sip:+15550142@203.0.113.20>;tag=tr100',
        'To: <sip:+15550102@192.0.2.1>;tag=pbx200',
        "Call-ID: {$c1}",
        'CSeq: 102 INVITE',
        'Contact: <sip:+15550102@192.0.2.1:5060>',
        'Content-Type: application/sdp',
    ], $sdpPbx))
    . $pcapRec($t1 + 6, 90000, '203.0.113.20', 5060, '192.0.2.1', 5060, $sipMsg('ACK sip:+15550102@192.0.2.1:5060 SIP/2.0', [
        'Via: SIP/2.0/UDP 203.0.113.20:5060;branch=z9hG4bK-ack1',
        'From: "Acme Freight" <sip:+15550142@203.0.113.20>;tag=tr100',
        'To: <sip:+15550102@192.0.2.1>;tag=pbx200',
        "Call-ID: {$c1}",
        'CSeq: 102 ACK',
    ]))
    . $pcapRec($t1 + 6, 98000, '192.0.2.1', 5060, '192.0.2.32', 5060, $sipMsg('ACK sip:1002@192.0.2.32:5060 SIP/2.0', [
        'Via: SIP/2.0/UDP 192.0.2.1:5060;branch=z9hG4bK-ack2',
        'From: "Acme Freight" <sip:+15550142@192.0.2.1>;tag=pbx100',
        'To: <sip:1002@192.0.2.32>;tag=yl46u',
        "Call-ID: {$c1}",
        'CSeq: 102 ACK',
    ]))
    . $pcapRec($t1 + 190, 20000, '192.0.2.32', 5060, '192.0.2.1', 5060, $sipMsg('BYE sip:+15550142@192.0.2.1:5060 SIP/2.0', [
        'Via: SIP/2.0/UDP 192.0.2.32:5060;branch=z9hG4bK-bye1',
        'From: <sip:1002@192.0.2.32>;tag=yl46u',
        'To: "Acme Freight" <sip:+15550142@192.0.2.1>;tag=pbx100',
        "Call-ID: {$c1}",
        'CSeq: 103 BYE',
    ]))
    . $pcapRec($t1 + 190, 32000, '192.0.2.1', 5060, '192.0.2.32', 5060, $sipMsg('SIP/2.0 200 OK', [
        'Via: SIP/2.0/UDP 192.0.2.32:5060;branch=z9hG4bK-bye1',
        'From: <sip:1002@192.0.2.32>;tag=yl46u',
        'To: "Acme Freight" <sip:+15550142@192.0.2.1>;tag=pbx100',
        "Call-ID: {$c1}",
        'CSeq: 103 BYE',
    ]))
    . $pcapRec($t1 + 190, 45000, '192.0.2.1', 5060, '203.0.113.20', 5060, $sipMsg('BYE sip:+15550142@203.0.113.20:5060 SIP/2.0', [
        'Via: SIP/2.0/UDP 192.0.2.1:5060;branch=z9hG4bK-bye2',
        'From: <sip:+15550102@192.0.2.1>;tag=pbx200',
        'To: "Acme Freight" <sip:+15550142@203.0.113.20>;tag=tr100',
        "Call-ID: {$c1}",
        'CSeq: 103 BYE',
    ]))
    . $pcapRec($t1 + 190, 70000, '203.0.113.20', 5060, '192.0.2.1', 5060, $sipMsg('SIP/2.0 200 OK', [
        'Via: SIP/2.0/UDP 192.0.2.1:5060;branch=z9hG4bK-bye2',
        'From: <sip:+15550102@192.0.2.1>;tag=pbx200',
        'To: "Acme Freight" <sip:+15550142@203.0.113.20>;tag=tr100',
        "Call-ID: {$c1}",
        'CSeq: 103 BYE',
    ]))
    // Dialog 2: outbound call-1003-02@northwind.example
    . $pcapRec($t1 + 900, 10000, '192.0.2.33', 5060, '192.0.2.1', 5060, $sipMsg('INVITE sip:+15550163@192.0.2.1:5060 SIP/2.0', [
        'Via: SIP/2.0/UDP 192.0.2.33:5060;branch=z9hG4bK-out1',
        'From: "John Carter" <sip:1003@192.0.2.1>;tag=jc1',
        'To: <sip:+15550163@192.0.2.1>',
        "Call-ID: {$c2}",
        'CSeq: 201 INVITE',
        'User-Agent: Grandstream GXP2160 1.0.11.64',
    ]))
    . $pcapRec($t1 + 900, 22000, '192.0.2.1', 5060, '192.0.2.33', 5060, $sipMsg('SIP/2.0 100 Trying', [
        'Via: SIP/2.0/UDP 192.0.2.33:5060;branch=z9hG4bK-out1',
        'From: "John Carter" <sip:1003@192.0.2.1>;tag=jc1',
        'To: <sip:+15550163@192.0.2.1>',
        "Call-ID: {$c2}",
        'CSeq: 201 INVITE',
    ]))
    . $pcapRec($t1 + 900, 45000, '192.0.2.1', 5061, '198.51.100.40', 5061, $sipMsg('INVITE sip:+15550163@198.51.100.40:5061 SIP/2.0', [
        'Via: SIP/2.0/TLS 192.0.2.1:5061;branch=z9hG4bK-sbc1',
        'From: "Northwind" <sip:+15550100@192.0.2.1>;tag=nw1',
        'To: <sip:+15550163@198.51.100.40>',
        "Call-ID: {$c2}",
        'CSeq: 201 INVITE',
        'User-Agent: AiPBX/22.0',
    ]))
    . $pcapRec($t1 + 903, 120000, '198.51.100.40', 5061, '192.0.2.1', 5061, $sipMsg('SIP/2.0 183 Session Progress', [
        'Via: SIP/2.0/TLS 192.0.2.1:5061;branch=z9hG4bK-sbc1',
        'From: "Northwind" <sip:+15550100@192.0.2.1>;tag=nw1',
        'To: <sip:+15550163@198.51.100.40>;tag=sbc9',
        "Call-ID: {$c2}",
        'CSeq: 201 INVITE',
    ]))
    . $pcapRec($t1 + 908, 300000, '192.0.2.1', 5060, '192.0.2.33', 5060, $sipMsg('SIP/2.0 200 OK', [
        'Via: SIP/2.0/UDP 192.0.2.33:5060;branch=z9hG4bK-out1',
        'From: "John Carter" <sip:1003@192.0.2.1>;tag=jc1',
        'To: <sip:+15550163@192.0.2.1>;tag=pbx3',
        "Call-ID: {$c2}",
        'CSeq: 201 INVITE',
    ]))
    . $pcapRec($t1 + 908, 340000, '192.0.2.33', 5060, '192.0.2.1', 5060, $sipMsg('ACK sip:+15550163@192.0.2.1:5060 SIP/2.0', [
        'Via: SIP/2.0/UDP 192.0.2.33:5060;branch=z9hG4bK-outack',
        'From: "John Carter" <sip:1003@192.0.2.1>;tag=jc1',
        'To: <sip:+15550163@192.0.2.1>;tag=pbx3',
        "Call-ID: {$c2}",
        'CSeq: 201 ACK',
    ]))
    . $pcapRec($t1 + 1150, 50000, '192.0.2.33', 5060, '192.0.2.1', 5060, $sipMsg('BYE sip:+15550163@192.0.2.1:5060 SIP/2.0', [
        'Via: SIP/2.0/UDP 192.0.2.33:5060;branch=z9hG4bK-outbye',
        'From: "John Carter" <sip:1003@192.0.2.1>;tag=jc1',
        'To: <sip:+15550163@192.0.2.1>;tag=pbx3',
        "Call-ID: {$c2}",
        'CSeq: 202 BYE',
    ]))
    . $pcapRec($t1 + 1150, 75000, '192.0.2.1', 5060, '192.0.2.33', 5060, $sipMsg('SIP/2.0 200 OK', [
        'Via: SIP/2.0/UDP 192.0.2.33:5060;branch=z9hG4bK-outbye',
        'From: "John Carter" <sip:1003@192.0.2.1>;tag=jc1',
        'To: <sip:+15550163@192.0.2.1>;tag=pbx3',
        "Call-ID: {$c2}",
        'CSeq: 202 BYE',
    ]))
    // Dialog 3: registration reg-1001@northwind.example
    . $pcapRec($t1 + 2200, 10000, '192.0.2.31', 5060, '192.0.2.1', 5060, $sipMsg('REGISTER sip:pbx.northwind.example SIP/2.0', [
        'Via: SIP/2.0/UDP 192.0.2.31:5060;branch=z9hG4bK-reg1',
        'From: "Anna Weber" <sip:1001-sip@pbx.northwind.example>;tag=r1a',
        'To: <sip:1001-sip@pbx.northwind.example>',
        "Call-ID: {$r1}",
        'CSeq: 41 REGISTER',
        'User-Agent: Yealink SIP-T54W 96.86.0.145',
    ]))
    . $pcapRec($t1 + 2200, 30000, '192.0.2.1', 5060, '192.0.2.31', 5060, $sipMsg('SIP/2.0 200 OK', [
        'Via: SIP/2.0/UDP 192.0.2.31:5060;branch=z9hG4bK-reg1',
        'From: "Anna Weber" <sip:1001-sip@pbx.northwind.example>;tag=r1a',
        'To: <sip:1001-sip@pbx.northwind.example>;tag=pbxreg',
        "Call-ID: {$r1}",
        'CSeq: 41 REGISTER',
        'Expires: 3600',
    ]));
file_put_contents($sipDir . '/sip-20261114-120000.pcap', $openBytes);
@chown($sipDir . '/sip-20261114-120000.pcap', 'asterisk');
@chgrp($sipDir . '/sip-20261114-120000.pcap', 'www-data');
@chmod($sipDir . '/sip-20261114-120000.pcap', 0640);

// --- problem recording (/problem-recording) ---
$diagDir = '/var/lib/aipbx/diag';
@mkdir($diagDir, 0750, true);
@chown($diagDir, 'root');
@chgrp($diagDir, 'www-data');
@chmod($diagDir, 0750);
foreach ([
    ['aipbx-diag-20261114-091522.zip', $now - 3600],
    ['aipbx-diag-20261113-164210.zip', $now - 86400],
] as [$zipName, $mtime]) {
    $zipPath = $diagDir . '/' . $zipName;
    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true) {
        $zip->addFromString('state.txt', "AiPBX diagnostic bundle (demo)\nHost: pbx.northwind.example\nAsterisk: 22.0\nDatabase: ok\n");
        $zip->addFromString('selftest.txt', "selftest: ALL PASS\ndisk_free: 42%\nsip_capture: active\n");
        $zip->close();
        @chown($zipPath, 'root');
        @chgrp($zipPath, 'www-data');
        @chmod($zipPath, 0640);
        @touch($zipPath, $mtime);
    }
}

// --- registrations (/registrations): AstDB contacts ---
@exec('asterisk -rx "database deltree registrar/contact" 2>/dev/null');
$regContacts = [
    ['1000-webrtc', 'sip:1000-webrtc@198.51.100.14:54312;transport=WS', 'AiPBX WebPhone / Chrome 131'],
    ['1001-sip', 'sip:1001-sip@192.0.2.31:5060;transport=UDP', 'Yealink SIP-T54W 96.86.0.145'],
    ['1001-mob-webrtc', 'sip:1001-mob-webrtc@198.51.100.21:55104;transport=WS', 'AiPBX Mobile / iOS 18.1'],
    ['1002-sip', 'sip:1002-sip@192.0.2.32:5060;transport=UDP', 'Yealink SIP-T46U 108.86.0.45'],
    ['1002-webrtc', 'sip:1002-webrtc@198.51.100.22:55210;transport=WS', 'AiPBX WebPhone / Chrome 131'],
    ['1003-sip', 'sip:1003-sip@192.0.2.33:5060;transport=UDP', 'Grandstream GXP2160 1.0.11.64'],
    ['1004-sip', 'sip:1004-sip@192.0.2.34:5060;transport=TCP', 'Fanvil X5U 2.4.12'],
    ['1004-mob-webrtc', 'sip:1004-mob-webrtc@198.51.100.24:55318;transport=WS', 'AiPBX Mobile / Android 15'],
    ['1005-sip', 'sip:1005-sip@192.0.2.35:5060;transport=TLS', 'snom D785 10.1.141.12'],
    ['1006-webrtc', 'sip:1006-webrtc@198.51.100.26:55402;transport=WS', 'AiPBX WebPhone / Firefox 132'],
    ['1007-mob-webrtc', 'sip:1007-mob-webrtc@198.51.100.27:55490;transport=WS', 'AiPBX Mobile / iOS 18.1'],
];
$expTime = (string) ($now + 3200);
foreach ($regContacts as [$ep, $uri, $ua]) {
    $hash = substr(md5($ep), 0, 10);
    $json = json_encode(['endpoint' => $ep, 'uri' => $uri, 'user_agent' => $ua, 'expiration_time' => $expTime], JSON_UNESCAPED_SLASHES);
    $cliVal = '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], (string) $json) . '"';
    @exec('asterisk -rx ' . escapeshellarg("database put registrar/contact \"{$ep};@{$hash}\" {$cliVal}") . ' 2>/dev/null');
}

// --- other tables: AI TTS history, web widget sessions, advanced config ---
$ins('ai_tts_history', [
    ['provider' => 'openai', 'voice' => 'alloy', 'language' => 'en', 'speed' => 1.00, 'text' => 'Welcome to Northwind Logistics. Please press 1 for Sales or 2 for Support.', 'chars' => 73, 'bytes' => 48200, 'duration_ms' => 4600, 'announcement' => 'custom/welcome', 'created_by' => 1, 'created_at' => $ago(86400 * 2)],
    ['provider' => 'elevenlabs', 'voice' => 'Rachel', 'language' => 'en', 'speed' => 0.95, 'text' => 'All representatives are currently assisting other callers. Please hold.', 'chars' => 71, 'bytes' => 45900, 'duration_ms' => 4200, 'announcement' => 'custom/busy', 'created_by' => 1, 'created_at' => $ago(86400)],
    ['provider' => 'google', 'voice' => 'de-DE-Wavenet-C', 'language' => 'de', 'speed' => 1.00, 'text' => 'Willkommen bei Northwind Logistics. Unsere Geschäftszeiten sind Montag bis Freitag.', 'chars' => 83, 'bytes' => 52100, 'duration_ms' => 5100, 'announcement' => 'custom/menu-de', 'created_by' => 1, 'created_at' => $ago(14400)],
]);
$ins('pbx_web_widget_sessions', [
    ['widget_id' => 1, 'kind' => 'call', 'token' => bin2hex(random_bytes(16)), 'result' => 'claimed', 'ip' => '198.51.100.41', 'origin' => 'https://www.northwind.example', 'user_agent' => 'Mozilla/5.0 Chrome/131.0', 'caller_name' => 'Acme Freight', 'caller_number' => '+15550142', 'created_at' => $ago(5400), 'claimed_at' => $ago(5390)],
    ['widget_id' => 1, 'kind' => 'callback', 'token' => null, 'result' => 'callback', 'ip' => '198.51.100.42', 'origin' => 'https://www.northwind.example', 'user_agent' => 'Mozilla/5.0 Safari/18.0', 'caller_name' => 'Bluebird Retail', 'caller_number' => '+15550155', 'created_at' => $ago(3600), 'claimed_at' => $ago(3540)],
    ['widget_id' => 2, 'kind' => 'call', 'token' => bin2hex(random_bytes(16)), 'result' => 'issued', 'ip' => '203.0.113.88', 'origin' => 'https://shop.northwind.example', 'user_agent' => 'Mozilla/5.0 Firefox/132.0', 'caller_name' => 'Kestrel Foods', 'caller_number' => '+447700900123', 'created_at' => $ago(1200), 'claimed_at' => null],
    ['widget_id' => 3, 'kind' => 'call', 'token' => null, 'result' => 'denied_origin', 'ip' => '192.0.2.77', 'origin' => 'https://untrusted.example', 'user_agent' => 'curl/8.5.0', 'caller_name' => '', 'caller_number' => '', 'created_at' => $ago(900), 'claimed_at' => null],
]);
$ins('pbx_advanced_pjsip', [
    ['config_file' => 'pjsip', 'target' => 'object', 'object_type' => 'endpoint', 'object_name' => '1001-sip', 'options' => "force_rport=yes\nrewrite_contact=yes\nrtp_symmetric=yes", 'note' => 'NAT traversal for desk phone 1001', 'is_active' => 1, 'created_at' => $ago(86400 * 3)],
    ['config_file' => 'pjsip', 'target' => 'trunks', 'object_type' => 'endpoint', 'object_name' => '', 'options' => "tos_audio=ef\ncos_audio=5", 'note' => 'QoS DSCP EF markings for carrier trunks', 'is_active' => 1, 'created_at' => $ago(86400 * 2)],
    ['config_file' => 'voicemail', 'target' => 'object', 'object_type' => '', 'object_name' => 'general', 'options' => 'tz=european', 'note' => '24-hour European timezone in greetings', 'is_active' => 1, 'created_at' => $ago(86400)],
]);

// --- plugins: a few official ones on, and the SMS plugin with conversations ---
foreach (['web-widgets', 'auto-dialer', 'ai-apps', 'ai-tts', 'ai-cloud', 'ai-models', 'ms-teams', 'google-auth'] as $slug) {
    PluginManager::enable($slug, 't');
}
$ins('plg_autodial_campaigns', [
    ['name' => 'Q4 Freight Renewals', 'status' => 'running', 'caller_id' => '+15550100', 'max_concurrent' => 3, 'queue_free_agent_pacing' => 1, 'max_retries' => 2, 'retry_interval_sec' => 300, 'amd_enabled' => 1, 'amd_machine_action' => 'hangup', 'amd_machine_sound' => '', 'dest_type' => 'queue', 'dest_id' => '1'],
    ['name' => 'Winter Schedule Notice', 'status' => 'paused', 'caller_id' => '+15550101', 'max_concurrent' => 2, 'queue_free_agent_pacing' => 1, 'max_retries' => 1, 'retry_interval_sec' => 600, 'amd_enabled' => 1, 'amd_machine_action' => 'hangup', 'amd_machine_sound' => '', 'dest_type' => 'ivr', 'dest_id' => '1'],
]);
$ins('plg_autodial_numbers', [
    ['campaign_id' => 1, 'phone_number' => '+15550142', 'contact_name' => 'Acme Freight', 'status' => 'completed', 'attempts' => 1, 'call_result' => 'answered', 'amd_status' => 'HUMAN', 'duration' => 145, 'last_attempt_at' => $ago(5400)],
    ['campaign_id' => 1, 'phone_number' => '+15550155', 'contact_name' => 'Bluebird Retail', 'status' => 'pending', 'attempts' => 0, 'call_result' => '', 'amd_status' => '', 'duration' => 0, 'last_attempt_at' => null],
    ['campaign_id' => 2, 'phone_number' => '+447700900123', 'contact_name' => 'Kestrel Foods', 'status' => 'pending', 'attempts' => 0, 'call_result' => '', 'amd_status' => '', 'duration' => 0, 'last_attempt_at' => null],
]);
echo "seeded\n";

// --- community plugins from the catalog: SMS with a few conversations ---
setSystemSetting('plugins_community_catalog', '1');
$check(PluginManager::installFromCatalog('sms', 't'), 'sms plugin');
$check(PluginManager::installFromCatalog('provider-templates', 't'), 'provider-templates plugin');
@exec('chown -R www-data:www-data ' . escapeshellarg(PluginManager::customDir()) . ' && chmod -R u+rwX,g+rX,o-rwx ' . escapeshellarg(PluginManager::customDir()));
require_once PluginManager::customDir() . '/sms/lib/Sms.php';
PluginManager::setSetting('sms', 'twilio_account_sid', 'AC0000000000000000000000000000demo');
PluginManager::setSetting('sms', 'twilio_auth_token', 'demo', true);
Sms::saveNumber(0, '+15550100', 'twilio', 'Main', [$uid['1000'] ?? 1, $uid['1001'], $uid['1004']]);
Sms::saveNumber(0, '+15550102', 'twilio', 'Sales', [$uid['1000'] ?? 1, $uid['1002'], $uid['1003']]);
$sms = [[1, '+15550142', 'in', 'Hi, is my order 4471 shipped yet?', 7200], [1, '+15550142', 'out', 'Yes! It left the warehouse this morning, tracking: NW-4471-US.', 7000],
    [1, '+15550142', 'in', 'Great, thank you 🙏', 6900], [1, '+15550155', 'in', 'Can you call me back about the invoice?', 3600],
    [1, '+447700900123', 'in', 'Delivery window tomorrow 9-12 works for us.', 1500], [1, '+447700900123', 'out', 'Booked. See you tomorrow.', 1400],
    [2, '+15550163', 'in', 'Please send the price list for weekly pickups.', 900], [2, '+15550163', 'out', 'Sent to your e-mail, thanks!', 600]];
foreach ($sms as [$n, $peer, $dir, $text, $sec]) {
    $ins('plg_sms_messages', [['number_id' => $n, 'peer' => $peer, 'direction' => $dir, 'body' => $text, 'status' => $dir === 'in' ? 'received' : 'sent', 'is_read' => $sec > 1000 ? 1 : 0, 'created_at' => $ago($sec)]]);
}
Sms::saveContact(1, '+15550142', 'Acme Freight');
Sms::saveContact(1, '+447700900123', 'Kestrel Foods');
Sms::saveContact(2, '+15550163', 'Orchid Labs');
$db->exec('DELETE FROM sys_notices');
$db->exec('DELETE FROM sys_pending_sync');
echo "seeded sms\n";
