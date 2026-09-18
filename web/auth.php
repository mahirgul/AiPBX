<?php
require_once __DIR__ . '/config.php';

function requireLogin() {
    if (!isset($_SESSION['user_id'])) {
        header('Location: /login');
        exit;
    }

    // Uygulama seviyesinde bir idle-timeout kontrolü yoktu — oturum, tarayıcı
    // kapanana (cookie lifetime=0) ya da PHP'nin güvenilir olmayan olasılıksal
    // GC'si devreye girene kadar geçerli kalabiliyordu (paylaşımlı/ortak
    // bilgisayar senaryosunda risk, 2026-08-21 denetiminde bulundu). 60 dakika
    // hareketsizlikten sonra oturum sonlandırılır; her istek last_activity'yi
    // yeniler (AJAX polling yapan sayfalar — cc_agent vb. — bu sayede "kullanımda"
    // kaldığı sürece timeout olmaz, tam istenen davranış).
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
 * requireLogin()'in JSON/fetch() tabanlı api/*.php uç noktaları için eşdeğeri —
 * aynı oturum+idle-timeout kuralını uygular ama HTML sayfasına redirect yerine
 * JSON 401 döner (fetch() bir login sayfasının HTML'ini "başarılı yanıt" sanıp
 * ayrıştırmaya çalışmaz, temiz bir hata görür). api/_bootstrap.php tarafından
 * kullanılır — 2026-08-23 incelemesinde bulunan "her api dosyası kendi auth
 * kontrolünü elle/tutarsız biçimde yazıyor" riskini merkezi bir noktaya toplar.
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
 * "Aynı anda başka bir admin de sistemde" uyarısı için (2026-08-24, kullanıcı
 * isteği) — sys_users.last_seen_at'i günceller, ama HER istekte değil: en
 * fazla ~20 saniyede bir (session'daki bir zaman damgasıyla throttled) —
 * yoksa yoğun AJAX polling yapan sayfalarda (cc_agent gibi) gereksiz yere
 * saniyede birkaç UPDATE sorgusu koşardı.
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
        // Sessizce atla — bu bir "en iyi çaba" özelliği, asıl isteği asla engellemez.
    }
}

