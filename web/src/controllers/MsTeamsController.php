<?php

require_once __DIR__ . '/../../auth.php';
require_once __DIR__ . '/../services/MsTeamsService.php';
require_once __DIR__ . '/../repositories/MsTeamsRepository.php';

class MsTeamsController extends BaseController
{
    public static function index(): void
    {
        requireLogin();
        static::requireModule('ms_teams', 'view');

        $active_tab = $_GET['tab'] ?? 'direct_routing';
        $message = '';
        $error = '';

        // --- AJAX: Test Webhook Gönderimi ---
        if (isset($_GET['action']) && $_GET['action'] === 'test_webhook') {
            header('Content-Type: application/json; charset=utf-8');
            if (!hasModulePermission('ms_teams', 'edit')) {
                echo json_encode(['success' => false, 'message' => 'Bu işlem için düzenleme yetkiniz bulunmamaktadır.']);
                exit;
            }
            $csrf = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
            if (!verifyCSRFToken($csrf)) {
                echo json_encode(['success' => false, 'message' => 'Geçersiz güvenlik oturumu (CSRF).']);
                exit;
            }

            $url = trim($_POST['webhook_url'] ?? '');
            if ($url === '') {
                $current = MsTeamsRepository::currentSettings();
                $url = $current['teams_webhook_url'] ?? '';
            }

            $result = MsTeamsService::sendTestWebhook($url);
            echo json_encode($result, JSON_UNESCAPED_UNICODE);
            exit;
        }

        // --- AJAX: Kullanıcı Eşleştirmesi Kaydet / Düzenle ---
        if (isset($_GET['action']) && $_GET['action'] === 'save_mapping') {
            header('Content-Type: application/json; charset=utf-8');
            if (!hasModulePermission('ms_teams', 'edit')) {
                echo json_encode(['success' => false, 'message' => 'Bu işlem için düzenleme yetkiniz bulunmamaktadır.']);
                exit;
            }
            $csrf = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
            if (!verifyCSRFToken($csrf)) {
                echo json_encode(['success' => false, 'message' => 'Geçersiz güvenlik oturumu (CSRF).']);
                exit;
            }

            $result = MsTeamsRepository::saveUserMapping($_POST);
            echo json_encode($result, JSON_UNESCAPED_UNICODE);
            exit;
        }

        // --- AJAX: Kullanıcı Eşleştirmesi Sil ---
        if (isset($_GET['action']) && $_GET['action'] === 'delete_mapping') {
            header('Content-Type: application/json; charset=utf-8');
            if (!hasModulePermission('ms_teams', 'delete')) {
                echo json_encode(['success' => false, 'message' => 'Bu işlem için silme yetkiniz bulunmamaktadır.']);
                exit;
            }
            $csrf = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
            if (!verifyCSRFToken($csrf)) {
                echo json_encode(['success' => false, 'message' => 'Geçersiz güvenlik oturumu (CSRF).']);
                exit;
            }

            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) {
                echo json_encode(['success' => false, 'message' => 'Geçersiz kayıt ID.']);
                exit;
            }

            $deleted = MsTeamsRepository::deleteUserMapping($id);
            if ($deleted) {
                echo json_encode(['success' => true, 'message' => 'Eşleştirme başarıyla silindi.']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Eşleştirme silinirken hata oluştu.']);
            }
            exit;
        }

        // --- PowerShell Script İndir ---
        if (isset($_GET['action']) && $_GET['action'] === 'download_powershell') {
            $settings = MsTeamsRepository::currentSettings();
            $mappings = MsTeamsRepository::allUserMappings();
            $script = MsTeamsService::generatePowerShellScript($settings, $mappings);

            $filename = 'aipbx_teams_setup_' . date('Ymd_His') . '.ps1';
            header('Content-Type: text/plain; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Content-Length: ' . strlen($script));
            echo $script;
            exit;
        }

        // --- Standart Form Gönderimleri (Direct Routing & Webhook Ayarları) ---
        if (static::isPost()) {
            $csrf = $_POST['csrf_token'] ?? '';
            if (!verifyCSRFToken($csrf)) {
                $error = 'Geçersiz form tokeni (CSRF). Lütfen sayfayı yenileyip tekrar deneyin.';
            } elseif (!hasModulePermission('ms_teams', 'edit')) {
                $error = 'Bu ayarları güncellemek için düzenleme yetkiniz bulunmamaktadır.';
            } else {
                if (isset($_POST['save_direct_routing'])) {
                    $res = MsTeamsService::saveDirectRoutingSettings($_POST);
                    $active_tab = 'direct_routing';
                    if ($res['success']) {
                        $message = $res['message'];
                    } else {
                        $error = $res['error'];
                    }
                } elseif (isset($_POST['save_webhook_settings'])) {
                    $res = MsTeamsService::saveWebhookSettings($_POST);
                    $active_tab = 'webhooks';
                    if ($res['success']) {
                        $message = $res['message'];
                    } else {
                        $error = $res['error'];
                    }
                }
            }
        }

        $settings = MsTeamsRepository::currentSettings();
        $mappings = MsTeamsRepository::allUserMappings();
        $extensions = MsTeamsRepository::availableExtensions();
        $certInfo = MsTeamsService::inspectTlsCert($settings['teams_tls_cert_path'] ?? '');
        $powerShellScript = MsTeamsService::generatePowerShellScript($settings, $mappings);

        $page_title = 'Microsoft Teams Entegrasyonu';
        $active_page = 'ms_teams.php';

        require_once dirname(__DIR__) . '/../header.php';
        static::render('ms_teams/index', [
            'settings'         => $settings,
            'mappings'         => $mappings,
            'extensions'       => $extensions,
            'certInfo'         => $certInfo,
            'powerShellScript' => $powerShellScript,
            'active_tab'       => $active_tab,
            'message'          => $message,
            'error'            => $error,
            'can_edit'         => hasModulePermission('ms_teams', 'edit'),
            'can_delete'       => hasModulePermission('ms_teams', 'delete'),
        ]);
        require_once dirname(__DIR__) . '/../footer.php';
    }
}
