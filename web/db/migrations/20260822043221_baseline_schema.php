<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Baseline schema — the full structural (data-free) image of the live
 * production database as of 2026-08-22. FROM this migration ON, every schema
 * change is written as a new, separate migration file — no ALTER TABLE by
 * hand.
 *
 * Scope: the application's own tables (sys_*, pbx_*, cc_*, fax_*,
 * callcenter_notes, sip, queues_details, pjsipsettings) + Asterisk's realtime
 * CDR table `asteriskcdr` (included ONLY so the `cdrs` view can be created in
 * a fresh environment — on a real install Asterisk manages this table itself
 * and it must not be ALTERed from here) + the `cdrs` view.
 *
 * OUT OF SCOPE (on purpose): asteriskcel, asteriskqueue — also purely
 * operational tables managed by Asterisk itself, not part of the
 * application's data model.
 *
 * Verification: this SQL was loaded into a scratch DB (aipbx_baseline_test)
 * and its mysqldump output compared line by line with the live prod schema;
 * it matched EXACTLY (except AUTO_INCREMENT counters and the view DEFINER)
 * (2026-08-22).
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
     * Splits baseline_schema.sql into individually executable statements.
     * A simple ";" split is enough because no statement in the file has a ";"
     * inside a string literal (checked, including the view definition).
     */
    private function splitSqlStatements(string $sql): array
    {
        return array_filter(array_map('trim', explode(";\n", str_replace(";\r\n", ";\n", $sql))));
    }
}
