<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * For the "another admin is in the system right now" warning (2026-08-24,
 * user request) — updated on requests (at most every ~20 s, throttled), see
 * auth.php::_touchLastSeen(). Do NOT CONFUSE it with last_activity (in the
 * SESSION, for the idle timeout) — this one is in the DB, for the
 * cross-session "who is active right now" query.
 */
final class AddLastSeenAtToUsers extends AbstractMigration
{
    public function change(): void
    {
        $this->table('sys_users')
            ->addColumn('last_seen_at', 'datetime', [
                'null' => true,
                'after' => 'language_preference',
            ])
            ->update();
    }
}
