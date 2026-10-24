<?php

use Phinx\Migration\AbstractMigration;

/**
 * "never" was offered for trunk session timers, but Asterisk does not know it:
 * the trunk's endpoint was refused and the trunk did not work. It meant "no".
 */
final class FixTrunkTimersNever extends AbstractMigration
{
    public function up(): void
    {
        $this->execute("UPDATE pbx_trunks SET timers = 'no' WHERE timers = 'never'");
    }

    public function down(): void
    {
    }
}
