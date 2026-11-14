<?php
require_once __DIR__ . '/config.php';

function requireLogin() {
    if (!isset($_SESSION['user_id'])) {
        header('Location: /login');
        exit;
    }

    // There was no application-level idle timeout — a session could stay valid
    // until the browser closed (cookie lifetime=0) or PHP's unreliable,
    // probabilistic GC kicked in (a risk on shared computers, found in the
    // 2026-08-21 audit). The session ends after 60 minutes of inactivity;
    // every request refreshes last_activity (pages doing AJAX polling —
    // cc_agent etc. — therefore never time out while "in use", exactly the
    // desired behaviour).
    if (!_isSessionActivityFresh()) {
        $_SESSION = [];
        session_destroy();
        header('Location: /login?timeout=1');
        exit;
    }
    if (!_isSessionEpochCurrent()) {
        $_SESSION = [];
        session_destroy();
        header('Location: /login');
        exit;
    }
    $_SESSION['last_activity'] = time();
    _touchLastSeen($_SESSION['user_id']);
}

/**
 * Web sessions follow sys_users.token_epoch, which goes up when a password is
 * changed: without this, changing the password of a stolen account left the
 * thief's browser signed in. A session is stamped on its first request, so a
 * session from before this check is not thrown out, and the stamp names the user
 * because signing in as someone else keeps the same session data. A disabled or
 * removed account loses its session here too. The role is read again on every
 * request: an admin turned into a user kept admin rights until signing out.
 */
