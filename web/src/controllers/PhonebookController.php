<?php

declare(strict_types=1);

require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../services/PhonebookService.php';

/**
 * /phonebook: the shared company contacts and the LDAP phonebook for desk phones.
 */
class PhonebookController extends BaseController
{
    public static function index(): void
    {
        static::requireLogin();
        static::requireModule('phones', 'access');
        $canEdit = hasModulePermission('phones', 'edit');
        $canDelete = hasModulePermission('phones', 'delete');
        $notices = ['message' => '', 'error' => ''];

        if (static::isPost()) {
            if (!verifyCSRFToken((string) ($_POST['csrf_token'] ?? ''))) {
                $notices['error'] = t('common.invalid_csrf');
            } else {
                $notices = self::applyAction((string) ($_POST['action'] ?? ''), $canEdit, $canDelete);
            }
        }

        static::renderPage('phonebook/index', [
            'contacts' => PhonebookService::contacts(),
            'counts' => PhonebookService::countsBySource(),
            'ldap' => PhonebookService::ldapSettings(),
            'listening' => ['ldap' => PhonebookService::listening(389), 'ldaps' => PhonebookService::listening(636)],
            'host' => preg_replace('/:\d+$/', '', (string) ($_SERVER['HTTP_HOST'] ?? '')),
            'canEdit' => $canEdit,
            'canDelete' => $canDelete,
            'notices' => $notices,
        ]);
    }

    /** @return array{message: string, error: string} */
    private static function applyAction(string $action, bool $canEdit, bool $canDelete): array
    {
        $ok = fn(string $key) => ['message' => t($key), 'error' => ''];
        $fail = fn(string $key) => ['message' => '', 'error' => t($key)];
        $user = $_SESSION['user_id'] ?? null;

        if ($action === 'save_contact' && $canEdit) {
            $id = (int) ($_POST['contact_id'] ?? 0);
            $err = PhonebookService::save($id, $_POST);
            if ($err !== '') {
                return $fail($err);
            }
            writeAuditLog(null, 'phonebook', $id ?: null, 'Phonebook contact ' . ($id > 0 ? 'changed' : 'added'), $id > 0 ? 'update' : 'create', $user);
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
        if ($action === 'ldap_settings' && $canEdit) {
            PhonebookService::saveLdapSettings(!empty($_POST['enabled']), !empty($_POST['anonymous']), !empty($_POST['new_password']));
            writeAuditLog(null, 'phonebook', null, 'LDAP phonebook settings saved', 'update', $user);
            return $ok('phonebook.msg_ldap_saved');
        }
        return $fail('common.action_failed');
    }
}
