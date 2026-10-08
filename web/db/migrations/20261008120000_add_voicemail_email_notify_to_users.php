<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Voicemail e-mail notification on/off per user. Until now a message was
 * always mailed (to voicemail_email, or the account e-mail when that was
 * empty). Existing users start switched on, so nothing changes for them.
 */
final class AddVoicemailEmailNotifyToUsers extends AbstractMigration
{
    public function change(): void
    {
        $this->table('sys_users')
            ->addColumn('voicemail_email_notify', 'boolean', [
                'null' => false,
                'default' => 1,
                'after' => 'voicemail_email',
            ])
            ->update();
    }
}
