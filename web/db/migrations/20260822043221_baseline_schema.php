<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Baseline şema — 2026-08-22 itibarıyla canlı üretim veritabanının tam
 * yapısal (data içermeyen) görüntüsü. Bu migration'dan İTİBAREN tüm şema
 * değişiklikleri yeni, ayrı migration dosyaları olarak yazılır — elle
 * ALTER TABLE çalıştırılmaz.
 *
 * Kapsam: uygulamanın kendi tabloları (sys_*, pbx_*, cc_*, fax_*,
 * callcenter_notes, sip, queues_details, pjsipsettings) + Asterisk'in
 * realtime CDR tablosu `asteriskcdr` (SADECE `cdrs` view'ı fresh bir
 * ortamda oluşturulabilsin diye dahil edildi — gerçek kurulumda bu tabloyu
 * Asterisk kendi yönetir, buradan ALTER edilmemeli) + `cdrs` view'ı.
 *
 * KAPSAM DIŞI (bilinçli olarak): asteriskcel, asteriskqueue — bunlar da
 * tamamen Asterisk'in kendi yönettiği operasyonel tablolar, uygulamanın
 * veri modeline dahil değil.
 *
 * Doğrulama: bu SQL, canlı prod şemasına karşı bir scratch DB'de
 * (aipbx_baseline_test) yüklenip mysqldump çıktısı satır satır
 * karşılaştırılarak (AUTO_INCREMENT sayaçları ve view DEFINER hariç)
 * BİREBİR eşleştiği teyit edildi (2026-08-22).
 */
final class BaselineSchema extends AbstractMigration
{
    public function up(): void
    {
        $sql = file_get_contents(__DIR__ . '/sql/baseline_schema.sql');
        $statements = $this->splitSqlStatements($sql);
        foreach ($statements as $statement) {
            $statement = trim($statement);
            if ($statement === '') continue;
            $this->execute($statement);
        }
    }

    public function down(): void
    {
        $this->execute('DROP VIEW IF EXISTS `cdrs`');
        $tables = [
            'asteriskcdr', 'callcenter_notes', 'cc_pause_logs', 'cc_queue_logs',
            'fax_received', 'fax_sent', 'pbx_announcements', 'pbx_dids',
            'pbx_feature_codes', 'pbx_hangup_actions', 'pbx_ivr_entries', 'pbx_ivrs',
            'pbx_moh_classes', 'pbx_outbound_routes', 'pbx_queues',
            'pbx_time_conditions', 'pbx_time_groups', 'pbx_trunks', 'pjsipsettings',
            'queues_details', 'sip', 'sys_did_mappings', 'sys_login_logs',
            'sys_role_permissions', 'sys_roles', 'sys_settings', 'sys_users',
        ];
        $this->execute('SET FOREIGN_KEY_CHECKS=0');
        foreach ($tables as $table) {
            $this->execute("DROP TABLE IF EXISTS `{$table}`");
        }
        $this->execute('SET FOREIGN_KEY_CHECKS=1');
    }

    /**
     * baseline_schema.sql dosyasını tek tek çalıştırılabilir ifadelere böler.
     * Basit bir ";" split'i yeterli çünkü dosyada string literal içinde ";"
     * geçen bir ifade yok (view tanımı dahil kontrol edildi).
     */
    private function splitSqlStatements(string $sql): array
    {
        return array_filter(array_map('trim', explode(";\n", str_replace(";\r\n", ";\n", $sql))));
    }
}
