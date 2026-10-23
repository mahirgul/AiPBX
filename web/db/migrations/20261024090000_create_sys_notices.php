<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Open problems raised by parts of AiPBX other than the health checks (a
 * plugin that sees a trunk losing media, …), shown in the top bar's bell.
 *
 * One row per problem: raising the same key again only updates it, resolving
 * it sets resolved_at. So the bell lists what is wrong now (resolved_at NULL),
 * not a log someone has to clear, and the resolved rows are its recent history
 * (kept 30 days).
 */
final class CreateSysNotices extends AbstractMigration
{
    public function up(): void
    {
        if ($this->hasTable('sys_notices')) {
            return;
        }
        $this->table('sys_notices', ['id' => false, 'primary_key' => ['notice_key'],
            'collation' => 'utf8mb4_general_ci', 'encoding' => 'utf8mb4'])
            ->addColumn('notice_key', 'string', ['limit' => 80])
            ->addColumn('severity', 'string', ['limit' => 8, 'default' => 'warn'])
            ->addColumn('title', 'string', ['limit' => 150, 'default' => ''])
            ->addColumn('message', 'string', ['limit' => 500, 'default' => ''])
            ->addColumn('link', 'string', ['limit' => 200, 'default' => ''])
            ->addColumn('source', 'string', ['limit' => 40, 'default' => 'core'])
            ->addColumn('raised_at', 'datetime')
            ->addColumn('updated_at', 'datetime')
            ->addColumn('resolved_at', 'datetime', ['null' => true])
            ->addIndex(['resolved_at'])
            ->create();
    }

    public function down(): void
    {
        if ($this->hasTable('sys_notices')) {
            $this->table('sys_notices')->drop()->save();
        }
    }
}
