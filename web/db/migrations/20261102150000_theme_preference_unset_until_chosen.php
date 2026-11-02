<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * A user who has not chosen a theme gets the one the administrator set as the default. Until now
 * every account was saved with 'light', so 'light' cannot tell a choice from the old default and
 * those rows are cleared. Without a new default set, they still see light.
 */
final class ThemePreferenceUnsetUntilChosen extends AbstractMigration
{
    public function up(): void
    {
        $this->execute("ALTER TABLE sys_users MODIFY theme_preference varchar(10) NULL DEFAULT NULL");
        $this->execute("UPDATE sys_users SET theme_preference = NULL WHERE theme_preference = 'light'");
    }

    public function down(): void
    {
    }
}
