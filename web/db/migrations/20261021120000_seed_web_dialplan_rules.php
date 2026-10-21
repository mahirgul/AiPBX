<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Seeds missing Web Dialplan Normalization Rules and Desktop International rule.
 * Idempotent: checks for existing rules before inserting.
 */
final class SeedWebDialplanRules extends AbstractMigration
{
    public function up(): void
    {
        if (!$this->hasTable('pbx_dialplan_rules')) {
            return;
        }
        // Only for installations that already got the (former) seeded rules;
        // a new installation starts without rules.
        $any = $this->fetchRow('SELECT COUNT(*) AS c FROM pbx_dialplan_rules');
        if (empty($any['c'])) {
            return;
        }

        // 1. Seed Web Rules if none exist
        $webCount = $this->fetchRow("SELECT COUNT(*) AS c FROM pbx_dialplan_rules WHERE client_type = 'web'");
        if (empty($webCount['c'])) {
            $webRules = [
                [
                    'client_type' => 'web',
                    'rule_name' => 'Web: E.164 Türkiye (+90 -> 0)',
                    'match_pattern' => '_+90.',
                    'strip_front' => 3,
                    'prepend' => '0',
                    'strip_back' => 0,
                    'append' => '',
                    'sort_order' => 1,
                    'is_active' => 1,
                    'description' => 'Web softphone veya CRM üzerinden +90 ile gelen aramaları ulusal 0 formatına çevirir.',
                ],
                [
                    'client_type' => 'web',
                    'rule_name' => 'Web: E.164 (+) Kaldır ve 00 Yap',
                    'match_pattern' => '_+X.',
                    'strip_front' => 1,
                    'prepend' => '00',
                    'strip_back' => 0,
                    'append' => '',
                    'sort_order' => 2,
                    'is_active' => 1,
                    'description' => 'Web softphone üzerinden + ile başlayan uluslararası numaraları standart 00 formatına çevirir.',
                ],
                [
                    'client_type' => 'web',
                    'rule_name' => 'Web: 10 Haneli GSM Başına 0 Ekle',
                    'match_pattern' => '_5XXXXXXXXX',
                    'strip_front' => 0,
                    'prepend' => '0',
                    'strip_back' => 0,
                    'append' => '',
                    'sort_order' => 3,
                    'is_active' => 1,
                    'description' => 'Web softphone üzerinden 10 hane girilen GSM numaralarına 0 ekler.',
                ],
            ];

            $this->table('pbx_dialplan_rules')->insert($webRules)->saveData();
        }

        // 2. Ensure Desktop International & E.164 rules exist
        $deskIntl = $this->fetchRow("SELECT COUNT(*) AS c FROM pbx_dialplan_rules WHERE client_type = 'desktop' AND match_pattern = '_000.'");
        if (empty($deskIntl['c'])) {
            $this->table('pbx_dialplan_rules')->insert([
                [
                    'client_type' => 'desktop',
                    'rule_name' => 'Masaüstü: Dış Hat Uluslararası (000 -> 00)',
                    'match_pattern' => '_000.',
                    'strip_front' => 1,
                    'prepend' => '',
                    'strip_back' => 0,
                    'append' => '',
                    'sort_order' => 2,
                    'is_active' => 1,
                    'description' => 'Masaüstü telefonlardan 0 dış hat koduyla uluslararası arama yapıldığında baştaki fazla 0ı temizler.',
                ]
            ])->saveData();
        }

        $deskE164Tr = $this->fetchRow("SELECT COUNT(*) AS c FROM pbx_dialplan_rules WHERE client_type = 'desktop' AND match_pattern = '_+90.'");
        if (empty($deskE164Tr['c'])) {
            $this->table('pbx_dialplan_rules')->insert([
                [
                    'client_type' => 'desktop',
                    'rule_name' => 'Masaüstü: E.164 Türkiye (+90 -> 0)',
                    'match_pattern' => '_+90.',
                    'strip_front' => 3,
                    'prepend' => '0',
                    'strip_back' => 0,
                    'append' => '',
                    'sort_order' => 3,
                    'is_active' => 1,
                    'description' => 'Masaüstü telefonlardan veya rehberden +90 ile gelen aramaları ulusal 0 formatına çevirir.',
                ],
                [
                    'client_type' => 'desktop',
                    'rule_name' => 'Masaüstü: E.164 (+) Kaldır ve 00 Yap',
                    'match_pattern' => '_+X.',
                    'strip_front' => 1,
                    'prepend' => '00',
                    'strip_back' => 0,
                    'append' => '',
                    'sort_order' => 4,
                    'is_active' => 1,
                    'description' => 'Masaüstü telefonlardan + ile başlayan uluslararası numaraları standart 00 formatına çevirir.',
                ],
            ])->saveData();
        }
    }

    public function down(): void
    {
        // Safe down: preserve custom rules, only revert if explicitly needed
    }
}
