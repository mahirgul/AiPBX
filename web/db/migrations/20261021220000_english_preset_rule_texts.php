<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Call normalization presets used to be loaded with Turkish names and
 * descriptions on every installation ("Masaüstü: …", "Mobil: …"). The presets
 * are English now; this renames the rules that are still exactly as loaded
 * (Turkish preset description, known pattern). Rules the administrator wrote
 * or renamed are left alone.
 */
final class EnglishPresetRuleTexts extends AbstractMigration
{
    public function up(): void
    {
        if (!$this->hasTable('pbx_dialplan_rules')) {
            return;
        }
        $rows = $this->fetchAll(
            "SELECT id, client_type, match_pattern, rule_name FROM pbx_dialplan_rules
              WHERE (rule_name LIKE 'Masaüstü: %' OR rule_name LIKE 'Mobil: %' OR rule_name LIKE 'Web: %')
                AND (description LIKE 'Masaüstü telefonlardan%' OR description LIKE 'Mobil rehber%' OR description LIKE 'Web softphone%')"
        );
        $device = ['desktop' => 'Desk phones', 'mobile' => 'Mobile app', 'web' => 'Web phone'];
        $stmt = $this->getAdapter()->getConnection()->prepare(
            'UPDATE pbx_dialplan_rules SET rule_name = ?, description = ? WHERE id = ?'
        );
        foreach ($rows as $r) {
            $dev = $device[$r['client_type']] ?? null;
            $p = (string)$r['match_pattern'];
            if ($dev === null) {
                continue;
            }
            if (($p === '_00.' || $p === '_00[1-9].') && $r['client_type'] === 'desktop') {
                $text = ["{$dev}: strip outside-line 0", 'For desk phones that dial 0 for an outside line: 0 + 0xxx (national) becomes 0xxx. Off by default.'];
            } elseif ($p === '_000.') {
                $text = ["{$dev}: outside line international (000 -> 00)", 'Outside-line 0 dialled before an international number: 000... becomes 00...'];
            } elseif ($p === '_+X.') {
                $text = ["{$dev}: E.164 international (+ -> 00)", 'International numbers with + are dialled with 00.'];
            } elseif (preg_match('/^_\+(\d{1,4})\.$/', $p, $m)) {
                $text = ["{$dev}: E.164 +{$m[1]} -> 0", "+{$m[1]} numbers become national numbers with a leading 0."];
            } elseif ($p === '_5XXXXXXXXX') {
                $text = ["{$dev}: 10-digit mobile number, add 0", 'Mobile numbers entered as 5xx xxx xx xx get the leading 0.'];
            } else {
                continue;
            }
            $stmt->execute([$text[0], $text[1], (int)$r['id']]);
        }
    }

    public function down(): void
    {
        // Texts only; nothing to restore.
    }
}
