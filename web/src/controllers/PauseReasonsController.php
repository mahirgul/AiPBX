<?php
require_once __DIR__ . '/../repositories/PauseReasonRepository.php';

/**
 * Call Center Break / Pause Reason Definitions (/pause-reasons).
 */
class PauseReasonsController extends BaseController
{
    public static function index(): void
    {
        static::requireRole(['admin', 'cc_manager']);

        $token = (string) ($_POST['csrf_token'] ?? '');
        $notices = static::handlePost([
            'save_reason' => static fn () => PBXHelper::handleAction($token, static function (): string {
                $id = (int) ($_POST['id'] ?? 0);
                $code = trim((string) ($_POST['code'] ?? ''));
                $name = trim((string) ($_POST['name'] ?? ''));
                $icon = trim((string) ($_POST['icon'] ?? '☕'));
                $maxRaw = trim((string) ($_POST['max_minutes'] ?? ''));
                $maxMinutes = $maxRaw !== '' ? max(1, (int) $maxRaw) : null;
                $sortOrder = (int) ($_POST['sort_order'] ?? 0);
                $isActive = !empty($_POST['is_active']) ? 1 : 0;
                $warnOnExceed = !empty($_POST['warn_on_exceed']) ? 1 : 0;
                $autoUnpause = !empty($_POST['auto_unpause']) ? 1 : 0;
                PauseReasonRepository::saveReason($id, $code, $name, $icon, $maxMinutes, $sortOrder, $isActive, $warnOnExceed, $autoUnpause);
                return t('common.saved', 'Saved.');
            }),
            'toggle_status' => static fn () => PBXHelper::handleAction($token, static function (): string {
                PauseReasonRepository::toggleActive((int) ($_POST['reason_id'] ?? 0));
                return t('common.saved', 'Saved.');
            }),
            'delete_reason' => static fn () => PBXHelper::handleAction($token, static function (): string {
                PauseReasonRepository::deleteById((int) ($_POST['reason_id'] ?? 0));
                return t('common.deleted', 'Deleted.');
            }),
        ]);

        static::renderPage('pause_reasons/index', [
            'csrf' => getCSRFToken(),
            'reasons' => PauseReasonRepository::allOrdered(),
        ], ['title' => t('pause_reports.col_reason', 'Break Reasons')] + $notices);
    }
}
