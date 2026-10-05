<?php

use Phinx\Migration\AbstractMigration;

/**
 * Outbound route groups.
 *
 * Goal: separate which user uses which outbound routes by grouping them.
 * Example: fax users use the routes in group 2, everybody else those in
 * group 1.
 *
 * The default is 1 on both sides — so after the migration the behaviour stays
 * EXACTLY the same; no call path changes until someone assigns a group.
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
