<?php

declare(strict_types=1);

/**
 * Example setup of a new installation: one of each thing, so a new administrator sees in every
 * menu how it is done (install.sh runs it once, as www-data, on a fresh install only).
 *
 *   php web/bin/seed_examples.php
 *
 * Prints one line per example, then KEY=value lines (the passwords it made) for the summary.
 * A second run does nothing: sys_settings.examples_seeded marks the first one. Everything it makes
 * is an ordinary record the administrator may change or delete.
 */
if (PHP_SAPI !== 'cli') {
    exit(1);
}
require dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/src/asterisk_sync.php';
require_once dirname(__DIR__) . '/src/core/PluginManager.php';
foreach (['UserService', 'ExtensionService', 'QueueService', 'RingGroupService', 'TimeConditionService', 'HolidayCalendarService',
    'ConferenceService', 'MeetingService', 'IVRService', 'RouteService', 'TrunkService', 'FaxSettingsService', 'PhonebookService'] as $s) {
    require_once dirname(__DIR__) . "/src/services/{$s}.php";
}
foreach (['CloudAiService', 'AiKnowledgeService', 'AiAppService', 'BuiltinAiAgentService'] as $s) {
    require_once dirname(__DIR__) . "/src/services/ai/{$s}.php";
}

$db = getDB();
if (DBHelper::fetchColumn("SELECT setting_value FROM sys_settings WHERE setting_key = 'examples_seeded'") === '1') {
    echo "examples: already in place\n";
    exit(0);
}
$admin = $db->query("SELECT id, extension FROM sys_users WHERE username = 'admin'")->fetch(PDO::FETCH_ASSOC);
if (!$admin) {
    fwrite(STDERR, "examples: no admin user\n");
    exit(1);
}
$_SESSION['csrf_token'] = 'examples';
$_SESSION['user_id'] = (int) $admin['id'];
$_SESSION['role'] = 'admin';
$csrf = ['csrf_token' => 'examples'];
$adminExt = (string) $admin['extension'];
$out = [];
$failed = 0;

/** Runs one example; a failure is reported and the others go on. */
$make = function (string $what, callable $fn) use (&$failed): mixed {
    try {
        $r = $fn();
        if (is_array($r) && array_key_exists('success', $r) && !$r['success']) {
            throw new RuntimeException((string) ($r['error'] ?? $r['message'] ?? 'failed'));
        }
        if (is_array($r) && array_key_exists('ok', $r) && !$r['ok']) {
            throw new RuntimeException(json_encode($r['errors'] ?? $r) ?: 'failed');
        }
        echo "  + {$what}\n";
        return $r;
    } catch (Throwable $e) {
        $failed++;
        echo "  ! {$what}: " . $e->getMessage() . "\n";
        return null;
    }
};
$idOf = fn(string $table, string $where, array $args = []) => (string) DBHelper::fetchColumn("SELECT id FROM {$table} WHERE {$where} ORDER BY id DESC LIMIT 1", $args);

// --- People -------------------------------------------------------------------------------------
$userPass = UserService::generateReadablePassword();
$userSip = bin2hex(random_bytes(8));
$make('User "Alex Morgan" (role user, extension 1001)', fn() => UserService::saveUser($csrf + [
    'username' => 'alex', 'full_name' => 'Alex Morgan', 'role' => 'user', 'extension' => '1001',
    'password' => $userPass, 'sip_password' => $userSip, 'email' => '', 'skip_invitation' => 1,
]));
$out['EXAMPLE_USER_PASS'] = $userPass;
$out['EXAMPLE_USER_SIP_PASS'] = $userSip;

$faxPass = UserService::generateReadablePassword();
$make('Fax account "Office Fax" (fax number 5550199, login "fax")', fn() => FaxSettingsService::saveAccount($csrf + [
    'did_id' => '', 'department_name' => 'Office Fax', 'did_extension' => '5550199', 'create_login' => 1,
    'username' => 'fax', 'password' => $faxPass, 'role' => 'fax_user', 'notification_email' => '', 'is_active' => 1,
]));
$out['EXAMPLE_FAX_PASS'] = $faxPass;

