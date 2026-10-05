<?php
/**
 * The "Apply" page — deferred Asterisk reload system (2026-08-24).
 * PBX changes saved in the admin panel no longer reach Asterisk the moment
 * they are saved; this page lists the pending changes and "Apply" triggers
 * the real regen+reload (applyPendingSync()).
 */
require_once __DIR__ . '/../helpers.php';

class PendingSyncController extends BaseController
{
    public static function index(): void
    {
        static::requireRole('admin');

        $applied_results = null;

        if (static::isPost() && isset($_POST['apply'])) {
            if (!static::verifyCsrf()) {
                static::notifyError(t('pending_sync.msg_csrf_error'));
            } else {
                $applied_results = applyPendingSync($_SESSION['user_id'] ?? null);
                $ok_count = count(array_filter($applied_results, fn($r) => $r['success']));
                $fail_count = count($applied_results) - $ok_count;

                if ($ok_count > 0) {
                    static::notifySuccess(sprintf(t('pending_sync.msg_applied'), $ok_count));
                }
                if ($fail_count > 0) {
                    // 2026-08-24: the admin now sees not just "how many domains
                    // failed" but Asterisk's REAL error output too
                    // (AsteriskHelper::assertReloadsOk() embeds the raw output in
                    // the Exception message) — this information used to be
                    // captured nowhere, and the admin always saw "success".
                    $fail_details = [];
                    foreach ($applied_results as $domain => $r) {
                        if (!$r['success']) {
                            $domain_label = t('pending_sync.domain_' . $domain, $domain);
                            $fail_details[] = "{$domain_label}: " . ($r['error'] ?? '?');
                        }
                    }
                    static::notifyError(sprintf(t('pending_sync.msg_apply_failed'), $fail_count) . ' ' . implode(' | ', $fail_details));
                }
                if ($ok_count === 0 && $fail_count === 0) {
                    static::notifySuccess(t('pending_sync.msg_nothing_pending'));
                }
            }
        }

        $pending = getPendingSyncList();

        $page_title = t('pending_sync.title');
        static::renderPage('pending_sync/index', [
            'pending' => $pending,
            'domain_map' => PENDING_SYNC_DOMAIN_MAP,
        ], ['title' => $page_title]);
    }
}
