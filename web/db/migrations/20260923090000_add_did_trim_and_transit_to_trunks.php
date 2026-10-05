<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Adds inbound DID trimming and trunk-to-trunk (transit) settings to SIP trunks:
 * - did_trim_digits: number of trailing digits kept of the incoming DID (0 = no trimming, e.g. 4 => 03704187840 -> 7840)
 * - allow_outbound_routing: allows trunk-to-trunk / passing on to outbound routes (transit calls)
 * - outbound_route_group: outbound route group used for transit (default: 1)
 */
final class AddDidTrimAndTransitToTrunks extends AbstractMigration
{
    public function change(): void
    {
        $table = $this->table('pbx_trunks');

        if (!$table->hasColumn('did_trim_digits')) {
            $table->addColumn('did_trim_digits', 'integer', [
                'default' => 0,
                'null' => false,
                'comment' => 'Gelen DID sondaki hane sayisi (0 = kirpma yok)',
                'after' => 'context',
            ]);
        }

        if (!$table->hasColumn('allow_outbound_routing')) {
            $table->addColumn('allow_outbound_routing', 'boolean', [
                'default' => false,
                'null' => false,
                'comment' => 'Giden rotalara erisim izni (Trunk-to-Trunk / Transit)',
                'after' => 'did_trim_digits',
            ]);
        }

        if (!$table->hasColumn('outbound_route_group')) {
            $table->addColumn('outbound_route_group', 'integer', [
                'default' => 1,
                'null' => false,
                'comment' => 'Transit aramalar icin giden rota grubu',
                'after' => 'allow_outbound_routing',
            ]);
        }

        $table->update();
    }
}
