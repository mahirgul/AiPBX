<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/helpers.php';

/**
 * Service for Ingress Dialplan / Call Normalization Rules.
 */
class DialplanRuleService
{
    public const VALID_CLIENT_TYPES = ['all', 'desktop', 'mobile', 'web'];

    public static function saveRule(array $data): array
    {
        return PBXHelper::handleAction($data['csrf_token'] ?? '', function() use ($data) {
            $rule_id = intval($data['rule_id'] ?? 0);
            $client_type = trim((string)($data['client_type'] ?? 'all'));
            if (!in_array($client_type, self::VALID_CLIENT_TYPES, true)) {
                $client_type = 'all';
            }

            $rule_name = trim((string)($data['rule_name'] ?? ''));
            $match_pattern = trim((string)($data['match_pattern'] ?? ''));
            // Preserve leading underscore if pattern, ensure clean pattern symbols
            if (!str_starts_with($match_pattern, '_') && preg_match('/[XZN.!\\[\\]+]/i', $match_pattern)) {
                $match_pattern = '_' . $match_pattern;
            }
            $match_pattern = preg_replace('/[^0-9NXZnxz.\\[\\]_!*#+-]/', '', $match_pattern);

            $prepend = preg_replace('/[^0-9*#+]/', '', trim((string)($data['prepend'] ?? '')));
            $append = preg_replace('/[^0-9*#+]/', '', trim((string)($data['append'] ?? '')));
            $strip_front = max(0, intval($data['strip_front'] ?? 0));
            $strip_back = max(0, intval($data['strip_back'] ?? 0));
            $is_active = PBXHelper::boolFlag($data, 'is_active', 1);
            $description = trim((string)($data['description'] ?? ''));

            if ($rule_name === '' || $match_pattern === '') {
                throw new \Exception(t('dialplan_rules.err_required'));
            }

            $is_new = ($rule_id <= 0);
            $db = getDB();

            if ($is_new) {
                $maxOrder = (int)$db->query("SELECT COALESCE(MAX(sort_order), 0) FROM pbx_dialplan_rules")->fetchColumn();
                $sort_order = $maxOrder + 1;

                $stmt = $db->prepare(
                    "INSERT INTO pbx_dialplan_rules (client_type, rule_name, match_pattern, strip_front, prepend, strip_back, append, sort_order, is_active, description, created_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())"
                );
                $stmt->execute([
                    $client_type,
                    $rule_name,
                    $match_pattern,
                    $strip_front,
                    $prepend,
                    $strip_back,
                    $append,
                    $sort_order,
                    $is_active,
                    $description ?: null,
                ]);
                $saved_id = (int)$db->lastInsertId();
            } else {
                $stmt = $db->prepare(
                    "UPDATE pbx_dialplan_rules
                     SET client_type = ?, rule_name = ?, match_pattern = ?, strip_front = ?, prepend = ?, strip_back = ?, append = ?, is_active = ?, description = ?, updated_at = NOW()
                     WHERE id = ?"
                );
                $stmt->execute([
                    $client_type,
                    $rule_name,
                    $match_pattern,
                    $strip_front,
                    $prepend,
                    $strip_back,
                    $append,
                    $is_active,
                    $description ?: null,
                    $rule_id,
                ]);
                $saved_id = $rule_id;
            }

            if (function_exists('markPendingSync')) {
                markPendingSync(
                    'outbound_dialplan',
                    'dialplan_rule',
                    $saved_id,
                    "Rule: {$rule_name} ({$client_type})",
                    $is_new ? 'create' : 'update',
                    $_SESSION['user_id'] ?? null
                );
            }

            return sprintf(t('dialplan_rules.msg_saved'), $rule_name);
        });
    }

