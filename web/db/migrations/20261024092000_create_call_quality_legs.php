<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Call quality (Reports → Call quality): one row per call leg — one SIP
 * Call-ID; the trunk leg and the phone leg of a call are two rows — built
 * from the RTCP reports in pbx_signaling_events by CallQualityService.
 *
 * "in" is what this server received (the reports Asterisk sends describe it),
 * "out" what the other side received. Loss is in percent (RFC 3550's
 * fraction_lost / 256); jitter stays in RTP timestamp units, as reported.
 */
final class CreateCallQualityLegs extends AbstractMigration
{
    public function up(): void
    {
        if ($this->hasTable('pbx_call_quality_legs')) {
            return;
        }
        $this->table('pbx_call_quality_legs', ['id' => false, 'primary_key' => ['call_id'],
            'collation' => 'utf8mb4_general_ci', 'encoding' => 'utf8mb4'])
            ->addColumn('call_id', 'string', ['limit' => 128])
            ->addColumn('first_ts', 'datetime')
            ->addColumn('last_ts', 'datetime')
            ->addColumn('leg_type', 'string', ['limit' => 10, 'default' => ''])
            ->addColumn('leg_name', 'string', ['limit' => 64, 'default' => ''])
            ->addColumn('peer', 'string', ['limit' => 64, 'default' => ''])
            ->addColumn('reports_in', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('reports_out', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('bad_in', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('bad_out', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('loss_in_max', 'decimal', ['precision' => 5, 'scale' => 2, 'default' => 0])
            ->addColumn('loss_out_max', 'decimal', ['precision' => 5, 'scale' => 2, 'default' => 0])
            ->addColumn('loss_in_sum', 'decimal', ['precision' => 10, 'scale' => 2, 'default' => 0])
            ->addColumn('loss_out_sum', 'decimal', ['precision' => 10, 'scale' => 2, 'default' => 0])
            ->addColumn('jitter_in_max', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('jitter_out_max', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('level', 'string', ['limit' => 5, 'default' => 'good'])
            ->addIndex(['last_ts'])
            ->addIndex(['leg_type', 'leg_name', 'last_ts'])
            ->create();
    }

    public function down(): void
    {
        if ($this->hasTable('pbx_call_quality_legs')) {
            $this->table('pbx_call_quality_legs')->drop()->save();
        }
    }
}
