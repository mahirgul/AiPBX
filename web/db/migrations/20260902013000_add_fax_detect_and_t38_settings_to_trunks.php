<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Adds fax detection and advanced T.38 / G.711 fax options to SIP trunks:
 * - fax_detect: Automatic detection of CNG/CED fax tones
 * - fax_detect_timeout: Detection timeout in seconds
 * - t38_udptl_maxdatagram: Maximum UDPTL datagram size for T.38
 */
final class AddFaxDetectAndT38SettingsToTrunks extends AbstractMigration
{
    public function change(): void
    {
        $this->table('pbx_trunks')
            ->addColumn('t38_udptl_maxdatagram', 'integer', [
                'default' => 400,
                'after' => 't38_udptl_nat',
            ])
            ->addColumn('fax_detect', 'boolean', [
                'default' => 1,
                'after' => 't38_udptl_maxdatagram',
            ])
            ->addColumn('fax_detect_timeout', 'integer', [
                'default' => 30,
                'after' => 'fax_detect',
            ])
            ->update();
    }
}
