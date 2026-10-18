<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/services/WebhookService.php';

/**
 * /webhooks — Outbound Webhooks & Event Delivery Queue Controller (admin only).
 */
class WebhooksController extends BaseController
{
    public static function index(): void
    {
        static::requireRole('admin');
        $message = '';
        $error = '';

        if (static::isPost()) {
            if (!static::verifyCsrf()) {
                $error = t('common.invalid_csrf');
            } else {
                $csrf = static::csrfToken();
                if (isset($_POST['save_webhook'])) {
                    $res = WebhookService::save($_POST);
                    ['message' => $message, 'error' => $error] = static::notices($res);
                } elseif (isset($_POST['test_webhook_id'])) {
                    $res = WebhookService::sendTestPing((int)$_POST['test_webhook_id'], $csrf);
                    ['message' => $message, 'error' => $error] = static::notices($res);
                } elseif (isset($_POST['delete_webhook_id'])) {
                    $res = WebhookService::delete((int)$_POST['delete_webhook_id'], $csrf);
                    ['message' => $message, 'error' => $error] = static::notices($res);
                } elseif (isset($_POST['process_queue_now'])) {
                    $stats = WebhookService::processPendingBatch(50);
                    $message = sprintf(
                        t('webhooks.msg_queue_processed', 'Processed %d queued job(s): %d delivered, %d failed.'),
                        $stats['processed'],
                        $stats['delivered'],
                        $stats['failed']
                    );
                }
            }
        }

        static::renderPage('webhooks/index', [
            'webhooks'          => WebhookService::all(),
            'supported_events'  => WebhookService::SUPPORTED_EVENTS,
            'recent_deliveries' => WebhookService::recentDeliveries(50),
            'csrf_token'        => getCSRFToken(),
        ], [
            'title'   => t('webhooks.title', 'Outbound Webhooks'),
            'message' => $message,
            'error'   => $error,
        ]);
    }
}
