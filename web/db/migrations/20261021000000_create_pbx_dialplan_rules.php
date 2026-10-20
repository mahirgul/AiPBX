<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Creates `pbx_dialplan_rules` table for Ingress Call Normalization Rules
 * evaluated per client origin (mobile, desktop hardphone, web softphone).
 */
final class CreatePbxDialplanRules extends AbstractMigration
{
    public function up(): void
    {
        if (!$this->hasTable('pbx_dialplan_rules')) {
            $this->table('pbx_dialplan_rules')
                ->addColumn('client_type', 'string', ['limit' => 20, 'default' => 'all'])
                ->addColumn('rule_name', 'string', ['limit' => 128])
                ->addColumn('match_pattern', 'string', ['limit' => 64])
                ->addColumn('strip_front', 'integer', ['default' => 0])
                ->addColumn('prepend', 'string', ['limit' => 32, 'default' => ''])
                ->addColumn('strip_back', 'integer', ['default' => 0])
                ->addColumn('append', 'string', ['limit' => 32, 'default' => ''])
                ->addColumn('sort_order', 'integer', ['default' => 0])
                ->addColumn('is_active', 'boolean', ['default' => 1])
                ->addColumn('description', 'text', ['null' => true])
                ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
                ->addColumn('updated_at', 'datetime', ['null' => true])
                ->addIndex(['client_type'])
                ->addIndex(['is_active'])
                ->addIndex(['sort_order'])
                ->create();

            // Seed sensible standard defaults
            $rules = [
                [
                    'client_type' => 'desktop',
                    'rule_name' => 'Masaüstü: Dış Hat Çıkış Kodunu (0) Temizle',
                    'match_pattern' => '_00.',
                    'strip_front' => 1,
                    'prepend' => '',
                    'strip_back' => 0,
                    'append' => '',
                    'sort_order' => 1,
                    'is_active' => 1,
                    'description' => 'Masaüstü telefonlardan 0 ile dış hat alıp ulusal numara (0xxx) çevrildiğinde baştaki fazla 0ı temizler.',
                ],
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
                ],
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
                [
                    'client_type' => 'mobile',
                    'rule_name' => 'Mobil: E.164 Türkiye (+90 -> 0)',
                    'match_pattern' => '_+90.',
                    'strip_front' => 3,
                    'prepend' => '0',
                    'strip_back' => 0,
                    'append' => '',
                    'sort_order' => 1,
                    'is_active' => 1,
                    'description' => 'Mobil rehberdeki +90 Türkiye numaralarını ulusal 0 formatına çevirir.',
                ],
                [
                    'client_type' => 'mobile',
                    'rule_name' => 'Mobil: E.164 (+) Kaldır ve 00 Yap',
                    'match_pattern' => '_+X.',
                    'strip_front' => 1,
                    'prepend' => '00',
                    'strip_back' => 0,
                    'append' => '',
                    'sort_order' => 2,
                    'is_active' => 1,
                    'description' => 'Mobil rehberden + ile başlayan uluslararası numaraları standart 00 formatına çevirir.',
                ],
                [
                    'client_type' => 'mobile',
                    'rule_name' => 'Mobil: 10 Haneli GSM Başına 0 Ekle',
                    'match_pattern' => '_5XXXXXXXXX',
                    'strip_front' => 0,
                    'prepend' => '0',
                    'strip_back' => 0,
                    'append' => '',
                    'sort_order' => 3,
                    'is_active' => 1,
                    'description' => 'Mobil rehberde 5xx şeklinde 10 hane kaydedilmiş numaraların başına 0 ekler.',
                ],
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

            $this->table('pbx_dialplan_rules')->insert($rules)->saveData();
        }
    }

    public function down(): void
    {
        if ($this->hasTable('pbx_dialplan_rules')) {
            $this->table('pbx_dialplan_rules')->drop()->save();
        }
    }
}
