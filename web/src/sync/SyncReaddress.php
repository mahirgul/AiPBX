<?php
/**
 * Pending domain "readdress": the server's address changed outside the portal (a new DHCP lease, a
 * cloned VM), and what still holds the old one moves to the new one on Apply. The rows are queued by
 * NetworkSettingsService::queueReaddress(); entity_id is "old>new".
 */
function syncReaddress(): void
{
    require_once dirname(__DIR__) . '/services/NetworkSettingsService.php';
    $ids = getDB()->query("SELECT entity_id FROM sys_pending_sync WHERE domain = 'readdress'")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($ids as $id) {
        [$old, $new] = array_pad(explode('>', (string) $id, 2), 2, '');
        $r = NetworkSettingsService::readdress($old, $new, $_SESSION['user_id'] ?? null);
        if (!$r['synced']) {
            throw new \RuntimeException(t('netset.readdress_sync_failed'));
        }
        if (!$r['files_ok']) {
            throw new \RuntimeException(t('netset.readdress_files_failed') . ' ' . $r['files']);
        }
    }
    if (!empty($ids)) {
        syncAllExtensions();
        getDB()->exec("DELETE FROM sys_pending_sync WHERE domain = 'extensions'");
    }
}
