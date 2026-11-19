<?php
declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Trunk monthly minutes / cost limit (Trunks -> Routing): a limit in minutes and/or in money
 * (minutes x price per minute), a warning at a percentage, and whether outbound calls are refused
 * at the limit or only warned about. TrunkUsageService counts the month from the call records.
 */
final class AddTrunkMonthlyLimits extends AbstractMigration
{
    public function up(): void
    {
        $t = $this->table('pbx_trunks');
        $cols = [
            'monthly_minutes_limit' => ['integer', ['default' => 0, 'null' => false]],
            'monthly_cost_per_minute' => ['decimal', ['precision' => 10, 'scale' => 4, 'default' => 0, 'null' => false]],
            'monthly_cost_limit' => ['decimal', ['precision' => 12, 'scale' => 2, 'default' => 0, 'null' => false]],
            'monthly_warn_percent' => ['integer', ['default' => 80, 'null' => false]],
            'monthly_block' => ['boolean', ['default' => 1, 'null' => false]],
        ];
        foreach ($cols as $name => [$type, $opts]) {
            if (!$t->hasColumn($name)) {
                $t->addColumn($name, $type, $opts);
            }
        }
        $t->update();
    }

    public function down(): void
    {
        $t = $this->table('pbx_trunks');
        foreach (['monthly_minutes_limit', 'monthly_cost_per_minute', 'monthly_cost_limit', 'monthly_warn_percent', 'monthly_block'] as $c) {
            if ($t->hasColumn($c)) {
                $t->removeColumn($c);
            }
        }
        $t->update();
    }
}