// --- Call handling ------------------------------------------------------------------------------
$make('Ring group "Sales" (600)', fn() => RingGroupService::saveRingGroup($csrf + [
    'group_number' => '600', 'name' => 'Sales', 'numbers_list' => "{$adminExt}\n1001", 'ring_strategy' => 'ringall',
    'ring_timeout' => 20, 'fallback_dest_type' => 'extension', 'fallback_dest_id' => $adminExt, 'is_active' => 1,
]));
$salesId = $idOf('pbx_ring_groups', "group_number = '600'");

$make('Queue "Support"', fn() => QueueService::saveQueue($csrf + [
    'queue_name' => 'support', 'title' => 'Support', 'strategy' => 'ringall', 'timeout' => 20, 'max_wait_seconds' => 300,
    'member_mode' => [$adminExt => 'static', '1001' => 'static'], 'static_members' => [$adminExt, '1001'], 'members' => [$adminExt, '1001'],
    'joinempty' => 'yes', 'leavewhenempty' => 'no', 'fallback_action' => 'hangup',
]));
$supportId = $idOf('pbx_queues', "queue_name = 'support'");

$make('Announcement "We are closed"', function () use ($db) {
    $db->prepare("INSERT INTO pbx_announcements (title, audio_file, post_dest_type, post_dest_id, is_active) VALUES ('We are closed', 'custom/example-closed', 'hangup', 'hangup', 1)")->execute();
    return true;
});
$closedId = $idOf('pbx_announcements', "title = 'We are closed'");

$make('Holiday calendar "Public Holidays" (empty: add your dates)', fn() => HolidayCalendarService::save($csrf + ['name' => 'Public Holidays', 'dates' => ''], null));
$holidaysId = $idOf('pbx_holiday_calendars', "name = 'Public Holidays'");

$make('Time group "Business Hours" (Mon–Fri 09:00–18:00)', fn() => TimeConditionService::saveTimeGroup($csrf + [
    'title' => 'Business Hours', 'time_start' => '09:00', 'time_end' => '18:00', 'days' => ['1', '2', '3', '4', '5'],
    'holiday_calendars' => $holidaysId !== '' ? [$holidaysId] : [], 'is_active' => 1,
]));
$hoursId = $idOf('pbx_time_groups', "title = 'Business Hours'");

$make('Conference "Team Meeting" (8000)', fn() => ConferenceService::saveConference($csrf + ['room_number' => '8000', 'title' => 'Team Meeting', 'is_active' => 1]));

// --- AI: an empty cloud account, a knowledge collection and the Virtual Receptionist -------------
$cloudId = $make('Cloud AI account "Example Cloud AI" (OpenAI, add your API key)', fn() => CloudAiService::addAccount('openai', 'Example Cloud AI', []));
$cloudId = is_string($cloudId) ? $cloudId : 'openai';

$collectionId = 0;
if (PluginManager::isEnabled('ai-knowledge')) {
    $dir = dirname(__DIR__, 2) . '/db/examples';
    $text = trim((string) file_get_contents("{$dir}/company-info.txt")) . "\n\n" . trim((string) file_get_contents("{$dir}/lookup-rules.txt"));
    $collectionId = (int) $make('Knowledge base "Company Info"', fn() => AiKnowledgeService::saveWithText(0, 'Company Info',
        'Opening hours, products, prices and lookup rules of Example Company.', $text, '', ['*']));
}

