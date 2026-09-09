<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * "Aynı anda başka bir admin de sistemde" uyarısı için (2026-08-24, kullanıcı
 * isteği) — her istekte (en fazla ~20sn'de bir, throttled) güncellenir, bkz.
 * auth.php::_touchLastSeen(). last_activity (SESSION'da, idle-timeout için)
 * ile KARIŞTIRILMASIN — bu DB'de, session'lar arası "kim şu an aktif"
 * sorgusu için.
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
