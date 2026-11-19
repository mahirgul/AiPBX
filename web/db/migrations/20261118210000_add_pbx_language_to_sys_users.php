<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * The language of the prompts an extension's own phones hear (voicemail menu, feature code
 * announcements): PJSIP endpoint language=. Empty: the system's default language.
 */
final class AddPbxLanguageToSysUsers extends AbstractMigration
{
    public function change(): void
    {
        $t = $this->table('sys_users');
        if (!$t->hasColumn('pbx_language')) {
            $t->addColumn('pbx_language', 'string', ['limit' => 10, 'default' => '', 'null' => false, 'after' => 'language_preference'])
              ->update();
        }
    }
}
