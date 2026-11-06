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

// --- call history: 45 calls over 6 days ---
$exts = array_column($people, 0);
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

// --- plugins: a few official ones on, and the SMS plugin with conversations ---
foreach (['web-widgets', 'auto-dialer', 'ai-apps', 'ai-tts', 'ai-cloud'] as $slug) {
    PluginManager::enable($slug, 't');
}
echo "seeded\n";

// --- community plugins from the catalog: SMS with a few conversations ---
setSystemSetting('plugins_community_catalog', '1');
$check(PluginManager::installFromCatalog('sms', 't'), 'sms plugin');
$check(PluginManager::installFromCatalog('provider-templates', 't'), 'provider-templates plugin');
require_once PluginManager::customDir() . '/sms/lib/Sms.php';
PluginManager::setSetting('sms', 'twilio_account_sid', 'AC0000000000000000000000000000demo');
PluginManager::setSetting('sms', 'twilio_auth_token', 'demo', true);
Sms::saveNumber(0, '+15550100', 'twilio', 'Main', [$uid['1001'], $uid['1004']]);
Sms::saveNumber(0, '+15550102', 'twilio', 'Sales', [$uid['1002'], $uid['1003']]);
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