function _isSessionEpochCurrent(): bool {
    $userId = (int) ($_SESSION['user_id'] ?? 0);
    if ($userId <= 0) {
        return false;
    }
    $stmt = getDB()->prepare('SELECT token_epoch, is_active, is_device, role FROM sys_users WHERE id = ?');
    $stmt->execute([$userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    // A device-only extension is no account, however the session came to be.
    if (!$row || empty($row['is_active']) || !empty($row['is_device'])) {
        return false;
    }
    if (!(PHP_SAPI === 'cli' && defined('AIPBX_SMOKE_FORGED_ROLE'))) {
        $_SESSION['user_role'] = (string) $row['role'];
    }
    $stamp = $userId . ':' . (int) $row['token_epoch'];
    $held = $_SESSION['session_epoch'] ?? null;
    if ($held === null || !str_starts_with((string) $held, $userId . ':')) {
        $_SESSION['session_epoch'] = $stamp;
        return true;
    }
    return hash_equals($stamp, (string) $held);
}

/** After changing their own password the user keeps this session; the others end. */
function refreshOwnSessionEpoch(int $changedUserId): void {
    if ((int) ($_SESSION['user_id'] ?? 0) !== $changedUserId) {
        return;
    }
    unset($_SESSION['session_epoch']);
    _isSessionEpochCurrent();
}

/**
 * requireLogin()'s counterpart for the JSON/fetch()-based api/*.php
 * endpoints — applies the same session + idle-timeout rule but returns JSON
 * 401 instead of redirecting to an HTML page (fetch() does not try to parse a
 * login page's HTML as a "successful response", it sees a clean error). Used
 * by api/_bootstrap.php — gathers into one place the "every api file writes
 * its own auth check by hand/inconsistently" risk found in the 2026-08-23 review.
 */
function requireApiLogin() {
    $valid = isset($_SESSION['user_id']) && _isSessionActivityFresh() && _isSessionEpochCurrent();
    if (!$valid) {
        if (isset($_SESSION['user_id'])) {
            $_SESSION = [];
            session_destroy();
        }
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Unauthorized']);
        exit;
    }
    $_SESSION['last_activity'] = time();
    _touchLastSeen($_SESSION['user_id']);
}

/**
 * For the "another admin is in the system right now" warning (2026-08-24,
 * user request) — updates sys_users.last_seen_at, but not on EVERY request:
 * at most every ~20 seconds (throttled by a timestamp in the session) —
 * otherwise pages with heavy AJAX polling (like cc_agent) would run several
 * pointless UPDATE queries per second.
 */
function _touchLastSeen($user_id) {
    if (empty($user_id)) return;
    $now = time();
    if (isset($_SESSION['_last_seen_touch']) && ($now - $_SESSION['_last_seen_touch']) < 20) {
        return;
    }
    $_SESSION['_last_seen_touch'] = $now;
    try {
        $stmt = getDB()->prepare("UPDATE sys_users SET last_seen_at = NOW() WHERE id = ?");
        $stmt->execute([$user_id]);
    } catch (\Exception $e) {
        // Skip silently — a best-effort feature, it never blocks the actual request.
    }
}

/**
 * Is ANOTHER admin active right now (made a request in the last ~2 minutes)
 * — for the "another admin is in the system right now" warning in the
 * sidebar. $exclude_user_id removes the caller's own session from the list.
 */
function getOtherActiveAdmins($exclude_user_id, $window_seconds = 120) {
    try {
        $stmt = getDB()->prepare(
            "SELECT id, full_name, last_seen_at FROM sys_users
             WHERE role = 'admin' AND is_active = 1 AND id != ?
             AND last_seen_at IS NOT NULL AND last_seen_at >= (NOW() - INTERVAL ? SECOND)
             ORDER BY last_seen_at DESC"
        );
        $stmt->execute([$exclude_user_id, $window_seconds]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (\Exception $e) {
        return [];
    }
}

/**
 * Landing page after sign-in for a role (all login flows and "/" use this).
 */
function roleHomePath(string $role): string {
    return match ($role) {
        'admin' => '/dashboard',
        'cc_agent' => '/cc-agent',
        'cc_manager' => '/cc-supervisor',
        'fax_user' => '/fax-inbox',
        default => '/my-phone',
    };
}

/**
 * Maps page filenames to RBAC module keys
 */
function getModuleKeyForPage($page = null) {
    if ($page === null) {
        $page = basename($_SERVER['PHP_SELF']);
    }
    $map = [
        'dashboard.php'         => 'dashboard',
        'my_phone.php'          => 'my_phone',
        'chat.php'              => 'chat',
        'trunks.php'            => 'trunks',
        'did_routes.php'        => 'did_routes',
        'blacklist.php'         => 'did_routes',
        'inbound_normalization.php' => 'did_routes',
        'outbound_routes.php'   => 'outbound_routes',
        'dialplan_rules.php'    => 'outbound_routes',
        'dial_permissions.php' => 'dial_permissions',
        'time_conditions.php'   => 'time_conditions',
        'ivrs.php'              => 'ivrs',
        // SQL against the external databases: administrators only, like them.
        'ivr_data_steps.php'    => 'external_dbs',
        'extensions.php'        => 'extensions',
        'phones.php'            => 'phones',
        'phone_keys.php'        => 'phones',
        'phonebook.php'         => 'phones',
        'phone_regions.php'     => 'phones',
        // DHCP/TFTP for desk phones: admin-only like the Phones page.
        'network_services.php'  => 'phones',
        'network_settings.php'  => 'phones',
        'vpn_server.php'        => 'phones',
        'vpn_client.php'        => 'phones',
        'reverse_proxy.php'     => 'phones',
        'phone_logs.php'        => 'phones',
        'ring_groups.php'       => 'ring_groups',
        'conferences.php'       => 'conferences',
        'meetings.php'          => 'meetings',
        'boss_secretary.php'    => 'boss_secretary',
        'users.php'             => 'system_users',
        'queues.php'            => 'queues',
        'sounds.php'            => 'sounds',
        'end_call.php'          => 'end_call',
        'cdr_reports.php'       => 'cdr_reports',
        'system_users.php'      => 'system_users',
        'roles.php'             => 'roles',
        'asterisk_settings.php' => 'asterisk_settings',
        'transports.php'        => 'asterisk_settings',
        'ui_kit.php'            => 'asterisk_settings',
        'brand_settings.php'    => 'brand_settings',
        'fax_mail_settings.php' => 'fax_mail_settings',
        'fax_settings.php'      => 'fax_settings',
        'fax_inbox.php'         => 'fax_inbox',
        'fax_send.php'          => 'fax_send',
        'fax_sent.php'          => 'fax_sent',
        'cc_agent.php'          => 'cc_agent',
        'cc_supervisor.php'     => 'queue_monitor',
        'cc_board.php'          => 'cc_board',
        'pause_reports.php'     => 'pause_reports',
        'queue_reports.php'     => 'queue_reports',
        'queue_logs.php'        => 'queue_logs',
        'feature_codes.php'        => 'feature_codes',
        'feature_codes_status.php' => 'feature_codes',
        'pending_sync.php'         => 'pending_sync',
        'audit_log.php'            => 'audit_log',
        'firewall.php'              => 'firewall',
        'fail2ban.php'              => 'fail2ban',
        // Sign-in settings (sign-in check, alerts, SSO only): admin-only like the other security pages.
        'sign_in_settings.php'      => 'fail2ban',
        'certificates.php'          => 'certificates',
        'system_update.php'         => 'system_update',
        'backup.php'                => 'backup',
        'scheduled_tasks.php'       => 'scheduled_tasks',
        // Admin → Help (system guide): admins only (HelpController), listed with System update.
        'help.php'                  => 'system_update',
        'push_settings.php'         => 'push_settings',
        'plugins.php'               => 'plugins',
        'api_keys.php'              => 'api_keys',
        'webhooks.php'              => 'webhooks',
        'external_dbs.php'          => 'external_dbs',
        'google_integration.php'    => 'google_integration',
        'ms_teams.php'              => 'ms_teams',
        'web_widgets.php'           => 'web_widgets',
        'ai_tts.php'                => 'ai_tts',
        'ai_models.php'             => 'ai_models',
        'ai_cloud.php'              => 'ai_cloud',
        'ai_apps.php'               => 'ai_apps',
        'assistant.php'             => 'assistant',
        'privacy.php'               => 'privacy',
        'mail_settings.php'         => 'mail_settings',
        'file_storage.php'          => 'file_storage',
        'health.php'                => 'health',
        'call_quality.php'          => 'call_quality',
    ];
    if (str_starts_with((string)$page, 'plugin_') && str_ends_with((string)$page, '.php')) {
        return 'plugins';
    }
    // A plugin's page for users (/apps/<slug>): the plugin's user/visible.php decides who opens it.
    if (str_starts_with((string)$page, 'app_') && str_ends_with((string)$page, '.php')) {
        return 'plugin_apps';
    }
    return $map[$page] ?? str_replace('.php', '', $page);
}

/**
 * Fetch permissions matrix for a given role key
 */
function getRolePermissionsMap($role_key = null, bool $reset = false) {
    static $cache = [];
    if ($reset) {
        $cache = [];
        if ($role_key === null) {
            return [];
        }
    }
    if ($role_key === null) {
        $role_key = $_SESSION['user_role'] ?? 'user';
    }
    
    if (isset($cache[$role_key])) {
        return $cache[$role_key];
    }

    $db = getDB();
    try {
        $stmt = $db->prepare("SELECT module_key, can_view, can_access, can_edit, can_delete FROM sys_role_permissions WHERE role_key = ?");
        $stmt->execute([$role_key]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $map = [];
        foreach ($rows as $row) {
            $map[$row['module_key']] = [
                'view' => (int)$row['can_view'],
                'access' => (int)$row['can_access'],
                'edit' => (int)$row['can_edit'],
                'delete' => (int)$row['can_delete']
            ];
        }
        $cache[$role_key] = $map;
        return $map;
    } catch (Exception $e) {
        return [];
    }
}

/**
 * Check if current user's role has permission for a module action ('view', 'access', 'edit', 'delete')
 */
/** Whether the signed-in user is on a fax number (Fax Units). Asked often, so kept for the request. */
function userIsOnFaxNumber(): bool
{
    static $cache = [];
    $userId = (int) ($_SESSION['user_id'] ?? 0);
    if ($userId <= 0) {
        return false;
    }
    if (!isset($cache[$userId])) {
        try {
            $stmt = getDB()->prepare('SELECT 1 FROM pbx_fax_number_users f JOIN sys_did_mappings m ON m.id = f.did_mapping_id WHERE f.user_id = ? AND m.is_active = 1 LIMIT 1');
            $stmt->execute([$userId]);
            $cache[$userId] = $stmt->fetchColumn() !== false;
        } catch (\Throwable $e) {
            $cache[$userId] = false; // During an update, before the table exists.
        }
    }
    return $cache[$userId];
}

function hasModulePermission($module_key, $action = 'access') {
    $role = $_SESSION['user_role'] ?? '';

    // The 'roles', 'system_users', 'firewall' and 'fail2ban' modules are under a SYMMETRIC circuit breaker:
    // admin can ALWAYS access them (if the roles page got locked the admin could
    // not recover), and NO non-admin role can EVER access them — whatever
    // sys_role_permissions says for these modules (the roles.php permission
    // matrix offered them like any other module, so a role could be given
    // user/role management by mistake or via "Select all", leading to full
    // privilege escalation (making one's own role admin) — found in the
    // 2026-08-21 audit).
    // 'firewall' and 'fail2ban' are under the same circuit breaker (2026-08-31 / 2026-09-01
    // RbacTest): these pages run real sudo, they can NEVER open for a non-admin role.
    // 'system_update' too: it updates the system and restarts services.
    // 'backup': a backup holds every secret of the system, and a restore replaces it.
    // 'certificates': installs the TLS key and reloads Apache, coturn and Asterisk.
    // 'phones': the provisioning URLs it shows hand out SIP passwords.
    // 'web_widgets': an external destination or the call-back form can cost money.
    // 'file_storage': holds the S3 secret key of the bucket with all chat files.
    // 'ai_models': installs software as root and downloads models (disk, RAM, CPU).
    // 'ai_cloud': holds the API keys of paid cloud AI services.
    // 'ai_apps': an application's lookup URL is requested by the server itself.
    // Keep in sync with RoleRepository::modulesDefinition() 'admin_only' (RbacTest checks it).
    if (in_array($module_key, ['advanced_config', 'roles', 'system_users', 'firewall', 'fail2ban', 'mail_settings', 'system_update', 'backup', 'scheduled_tasks', 'certificates', 'privacy', 'plugins', 'api_keys', 'webhooks', 'external_dbs', 'google_integration', 'phones', 'web_widgets', 'file_storage', 'ai_models', 'ai_cloud', 'ai_apps', 'assistant', 'health', 'call_quality', 'custom_dialplan'], true)) {
        return $role === 'admin';
    }

    // Plugin-backed delegable modules ('ms_teams' -> 'ms-teams', 'ai_tts' -> 'ai-tts'):
    // when a plugin is not enabled in sys_plugins, non-admin roles must never access
    // its pages or API endpoints even if sys_role_permissions grants them access.
    $pluginModuleSlugs = [
        'ms_teams' => 'ms-teams',
        'ai_tts'   => 'ai-tts',
    ];
    if ($role !== 'admin' && isset($pluginModuleSlugs[$module_key])) {
        if (is_file(__DIR__ . '/src/core/PluginManager.php')) {
            require_once __DIR__ . '/src/core/PluginManager.php';
            if (!PluginManager::isEnabled($pluginModuleSlugs[$module_key])) {
                return false;
            }
        }
    }

    // Read-only viewer (read_only_admin) rule: can never change settings (edit) or delete (delete) in any module!
    // Per the user's request: "it must stay a viewer only and not be able to change settings."
    if ($role === 'read_only_admin' && ($action === 'edit' || $action === 'delete')) {
        return false;
    }

    // The 'push_settings' module:
    // holds the Google Cloud service account JSON private key.
    // Editing ('edit') and deleting ('delete') are open ONLY to the 'admin' role.
    if ($module_key === 'push_settings' && ($action === 'edit' || $action === 'delete')) {
        return $role === 'admin';
    }

    // Being on a fax number opens the fax pages for it, whatever the role: a person with a phone needs
    // no second account. Deleting stays a right of the role.
    if (in_array($module_key, ['fax_inbox', 'fax_send', 'fax_sent'], true) && $action !== 'delete'
        && ($module_key === 'fax_send' || $action !== 'edit') && userIsOnFaxNumber()) {
        return true;
    }

    $map = getRolePermissionsMap($role);

    // Read only admin fallback if not explicitly in table
    if ($role === 'read_only_admin' && !isset($map[$module_key])) {
        if ($action === 'view' || $action === 'access') return true;
        return false;
    }

    // Admin fallback: do NOT block admin by default on a module that was never
    // configured (newly added, no permission row in roles.php yet) — otherwise
    // the admin would be locked out of every newly added page.
    // If the module has a row (falls through below), that row's value applies.
    if ($role === 'admin' && !isset($map[$module_key])) {
        return true;
    }

    if (!isset($map[$module_key])) {
        return false;
    }

    $perm = $map[$module_key];
    return !empty($perm[$action]);
}

/**
 * Detect if an incoming POST request is an entity deletion action
 */
function isPostDeleteRequest(): bool {
    foreach ($_POST as $k => $v) {
        if (str_starts_with($k, 'delete_') || str_starts_with($k, 'remove_') || str_starts_with($k, 'cancel_') || $k === 'delete') {
            return true;
        }
    }
    $req_action = $_POST['action'] ?? $_GET['action'] ?? '';
    if ($req_action !== '') {
        if (str_starts_with($req_action, 'delete_') || str_starts_with($req_action, 'remove_') || str_starts_with($req_action, 'cancel_') || $req_action === 'delete') {
            return true;
        }
    }
    return false;
}

/**
 * Guard function to enforce module permissions on pages
 */
function requireModulePermission($module_key, $action = 'access') {
    requireLogin();

    // NOTE: hasModulePermission() applies the nuanced logic to admin too (see
    // the comment there) — no separate unconditional admin shortcut needed here.
    $allowed = hasModulePermission($module_key, $action);

    // Block POST mutation if user lacks edit or delete permission
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (isPostDeleteRequest()) {
            if (!hasModulePermission($module_key, 'delete')) {
                if (function_exists('notify')) {
                    notify(t('auth.no_delete_module'), "danger");
                }
                header('Location: ' . ($_SERVER['REQUEST_URI'] ?? '/dashboard'));
                exit;
            }
        } elseif (!hasModulePermission($module_key, 'edit')) {
            if (function_exists('notify')) {
                notify(t('auth.no_edit_module'), "danger");
            }
            header('Location: ' . ($_SERVER['REQUEST_URI'] ?? '/dashboard'));
            exit;
        }
    }

    if (!$allowed) {
        http_response_code(403);
        echo '<!DOCTYPE html><html lang="' . getUserLanguage() . '"><head><title>403 - ' . t('auth.forbidden') . '</title><link rel="stylesheet" href="/assets/css/variables.css"><link rel="stylesheet" href="/assets/css/layout.css">' . uiStylesheets() . '<link rel="stylesheet" href="/assets/css/fontawesome.min.css"><link rel="stylesheet" href="/assets/css/style.css"></head>';
        echo '<body class="auth-body"><div class="auth-card" style="text-align:center; max-width:480px;">';
        echo '<h2 style="color:var(--danger);"><i class="fas fa-lock"></i> 403 - ' . t('auth.forbidden') . '</h2>';
        echo '<p style="margin:20px 0; color:var(--text-muted);">' . sprintf(t('auth.no_module_access'), htmlspecialchars($module_key)) . '</p>';
        echo '<a href="/" class="btn btn-primary"><i class="fas fa-arrow-left"></i> ' . t('auth.back_home') . '</a>';
        echo '</div></body></html>';
        exit;
    }
}

/**
 * Central requireRole function integrated with dynamic RBAC permissions
 */
function requireRole($allowed_roles) {
    requireLogin();
    
    $user_role = $_SESSION['user_role'] ?? '';

    // NOTE: there used to be an unconditional shortcut for $user_role === 'admin' here.
    // hasModulePermission() now applies the same nuanced logic to admin too
    // (roles.php/system_users.php stay open unconditionally — a lockout
    // guard — on every other module the admin's DB permission applies, allowed
    // by default when there is no row), so admin goes through the general
    // flow below as well; no separate shortcut needed.

    if (!is_array($allowed_roles)) {
        $allowed_roles = [$allowed_roles];
    }

    $module_key = getModuleKeyForPage();
    if ($module_key === 'cc') {
        if ($user_role === 'read_only_admin' && !in_array('read_only_admin', $allowed_roles, true)) {
            http_response_code(403);
            exit;
        }
        $cc_modules = ['cc_agent', 'queue_monitor', 'cc_board', 'cc_reports'];
        foreach ($cc_modules as $cm) {
            if (hasModulePermission($cm, 'access') || hasModulePermission($cm, 'view')) {
                return;
            }
        }
        $role_perm_map = getRolePermissionsMap($user_role);
        foreach ($cc_modules as $cm) {
            if (isset($role_perm_map[$cm])) {
                http_response_code(403);
                echo '<!DOCTYPE html><html lang="' . getUserLanguage() . '"><head><title>' . t('auth.blocked') . '</title><link rel="stylesheet" href="/assets/css/variables.css"><link rel="stylesheet" href="/assets/css/layout.css">' . uiStylesheets() . '<link rel="stylesheet" href="/assets/css/fontawesome.min.css"><link rel="stylesheet" href="/assets/css/style.css"></head>';
                echo '<body class="auth-body"><div class="auth-card" style="text-align:center; max-width:480px;">';
                echo '<h2 style="color:var(--danger);"><i class="fas fa-lock"></i> 403 - ' . t('auth.forbidden') . '</h2>';
                echo '<p style="margin:20px 0; color:var(--text-muted);">' . t('auth.no_page_access') . '</p>';
                echo '<a href="/" class="btn btn-primary"><i class="fas fa-arrow-left"></i> ' . t('auth.back_home') . '</a>';
                echo '</div></body></html>';
                exit;
            }
        }
    }

    if (hasModulePermission($module_key, 'access')) {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (isPostDeleteRequest()) {
                if (!hasModulePermission($module_key, 'delete')) {
                    if (function_exists('notify')) {
                        notify(t('auth.no_delete_page'), "danger");
                    }
                    header('Location: ' . ($_SERVER['REQUEST_URI'] ?? '/dashboard'));
                    exit;
                }
            } elseif (!hasModulePermission($module_key, 'edit')) {
                if (function_exists('notify')) {
                    notify(t('auth.no_edit_page'), "danger");
                }
                header('Location: ' . ($_SERVER['REQUEST_URI'] ?? '/dashboard'));
                exit;
            }
        }
        return;
    }

    // hasModulePermission() returned false above — but that did not tell apart
    // whether the role's access to this module was EXPLICITLY closed in
    // roles.php or the module was simply never configured for this role. If
    // it was explicitly closed, the fixed $allowed_roles list below must NEVER
    // override it — otherwise closing a role's access in roles.php did nothing
    // on pages whose fixed list names that role (inconsistent access behaviour).
    $role_perm_map = getRolePermissionsMap($user_role);
    if (isset($role_perm_map[$module_key])) {
        http_response_code(403);
        echo '<!DOCTYPE html><html lang="' . getUserLanguage() . '"><head><title>' . t('auth.blocked') . '</title><link rel="stylesheet" href="/assets/css/variables.css"><link rel="stylesheet" href="/assets/css/layout.css">' . uiStylesheets() . '<link rel="stylesheet" href="/assets/css/fontawesome.min.css"><link rel="stylesheet" href="/assets/css/style.css"></head>';
        echo '<body class="auth-body"><div class="auth-card" style="text-align:center; max-width:480px;">';
        echo '<h2 style="color:var(--danger);"><i class="fas fa-lock"></i> 403 - ' . t('auth.forbidden') . '</h2>';
        echo '<p style="margin:20px 0; color:var(--text-muted);">' . t('auth.no_page_access') . '</p>';
        echo '<a href="/" class="btn btn-primary"><i class="fas fa-arrow-left"></i> ' . t('auth.back_home') . '</a>';
        echo '</div></body></html>';
        exit;
    }

    if (!in_array($user_role, $allowed_roles)) {
        http_response_code(403);
        echo '<!DOCTYPE html><html lang="' . getUserLanguage() . '"><head><title>' . t('auth.blocked') . '</title><link rel="stylesheet" href="/assets/css/variables.css"><link rel="stylesheet" href="/assets/css/layout.css">' . uiStylesheets() . '<link rel="stylesheet" href="/assets/css/fontawesome.min.css"><link rel="stylesheet" href="/assets/css/style.css"></head>';
        echo '<body class="auth-body"><div class="auth-card" style="text-align:center; max-width:480px;">';
        echo '<h2 style="color:var(--danger);"><i class="fas fa-lock"></i> 403 - ' . t('auth.forbidden') . '</h2>';
        echo '<p style="margin:20px 0; color:var(--text-muted);">' . t('auth.no_page_access') . '</p>';
        echo '<a href="/" class="btn btn-primary"><i class="fas fa-arrow-left"></i> ' . t('auth.back_home') . '</a>';
        echo '</div></body></html>';
        exit;
    }
}

function getCurrentUser() {
    if (!isset($_SESSION['user_id'])) return null;
    $db = getDB();
    $stmt = $db->prepare('SELECT u.id, u.username, u.full_name, u.email, u.extension, u.sip_password, u.role, u.can_listen_recordings, u.can_view_all_cdrs, u.can_view_queue_monitor, u.allowed_phone_mode, u.outbound_group, u.permission_group_id, u.theme_preference, COALESCE(r.role_name, u.role) as role_display_name FROM sys_users u LEFT JOIN sys_roles r ON u.role = r.role_key WHERE u.id = ?');
    $stmt->execute([$_SESSION['user_id']]);
    return $stmt->fetch();
}
