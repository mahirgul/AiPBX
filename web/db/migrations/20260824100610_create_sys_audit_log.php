<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Permanent audit record (audit log) — 2026-08-24, user request.
 * DIFFERENT from `sys_pending_sync` (the pending list, whose ROWS ARE DELETED
 * after Apply): this table is INSERT-ONLY, no row is ever deleted — "who
 * changed what and when" stays here permanently. Every time markPendingSync()
 * is called (a PBX setting saved/deleted) a row lands here too;
 * applyPendingSync() logs separately as well (who pressed Apply, which
 * domain, successful or not). username is DENORMALIZED (a snapshot of
 * users.full_name at that moment) — even if the user is deleted/renamed
 * later, the history keeps showing "whoever did it at that moment".
 */
final class CreateSysAuditLog extends AbstractMigration
{
    public function change(): void
    {
        $this->table('sys_audit_log', ['id' => true, 'primary_key' => 'id'])
            ->addColumn('domain', 'string', ['limit' => 50, 'null' => true])
            ->addColumn('entity_type', 'string', ['limit' => 50, 'null' => false])
            ->addColumn('entity_id', 'string', ['limit' => 100, 'null' => true])
            ->addColumn('entity_label', 'string', ['limit' => 255, 'null' => false])
            ->addColumn('action', 'string', ['limit' => 20, 'null' => false])
            ->addColumn('user_id', 'integer', ['null' => true])
            ->addColumn('username', 'string', ['limit' => 150, 'null' => true])
            ->addColumn('ip_address', 'string', ['limit' => 45, 'null' => true])
            ->addColumn('created_at', 'datetime', ['null' => false])
            ->addIndex(['created_at'])
            ->addIndex(['domain'])
            ->addIndex(['user_id'])
            ->create();
    }
}
