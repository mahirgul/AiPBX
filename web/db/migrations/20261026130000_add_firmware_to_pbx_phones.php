<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * The SIP firmware switch, per phone (see PhoneFirmwareService).
 *
 * A 7900-series Cisco phone is usually found running the SCCP firmware it left
 * the factory with; it only becomes an AiPBX phone once it is running Cisco's
 * SIP load. That switch is configuration-driven (the phone reads its file,
 * compares the load with the one in flash and reflashes), so what AiPBX has to
 * remember is: which load was written, whether that load's file set was on the
 * phone server, and whether the phone has come back and read its file since.
 *
 * - firmware_load:  the load the phone was pointed at ('' = never armed).
 * - firmware_state: 'armed' (written, waiting for the phone), 'fetched' (the
 *                   phone read its file since: it has rebooted and taken the
 *                   config) or 'incomplete' (the load was written but its files
 *                   are not on this server — the phone would stall at
 *                   "Upgrading" if it rebooted, so the note names them).
 * - firmware_note:  the missing file names, or 'unreadable' when the load's
 *                   manifest could not be parsed.
 * - firmware_at:    when the switch was last (re)armed.
 */
final class AddFirmwareToPbxPhones extends AbstractMigration
{
    public function up(): void
    {
        $this->table('pbx_phones')
            ->addColumn('firmware_load', 'string', ['limit' => 64, 'null' => false, 'default' => '', 'after' => 'notes'])
            ->addColumn('firmware_state', 'string', ['limit' => 16, 'null' => false, 'default' => '', 'after' => 'firmware_load'])
            ->addColumn('firmware_note', 'string', ['limit' => 255, 'null' => false, 'default' => '', 'after' => 'firmware_state'])
            ->addColumn('firmware_at', 'datetime', ['null' => true, 'default' => null, 'after' => 'firmware_note'])
            ->update();
    }

    public function down(): void
    {
        $this->table('pbx_phones')
            ->removeColumn('firmware_load')
            ->removeColumn('firmware_state')
            ->removeColumn('firmware_note')
            ->removeColumn('firmware_at')
            ->update();
    }
}
