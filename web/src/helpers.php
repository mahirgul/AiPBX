<?php
/**
 * Loads the domain services (src/services/) and holds the two helpers they
 * share: handleAction() (CSRF check + exception safety) and toggleStatus().
 */

require_once __DIR__ . '/db_helper.php';
require_once __DIR__ . '/file_helper.php';
require_once __DIR__ . '/asterisk_helper.php';
require_once __DIR__ . '/asterisk_sync.php';

// Require Domain Services
require_once __DIR__ . '/services/UserService.php';
require_once __DIR__ . '/services/TrunkService.php';
require_once __DIR__ . '/services/QueueService.php';
require_once __DIR__ . '/services/IVRService.php';
require_once __DIR__ . '/services/TimeConditionService.php';
require_once __DIR__ . '/services/RouteService.php';
require_once __DIR__ . '/services/SoundService.php';
require_once __DIR__ . '/services/SoundPackService.php';
require_once __DIR__ . '/services/ExtensionService.php';
require_once __DIR__ . '/services/FeatureCodeService.php';
require_once __DIR__ . '/services/HangupActionService.php';

class PBXHelper {
    /**
     * Unified Form Action Handler with CSRF Check & Exception Safety
     */
    public static function handleAction($csrf_token, callable $action) {
        if (!verifyCSRFToken($csrf_token)) {
            return ['success' => false, 'error' => t('common.invalid_csrf')];
        }
        try {
            $msg = $action();
            return ['success' => true, 'message' => is_string($msg) ? $msg : t('common.done')];
        } catch (\PDOException $e) {
            return ['success' => false, 'error' => sprintf(t('common.db_error'), $e->getMessage())];
        } catch (\Exception $e) {
            return ['success' => false, 'error' => 'Hata: ' . $e->getMessage()];
        }
    }

    /**
     * Unified Toggle Active/Passive Status
     */
    public static function toggleStatus($table, $id, $csrf_token) {
        return self::handleAction($csrf_token, function() use ($table, $id) {
            // domain: the PENDING_SYNC_DOMAIN_MAP key (until 2026-08-24 this
            // function called syncXxx() DIRECTLY/AT ONCE — in the rollout that
            // moved 11 service files to the deferred reload system
            // (2026-08-24) it was missed because this general-purpose helper
            // lives in src/helpers.php; it was found during the user's
            // audit-log extension request and moved to the same system.
            // label_col: the column read for entity_label.
            $allowed_tables = [
                'sys_users' => ['domain' => 'extensions', 'label_col' => 'full_name', 'entity_type' => 'extension'],
                'pbx_trunks' => ['domain' => 'trunks', 'label_col' => 'title', 'entity_type' => 'trunk'],
                'pbx_dids' => ['domain' => 'inbound_dialplan', 'label_col' => 'title', 'entity_type' => 'did_route'],
                'pbx_outbound_routes' => ['domain' => 'outbound_dialplan', 'label_col' => 'route_name', 'entity_type' => 'outbound_route'],
                'pbx_queues' => ['domain' => 'queues', 'label_col' => 'title', 'entity_type' => 'queue', 'has_internal_number' => true],
                'pbx_time_conditions' => ['domain' => 'time_conditions', 'label_col' => 'title', 'entity_type' => 'time_condition', 'has_internal_number' => true],
                'pbx_ivrs' => ['domain' => 'ivrs', 'label_col' => 'title', 'entity_type' => 'ivr', 'has_internal_number' => true],
                'pbx_announcements' => ['domain' => 'inbound_dialplan', 'label_col' => 'title', 'entity_type' => 'announcement', 'has_internal_number' => true],
                'pbx_time_groups' => ['domain' => 'time_conditions', 'label_col' => 'title', 'entity_type' => 'time_group'],
                'sys_did_mappings' => ['domain' => 'inbound_dialplan', 'label_col' => 'department_name', 'entity_type' => 'did_mapping'],
            ];
            if (!isset($allowed_tables[$table])) {
                throw new \Exception("Invalid table name!");
            }
            $id = intval($id);
            $meta = $allowed_tables[$table];
            $row = DBHelper::fetchOne("SELECT is_active, {$meta['label_col']} AS label" . ($table === 'sys_users' ? ', extension' : '') . " FROM {$table} WHERE id = ?", [$id]);
            if (!$row || $row['is_active'] === null) {
                throw new \Exception(t('common.not_found'));
            }
            $current = $row['is_active'];
            $new_status = ($current == 1) ? 0 : 1;

            // sys_users + deactivation: if the last active admin account is
            // deactivated, roles.php/system_users.php open for nobody (because
            // of auth.php's admin-only circuit breaker) — the equivalent of the
            // same protection in deleteUser() applies here too (see
            // UserService::deleteUser()).
            if ($table === 'sys_users' && $new_status === 0) {
                $target_role = DBHelper::fetchColumn("SELECT role FROM sys_users WHERE id = ?", [$id]);
                if ($target_role === 'admin') {
                    $other_admins = DBHelper::fetchColumn("SELECT COUNT(*) FROM sys_users WHERE role = 'admin' AND is_active = 1 AND id != ?", [$id]);
                    if (intval($other_admins) < 1) {
                        throw new \Exception(t('srv_user.err_last_admin_disable'));
                    }
                }
            }

            DBHelper::update($table, ['is_active' => $new_status], 'id', $id);

            $status_text = ($new_status == 1) ? t('common.active') : t('common.passive');
            // sys_users special case: the active/passive state of a user
            // without an extension (e.g. plain office staff) does not affect
            // the PJSIP config at all — do not dirty the "extensions" domain
            // for nothing.
            if ($table !== 'sys_users' || !empty($row['extension'])) {
                markPendingSync($meta['domain'], $meta['entity_type'], $id, ($row['label'] ?: $id) . " ({$status_text})", 'update', $_SESSION['user_id'] ?? null);
                // Pasife alinan bir hedefin numarasi da dialplan'dan dusmeli
                // (SyncInternalNumbers yalnizca is_active=1 satirlari yazar).
                if (!empty($meta['has_internal_number'])) {
                    $num = DBHelper::fetchColumn("SELECT internal_number FROM {$table} WHERE id = ?", [$id]);
                    if (!empty($num)) {
                        markPendingSync('internal_numbers', $meta['entity_type'], $id,
                            ($row['label'] ?: $id) . " ({$status_text}, ext {$num})",
                            'update', $_SESSION['user_id'] ?? null);
                    }
                }
                if ($table === 'pbx_outbound_routes') {
                    markPendingSync('ivrs', $meta['entity_type'], $id, ($row['label'] ?: $id) . " ({$status_text})", 'update', $_SESSION['user_id'] ?? null);
                }
                return sprintf(t('common.status_set_apply'), $status_text);
            }
            return sprintf(t('common.status_set'), $status_text);
        });
    }
}

