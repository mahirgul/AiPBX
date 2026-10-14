<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Voicemail archived to the S3 bucket (#69, bin/voicemail_to_bucket.php):
 * heard messages older than a set number of days leave Asterisk's spool; the
 * portal and the apps still list, play and delete them.
 */
final class CreateVoicemailArchive extends AbstractMigration
{
    public function up(): void
    {
        if ($this->hasTable('voicemail_archive')) {
            return;
        }
        $this->table('voicemail_archive')
            ->addColumn('mailbox', 'string', ['limit' => 20])
            ->addColumn('callerid', 'string', ['limit' => 255, 'default' => ''])
            ->addColumn('origtime', 'integer', ['default' => 0])
            ->addColumn('duration', 'integer', ['default' => 0])
            ->addColumn('object_key', 'string', ['limit' => 255])
            ->addColumn('archived_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
            ->addIndex(['mailbox'])
            ->create();
    }

    public function down(): void
    {
        if ($this->hasTable('voicemail_archive')) {
            $this->table('voicemail_archive')->drop()->save();
        }
    }
}
