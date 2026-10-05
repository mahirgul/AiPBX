<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * sys_users.language_preference for the web interface language preference (i18n).
 * CAUTION: this is a system COMPLETELY SEPARATE from the Asterisk SPOKEN
 * prompt language feature finished this morning
 * (pbx_dids/pbx_ivrs/pbx_queues.language) — that one controls the language of
 * the spoken prompts in a phone call; this one the language of the TEXTS the
 * user sees in the web panel.
 */
final class AddLanguagePreferenceToUsers extends AbstractMigration
{
    public function change(): void
    {
        $this->table('sys_users')
            ->addColumn('language_preference', 'string', [
                'limit' => 5,
                'default' => 'tr',
                'null' => false,
                'after' => 'theme_preference',
            ])
            ->update();
    }
}
