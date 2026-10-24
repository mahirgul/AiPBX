<?php
/**
 * Sidebar menu definition, rendered by templates/sidebar.php.
 *
 * Group:  id, icon (full class list), icon_style?, label, items,
 *         open_on? (extra pages that open the group without an item of their own)
 * Item:   href, icon, icon_style?, label, title? (tooltip, defaults to label),
 *         show (bool), pages (basenames that mark it active), uri? (request-URI
 *         part that also marks it active)
 * label/title: a translation key, or [key, default].
 *
 * A group is shown when at least one of its items is shown, and opened when
 * one of its items (or an open_on page) is active.
 *
 * Expects $role and $user (set by app_header.php).
 */
$can = fn(string $module) => hasModulePermission($module, 'view');
$isAdmin = ($role ?? '') === 'admin';

// The call-center board is also open to queue supervisors without the
// cc_board permission.
$canSuperviseQueues = !empty($user['can_view_queue_monitor'])
    || $can('queue_monitor')
    || CcBoardRepository::supervisesAnyQueue((string) ($user['extension'] ?? ''));

require_once dirname(__DIR__) . '/src/core/PluginManager.php';
$pluginOn = fn(string $slug) => PluginManager::isEnabled($slug);

return [
    [
        'id' => 'dashboard', 'icon' => 'fas fa-chart-pie u-primary', 'label' => 'sidebar.group_dashboard',
        'open_on' => ['index.php'],
        'items' => [
            ['href' => '/dashboard', 'icon' => 'fas fa-tachometer-alt', 'label' => 'sidebar.item_dashboard_overview', 'show' => $can('dashboard'), 'pages' => ['dashboard.php']],
            ['href' => '/my-phone', 'icon' => 'fas fa-phone-volume', 'label' => 'sidebar.item_my_phone', 'show' => $can('my_phone'), 'pages' => ['my_phone.php']],
            ['href' => '/chat', 'icon' => 'fas fa-comments', 'label' => 'sidebar.item_chat', 'show' => $can('chat'), 'pages' => ['chat.php']],
            ['href' => '/cdr-reports', 'icon' => 'fas fa-file-audio', 'label' => 'sidebar.item_cdr_reports', 'show' => $can('cdr_reports') || $can('cc_reports'), 'pages' => ['cdr_reports.php', 'reports.php']],
            ['href' => '/call-quality', 'icon' => 'fas fa-chart-line', 'label' => 'sidebar.item_call_quality', 'show' => $isAdmin, 'pages' => ['call_quality.php']],
        ],
    ],
    [
        'id' => 'admin', 'icon' => 'fas fa-user-shield u-warning', 'label' => 'sidebar.group_system',
        'open_on' => ['fax_settings.php'],
        'items' => [
            ['href' => '/system-users', 'icon' => 'fas fa-users-cog', 'label' => 'sidebar.item_system_users', 'show' => $can('system_users'), 'pages' => ['system_users.php']],
            ['href' => '/roles', 'icon' => 'fas fa-user-shield', 'label' => 'sidebar.item_roles', 'show' => $can('roles'), 'pages' => ['roles.php']],
            ['href' => '/asterisk-settings', 'icon' => 'fas fa-cogs', 'label' => 'sidebar.item_asterisk_settings', 'show' => $can('asterisk_settings'), 'pages' => ['asterisk_settings.php']],
            ['href' => '/brand-settings', 'icon' => 'fas fa-palette', 'label' => 'sidebar.item_brand_settings', 'show' => $can('brand_settings'), 'pages' => ['brand_settings.php']],
            ['href' => '/push-settings', 'icon' => 'fas fa-bell', 'label' => 'sidebar.item_push_settings', 'show' => $can('push_settings'), 'pages' => ['push_settings.php']],
            ['href' => '/mail-settings', 'icon' => 'fas fa-envelope-open-text', 'label' => 'sidebar.item_mail_settings', 'show' => $can('mail_settings'), 'pages' => ['mail_settings.php']],
            ['href' => '/fax-mail-settings', 'icon' => 'fas fa-paper-plane', 'label' => 'sidebar.item_fax_mail_settings', 'show' => $can('fax_mail_settings'), 'pages' => ['fax_mail_settings.php']],
            ['href' => '/file-storage', 'icon' => 'fas fa-hard-drive', 'label' => 'sidebar.item_file_storage', 'show' => $can('file_storage'), 'pages' => ['file_storage.php']],
            ['href' => '/pending-sync', 'icon' => 'fas fa-cloud-upload-alt', 'label' => 'sidebar.item_pending_sync', 'show' => $can('pending_sync'), 'pages' => ['pending_sync.php']],
            ['href' => '/system-update', 'icon' => 'fas fa-cloud-arrow-down', 'label' => 'sidebar.item_system_update', 'show' => $isAdmin, 'pages' => ['system_update.php']],
            ['href' => '/scheduled-tasks', 'icon' => 'fas fa-calendar-check', 'label' => 'sidebar.item_scheduled_tasks', 'show' => $isAdmin, 'pages' => ['scheduled_tasks.php']],
            ['href' => '/health', 'icon' => 'fas fa-heart-pulse', 'label' => 'sidebar.item_health', 'show' => $isAdmin, 'pages' => ['health.php']],
            ['href' => '/help', 'icon' => 'fas fa-book', 'label' => 'sidebar.item_help', 'show' => $isAdmin, 'pages' => ['help.php']],
        ],
    ],
    [
        'id' => 'trunks', 'icon' => 'fas fa-network-wired u-primary', 'label' => 'sidebar.group_trunks',
        'items' => [
            ['href' => '/trunks', 'icon' => 'fas fa-server', 'label' => 'sidebar.item_trunks', 'show' => $can('trunks'), 'pages' => ['trunks.php']],
            ['href' => '/did-routes', 'icon' => 'fas fa-route', 'label' => 'sidebar.item_did_routes', 'show' => $can('did_routes'), 'pages' => ['did_routes.php']],
            ['href' => '/outbound-routes', 'icon' => 'fas fa-sign-out-alt', 'label' => 'sidebar.item_outbound_routes', 'show' => $can('outbound_routes'), 'pages' => ['outbound_routes.php']],
            ['href' => '/dialplan-rules', 'icon' => 'fas fa-sliders-h', 'label' => 'sidebar.item_dialplan_rules', 'show' => $can('outbound_routes'), 'pages' => ['dialplan_rules.php']],
            ['href' => '/dial-permissions', 'icon' => 'fas fa-shield-alt', 'label' => 'sidebar.item_dial_permissions', 'title' => 'sidebar.item_dial_permissions', 'show' => $can('dial_permissions'), 'pages' => ['dial_permissions.php'], 'uri' => '/dial-permissions'],
        ],
    ],
    [
        'id' => 'pbx', 'icon' => 'fas fa-phone-alt u-primary', 'label' => 'sidebar.group_pbx',
        'items' => [
            ['href' => '/extensions', 'icon' => 'fas fa-phone-square-alt', 'label' => 'sidebar.item_extensions', 'show' => $can('extensions'), 'pages' => ['extensions.php', 'users.php']],
            ['href' => '/ivrs', 'icon' => 'fas fa-microphone-alt', 'label' => 'sidebar.item_ivrs', 'show' => $can('ivrs'), 'pages' => ['ivrs.php']],
            ['href' => '/time-conditions', 'icon' => 'fas fa-clock', 'label' => 'sidebar.item_time_conditions', 'show' => $can('time_conditions'), 'pages' => ['time_conditions.php']],
            ['href' => '/ring-groups', 'icon' => 'fas fa-users', 'label' => 'sidebar.item_ring_groups', 'show' => $can('ring_groups'), 'pages' => ['ring_groups.php'], 'uri' => '/ring-groups'],
            ['href' => '/queues', 'icon' => 'fas fa-layer-group', 'label' => 'sidebar.item_queues', 'show' => $can('queues'), 'pages' => ['queues.php']],
            ['href' => '/conferences', 'icon' => 'fas fa-users-rectangle', 'label' => 'sidebar.item_conferences', 'show' => $can('conferences'), 'pages' => ['conferences.php'], 'uri' => '/conferences'],
            ['href' => '/boss-secretary', 'icon' => 'fas fa-user-tie', 'label' => 'sidebar.item_boss_secretary', 'show' => $can('boss_secretary'), 'pages' => ['boss_secretary.php'], 'uri' => '/boss-secretary'],
            ['href' => '/end-call', 'icon' => 'fas fa-phone-slash', 'label' => 'sidebar.item_end_call', 'show' => $can('end_call'), 'pages' => ['end_call.php']],
            ['href' => '/sounds', 'icon' => 'fas fa-music', 'label' => 'sidebar.item_sounds', 'show' => $can('sounds'), 'pages' => ['sounds.php']],
            ['href' => '/feature-codes', 'icon' => 'fas fa-hashtag', 'label' => 'sidebar.item_feature_codes', 'title' => 'sidebar.item_feature_codes_tooltip', 'show' => $can('feature_codes'), 'pages' => ['feature_codes.php']],
            ['href' => '/feature-codes-status', 'icon' => 'fas fa-list-check', 'label' => 'sidebar.item_feature_codes_status', 'show' => $can('feature_codes'), 'pages' => ['feature_codes_status.php']],
        ],
    ],
    [
        // Network and desk phones together: the address phones are given, how they
        // find it (DHCP/TFTP/FTP), their files and their logs (roadmap 14).
        'id' => 'network', 'icon' => 'fas fa-ethernet u-primary', 'label' => 'sidebar.group_network',
        'items' => [
            ['href' => '/network-settings', 'icon' => 'fas fa-network-wired', 'label' => 'sidebar.item_network_settings', 'show' => $isAdmin, 'pages' => ['network_settings.php']],
            ['href' => '/network-services', 'icon' => 'fas fa-ethernet', 'label' => 'sidebar.item_network_services', 'show' => $isAdmin, 'pages' => ['network_services.php']],
            ['href' => '/phones', 'icon' => 'fas fa-phone', 'label' => 'sidebar.item_phones', 'show' => $can('phones'), 'pages' => ['phones.php', 'phone_keys.php']],
            ['href' => '/phone-logs', 'icon' => 'fas fa-scroll', 'label' => 'sidebar.item_phone_logs', 'show' => $isAdmin, 'pages' => ['phone_logs.php']],
        ],
    ],
    [
        'id' => 'cc', 'icon' => 'fas fa-headset u-success', 'label' => 'sidebar.group_cc',
        'items' => [
            ['href' => '/cc-board', 'icon' => 'fas fa-chart-line u-primary', 'label' => 'sidebar.item_cc_board_unified', 'title' => 'sidebar.item_cc_board_unified_tooltip', 'show' => $canSuperviseQueues || $can('cc_board'), 'pages' => ['cc_board.php', 'cc_supervisor.php']],
            ['href' => '/cc-agent', 'icon' => 'fas fa-phone-alt', 'label' => 'sidebar.item_cc_agent', 'show' => $can('cc_agent'), 'pages' => ['cc_agent.php']],
            ['href' => '/queue-reports', 'icon' => 'fas fa-chart-column', 'label' => 'sidebar.item_queue_reports', 'show' => $can('queue_reports'), 'pages' => ['queue_reports.php']],
            ['href' => '/pause-reports', 'icon' => 'fas fa-coffee', 'label' => 'sidebar.item_pause_reports', 'show' => $can('pause_reports'), 'pages' => ['pause_reports.php']],
            ['href' => '/queue-logs', 'icon' => 'fas fa-list-alt', 'label' => 'sidebar.item_queue_logs', 'show' => $can('queue_logs'), 'pages' => ['queue_logs.php']],
        ],
    ],
    [
        'id' => 'fax', 'icon' => 'fas fa-fax u-primary', 'label' => 'sidebar.group_fax',
        'items' => [
            ['href' => '/fax-inbox', 'icon' => 'fas fa-inbox', 'label' => 'sidebar.item_fax_inbox', 'show' => $can('fax_inbox'), 'pages' => ['fax_inbox.php']],
            ['href' => '/fax-send', 'icon' => 'fas fa-paper-plane', 'label' => 'sidebar.item_fax_send', 'show' => $can('fax_send'), 'pages' => ['fax_send.php']],
            ['href' => '/fax-sent', 'icon' => 'fas fa-history', 'label' => 'sidebar.item_fax_sent', 'show' => $can('fax_sent'), 'pages' => ['fax_sent.php']],
        ],
    ],
    [
        'id' => 'ai', 'icon' => 'fas fa-wand-magic-sparkles', 'icon_style' => 'color: var(--purple);', 'label' => 'sidebar.group_ai',
        'items' => [
            ['href' => '/ai-tts', 'icon' => 'fas fa-comment-dots', 'label' => 'sidebar.item_ai_tts', 'show' => $can('ai_tts') && $pluginOn('ai-tts'), 'pages' => ['ai_tts.php']],
            ['href' => '/assistant', 'icon' => 'fas fa-wand-magic-sparkles', 'label' => 'sidebar.item_assistant', 'show' => $isAdmin && $pluginOn('ai-assistant'), 'pages' => ['assistant.php']],
            ['href' => '/ai-apps', 'icon' => 'fas fa-robot', 'label' => 'sidebar.item_ai_apps', 'show' => $isAdmin && $pluginOn('ai-apps'), 'pages' => ['ai_apps.php']],
            ['href' => '/ai-models', 'icon' => 'fas fa-microchip', 'label' => 'sidebar.item_ai_models', 'show' => $isAdmin && $pluginOn('ai-models'), 'pages' => ['ai_models.php']],
            ['href' => '/ai-cloud', 'icon' => 'fas fa-cloud', 'label' => 'sidebar.item_ai_cloud', 'show' => $isAdmin && $pluginOn('ai-cloud'), 'pages' => ['ai_cloud.php']],
            ...PluginManager::dynamicSidebarItems($isAdmin, 'ai'),
        ],
    ],
    [
        'id' => 'integrations', 'icon' => 'fas fa-plug', 'icon_style' => 'color: #3b82f6;', 'label' => 'sidebar.group_integrations',
        'items' => [
            ['href' => '/plugins', 'icon' => 'fas fa-puzzle-piece', 'label' => 'sidebar.item_plugins', 'show' => $isAdmin, 'pages' => ['plugins.php']],
            ['href' => '/api-keys', 'icon' => 'fas fa-key', 'label' => 'sidebar.item_api_keys', 'show' => $isAdmin, 'pages' => ['api_keys.php'], 'uri' => '/api-keys'],
            ['href' => '/webhooks', 'icon' => 'fas fa-satellite-dish', 'label' => 'sidebar.item_webhooks', 'show' => $isAdmin, 'pages' => ['webhooks.php'], 'uri' => '/webhooks'],
            ['href' => '/external-dbs', 'icon' => 'fas fa-database', 'label' => 'sidebar.item_external_dbs', 'show' => $isAdmin, 'pages' => ['external_dbs.php'], 'uri' => '/external-dbs'],
            ['href' => '/google-integration', 'icon' => 'fab fa-google', 'icon_style' => 'color: #ea4335;', 'label' => 'sidebar.item_google_integration', 'show' => $isAdmin && $pluginOn('google-auth'), 'pages' => ['google_integration.php'], 'uri' => '/google-integration'],
            ['href' => '/ms-teams', 'icon' => 'fab fa-windows', 'icon_style' => 'color: #6264a7;', 'label' => 'sidebar.item_ms_teams', 'show' => $can('ms_teams') && $pluginOn('ms-teams'), 'pages' => ['ms_teams.php']],
            ['href' => '/web-widgets', 'icon' => 'fas fa-headset', 'label' => 'sidebar.item_web_widgets', 'show' => $can('web_widgets') && $pluginOn('web-widgets'), 'pages' => ['web_widgets.php']],
            ...PluginManager::dynamicSidebarItems($isAdmin, 'integrations'),
        ],
    ],
    [
        // Firewall/fail2ban/certificates are ALWAYS admin-only (auth.php
        // circuit breaker), independent of the role permission matrix.
        'id' => 'security', 'icon' => 'fas fa-shield-halved u-danger', 'label' => 'sidebar.group_security',
        'items' => [
            ['href' => '/firewall', 'icon' => 'fas fa-fire', 'label' => 'sidebar.item_firewall', 'show' => $isAdmin, 'pages' => ['firewall.php']],
            ['href' => '/fail2ban', 'icon' => 'fas fa-user-shield', 'label' => 'sidebar.item_fail2ban', 'show' => $isAdmin, 'pages' => ['fail2ban.php']],
            ['href' => '/certificates', 'icon' => 'fas fa-certificate', 'label' => 'sidebar.item_certificates', 'show' => $isAdmin, 'pages' => ['certificates.php']],
            ['href' => '/privacy', 'icon' => 'fas fa-user-lock', 'label' => 'sidebar.item_privacy', 'show' => $isAdmin, 'pages' => ['privacy.php']],
            ['href' => '/audit-log', 'icon' => 'fas fa-shield-alt', 'label' => 'sidebar.item_audit_log', 'show' => $can('audit_log'), 'pages' => ['audit_log.php']],
        ],
    ],
];
