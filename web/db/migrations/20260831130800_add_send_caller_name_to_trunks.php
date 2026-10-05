<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * "Send display name on outgoing calls" option in the trunk settings
 * (2026-08-31, user request) — its only consumer right now is fax sending
 * (FaxSendService::submitCallFile()): when off, the caller ID is the number
 * only; when on it is sent as "{unit name}" <{number}>. Off (0) by default —
 * in the same session it was said "let's not put a name in the SIP from
 * part" and nameless fax sending was chosen ON PURPOSE; this default is
 * consistent with that decision, and the admin can turn it on per trunk.
 */
final class AddSendCallerNameToTrunks extends AbstractMigration
{
    public function change(): void
    {
        $this->table('pbx_trunks')
            ->addColumn('send_caller_name', 'boolean', [
                'default' => false,
                'after' => 'qualify_frequency',
            ])
            ->update();
    }
}
