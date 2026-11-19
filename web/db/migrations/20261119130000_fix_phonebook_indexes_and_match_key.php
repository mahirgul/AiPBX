<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Index phonebook_contacts.numbers_indexed_at so syncNumbers() avoids full table scans,
 * and widen phonebook_numbers.match_key to 40 characters so caller_match_digits = 0
 * ("whole number") matches the 40-character phonebook_numbers.number column.
 */
final class FixPhonebookIndexesAndMatchKey extends AbstractMigration
{
    public function up(): void
    {
        if ($this->hasTable('phonebook_contacts')) {
            $contacts = $this->table('phonebook_contacts');
            if ($contacts->hasColumn('numbers_indexed_at') && !$contacts->hasIndex(['numbers_indexed_at'])) {
                $contacts->addIndex(['numbers_indexed_at'])->update();
            }
        }
        if ($this->hasTable('phonebook_numbers')) {
            $numbers = $this->table('phonebook_numbers');
            if ($numbers->hasColumn('match_key')) {
                $numbers->changeColumn('match_key', 'string', ['limit' => 40, 'null' => false, 'default' => ''])->update();
            }
        }
    }

    public function down(): void
    {
        if ($this->hasTable('phonebook_contacts')) {
            $contacts = $this->table('phonebook_contacts');
            if ($contacts->hasIndex(['numbers_indexed_at'])) {
                $contacts->removeIndex(['numbers_indexed_at'])->update();
            }
        }
        if ($this->hasTable('phonebook_numbers')) {
            $numbers = $this->table('phonebook_numbers');
            if ($numbers->hasColumn('match_key')) {
                $this->execute('UPDATE phonebook_numbers SET match_key = LEFT(match_key, 24) WHERE CHAR_LENGTH(match_key) > 24');
                $numbers->changeColumn('match_key', 'string', ['limit' => 24, 'null' => false, 'default' => ''])->update();
            }
        }
    }
}
