<?php

use Phinx\Migration\AbstractMigration;

/**
 * Things the PBX found and a person should know about.
 *
 * One row per notice. `dedupe_key` is what turns "the trunk is down" into one
 * row instead of one per five-minute check: the service refuses a second notice
 * with the same key inside the configured window, so a repeating problem
 * informs once and a new problem still gets its own row.
 *
 * `read_at` is per install, not per user: every signed-in person sees the same
 * cabinet, and the first one who reads a notice is enough. A per-user table
 * would be the right shape only when notices are addressed to somebody.
 *
 * `emailed_at` says whether the mail went out, so a notice that was e-mailed
 * can be told apart from one that was not (mail off, quiet hours, warn/info).
 */
final class CreatePbxNotices extends AbstractMigration
{
    public function up(): void
    {
        if ($this->hasTable('pbx_notices')) {
            return;
        }
        $this->table('pbx_notices', ['collation' => 'utf8mb4_general_ci', 'encoding' => 'utf8mb4'])
            ->addColumn('created_at', 'datetime', ['null' => false])
            ->addColumn('severity', 'string', ['limit' => 8, 'default' => 'info', 'null' => false])
            ->addColumn('title', 'string', ['limit' => 150, 'default' => '', 'null' => false])
            ->addColumn('body', 'text', ['null' => false])
            ->addColumn('source', 'string', ['limit' => 32, 'default' => 'core', 'null' => false])
            ->addColumn('dedupe_key', 'string', ['limit' => 64, 'default' => '', 'null' => false])
            ->addColumn('read_at', 'datetime', ['null' => true, 'default' => null])
            ->addColumn('emailed_at', 'datetime', ['null' => true, 'default' => null])
            ->addIndex(['created_at'])
            ->addIndex(['dedupe_key', 'created_at'])
            ->addIndex(['read_at'])
            ->create();
    }

    public function down(): void
    {
        if ($this->hasTable('pbx_notices')) {
            $this->table('pbx_notices')->drop()->save();
        }
    }
}
