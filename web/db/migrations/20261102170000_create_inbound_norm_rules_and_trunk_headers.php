<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Rules that rewrite the caller's number or the dialled number of incoming calls, for one trunk or
 * all of them, and SIP headers a trunk adds to outgoing calls.
 */
final class CreateInboundNormRulesAndTrunkHeaders extends AbstractMigration
{
    public function change(): void
    {
        if (!$this->hasTable('pbx_inbound_norm_rules')) {
            $this->table('pbx_inbound_norm_rules')
                ->addColumn('trunk_name', 'string', ['limit' => 100, 'null' => true])
                ->addColumn('target', 'string', ['limit' => 10])
                ->addColumn('rule_name', 'string', ['limit' => 120])
                ->addColumn('match_pattern', 'string', ['limit' => 40])
                ->addColumn('strip_front', 'integer', ['default' => 0])
                ->addColumn('prepend', 'string', ['limit' => 30, 'default' => ''])
                ->addColumn('is_active', 'boolean', ['default' => true])
                ->addTimestamps()
                ->addIndex(['trunk_name', 'target', 'match_pattern'], ['unique' => true])
                ->create();
        }
        $trunks = $this->table('pbx_trunks');
        if (!$trunks->hasColumn('custom_sip_headers')) {
            $trunks->addColumn('custom_sip_headers', 'text', ['null' => true, 'after' => 'custom_pjsip_params'])->update();
        }
    }
}
