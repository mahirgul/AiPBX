<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Turns off two kinds of seeded normalization rules that broke calls.
 *
 * 1. Desktop "_00." / "_00[1-9]." (strip the first 0): meant for offices
 *    whose users dial 0 for an outside line, but AiPBX's own international
 *    format is 00 + country code. A desk phone dialling 0049... was turned
 *    into 049... and the call failed. Faxes and call forwarding go through
 *    the desktop rules too.
 * 2. Turkey-only rules ("_+90." -> 0, "_5XXXXXXXXX" -> 05...) on
 *    installations that are not in Turkey: e.g. a US number 512 555 1234
 *    from the web phone became 0512...
 *
 * Only rules still exactly as seeded are switched off (not deleted); a rule
 * the administrator changed is left alone. They can be switched on again on
 * Trunks -> Call Normalization.
 */
final class FixDialplanRuleDefaults extends AbstractMigration
{
    public function up(): void
    {
        if (!$this->hasTable('pbx_dialplan_rules')) {
            return;
        }

        $this->execute(
            "UPDATE pbx_dialplan_rules SET is_active = 0
              WHERE client_type = 'desktop' AND match_pattern IN ('_00.', '_00[1-9].')
                AND strip_front = 1 AND strip_back = 0
                AND COALESCE(prepend, '') = '' AND COALESCE(append, '') = ''"
        );

        if ($this->countryCode() === '90') {
            return;
        }
        $this->execute(
            "UPDATE pbx_dialplan_rules SET is_active = 0
              WHERE (match_pattern = '_+90.' AND strip_front = 3 AND prepend = '0')
                 OR (match_pattern = '_5XXXXXXXXX' AND strip_front = 0 AND prepend = '0')"
        );
    }

    public function down(): void
    {
        // Nothing to undo automatically: the rules can be switched on in the portal.
    }

    /**
     * The country the rules were loaded for, else Turkey when the
     * installation's default language is Turkish. '' = unknown (e.g. a fresh
     * install, where the settings are seeded after the migrations).
     */
    private function countryCode(): string
    {
        if (!$this->hasTable('sys_settings')) {
            return '';
        }
        $row = $this->fetchRow("SELECT setting_value FROM sys_settings WHERE setting_key = 'dialplan_rules_country_code'");
        $cc = preg_replace('/[^0-9]/', '', (string)($row['setting_value'] ?? ''));
        if ($cc !== '') {
            return $cc;
        }
        $row = $this->fetchRow("SELECT setting_value FROM sys_settings WHERE setting_key = 'system_default_language'");
        return (($row['setting_value'] ?? '') === 'tr') ? '90' : '';
    }
}