// The models are the empty cloud account's: the agent works once its key is in, and until then
// the call goes to the operator (the fallback). Built as the AI Agent page saves it, because the
// page only offers accounts that already have a key.
$agentId = 0;
if ($cloudId !== '') {
    $greeting = 'Hello {caller_name}, thank you for calling Example Company. How can I help you?';
    $agentId = (int) $make('AI Agent "Virtual Receptionist"', fn() => AiAppService::save([
        'id' => 0, 'title' => 'Virtual Receptionist', 'internal_number' => '', 'app_type' => AiAppService::BUILTIN_AGENT,
        'dest_type' => 'extension', 'dest_id' => $adminExt, 'is_active' => 1, 'model_id' => "cloud:{$cloudId}", 'text_template' => $greeting,
        'agent_config' => (static function () use ($greeting, $salesId, $adminExt, $collectionId, $cloudId): array {
            // Every tool of the agent is written into its instructions, as the AI Agent page saves them.
            $base = $collectionId > 0 ? (string) (AiKnowledgeService::collection($collectionId)['name'] ?? '') : '';
            $instructions = "You are the virtual receptionist of Example Company. Answer briefly and politely.\n"
                . "{greeting, 1, en, {$greeting}}\n"
                . ($salesId !== '' ? "Callers who want to order or ask for prices go to {transfer, sales, group:{$salesId}}.\n" : '')
                . "Callers who ask for a person go to {transfer, operator, extension:{$adminExt}}.\n"
                . ($base !== '' ? "Opening hours, products and prices are in {knowledge, {$base}}.\n" : '')
                . "{waiting, One moment, please, I am checking.}";
            $p = BuiltinAiAgentService::parseInstructions($instructions);
            return ['mode' => 'cascade', 'conversation' => true, 'barge_in' => true, 'listen_seconds' => 10, 'retries' => 0,
                'automatic_announcements' => false, 'max_turns' => 12, 'greeting' => $greeting, 'stt_lang' => 'en',
                'stt_model' => "cloud:{$cloudId}", 'understand' => "cloud:{$cloudId}",
                'instructions' => $instructions, 'instructions_model' => $p['text'], 'transfers' => $p['transfers'],
                'greetings_by_lang' => $p['greetings_by_lang'], 'greetings' => array_column($p['greetings_by_lang']['en'] ?? [], 'text'),
                'waiting_phrases' => $p['waiting_phrases'], 'knowledge_collections' => $p['knowledge_collections'],
                'http_tools' => '', 'db_queries' => [], 'db_lookups' => [], 'pbx_tools' => []];
        })(),
    ], true));
}

// --- Main menu, time condition, inbound numbers -------------------------------------------------
$make('IVR "Main Menu"', fn() => IVRService::saveIVR($csrf + [
    'title' => 'Main Menu', 'prompt_file' => 'custom/example-main-menu', 'timeout_seconds' => 5, 'max_failures' => 3,
    'timeout_dest_type' => 'extension', 'timeout_dest_id' => $adminExt, 'invalid_dest_type' => 'extension', 'invalid_dest_id' => $adminExt,
    'allow_direct_dial' => 1, 'is_active' => 1,
]));
$ivrId = $idOf('pbx_ivrs', "title = 'Main Menu'");
foreach (['1' => ['ring_group', $salesId, 'Sales'], '2' => ['queue', $supportId, 'Support'],
    '3' => ['builtin_ai_agent', (string) $agentId, 'Virtual Receptionist'], '0' => ['extension', $adminExt, 'Operator']] as $digit => [$type, $id, $label]) {
    if ($ivrId !== '' && $id !== '' && $id !== '0') {
        $make("Main Menu key {$digit} → {$label}", fn() => IVRService::saveIVREntry($csrf + ['ivr_id' => $ivrId, 'digit' => (string) $digit, 'dest_type' => $type, 'dest_id' => $id]));
    }
}

$make('Time condition "Office Hours" (open → Main Menu, closed → We are closed)', fn() => TimeConditionService::saveTimeCondition($csrf + [
    'title' => 'Office Hours', 'time_group_id' => $hoursId, 'match_dest_type' => 'ivr', 'match_dest_id' => $ivrId,
    'nomatch_dest_type' => 'announcement', 'nomatch_dest_id' => $closedId, 'is_active' => 1,
]));
$officeHoursId = $idOf('pbx_time_conditions', "title = 'Office Hours'");

