<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AddPhonebookVip extends AbstractMigration
{
    public function change(): void
    {
        $this->table('phonebook_contacts')
            ->addColumn('is_vip', 'boolean', ['default' => false, 'null' => false])
            ->update();
    }
}
