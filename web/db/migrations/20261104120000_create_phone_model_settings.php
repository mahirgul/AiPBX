<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/** Extra settings written into the provisioning files of every phone of one model. */
final class CreatePhoneModelSettings extends AbstractMigration
{
    public function up(): void
    {
        $this->execute("CREATE TABLE IF NOT EXISTS pbx_phone_model_settings (
            model VARCHAR(64) NOT NULL PRIMARY KEY,
            extra_settings TEXT NOT NULL,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    public function down(): void
    {
        $this->execute('DROP TABLE IF EXISTS pbx_phone_model_settings');
    }
}