$make('Inbound number 5550100 "Main Line" → Office Hours', fn() => RouteService::saveDIDRoute($csrf + [
    'did_number' => '5550100', 'title' => 'Main Line', 'dest_type' => 'time_condition', 'dest_id' => $officeHoursId, 'is_active' => 1,
]));
$make('Inbound number 5550199 "Fax Line" → Office Fax', fn() => RouteService::saveDIDRoute($csrf + [
    'did_number' => '5550199', 'title' => 'Fax Line', 'dest_type' => 'fax', 'dest_id' => '5550199', 'is_active' => 1,
]));

// --- Carrier (inactive until filled in) ---------------------------------------------------------
$make('Trunk "Example SIP Provider" (inactive: fill in your provider)', fn() => TrunkService::saveTrunk($csrf + [
    'trunk_name' => 'example_provider', 'title' => 'Example SIP Provider', 'ip_address' => 'sip.example.com', 'port' => 5060,
    'transport' => 'udp', 'is_active' => 0, 'registration_enabled' => 0, 'auth_username' => '', 'auth_password' => '',
]));
$make('Outbound route "Outbound calls (example)"', fn() => RouteService::saveOutboundRoute($csrf + [
    'route_name' => 'Outbound calls (example)', 'match_pattern' => '_0X.', 'trunk_name' => ['example_provider'],
]));

// --- Phonebook, chat, video meeting -------------------------------------------------------------
$make('Phonebook contact "Example Customer"', fn() => PhonebookService::save(0, [
    'display_name' => 'Example Customer', 'first_name' => 'Jamie', 'last_name' => 'Lee', 'company' => 'Example Customer Ltd.',
    'phone_work' => '5550123', 'email' => 'jamie@example.com',
]));

$make('Group chat "General" (admin and Alex)', function () use ($db, $adminExt) {
    $db->beginTransaction();
    $db->prepare("INSERT INTO chat_conversations (type, title, avatar_url, description, created_by, created_at, updated_at, is_deleted)
        VALUES ('group', 'General', '', 'Example group chat for everyone', ?, NOW(), NOW(), 0)")->execute([$adminExt]);
    $conv = (int) $db->lastInsertId();
    $db->prepare("INSERT INTO chat_participants (conversation_id, extension, role, joined_at) VALUES (?, ?, 'admin', NOW())")->execute([$conv, $adminExt]);
    $db->prepare("INSERT INTO chat_participants (conversation_id, extension, role, added_by, joined_at) VALUES (?, '1001', 'member', ?, NOW())")->execute([$conv, $adminExt]);
    $db->prepare("INSERT INTO chat_messages (conversation_id, sender_ext, msg_type, message, created_at) VALUES (?, ?, 'text', ?, NOW())")
        ->execute([$conv, $adminExt, 'Welcome to AiPBX! This is an example group chat.']);
    $db->commit();
    return true;
});

$start = strtotime('next monday 10:00');
$make('Video meeting "Weekly Video Meeting" (next Monday 10:00)', fn() => MeetingService::saveMeeting([
    'title' => 'Weekly Video Meeting', 'description' => 'Example video meeting: open it, then share the link.',
    'scheduled_start' => date('Y-m-d H:i', $start), 'scheduled_end' => date('Y-m-d H:i', $start + 3600),
    'video_mode' => 'sfu', 'max_participants' => 10, 'allow_guests' => 1,
], (int) $_SESSION['user_id']));

// --- Apply --------------------------------------------------------------------------------------
$db->prepare("REPLACE INTO sys_settings (setting_key, setting_value) VALUES ('examples_seeded', '1')")->execute();
try {
    applyPendingSync((int) $_SESSION['user_id']);
    echo "  + Applied\n";
} catch (Throwable $e) {
    $failed++;
    echo "  ! Apply: " . $e->getMessage() . "\n";
}
foreach ($out as $k => $v) {
    echo "{$k}={$v}\n";
}
exit($failed > 0 ? 2 : 0);
