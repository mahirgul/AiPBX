<?php
require_once __DIR__ . '/../repositories/FaxInboxRepository.php';
require_once __DIR__ . '/../services/FaxSendService.php';

class FaxSendController extends BaseController
{
    public static function index(): void
    {
        // A role an administrator made for fax users opens these pages too.
        if (!hasModulePermission('fax_send', 'access')) {
            static::requireRole(['admin', 'fax_user']);
        }

        $user_id = $_SESSION['user_id'];
        $user_ext = (string)($_SESSION['extension'] ?? '');
        $user_role = $_SESSION['user_role'] ?? 'user';

        if (static::isPost() && !empty($_POST['cover_preview']) && verifyCSRFToken($_POST['csrf_token'] ?? '')) {
            $settings = FaxCoverService::settingsFor($_POST, (int) $user_id, (string) $user_role);
            // The settings page previews what is in its form, before it is saved.
            if (isset($_POST['fax_cover_company']) && $user_role === 'admin') {
                $settings['company'] = mb_substr(trim((string) $_POST['fax_cover_company']), 0, 120);
                $settings['notice'] = mb_substr(trim((string) ($_POST['fax_cover_notice'] ?? '')), 0, 1000);
                $settings['logo'] = !empty($_POST['fax_cover_logo']);
            }
            $png = FaxCoverService::previewPng(
                FaxCoverService::fieldsFromPost($_POST),
                $settings,
                $user_ext,
                (string) preg_replace('/[^0-9+]/', '', (string) ($_POST['dest_number'] ?? ''))
            );
            header('Content-Type: image/png');
            header('Cache-Control: no-store');
            echo $png;
            exit;
        }

        $notices = !empty($_POST['save_cover_template'])
            ? static::notices(FaxCoverService::saveTemplate($_POST, (int) $user_id))
            : (!empty($_POST['delete_cover_template']) ? static::notices(FaxCoverService::deleteTemplate($_POST, (int) $user_id)) : null);
        $notices ??= static::isPost()
            ? static::notices(FaxSendService::sendFax($_POST, $_FILES, $user_id, $user_ext))
            : ['message' => '', 'error' => ''];

        $csrf_token = getCSRFToken();

        // A dropdown so the admin can send ON BEHALF of a fax unit instead of
        // their own SIP extension (e.g. 19000) — queried only for admin; a
        // regular fax user always uses their own extension anyway (2026-08-31,
        // user request).
        $fax_users = ($user_role === 'admin') ? FaxSettingsRepository::faxNumbersForDropdown() : [];
        // A fax user with several numbers chooses which one to send from.
        $own_numbers = ($user_role === 'admin') ? [] : FaxInboxRepository::sendableNumbers((int) ($_SESSION['user_id'] ?? 0));

        $page_title = t('fax_send.title');
        static::renderPage('fax_send/index', [
            'user_ext' => $user_ext,
            'user_role' => $user_role,
            'fax_users' => $fax_users,
            'own_numbers' => $own_numbers,
            'templates' => FaxCoverService::templatesFor((int) $user_id),
            'csrf_token' => $csrf_token,
            'cover' => FaxCoverService::settings(),
        ], ['title' => $page_title] + $notices);
    }
}
