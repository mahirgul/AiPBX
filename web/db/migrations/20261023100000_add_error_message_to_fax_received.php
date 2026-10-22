<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Why an incoming fax failed.
 *
 * A fax that failed before a page arrived left no file, and the processor
 * stopped there: no row, no trace in the inbox. On a live system 116 calls to
 * fax numbers in a month all ended like that and nobody could see them. Such
 * calls are now recorded as FAILED with Asterisk's reason (FAXOPT(error) and
 * FAXOPT(statusstr)) in this column; fax_sent has had its own error_message
 * from the start.
 */
final class AddErrorMessageToFaxReceived extends AbstractMigration
{
    public function up(): void
    {
        if (!$this->hasTable('fax_received')) {
            return;
        }
        $table = $this->table('fax_received');
        if (!$table->hasColumn('error_message')) {
            $table->addColumn('error_message', 'string', [
                'limit' => 255, 'default' => '', 'null' => false, 'after' => 'status',
            ])->update();
        }
    }

    public function down(): void
    {
        if (!$this->hasTable('fax_received')) {
            return;
        }
        $table = $this->table('fax_received');
        if ($table->hasColumn('error_message')) {
            $table->removeColumn('error_message')->update();
        }
    }
}
