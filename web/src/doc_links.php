<?php
/**
 * The documentation behind each page's help box: the page's module (the `module` of its route, without .php)
 * => docs/<file>[#heading anchor]. uiHelpBoxClose() turns it into a link: administrators open Help on this
 * server (the docs of the installed version, without internet), other users the same page on aipbx.bid.
 * tests/unit/DocLinksTest.php checks that every file and heading exists.
 */
const DOC_LINKS = [
    'advanced_config' => 'advanced-config',
    'agi_scripts' => 'agi-scripts',
    'ai_assistant' => 'assistant',
    'assistant' => 'assistant',
    'ai_call_analysis' => 'cloud-ai#call-quality-review',
    'ai_cloud' => 'cloud-ai',
    'ai_knowledge' => 'assistant#knowledge-base-and-shortcuts',
    'ai_local' => 'local-ai',
    'ai_tts' => 'cloud-tts',
    'ai_voicemail' => 'ai-voicemail',
    'builtin_ai_agent' => 'ai-apps#built-in-ai-agent',
    'api_keys' => 'rest-api-v1',
    'asterisk_logs' => 'troubleshooting',
    'asterisk_settings' => 'pbx-settings',
    'audit_log' => 'security',
    'backup' => 'backup-and-rollback',
    'blacklist' => 'blacklist',
    'boss_secretary' => 'extensions',
    'brand_settings' => 'portal',
    'call_modifiers' => 'call-modifiers',
    'call_quality' => 'call-quality',
    'cc_agent' => 'queues#agent-workflow',
    'cc_board' => 'queues#agent-workflow',
    'cc_supervisor' => 'queues#agent-workflow',
    'cdr_reports' => 'reports#call-reports',
    'certificates' => 'certificates',
    'conferences' => 'meetings',
    'connections' => 'connections',
    'custom_configs' => 'advanced-config',
    'custom_dialplan' => 'custom-dialplan',
    'dashboard' => 'portal#the-dashboard',
    'dial_permissions' => 'security#toll-fraud',
    'dialplan_rules' => 'call-normalization',
    'did_routes' => 'inbound-routes',
    'extensions' => 'extensions',
    'external_dbs' => 'external-databases',
    'fail2ban' => 'security#fail2ban',
    'fax_inbox' => 'fax-users',
    'fax_mail_settings' => 'fax-users',
    'fax_send' => 'fax-users',
    'fax_sent' => 'fax-users',
    'fax_settings' => 'fax-users',
    'file_storage' => 'file-storage',
    'firewall' => 'security#firewall',
    'google_integration' => 'sso',
    'health' => 'health',
    'inbound_normalization' => 'number-normalization#incoming-call',
    'ivr_data_steps' => 'dynamic-routing',
    'mail_settings' => 'mail',
    'meetings' => 'meetings',
    'my_phone' => 'users-and-roles#my-phone',
    'network_services' => 'network-services',
    'network_settings' => 'network-settings',
    'outbound_routes' => 'outbound-routes',
    'parking' => 'parking',
    'pause_reports' => 'queues',
    'pending_sync' => 'portal#saving-and-applying-changes',
    'phonebook' => 'phonebook',
    'phone_keys' => 'phones#key-layout',
    'phone_logs' => 'phones#logging-and-protection',
    'phone_regions' => 'phones#regions',
    'phones' => 'phones',
    'plugins' => 'plugin-development',
    'privacy' => 'privacy',
    'queue_logs' => 'reports#queue-report-centre',
    'queue_reports' => 'reports#queue-report-centre',
    'queues' => 'queues',
    'reverse_proxy' => 'reverse-proxy',
    'roles' => 'users-and-roles#built-in-roles',
    'scheduled_tasks' => 'scheduled-tasks',
    'security' => 'security',
    'sign_in_settings' => 'sso',
    'sip_capture_settings' => 'signaling',
    'sip_live' => 'signaling',
    'sip_traffic' => 'signaling',
    'sounds' => 'sounds',
    'system_update' => 'backup-and-rollback',
    'system_users' => 'users-and-roles',
    'time_conditions' => 'time-conditions',
    'transports' => 'transports',
    'trunks' => 'trunks',
    'vpn_client' => 'vpn',
    'vpn_server' => 'vpn',
    'webhooks' => 'rest-api-v1#outbound-webhooks-x-aipbx-signature-256',
    'web_widgets' => 'web-widgets',
];

/** The link to the page's documentation, or '' when the page has none. */
function uiDocLink(?string $page = null): string {
    $page ??= basename((string) ($_SERVER['PHP_SELF'] ?? ''), '.php');
    $target = DOC_LINKS[$page] ?? '';
    if ($target === '') {
        return '';
    }
    [$doc, $anchor] = array_pad(explode('#', $target, 2), 2, '');
    $hash = $anchor !== '' ? '#' . $anchor : '';
    $admin = ($_SESSION['user_role'] ?? '') === 'admin';
    $url = $admin ? '/help?doc=' . rawurlencode($doc) . $hash : 'https://aipbx.bid/docs/' . rawurlencode($doc) . $hash;
    return '<p class="u-mt-10 u-mb-0"><a href="' . htmlspecialchars($url, ENT_QUOTES) . '"'
        . ($admin ? '' : ' target="_blank" rel="noopener"') . '><i class="fas fa-book"></i> '
        . htmlspecialchars(t('help.doc_link'), ENT_QUOTES) . '</a></p>';
}
