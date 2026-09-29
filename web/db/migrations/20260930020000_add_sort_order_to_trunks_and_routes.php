<?php

use Phinx\Migration\AbstractMigration;

/**
 * User-defined order (drag and drop) for trunks and outbound routes.
 * Existing rows keep their current (id) order.
 */
final class AddSortOrderToTrunksAndRoutes extends AbstractMigration
{
    public function up(): void
    {
        foreach (['pbx_trunks', 'pbx_outbound_routes'] as $name) {
            $table = $this->table($name);
            if (!$table->hasColumn('sort_order')) {
                $table->addColumn('sort_order', 'integer', ['null' => false, 'default' => 0])->update();
                $this->execute("UPDATE `{$name}` SET sort_order = id");
            }
        }
    }

    public function down(): void
    {
        foreach (['pbx_trunks', 'pbx_outbound_routes'] as $name) {
            $table = $this->table($name);
            if ($table->hasColumn('sort_order')) {
                $table->removeColumn('sort_order')->update();
            }
        }
    }
}
