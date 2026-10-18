<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Call signaling captured from the PBX itself (PBX settings -> Signaling).
 *
 * Asterisk's res_hep* copy every SIP and RTCP message they handle to the
 * capture daemon (aipbx-signaling, default 127.0.0.1:9061), which writes one
 * row per message here. The portal answers questions about the phone system
 * from these rows and can draw a call ladder for a Call-ID.
 *
 * Two deliberate choices:
 *
 *  - The SIP body is NOT stored, only a bounded summary line (`line`). A busy
 *    PBX sends ~17k messages a day on a two-phone test system; keeping whole
 *    bodies would grow this table far faster than any report needs.
 *  - `ts` is the HEP timestamp, which res_hep sends in whole seconds, so a
 *    datetime is exact — microsecond precision would be invented, not
 *    measured. `id` orders messages inside the same second.
 *
 * Retention is a setting (signaling_retention_days); the capture daemon prunes
 * old rows, so the table does not grow without bound.
 */
final class CreateSignalingEvents extends AbstractMigration
{
    public function up(): void
    {
        if ($this->hasTable('pbx_signaling_events')) {
            return;
        }
        $this->table('pbx_signaling_events', ['collation' => 'utf8mb4_general_ci', 'encoding' => 'utf8mb4'])
            ->addColumn('ts', 'datetime', ['null' => false])
            ->addColumn('call_id', 'string', ['limit' => 128, 'default' => '', 'null' => false])
            ->addColumn('method', 'string', ['limit' => 32, 'default' => '', 'null' => false])
            ->addColumn('proto', 'string', ['limit' => 8, 'default' => 'sip', 'null' => false])
            ->addColumn('transport', 'string', ['limit' => 8, 'default' => '', 'null' => false])
            ->addColumn('src', 'string', ['limit' => 64, 'default' => '', 'null' => false])
            ->addColumn('dst', 'string', ['limit' => 64, 'default' => '', 'null' => false])
            ->addColumn('from_uri', 'string', ['limit' => 255, 'default' => '', 'null' => false])
            ->addColumn('to_uri', 'string', ['limit' => 255, 'default' => '', 'null' => false])
            ->addColumn('cseq', 'string', ['limit' => 64, 'default' => '', 'null' => false])
            ->addColumn('status_code', 'string', ['limit' => 8, 'default' => '', 'null' => false])
            ->addColumn('first_line', 'string', ['limit' => 200, 'default' => '', 'null' => false])
            ->addColumn('line', 'string', ['limit' => 500, 'default' => '', 'null' => false])
            ->addColumn('capture_id', 'string', ['limit' => 32, 'default' => '', 'null' => false])
            ->addIndex(['ts'])
            ->addIndex(['call_id'])
            ->addIndex(['method'])
            ->addIndex(['ts', 'method'])
            ->create();
    }

    public function down(): void
    {
        if ($this->hasTable('pbx_signaling_events')) {
            $this->table('pbx_signaling_events')->drop()->save();
        }
    }
}
