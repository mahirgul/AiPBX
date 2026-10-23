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
 * `pbx_notice_reads` is per person, not per install. The cabinet is shown to
 * every administrator, and a problem is not dismissed because one of them
 * happened to open the bell first: each administrator marks their own reads.
 * One row per (notice, user) keeps that answerable without touching the notice
 * itself, and a notice with no row for a user is simply unread for them.
 *
 * `emailed_at` says whether the mail went out, so a notice that was e-mailed
 * can be told apart from one that was not (mail off, quiet hours, warn/info).
 */
final class CreatePbxNotices extends AbstractMigration
{
    public function up(): void
    {
        if (!$this->hasTable('pbx_notices')) {
            $this->table('pbx_notices', ['collation' => 'utf8mb4_general_ci', 'encoding' => 'utf8mb4'])
                ->addColumn('created_at', 'datetime', ['null' => false])
                ->addColumn('severity', 'string', ['limit' => 8, 'default' => 'info', 'null' => false])
                ->addColumn('title', 'string', ['limit' => 150, 'default' => '', 'null' => false])
                ->addColumn('body', 'text', ['null' => false])
                ->addColumn('source', 'string', ['limit' => 32, 'default' => 'core', 'null' => false])
                ->addColumn('dedupe_key', 'string', ['limit' => 64, 'default' => '', 'null' => false])
                ->addColumn('emailed_at', 'datetime', ['null' => true, 'default' => null])
                ->addIndex(['created_at'])
                ->addIndex(['dedupe_key', 'created_at'])
                ->create();
        }

        if (!$this->hasTable('pbx_notice_reads')) {
            $this->table('pbx_notice_reads', [
                'id' => false,
                'primary_key' => ['notice_id', 'user_id'],
                'collation' => 'utf8mb4_general_ci',
                'encoding' => 'utf8mb4',
            ])
                ->addColumn('notice_id', 'integer', ['null' => false])
                ->addColumn('user_id', 'integer', ['null' => false])
                ->addColumn('read_at', 'datetime', ['null' => false])
                ->addIndex(['user_id'])
                ->create();
        }
    }

    public function down(): void
    {
        if ($this->hasTable('pbx_notice_reads')) {
            $this->table('pbx_notice_reads')->drop()->save();
        }
        if ($this->hasTable('pbx_notices')) {
            $this->table('pbx_notices')->drop()->save();
        }
    }
}
