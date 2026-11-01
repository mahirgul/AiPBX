<?php

use Phinx\Migration\AbstractMigration;

final class AddConferenceJoinFeatureCode extends AbstractMigration
{
    public function up(): void
    {
        if ($this->hasTable('pbx_feature_codes')) {
            $ex = $this->query("SELECT id FROM `pbx_feature_codes` WHERE `feature_key` = 'conference_join'")->fetchAll();
            if (empty($ex)) {
                $this->execute("INSERT INTO `pbx_feature_codes` (`feature_key`, `title`, `code`, `allowed_roles`, `is_active`) VALUES ('conference_join', 'Konferans Odası Girişi (*9 / #9)', '_#9.', NULL, 1)");
            }
        }
    }

    public function down(): void
    {
        if ($this->hasTable('pbx_feature_codes')) {
            $this->execute("DELETE FROM `pbx_feature_codes` WHERE `feature_key` = 'conference_join'");
        }
    }
}
