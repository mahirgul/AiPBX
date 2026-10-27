<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Phones found on the network (engine/aipbx_engine).
 *
 * One row per MAC the engine has seen, with the portal's decision on it:
 *
 *   status = 'new'         the engine saw it; the pool may provision it, or an
 *                          administrator may approve or ignore it
 *   status = 'ignored'     an administrator said no; the automatic step never
 *                          touches it again, however often it is seen
 *   status = 'provisioned' a phone exists for this MAC (phone_id) — this is what
 *                          keeps an already provisioned phone from being
 *                          provisioned a second time
 *
 * `phone_id` is cleared (SET NULL) when the phone is deleted, and the status
 * stays 'provisioned': a deleted phone is not silently re-created by the next
 * run, the page shows it as "was a phone, now deleted" and an administrator can
 * approve it again on purpose.
 *
 * The engine writes this table and the portal reads it; the portal is the only
 * thing that writes `status`, `phone_id` and `approved_*`. See
 * web/src/services/PhoneDiscoveryService.php.
 *
 * No seed row is needed for an existing installation: sys_settings rows are
 * created on first save, and getSystemSetting() falls back to the default in
 * PhoneDiscoveryService::SETTINGS, which the engine mirrors. They are inserted
 * here anyway so an operator looking at the table sees the switches that are
 * available.
 */
final class CreatePhoneDiscoveries extends AbstractMigration
{
    public function up(): void
    {
        $this->table('pbx_phone_discoveries', ['signed' => false])
            ->addColumn('mac', 'char', ['limit' => 12, 'null' => false])
            ->addColumn('ip', 'string', ['limit' => 45, 'null' => false, 'default' => ''])
            ->addColumn('vendor', 'string', ['limit' => 20, 'null' => false, 'default' => ''])
            ->addColumn('hostname', 'string', ['limit' => 120, 'null' => false, 'default' => ''])
            // Where the sighting came from: lease, arp, journal or sweep.
            ->addColumn('source', 'string', ['limit' => 16, 'null' => false, 'default' => ''])
            ->addColumn('status', 'string', ['limit' => 16, 'null' => false, 'default' => 'new'])
            ->addColumn('phone_id', 'integer', ['signed' => false, 'null' => true, 'default' => null])
            ->addColumn('seen_count', 'integer', ['null' => false, 'default' => 1])
            ->addColumn('first_seen_at', 'datetime', ['null' => false, 'default' => 'CURRENT_TIMESTAMP'])
            ->addColumn('last_seen_at', 'datetime', ['null' => false, 'default' => 'CURRENT_TIMESTAMP'])
            ->addColumn('approved_at', 'datetime', ['null' => true, 'default' => null])
            ->addColumn('approved_by', 'integer', ['null' => true, 'default' => null])
            ->addIndex(['mac'], ['unique' => true])
            ->addIndex(['status', 'last_seen_at'])
            ->addIndex(['phone_id'])
            ->addForeignKey('phone_id', 'pbx_phones', 'id', ['delete' => 'SET_NULL'])
            ->addForeignKey('approved_by', 'sys_users', 'id', ['delete' => 'SET_NULL'])
            ->create();

        // The switches, so they are visible in sys_settings before the page is
        // saved once. INSERT IGNORE: an existing value is never overwritten.
        $this->execute(
            "INSERT IGNORE INTO sys_settings (setting_key, setting_value) VALUES
             ('provision_discovery', '1'),
             ('provision_discovery_sweep', '0'),
             ('provision_discovery_networks', ''),
             ('provision_discovery_pool', ''),
             ('provision_discovery_models', '')"
        );
    }

    public function down(): void
    {
        $this->table('pbx_phone_discoveries')->drop()->save();
        $this->execute(
            "DELETE FROM sys_settings WHERE setting_key IN
             ('provision_discovery', 'provision_discovery_sweep', 'provision_discovery_networks',
              'provision_discovery_pool', 'provision_discovery_models')"
        );
    }
}
