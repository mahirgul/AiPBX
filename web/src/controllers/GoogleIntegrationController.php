<?php

require_once __DIR__ . '/../../auth.php';
require_once __DIR__ . '/../services/GoogleAuthService.php';

/**
 * Sign in with Google page (/google-integration, /integrations/google).
 * Renders templates/views/google_integration/index.php with assets/js/google_integration.js.
 * Logic: GoogleAuthService.
 * POST actions: action.
 * Admins only.
 */
class GoogleIntegrationController extends BaseController
{
    public static function index(): void
    {
        requireLogin();
        static::requireRole('admin');

        $notices = static::handlePost([
            'action' => fn() => match (true) {
                !static::verifyCsrf() => ['success' => false, 'error' => t('common.invalid_csrf')],
                ($_POST['action'] ?? '') === 'save_settings' => GoogleAuthService::saveSettings($_POST),
                default => ['success' => true],
            },
        ]);


        $page_title = t('google_integration.title');
        static::renderPage('google_integration/index', [
            'settings' => GoogleAuthService::settingsForPage(),
            'csrf_token' => getCSRFToken(),
        ], ['title' => $page_title] + $notices);
    }
}
