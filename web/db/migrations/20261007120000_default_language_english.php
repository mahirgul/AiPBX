<?php

use Phinx\Migration\AbstractMigration;

/**
 * English is the default interface language: users created from now on
 * (including the admin of a fresh install) start in English. Existing users
 * keep the language stored in their row, so an upgraded Turkish installation
 * stays Turkish.
 */
final class DefaultLanguageEnglish extends AbstractMigration
{
    public function up(): void
    {
        $this->table('sys_users')
            ->changeColumn('language_preference', 'string', ['limit' => 5, 'default' => 'en', 'null' => false])
            ->update();
    }

    public function down(): void
    {
        $this->table('sys_users')
            ->changeColumn('language_preference', 'string', ['limit' => 5, 'default' => 'tr', 'null' => false])
            ->update();
    }
}
