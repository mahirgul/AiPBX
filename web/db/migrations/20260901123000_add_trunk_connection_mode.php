<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Trunk (SIP trunk) connection mode.
 *
 * 'ip'       : the far PBX has a fixed IP; identity is checked by IP (type=identify)
 *              and a STATIC contact is kept on the AOR. The only behaviour so far.
 * 'register' : the far PBX registers TO US. The static contact on the AOR is removed
 *              (the address is learned from the registration) and INBOUND
 *              authentication (auth=) is added to the endpoint.
 *
 * The default is deliberately 'ip': the behaviour of existing trunks does NOT
 * CHANGE with the migration; the mode only takes effect when explicitly
 * chosen in the panel (live PBX).
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
