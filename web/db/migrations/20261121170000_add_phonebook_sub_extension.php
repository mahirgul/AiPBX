<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AddPhonebookSubExtension extends AbstractMigration
{
    public function change(): void
    {
        if (!$this->hasTable('phonebook_contacts')) {
            return;
        }
        $table = $this->table('phonebook_contacts');
        if (!$table->hasColumn('sub_extension')) {
            $table->addColumn('sub_extension', 'string', [
                'limit' => 32,
                'default' => '',
                'null' => false,
                'after' => 'company',
            ])->update();
        }
    }
}