    public static function deleteRule(int $ruleId, string $csrfToken): array
    {
        return PBXHelper::handleAction($csrfToken, function() use ($ruleId) {
            $db = getDB();
            $stmt = $db->prepare("SELECT rule_name, client_type FROM pbx_dialplan_rules WHERE id = ?");
            $stmt->execute([$ruleId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                throw new \Exception(t('dialplan_rules.err_not_found'));
            }

            $stmtDel = $db->prepare("DELETE FROM pbx_dialplan_rules WHERE id = ?");
            $stmtDel->execute([$ruleId]);

            if (function_exists('markPendingSync')) {
                markPendingSync(
                    'outbound_dialplan',
                    'dialplan_rule',
                    $ruleId,
                    "Rule: {$row['rule_name']} (deleted)",
                    'delete',
                    $_SESSION['user_id'] ?? null
                );
            }

            return sprintf(t('dialplan_rules.msg_deleted'), $row['rule_name']);
        });
    }

    public static function toggleStatus(int $ruleId, string $csrfToken): array
    {
        return PBXHelper::handleAction($csrfToken, function() use ($ruleId) {
            $db = getDB();
            $stmt = $db->prepare("SELECT rule_name, is_active FROM pbx_dialplan_rules WHERE id = ?");
            $stmt->execute([$ruleId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                throw new \Exception(t('dialplan_rules.err_not_found'));
            }

            $newStatus = (intval($row['is_active']) === 1) ? 0 : 1;
            $stmtUp = $db->prepare("UPDATE pbx_dialplan_rules SET is_active = ?, updated_at = NOW() WHERE id = ?");
            $stmtUp->execute([$newStatus, $ruleId]);

            if (function_exists('markPendingSync')) {
                markPendingSync(
                    'outbound_dialplan',
                    'dialplan_rule',
                    $ruleId,
                    "Rule: {$row['rule_name']} (" . ($newStatus ? 'activated' : 'deactivated') . ")",
                    'update',
                    $_SESSION['user_id'] ?? null
                );
            }

            return t('dialplan_rules.msg_status_updated');
        });
    }

    public static function reorder(array $orderedIds, string $csrfToken): array
    {
        return PBXHelper::handleAction($csrfToken, function() use ($orderedIds) {
            $db = getDB();
            $stmt = $db->prepare("UPDATE pbx_dialplan_rules SET sort_order = ? WHERE id = ?");
            $order = 1;
            foreach ($orderedIds as $id) {
                $id = intval($id);
                if ($id > 0) {
                    $stmt->execute([$order++, $id]);
                }
            }

            if (function_exists('markPendingSync')) {
                markPendingSync(
                    'outbound_dialplan',
                    'dialplan_rule',
                    'reorder',
                    "Dialplan rules reordered",
                    'update',
                    $_SESSION['user_id'] ?? null
                );
            }

            return t('dialplan_rules.msg_reordered');
        });
    }

    public static function loadDefaults(string $csrfToken): array
    {
        return PBXHelper::handleAction($csrfToken, function() {
            $db = getDB();
            $db->exec("TRUNCATE TABLE pbx_dialplan_rules");

            $defaults = [
                ['desktop', 'Masaüstü: Dış Hat Çıkış Kodunu (0) Temizle', '_00[1-9].', 1, '', 0, '', 1, 1, 'Masaüstü telefonlardan 0 ile dış hat alıp ulusal numara (0xxx) çevrildiğinde baştaki fazla 0ı temizler.'],
                ['desktop', 'Masaüstü: Dış Hat Uluslararası (000 -> 00)', '_000.', 1, '', 0, '', 2, 1, 'Masaüstü telefonlardan 0 dış hat koduyla uluslararası arama yapıldığında baştaki fazla 0ı temizler.'],
                ['mobile', 'Mobil: E.164 Türkiye (+90 -> 0)', '_+90.', 3, '0', 0, '', 1, 1, 'Mobil rehberdeki +90 Türkiye numaralarını ulusal 0 formatına çevirir.'],
                ['mobile', 'Mobil: E.164 (+) Kaldır ve 00 Yap', '_+X.', 1, '00', 0, '', 2, 1, 'Mobil rehberden + ile başlayan uluslararası numaraları standart 00 formatına çevirir.'],
                ['mobile', 'Mobil: 10 Haneli GSM Başına 0 Ekle', '_5XXXXXXXXX', 0, '0', 0, '', 3, 1, 'Mobil rehberde 5xx şeklinde 10 hane kaydedilmiş numaraların başına 0 ekler.'],
                ['web', 'Web: E.164 Türkiye (+90 -> 0)', '_+90.', 3, '0', 0, '', 1, 1, 'Web softphone veya CRM üzerinden +90 ile gelen aramaları ulusal 0 formatına çevirir.'],
                ['web', 'Web: E.164 (+) Kaldır ve 00 Yap', '_+X.', 1, '00', 0, '', 2, 1, 'Web softphone üzerinden + ile başlayan uluslararası numaraları standart 00 formatına çevirir.'],
                ['web', 'Web: 10 Haneli GSM Başına 0 Ekle', '_5XXXXXXXXX', 0, '0', 0, '', 3, 1, 'Web softphone üzerinden 10 hane girilen GSM numaralarına 0 ekler.'],
            ];

            $stmt = $db->prepare(
                "INSERT INTO pbx_dialplan_rules (client_type, rule_name, match_pattern, strip_front, prepend, strip_back, append, sort_order, is_active, description, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())"
            );

            foreach ($defaults as $d) {
                $stmt->execute($d);
            }

            if (function_exists('markPendingSync')) {
                markPendingSync(
                    'outbound_dialplan',
                    'dialplan_rule',
                    'defaults',
                    "Default dialplan normalization rules loaded",
                    'update',
                    $_SESSION['user_id'] ?? null
                );
            }

            return t('dialplan_rules.msg_defaults_loaded');
        });
    }
}
