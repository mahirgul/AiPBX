<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * SIP Trunk'lara gelen DID kırpma ve trunk-to-trunk (transit) geçiş ayarları ekler:
 * - did_trim_digits: Gelen DID sondaki hane sayısı (0 = kırpma yok, örn: 4 => 03704187840 -> 7840)
 * - allow_outbound_routing: Trunk-to-trunk / Giden rotalara geçiş izni (transit çağrı)
 * - outbound_route_group: Transit geçişte kullanılacak giden rota grubu (varsayılan: 1)
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
