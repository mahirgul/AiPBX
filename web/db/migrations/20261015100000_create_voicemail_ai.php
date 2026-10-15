<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Transcript and summary of a voicemail (VoicemailAiService): made once per
 * message, used by the e-mail, the push notification and the voicemail lists.
 */
final class CreateVoicemailAi extends AbstractMigration
{
    public function up(): void
    {
        if ($this->hasTable('voicemail_ai')) {
            return;
        }
        $this->table('voicemail_ai')
            ->addColumn('mailbox', 'string', ['limit' => 20])
            ->addColumn('origtime', 'integer', ['default' => 0])
            ->addColumn('transcript', 'text', ['null' => true])
            ->addColumn('summary', 'string', ['limit' => 500, 'default' => ''])
            ->addColumn('engine', 'string', ['limit' => 100, 'default' => ''])
            ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
            ->addIndex(['mailbox', 'origtime'], ['unique' => true])
            ->create();
    }

    public function down(): void
    {
        if ($this->hasTable('voicemail_ai')) {
            $this->table('voicemail_ai')->drop()->save();
        }
    }
}