/**
 * Şu an aktif (son ~2 dakikada bir istek yapmış) BAŞKA admin var mı —
 * sidebar'daki "Aynı anda başka bir admin de sistemde" uyarısı için.
 * $exclude_user_id kendi oturumunu listeden çıkarır.
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
        'time_conditions.php'   => 'time_conditions',
        'ivrs.php'              => 'ivrs',
        'extensions.php'        => 'extensions',
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
        'queue_logs.php'        => 'queue_logs',
        'feature_codes.php'        => 'feature_codes',
        'feature_codes_status.php' => 'feature_codes',
        'pending_sync.php'         => 'pending_sync',
        'audit_log.php'            => 'audit_log',
        'firewall.php'              => 'firewall',
        'fail2ban.php'              => 'fail2ban',
        'push_settings.php'         => 'push_settings',
        'ms_teams.php'              => 'ms_teams',
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
        $role_key = $_SESSION['user_role'] ?? 'fax_user';
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

    // 'roles', 'system_users', 'firewall' ve 'fail2ban' modülleri SİMETRİK bir circuit-breaker altında:
    // admin HER ZAMAN erişebilir (roller sayfası kilitlenirse admin kendini geri
    // kurtaramaz), admin OLMAYAN hiçbir rol ASLA erişemez — sys_role_permissions
    // tablosunda bu iki modül için ne yazarsa yazsın (roles.php'nin izin matrisi
    // formu bunları sıradan bir modül gibi sunduğu için yanlışlıkla/"Tümünü Seç"
    // ile bir role kullanıcı/rol yönetim yetkisi verilip tam yetki yükseltmesine
    // (kendi rolünü admin yapma) yol açabiliyordu — 2026-08-21 denetiminde bulundu).
    // 'firewall' ve 'fail2ban' de aynı circuit-breaker'a dahildir (2026-08-31 / 2026-09-01
    // RbacTest): bu sayfalar gerçek sudo çalıştırır, admin dışındaki hiçbir role ASLA açılamaz.
    if (in_array($module_key, ['roles', 'system_users', 'firewall', 'fail2ban', 'mail_settings'], true)) {
        return $role === 'admin';
    }

    // Sadece İzleyici (read_only_admin) kuralı: Kesinlikle hiçbir modülde ayar (edit) veya silme (delete) yapamaz!
    // Kullanıcı talebi doğrultusunda: "sadece izleyici olarak kalmalı ayar yapamamalı."
    if ($role === 'read_only_admin' && ($action === 'edit' || $action === 'delete')) {
        return false;
    }

    // 'push_settings' modülü:
    // Google Cloud Servis Hesabı JSON özel anahtarı barındırır.
    // Düzenleme ('edit') ve silme ('delete') işlemleri SADECE 'admin' rolüne açıktır.
    if ($module_key === 'push_settings' && ($action === 'edit' || $action === 'delete')) {
        return $role === 'admin';
    }

    $map = getRolePermissionsMap($role);

    // Read only admin fallback if not explicitly in table
    if ($role === 'read_only_admin' && !isset($map[$module_key])) {
        if ($action === 'view' || $action === 'access') return true;
        return false;
    }

    // Admin fallback: hiç yapılandırılmamış (yeni eklenmiş, henüz roles.php'de
    // izin satırı oluşmamış) bir modülde admin'i varsayılan olarak ENGELLEMEYELİM
    // — o zaman yeni bir sayfa eklendiğinde admin'in kendisi dışarıda kalırdı.
    // Modül için satır varsa (aşağıya düşer) o satırdaki değer geçerli olur.
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

    // NOT: hasModulePermission() admin için de nüanslı mantığı uyguluyor (bkz.
    // oradaki yorum) — burada ayrı bir koşulsuz admin kısayoluna gerek yok.
    $allowed = hasModulePermission($module_key, $action);

    // Block POST mutation if user lacks edit or delete permission
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (isPostDeleteRequest()) {
            if (!hasModulePermission($module_key, 'delete')) {
                if (function_exists('notify')) {
                    notify("Bu modülde silme yetkiniz bulunmamaktadır.", "danger");
                }
                header('Location: ' . ($_SERVER['REQUEST_URI'] ?? '/dashboard'));
                exit;
            }
        } elseif (!hasModulePermission($module_key, 'edit')) {
            if (function_exists('notify')) {
                notify("Bu modülde değişiklik / kaydetme yetkiniz bulunmamaktadır.", "danger");
            }
            header('Location: ' . ($_SERVER['REQUEST_URI'] ?? '/dashboard'));
            exit;
        }
    }

    if (!$allowed) {
        http_response_code(403);
        echo '<!DOCTYPE html><html lang="tr"><head><title>403 - Yetkisiz Erişim</title><link rel="stylesheet" href="/assets/css/variables.css"><link rel="stylesheet" href="/assets/css/layout.css"><link rel="stylesheet" href="/assets/css/components.css"><link rel="stylesheet" href="/assets/css/fontawesome.min.css"><link rel="stylesheet" href="/assets/css/style.css"></head>';
        echo '<body class="auth-body"><div class="auth-card" style="text-align:center; max-width:480px;">';
        echo '<h2 style="color:var(--danger);"><i class="fas fa-lock"></i> 403 - Yetkisiz Erişim</h2>';
        echo '<p style="margin:20px 0; color:var(--text-muted);">Bu modüle (' . htmlspecialchars($module_key) . ') erişim yetkiniz bulunmamaktadır.</p>';
        echo '<a href="/" class="btn btn-primary"><i class="fas fa-arrow-left"></i> Ana Sayfaya Dön</a>';
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

    // NOT: Burada eskiden $user_role === 'admin' için koşulsuz bir kısayol vardı.
    // hasModulePermission() artık admin için de aynı nüanslı mantığı uyguluyor
    // (roles.php/system_users.php koşulsuz açık kalır — kilitlenme önleyici — diğer
    // tüm modüllerde admin'in DB'deki izni geçerli, satır yoksa varsayılan izinli),
    // o yüzden admin de aşağıdaki genel akıştan geçiyor; ayrı bir kısayola gerek yok.

    $module_key = getModuleKeyForPage();
    if (hasModulePermission($module_key, 'access')) {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (isPostDeleteRequest()) {
                if (!hasModulePermission($module_key, 'delete')) {
                    if (function_exists('notify')) {
                        notify("Bu sayfada silme yetkiniz bulunmamaktadır.", "danger");
                    }
                    header('Location: ' . ($_SERVER['REQUEST_URI'] ?? '/dashboard'));
                    exit;
                }
            } elseif (!hasModulePermission($module_key, 'edit')) {
                if (function_exists('notify')) {
                    notify("Bu sayfada değişiklik yapma / kaydetme yetkiniz bulunmamaktadır.", "danger");
                }
                header('Location: ' . ($_SERVER['REQUEST_URI'] ?? '/dashboard'));
                exit;
            }
        }
        return;
    }

    // hasModulePermission() yukarıda false döndü — ama bu, rolün bu modülde
    // roles.php'den AÇIKÇA erişimi kapatılmış mı, yoksa modül bu rol için hiç
    // yapılandırılmamış mı ayırt etmiyordu. Açıkça kapatılmışsa aşağıdaki sabit
    // $allowed_roles listesi bunu ASLA ezmemeli — aksi halde roles.php'den bir
    // rolün erişimini kapatmak, bu sabit listede o rol geçen sayfalarda hiçbir
    // işe yaramıyordu (tutarsız yetki davranışı).
    $role_perm_map = getRolePermissionsMap($user_role);
    if (isset($role_perm_map[$module_key])) {
        http_response_code(403);
        echo '<!DOCTYPE html><html lang="tr"><head><title>Erişim Engellendi</title><link rel="stylesheet" href="/assets/css/variables.css"><link rel="stylesheet" href="/assets/css/layout.css"><link rel="stylesheet" href="/assets/css/components.css"><link rel="stylesheet" href="/assets/css/fontawesome.min.css"><link rel="stylesheet" href="/assets/css/style.css"></head>';
        echo '<body class="auth-body"><div class="auth-card" style="text-align:center; max-width:480px;">';
        echo '<h2 style="color:var(--danger);"><i class="fas fa-lock"></i> 403 - Yetkisiz Erişim</h2>';
        echo '<p style="margin:20px 0; color:var(--text-muted);">Bu sayfaya erişim yetkiniz bulunmamaktadır.</p>';
        echo '<a href="/" class="btn btn-primary"><i class="fas fa-arrow-left"></i> Ana Sayfaya Dön</a>';
        echo '</div></body></html>';
        exit;
    }

    if (!is_array($allowed_roles)) {
        $allowed_roles = [$allowed_roles];
    }

    if (!in_array($user_role, $allowed_roles)) {
        http_response_code(403);
        echo '<!DOCTYPE html><html lang="tr"><head><title>Erişim Engellendi</title><link rel="stylesheet" href="/assets/css/variables.css"><link rel="stylesheet" href="/assets/css/layout.css"><link rel="stylesheet" href="/assets/css/components.css"><link rel="stylesheet" href="/assets/css/fontawesome.min.css"><link rel="stylesheet" href="/assets/css/style.css"></head>';
        echo '<body class="auth-body"><div class="auth-card" style="text-align:center; max-width:480px;">';
        echo '<h2 style="color:var(--danger);"><i class="fas fa-lock"></i> 403 - Yetkisiz Erişim</h2>';
        echo '<p style="margin:20px 0; color:var(--text-muted);">Bu sayfaya erişim yetkiniz bulunmamaktadır.</p>';
        echo '<a href="/" class="btn btn-primary"><i class="fas fa-arrow-left"></i> Ana Sayfaya Dön</a>';
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
