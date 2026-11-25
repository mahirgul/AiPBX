<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/services/ConnectionService.php';

/**
 * /connections — outside systems (Odoo, CRMs, n8n) with their own token and webhook (admin only).
 * The connection pack is shown in the answer to create/rotate only; it is not stored readable.
 */
class ConnectionsController extends BaseController
{
    public static function index(): void
    {
        static::requireRole('admin');
        $message = '';
        $error = '';
        $pack = '';

        if (static::isPost()) {
            if (!static::verifyCsrf()) {
                $error = t('common.invalid_csrf');
            } else {
                $csrf = static::csrfToken();
                $id = (int) ($_POST['connection_id'] ?? 0);
                $action = (string) ($_POST['action'] ?? '');
                $res = match ($action) {
                    'create' => ConnectionService::create($_POST, $csrf),
                    'rotate' => ConnectionService::rotate($id, $csrf),
                    'test' => ConnectionService::test($id, $csrf),
                    'enable', 'disable' => ConnectionService::setActive($id, $action === 'enable', $csrf),
                    'delete' => ConnectionService::delete($id)
                        ? ['success' => true, 'message' => t('connections.msg_deleted')]
                        : ['success' => false, 'message' => t('common.not_found')],
                    default => ['success' => false, 'message' => t('common.action_failed')],
                };
                $pack = (string) ($res['pack'] ?? '');
                if (!empty($res['success'])) {
                    $message = (string) ($res['message'] ?? '');
                } else {
                    $error = (string) ($res['message'] ?? $res['error'] ?? t('common.action_failed'));
                }
            }
        }

        if (!headers_sent()) {
            header('Cache-Control: private, no-store');
        }
        static::renderPage('connections/index', [
            'connections' => ConnectionService::all(),
            'pack' => $pack,
            'message' => $message,
            'error' => $error,
        ], ['title' => t('connections.title')]);
    }
}
