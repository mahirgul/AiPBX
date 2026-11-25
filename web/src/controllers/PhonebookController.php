<?php

declare(strict_types=1);

require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../services/PhonebookService.php';
require_once __DIR__ . '/../services/ExternalDbQueryService.php';

/**
 * /phonebook: the shared company contacts and the LDAP phonebook for desk phones.
 */
class PhonebookController extends BaseController
{
    public static function index(): void
    {
        static::requireLogin();
        static::requireModule('phonebook', 'access');
        $canEdit = hasModulePermission('phonebook', 'edit');
        $canDelete = hasModulePermission('phonebook', 'delete');
        // The LDAP server for desk phones and the matching rule are PBX settings.
        $canAdmin = hasModulePermission('phones', 'edit');
        $notices = ['message' => '', 'error' => ''];

        if (static::isPost()) {
            if (!verifyCSRFToken((string) ($_POST['csrf_token'] ?? ''))) {
                $notices['error'] = t('common.invalid_csrf');
            } else {
                $notices = self::applyAction((string) ($_POST['action'] ?? ''), $canEdit, $canDelete, $canAdmin);
            }
        }

        $ldap = PhonebookService::ldapSettings();
        static::renderPage('phonebook/index', [
            'contacts' => PhonebookService::contacts(),
            'counts' => PhonebookService::countsBySource(),
            'ldap' => $ldap,
            'external_dbs' => ExternalDbRepository::findActive(),
            'saved_queries' => array_values(array_filter(ExternalDbQueryService::all(), static fn ($q) => ExternalDbQueryService::available($q) && $q['access_mode'] === 'public' && !DataSourceService::placeholders((string) $q['query_sql'], true))),
            'extdb_settings' => PhonebookService::externalDbSettings(),
            'canAdmin' => $canAdmin,
            'listening' => $canAdmin && $ldap['enabled'] ? ['ldap' => PhonebookService::listening(389), 'ldaps' => PhonebookService::listening(636)] : [],
            'host' => preg_replace('/:\d+$/', '', (string) ($_SERVER['HTTP_HOST'] ?? '')),
            'matchDigits' => PhonebookService::matchDigits(),
            'autoSave' => getSystemSetting('phonebook_auto_save_callers', '0') === '1',
            // "Save to address book" from the call list: the form opens with this number.
            'prefill' => ($canEdit && !static::isPost()) ? preg_replace('/[^0-9+*#]/', '', (string) ($_GET['add'] ?? '')) : '',
            'canEdit' => $canEdit,
            'canDelete' => $canDelete,
            'notices' => $notices,
        ], ['title' => t('phonebook.title')]);
    }

    /** @return array{message: string, error: string} */
    private static function applyAction(string $action, bool $canEdit, bool $canDelete, bool $canAdmin): array
    {
        $ok = fn(string $key) => ['message' => t($key), 'error' => ''];
        $fail = fn(string $key) => ['message' => '', 'error' => t($key)];
        $user = $_SESSION['user_id'] ?? null;

        if ($action === 'save_contact' && $canEdit) {
            $id = (int) ($_POST['contact_id'] ?? 0);
            [$savedId, $err] = PhonebookService::store($id, $_POST, ($_POST['is_vip'] ?? '') === '1');
            if ($err !== '') {
                return $fail($err);
            }
            writeAuditLog(null, 'phonebook', $savedId ?: null, 'Phonebook contact ' . ($id > 0 ? 'changed' : 'added'), $id > 0 ? 'update' : 'create', $user);
            return $ok('phonebook.msg_saved');
        }
        if ($action === 'delete_contact' && $canDelete) {
            $id = (int) ($_POST['contact_id'] ?? 0);
            if (!PhonebookService::delete($id)) {
                return $fail('common.action_failed');
            }
            writeAuditLog(null, 'phonebook', $id, 'Phonebook contact deleted', 'delete', $user);
            return $ok('phonebook.msg_deleted');
        }
        if ($action === 'import_csv' && $canEdit) {
            $tmp = (string) ($_FILES['csv_file']['tmp_name'] ?? '');
            if ($tmp === '' || !is_uploaded_file($tmp)) {
                return $fail('phonebook.err_no_file');
            }
            [$added, $skipped] = PhonebookService::importCsv($tmp);
            writeAuditLog(null, 'phonebook', null, "Phonebook CSV import: {$added} added, {$skipped} skipped", 'create', $user);
            return ['message' => sprintf(t('phonebook.msg_imported'), $added, $skipped), 'error' => ''];
        }
        if ($action === 'import_external_db' && $canEdit) {
            $queryKey = trim((string) ($_POST['saved_query_key'] ?? ''));
            $nightly = !empty($_POST['nightly_sync']);
            try {
                $q = $queryKey !== '' ? ExternalDbQueryService::find($queryKey) : null;
                if (!$q || !ExternalDbQueryService::available($q) || $q['access_mode'] !== 'public'
                    || DataSourceService::placeholders((string) $q['query_sql'], true)) {
                    throw new \InvalidArgumentException(t('external_dbs.query_select_public'));
                }
                $res = PhonebookService::syncFromExternalDb((int) $q['external_db_id'], (string) $q['query_sql']);
                if ($canAdmin) {
                    PhonebookService::saveExternalDbSettings((int) $q['external_db_id'], (string) $q['query_sql'], $nightly, (string) $q['query_key']);
                }
                writeAuditLog(null, 'phonebook', null, "Phonebook external DB sync: {$res['added']} added, {$res['updated']} updated, {$res['skipped']} skipped", 'create', $user);
                return ['message' => sprintf(t('phonebook.msg_extdb_synced'), $res['added'], $res['updated'], $res['skipped']), 'error' => ''];
            } catch (\Throwable $e) {
                return ['message' => '', 'error' => $e->getMessage()];
            }
        }
        if ($action === 'match_digits' && $canAdmin) {
            if (!PhonebookService::saveMatchDigits((int) ($_POST['caller_match_digits'] ?? -1))) {
                return $fail('common.action_failed');
            }
            setSystemSetting('phonebook_auto_save_callers', !empty($_POST['auto_save_callers']) ? '1' : '0');
            writeAuditLog(null, 'phonebook', null, 'Caller number matching changed', 'update', $user);
            return $ok('phonebook.msg_match_saved');
        }
        if ($action === 'ldap_settings' && $canAdmin) {
            PhonebookService::saveLdapSettings(!empty($_POST['enabled']), !empty($_POST['anonymous']), !empty($_POST['new_password']));
            writeAuditLog(null, 'phonebook', null, 'LDAP phonebook settings saved', 'update', $user);
            return $ok('phonebook.msg_ldap_saved');
        }
        return $fail('common.action_failed');
    }
}
