<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/services/ApiTokenService.php';

/**
 * /api-keys — REST API Keys & Scopes Management Controller (admin only).
 */
class ApiKeysController extends BaseController
{
    public static function index(): void
    {
        static::requireRole('admin');
        $message = '';
        $error = '';
        $createdRawToken = '';

        if (static::isPost()) {
            if (!static::verifyCsrf()) {
                $error = t('common.invalid_csrf');
            } else {
                $csrf = static::csrfToken();
                if (isset($_POST['create_api_token'])) {
                    $res = ApiTokenService::create($_POST);
                    ['message' => $message, 'error' => $error] = static::notices($res);
                    $createdRawToken = (string)($res['raw_token'] ?? '');
                } elseif (isset($_POST['toggle_token_id'])) {
                    $res = ApiTokenService::toggle((int)$_POST['toggle_token_id'], $csrf);
                    ['message' => $message, 'error' => $error] = static::notices($res);
                } elseif (isset($_POST['delete_token_id'])) {
                    $res = ApiTokenService::delete((int)$_POST['delete_token_id'], $csrf);
                    ['message' => $message, 'error' => $error] = static::notices($res);
                }
            }
        }

        static::renderPage('api_keys/index', [
            'tokens'            => ApiTokenService::all(),
            'available_scopes'  => ApiTokenService::AVAILABLE_SCOPES,
            'created_raw_token' => $createdRawToken,
            'csrf_token'        => getCSRFToken(),
        ], [
            'title'   => t('api_keys.title', 'REST API Keys'),
            'message' => $message,
            'error'   => $error,
        ]);
    }
}
