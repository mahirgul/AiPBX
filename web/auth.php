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
    $_SESSION['last_activity'] = time();
    _touchLastSeen($_SESSION['user_id']);
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
    $valid = isset($_SESSION['user_id']) && _isSessionActivityFresh();
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

function _isSessionActivityFresh() {
    $idle_limit = 3600;
    return !isset($_SESSION['last_activity']) || (time() - $_SESSION['last_activity']) <= $idle_limit;
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
        'outbound_routes.php'   => 'outbound_routes',
        'dial_permissions.php' => 'dial_permissions',
        'time_conditions.php'   => 'time_conditions',
        'ivrs.php'              => 'ivrs',
        'extensions.php'        => 'extensions',
        'phones.php'            => 'phones',
        'phone_keys.php'        => 'phones',
        'ring_groups.php'       => 'ring_groups',
        'conferences.php'       => 'conferences',
        'boss_secretary.php'    => 'boss_secretary',
        'users.php'             => 'system_users',
        'queues.php'            => 'queues',
        'sounds.php'            => 'sounds',
        'end_call.php'          => 'end_call',
        'cdr_reports.php'       => 'cdr_reports',
        'system_users.php'      => 'system_users',
        'roles.php'             => 'roles',
        'asterisk_settings.php' => 'asterisk_settings',
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
        'certificates.php'          => 'certificates',
        'system_update.php'         => 'system_update',
        'push_settings.php'         => 'push_settings',
        'ms_teams.php'              => 'ms_teams',
        'web_widgets.php'           => 'web_widgets',
        'ai_tts.php'                => 'ai_tts',
        'mail_settings.php'         => 'mail_settings',
    ];
    return $map[$page] ?? str_replace('.php', '', $page);
}

/**
 * Fetch permissions matrix for a given role key
 */
function getRolePermissionsMap($role_key = null) {
    static $cache = [];
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
    // 'certificates': installs the TLS key and reloads Apache, coturn and Asterisk.
    // 'phones': the provisioning URLs it shows hand out SIP passwords.
    // 'web_widgets': an external destination or the call-back form can cost money.
    // Keep in sync with RoleRepository::modulesDefinition() 'admin_only' (RbacTest checks it).
    if (in_array($module_key, ['roles', 'system_users', 'firewall', 'fail2ban', 'mail_settings', 'system_update', 'certificates', 'google_integration', 'phones', 'web_widgets'], true)) {
        return $role === 'admin';
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
        if (str_starts_with($k, 'delete_') || str_starts_with($k, 'remove_') || $k === 'delete') {
            return true;
        }
    }
    $req_action = $_POST['action'] ?? $_GET['action'] ?? '';
    if ($req_action !== '') {
        if (str_starts_with($req_action, 'delete_') || str_starts_with($req_action, 'remove_') || $req_action === 'delete') {
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
        echo '<!DOCTYPE html><html lang="' . getUserLanguage() . '"><head><title>403 - ' . t('auth.forbidden') . '</title><link rel="stylesheet" href="/assets/css/variables.css"><link rel="stylesheet" href="/assets/css/layout.css"><link rel="stylesheet" href="/assets/css/components.css"><link rel="stylesheet" href="/assets/css/fontawesome.min.css"><link rel="stylesheet" href="/assets/css/style.css"></head>';
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

    $module_key = getModuleKeyForPage();
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
        echo '<!DOCTYPE html><html lang="' . getUserLanguage() . '"><head><title>' . t('auth.blocked') . '</title><link rel="stylesheet" href="/assets/css/variables.css"><link rel="stylesheet" href="/assets/css/layout.css"><link rel="stylesheet" href="/assets/css/components.css"><link rel="stylesheet" href="/assets/css/fontawesome.min.css"><link rel="stylesheet" href="/assets/css/style.css"></head>';
        echo '<body class="auth-body"><div class="auth-card" style="text-align:center; max-width:480px;">';
        echo '<h2 style="color:var(--danger);"><i class="fas fa-lock"></i> 403 - ' . t('auth.forbidden') . '</h2>';
        echo '<p style="margin:20px 0; color:var(--text-muted);">' . t('auth.no_page_access') . '</p>';
        echo '<a href="/" class="btn btn-primary"><i class="fas fa-arrow-left"></i> ' . t('auth.back_home') . '</a>';
        echo '</div></body></html>';
        exit;
    }

    if (!is_array($allowed_roles)) {
        $allowed_roles = [$allowed_roles];
    }

    if (!in_array($user_role, $allowed_roles)) {
        http_response_code(403);
        echo '<!DOCTYPE html><html lang="' . getUserLanguage() . '"><head><title>' . t('auth.blocked') . '</title><link rel="stylesheet" href="/assets/css/variables.css"><link rel="stylesheet" href="/assets/css/layout.css"><link rel="stylesheet" href="/assets/css/components.css"><link rel="stylesheet" href="/assets/css/fontawesome.min.css"><link rel="stylesheet" href="/assets/css/style.css"></head>';
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
    $stmt = $db->prepare('SELECT u.id, u.username, u.full_name, u.email, u.extension, u.sip_password, u.role, u.can_listen_recordings, u.can_view_all_cdrs, u.can_view_queue_monitor, u.allowed_phone_mode, u.theme_preference, COALESCE(r.role_name, u.role) as role_display_name FROM sys_users u LEFT JOIN sys_roles r ON u.role = r.role_key WHERE u.id = ?');
    $stmt->execute([$_SESSION['user_id']]);
    return $stmt->fetch();
}
