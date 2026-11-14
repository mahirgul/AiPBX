<?php
declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * The shared phonebook: company contacts the desk phones see over LDAP next to
 * the extensions. "manual" rows are kept on the Phonebook page; a plugin that
 * reads another directory writes its own rows under its name (source).
 */
final class CreatePhonebookContacts extends AbstractMigration
{
    public function change(): void
    {
        if ($this->hasTable('phonebook_contacts')) {
            return;
        }
        $this->table('phonebook_contacts', ['collation' => 'utf8mb4_general_ci', 'encoding' => 'utf8mb4'])
            ->addColumn('source', 'string', ['limit' => 40, 'default' => 'manual', 'null' => false])
            ->addColumn('external_id', 'string', ['limit' => 191, 'null' => true])
            ->addColumn('display_name', 'string', ['limit' => 150, 'default' => '', 'null' => false])
            ->addColumn('first_name', 'string', ['limit' => 80, 'default' => '', 'null' => false])
            ->addColumn('last_name', 'string', ['limit' => 80, 'default' => '', 'null' => false])
            ->addColumn('company', 'string', ['limit' => 120, 'default' => '', 'null' => false])
            ->addColumn('phone_work', 'string', ['limit' => 40, 'default' => '', 'null' => false])
            ->addColumn('phone_mobile', 'string', ['limit' => 40, 'default' => '', 'null' => false])
            ->addColumn('phone_other', 'string', ['limit' => 40, 'default' => '', 'null' => false])
            ->addColumn('email', 'string', ['limit' => 191, 'default' => '', 'null' => false])
            ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
            ->addColumn('updated_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP', 'update' => 'CURRENT_TIMESTAMP'])
            ->addIndex(['source', 'external_id'], ['unique' => true])
            ->addIndex(['display_name'])
            ->create();
    }
}
