<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/** The AI languages are a fixed table in the code now (AiLanguages): the administrator's list is not read any more. */
final class RemoveAiLanguagesSetting extends AbstractMigration
{
    public function up(): void
    {
        $this->execute("DELETE FROM sys_settings WHERE setting_key = 'ai_languages'");
    }

    public function down(): void
    {
    }
}
