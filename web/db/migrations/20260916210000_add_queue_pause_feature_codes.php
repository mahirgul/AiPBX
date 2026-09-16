<?php
use Phinx\Migration\AbstractMigration;

final class AddQueuePauseFeatureCodes extends AbstractMigration
{
    public function up(): void
    {
        $this->execute("
            INSERT INTO pbx_feature_codes (feature_key, title, code, allowed_roles, is_active, created_at)
            VALUES
            ('queue_pause', 'Kuyruk Mola Al (Mola ID ile, *22<id>)', '_*22.', 'admin,cc_manager,cc_agent,user', 1, NOW()),
            ('queue_unpause', 'Kuyruk Mola İptal / Dönüş (*23)', '*23', 'admin,cc_manager,cc_agent,user', 1, NOW())
            ON DUPLICATE KEY UPDATE title = VALUES(title), code = VALUES(code), allowed_roles = VALUES(allowed_roles)
        ");
    }

    public function down(): void
    {
        $this->execute("DELETE FROM pbx_feature_codes WHERE feature_key IN ('queue_pause', 'queue_unpause')");
    }
}
