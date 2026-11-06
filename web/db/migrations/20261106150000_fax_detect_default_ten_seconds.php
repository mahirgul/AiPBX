<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * A route listens 10 seconds for a fax tone by default, not 5: some fax machines start their tone
 * late and the call went on to the voice destination first (issue #153). Routes keep the time set on them.
 */
final class FaxDetectDefaultTenSeconds extends AbstractMigration
{
    public function up(): void
    {
        $this->table('pbx_dids')->changeColumn('fax_detect_seconds', 'integer', ['default' => 10])->update();
    }

    public function down(): void
    {
        $this->table('pbx_dids')->changeColumn('fax_detect_seconds', 'integer', ['default' => 5])->update();
    }
}
