<?php
/**
 * "Uygula" sayfası — ertelenmiş Asterisk reload sistemi (2026-08-24).
 * Admin panelinde kaydedilen PBX değişiklikleri artık kaydedildiği anda
 * Asterisk'e yansıtılmıyor; bu sayfa bekleyen değişiklikleri listeler ve
 * "Gönder" ile gerçek regen+reload'ı (applyPendingSync()) tetikler.
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
                    // 2026-08-24: artık admin'e sadece "kaç domain başarısız oldu"
                    // değil, Asterisk'in GERÇEK hata çıktısı da gösteriliyor
                    // (AsteriskHelper::assertReloadsOk() Exception mesajına ham
                    // çıktıyı gömüyor) — önceden bu bilgi hiçbir yerde
                    // yakalanmıyordu, admin her zaman "başarılı" görürdü.
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
        require_once dirname(__DIR__) . '/../header.php';
        static::render('pending_sync/index', [
            'pending' => $pending,
            'domain_map' => PENDING_SYNC_DOMAIN_MAP,
        ]);
        require_once dirname(__DIR__) . '/../footer.php';
    }
}
