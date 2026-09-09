<?php

use Phinx\Migration\AbstractMigration;

/**
 * Giden rota grupları.
 *
 * Amaç: hangi kullanıcının hangi giden rotaları kullanacağını gruplayarak
 * ayırmak. Örnek: faks kullanıcıları grup 2'deki rotaları, diğerleri grup
 * 1'dekileri kullansın.
 *
 * Varsayılan her iki tarafta da 1 — yani göç sonrası davranış AYNEN korunur;
 * kimse grup atamadıkça hiçbir çağrı yolu değişmez.
 */
final class OutboundRouteGroups extends AbstractMigration
{
    public function up(): void
    {
        if (!$this->table('pbx_outbound_routes')->hasColumn('route_group')) {
            $this->table('pbx_outbound_routes')
                 ->addColumn('route_group', 'integer', [
                     'default' => 1, 'null' => false, 'after' => 'is_internal',
                     'comment' => 'Bu rotayı hangi kullanıcı grubu kullanabilir',
                 ])
                 ->addIndex(['route_group'], ['name' => 'idx_route_group'])
                 ->update();
        }

        if (!$this->table('sys_users')->hasColumn('outbound_group')) {
            $this->table('sys_users')
                 ->addColumn('outbound_group', 'integer', [
                     'default' => 1, 'null' => false, 'after' => 'extension_type',
                     'comment' => 'Kullanıcının kullanabileceği giden rota grubu',
                 ])
                 ->update();
        }
    }

    public function down(): void
    {
        if ($this->table('pbx_outbound_routes')->hasColumn('route_group')) {
            $this->table('pbx_outbound_routes')->removeColumn('route_group')->update();
        }
        if ($this->table('sys_users')->hasColumn('outbound_group')) {
            $this->table('sys_users')->removeColumn('outbound_group')->update();
        }
    }
}
