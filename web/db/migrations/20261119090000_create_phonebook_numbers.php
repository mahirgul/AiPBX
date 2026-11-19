<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Address book: any number of labelled numbers per contact (mobile, work, work 2, home,
 * fax, other). match_key is the number cut to its last N digits (setting
 * caller_match_digits, 10 by default), so +90 532..., 0532... and 0090 532... find the
 * same contact. phone_work/phone_mobile/phone_other stay as a copy for the LDAP
 * phonebook of desk phones and for plugins that write them.
 */
final class CreatePhonebookNumbers extends AbstractMigration
{
    public function up(): void
    {
        if (!$this->hasTable('phonebook_numbers')) {
            $this->table('phonebook_numbers', ['signed' => false, 'collation' => 'utf8mb4_general_ci'])
                ->addColumn('contact_id', 'integer', ['signed' => false, 'null' => false])
                ->addColumn('label', 'string', ['limit' => 20, 'null' => false, 'default' => 'other'])
                ->addColumn('number', 'string', ['limit' => 40, 'null' => false])
                ->addColumn('match_key', 'string', ['limit' => 24, 'null' => false, 'default' => ''])
                ->addColumn('sort', 'integer', ['limit' => 255, 'null' => false, 'default' => 0])
                ->addIndex(['match_key'])
                ->addIndex(['contact_id'])
                ->addForeignKey('contact_id', 'phonebook_contacts', 'id', ['delete' => 'CASCADE'])
                ->create();
        }
        $contacts = $this->table('phonebook_contacts');
        if (!$contacts->hasColumn('numbers_indexed_at')) {
            $contacts->addColumn('numbers_indexed_at', 'datetime', ['null' => true, 'default' => null])->update();
        }
        // Existing rows are indexed by PhonebookService::syncNumbers() on first use.
    }

    public function down(): void
    {
        if ($this->hasTable('phonebook_numbers')) {
            $this->table('phonebook_numbers')->drop()->save();
        }
        if ($this->table('phonebook_contacts')->hasColumn('numbers_indexed_at')) {
            $this->table('phonebook_contacts')->removeColumn('numbers_indexed_at')->update();
        }
    }
}
