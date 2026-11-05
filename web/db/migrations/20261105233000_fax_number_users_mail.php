<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/** Whether each person on a fax number gets its incoming faxes by e-mail. */
final class FaxNumberUsersMail extends AbstractMigration
{
    public function up(): void
    {
        $table = $this->table('pbx_fax_number_users');
        if (!$table->hasColumn('mail')) {
            $table->addColumn('mail', 'boolean', ['default' => true])->update();
            $this->execute('UPDATE pbx_fax_number_users f JOIN sys_users u ON u.id = f.user_id SET f.mail = u.fax_mail');
        }
    }

    public function down(): void
    {
        $this->table('pbx_fax_number_users')->removeColumn('mail')->update();
    }
}
