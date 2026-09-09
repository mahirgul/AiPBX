<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * sys_users tablosuna gelişmiş çağrı yönlendirme alternatifleri eklenmesi:
 * - cf_busy_number (Meşgulken Yönlendir)
 * - cf_noanswer_number (Cevapsızken Yönlendir)
 * - cf_noanswer_timeout (Cevapsız yönlendirme zil süresi)
 */
final class AddCallForwardingOptionsToUsers extends AbstractMigration
{
    public function change(): void
    {
        $this->table('sys_users')
            ->addColumn('cf_busy_number', 'string', [
                'limit' => 20,
                'null' => true,
                'default' => null,
                'after' => 'call_forward_number',
            ])
            ->addColumn('cf_noanswer_number', 'string', [
                'limit' => 20,
                'null' => true,
                'default' => null,
                'after' => 'cf_busy_number',
            ])
            ->addColumn('cf_noanswer_timeout', 'integer', [
                'limit' => 11,
                'null' => false,
                'default' => 20,
                'after' => 'cf_noanswer_number',
            ])
            ->update();
    }
}
