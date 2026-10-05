<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Pending change list for the deferred Asterisk reload system.
 * When an admin saves/deletes a PBX setting it is written to the DB at once,
 * but the Asterisk config file is NOT regenerated and reloaded right away —
 * instead a "which record changed" row is added to this table. The "Apply"
 * page in the sidebar lists this table; when the admin presses "Apply", the
 * real sync+reload of the affected domains runs and that domain's rows are
 * deleted. domain matches the withSyncLock() lock names in
 * src/asterisk_sync.php exactly (extensions/queues/ivrs/trunks/...) — the
 * apply flow uses the domain -> syncXxx() map.
 *
 * (domain, entity_type, entity_id) is unique: if the same record is edited
 * several times before Apply, the row is updated (deduplicated) instead of
 * piling up one row per edit.
 */
final class CreateSysPendingSync extends AbstractMigration
{
    public function change(): void
    {
        $this->table('sys_pending_sync', ['id' => true, 'primary_key' => 'id'])
            ->addColumn('domain', 'string', ['limit' => 50, 'null' => false])
            ->addColumn('entity_type', 'string', ['limit' => 50, 'null' => false])
            ->addColumn('entity_id', 'string', ['limit' => 100, 'null' => true])
            ->addColumn('entity_label', 'string', ['limit' => 255, 'null' => false])
            ->addColumn('action', 'string', ['limit' => 20, 'null' => false])
            ->addColumn('changed_by', 'integer', ['null' => true])
            ->addColumn('changed_at', 'datetime', ['null' => false])
            ->addIndex(['domain', 'entity_type', 'entity_id'], ['unique' => true, 'name' => 'uniq_pending_entity'])
            ->addIndex(['domain'])
            ->create();
    }
}
