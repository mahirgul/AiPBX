<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * A trunk listens for a fax tone for the first 10 seconds of a call, not 30. Later the call is in a
 * ring group, queue or IVR, where Asterisk has nowhere to send a fax and only logs it (issue #153);
 * a route that takes faxes listens 10 seconds itself. Trunks still on the old default move with it,
 * and wait on Apply to reach Asterisk.
 */
final class TrunkFaxDetectTenSeconds extends AbstractMigration
{
    public function up(): void
    {
        $this->table('pbx_trunks')->changeColumn('fax_detect_timeout', 'integer', ['default' => 10])->update();
        $moved = $this->execute('UPDATE pbx_trunks SET fax_detect_timeout = 10 WHERE fax_detect_timeout = 30')
            + $this->execute("UPDATE sip SET data = '10' WHERE keyword = 'fax_detect_timeout' AND data = '30'");
        if ($moved > 0 && $this->hasTable('sys_pending_sync')) {
            $this->execute("INSERT INTO sys_pending_sync (domain, entity_type, entity_id, entity_label, action, changed_at)
                VALUES ('trunks', 'trunk', 'fax_detect_timeout', 'Fax detection on trunks: 10 seconds (update)', 'update', NOW())
                ON DUPLICATE KEY UPDATE changed_at = NOW()");
        }
    }

    public function down(): void
    {
        $this->table('pbx_trunks')->changeColumn('fax_detect_timeout', 'integer', ['default' => 30])->update();
    }
}
