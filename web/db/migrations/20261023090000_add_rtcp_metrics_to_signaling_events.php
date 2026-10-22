<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * RTCP quality counters on the signaling stream.
 *
 * `res_hep_rtcp` sends every Sender/Receiver Report through the same capture
 * daemon as SIP. The daemon already stored the report as one summary line;
 * these columns keep the numbers a quality decision needs, without storing
 * the JSON body:
 *
 *  - `rtcp_fraction_lost` is RFC 3550's 8-bit fixed-point fraction
 *    (255 = 100 %), exactly as Asterisk reports it.
 *  - `rtcp_lost` is the report block's cumulative packet count for the stream.
 *  - `rtcp_jitter` is `ia_jitter` in RTP timestamp units. The sample rate is
 *    not in the report, so this is deliberately NOT converted to
 *    milliseconds — the portal must not invent precision Asterisk did not
 *    send.
 *  - `rtcp_ssrc` is the source SSRC of the worst report block.
 *
 * Nullable: a SIP row carries none of them, and an RTCP report that lacks a
 * counter leaves it NULL rather than a zero that would look healthy.
 */
final class AddRtcpMetricsToSignalingEvents extends AbstractMigration
{
    public function up(): void
    {
        if (!$this->hasTable('pbx_signaling_events')) {
            return;
        }
        $table = $this->table('pbx_signaling_events');
        if (!$table->hasColumn('rtcp_ssrc')) {
            $table->addColumn('rtcp_ssrc', 'string', [
                'limit' => 32, 'default' => '', 'null' => false, 'after' => 'capture_id',
            ]);
        }
        if (!$table->hasColumn('rtcp_lost')) {
            $table->addColumn('rtcp_lost', 'integer', [
                'signed' => false, 'null' => true, 'default' => null, 'after' => 'rtcp_ssrc',
            ]);
        }
        if (!$table->hasColumn('rtcp_fraction_lost')) {
            $table->addColumn('rtcp_fraction_lost', 'integer', [
                'signed' => false, 'null' => true, 'default' => null, 'after' => 'rtcp_lost',
            ]);
        }
        if (!$table->hasColumn('rtcp_jitter')) {
            $table->addColumn('rtcp_jitter', 'integer', [
                'signed' => false, 'null' => true, 'default' => null, 'after' => 'rtcp_fraction_lost',
            ]);
        }
        $table->update();
    }

    public function down(): void
    {
        if (!$this->hasTable('pbx_signaling_events')) {
            return;
        }
        $table = $this->table('pbx_signaling_events');
        foreach (['rtcp_jitter', 'rtcp_fraction_lost', 'rtcp_lost', 'rtcp_ssrc'] as $column) {
            if ($table->hasColumn($column)) {
                $table->removeColumn($column);
            }
        }
        $table->update();
    }
}
