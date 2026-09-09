<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Dış Hat (SIP Trunk) bağlantı modu.
 *
 * 'ip'       : Karşı santral sabit IP'de; kimlik IP ile doğrulanır (type=identify)
 *              ve AOR'da STATİK contact tutulur. Bugüne kadarki tek davranış.
 * 'register' : Karşı santral BİZE register olur. AOR'daki statik contact kaldırılır
 *              (adres kayıttan öğrenilir), endpoint'e GELEN kimlik doğrulama
 *              (auth=) eklenir.
 *
 * Varsayılan bilinçli olarak 'ip': mevcut trunk'ların davranışı migration ile
 * DEĞİŞMEZ, mod ancak panelden açıkça seçilince devreye girer (canlı santral).
 */
final class AddTrunkConnectionMode extends AbstractMigration
{
    public function change(): void
    {
        $this->table('pbx_trunks')
            ->addColumn('connection_mode', 'string', [
                'limit' => 16,
                'null' => false,
                'default' => 'ip',
                'after' => 'transport',
                'comment' => 'ip = IP tabanli (identify), register = karsi taraf bize kaydolur',
            ])
            ->update();
    }
}
