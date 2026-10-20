<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/helpers.php';

/**
 * Service for Ingress Dialplan / Call Normalization Rules.
 */
class DialplanRuleService
{
    public const VALID_CLIENT_TYPES = ['all', 'desktop', 'mobile', 'web'];

    /**
     * Validates a dialplan normalization rule input array.
     * Enforces Asterisk pattern format, balanced brackets, allowed pattern characters,
     * bounds on strip (0..32), length bounds, and non-duplicate patterns per client_type.
     *
     * @return array{valid: bool, errors: string[], pattern: string}
     */
    public static function validateRule(array $data, ?int $ruleId = null): array
    {
        $errors = [];

        $rule_name = trim((string)($data['rule_name'] ?? ''));
        if ($rule_name === '') {
            $errors[] = t('dialplan_rules.err_required');
        } elseif (mb_strlen($rule_name) > 100) {
            $errors[] = 'Rule name must not exceed 100 characters.';
        }

        $client_type = trim((string)($data['client_type'] ?? 'all'));
        if (!in_array($client_type, self::VALID_CLIENT_TYPES, true)) {
            $client_type = 'all';
        }

        $raw_pattern = trim((string)($data['match_pattern'] ?? ''));
        $match_pattern = $raw_pattern;
        if (!str_starts_with($match_pattern, '_')) {
            $match_pattern = '_' . $match_pattern;
        }

        if ($raw_pattern === '' || $match_pattern === '_') {
            $errors[] = t('dialplan_rules.err_pattern_invalid');
        } elseif (preg_match('/[^0-9NXZnxz.\[\]_!*#+-]/', $match_pattern)) {
            $errors[] = t('dialplan_rules.err_pattern_invalid');
        } else {
            // Bracket validation: balanced, not empty, no nested
            $open = substr_count($match_pattern, '[');
            $close = substr_count($match_pattern, ']');
            if ($open !== $close || str_contains($match_pattern, '[]')) {
                $errors[] = t('dialplan_rules.err_pattern_brackets');
            } else {
                $depth = 0;
                $bracketOk = true;
                for ($i = 0; $i < strlen($match_pattern); $i++) {
                    if ($match_pattern[$i] === '[') {
                        $depth++;
                        if ($depth > 1) { $bracketOk = false; break; }
                    } elseif ($match_pattern[$i] === ']') {
                        $depth--;
                        if ($depth < 0) { $bracketOk = false; break; }
                    }
                }
                if (!$bracketOk || $depth !== 0) {
                    $errors[] = t('dialplan_rules.err_pattern_brackets');
                }
            }

            // Wildcards (. or !) must only appear at the end of the pattern in Asterisk
            $wildPos = strcspn($match_pattern, '.!');
            if ($wildPos < strlen($match_pattern) - 1) {
                $errors[] = t('dialplan_rules.err_pattern_invalid');
            }

            if (strlen($match_pattern) > 64) {
                $errors[] = 'Match pattern must not exceed 64 characters.';
            }

            // Check duplicate pattern for same client_type
            $db = getDB();
            $checkSql = "SELECT id, rule_name FROM pbx_dialplan_rules WHERE client_type = ? AND match_pattern = ?";
            $params = [$client_type, $match_pattern];
            if ($ruleId !== null && $ruleId > 0) {
                $checkSql .= " AND id <> ?";
                $params[] = $ruleId;
            }
            $stmt = $db->prepare($checkSql);
            $stmt->execute($params);
            $existing = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($existing) {
                $errors[] = sprintf(t('dialplan_rules.err_pattern_exists'), $match_pattern, $client_type);
            }
        }

        $strip_front = intval($data['strip_front'] ?? 0);
        $strip_back = intval($data['strip_back'] ?? 0);
        if ($strip_front < 0 || $strip_front > 32 || $strip_back < 0 || $strip_back > 32) {
            $errors[] = t('dialplan_rules.err_strip_range');
        }

        $prepend = trim((string)($data['prepend'] ?? ''));
        $append = trim((string)($data['append'] ?? ''));
        if (strlen($prepend) > 32 || strlen($append) > 32) {
            $errors[] = 'Prepend and Append prefixes must not exceed 32 characters.';
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'pattern' => $match_pattern,
        ];
    }

    public static function saveRule(array $data): array
    {
        return PBXHelper::handleAction($data['csrf_token'] ?? '', function() use ($data) {
            $rule_id = intval($data['rule_id'] ?? 0);
            $client_type = trim((string)($data['client_type'] ?? 'all'));
            if (!in_array($client_type, self::VALID_CLIENT_TYPES, true)) {
                $client_type = 'all';
            }

            $validation = self::validateRule($data, $rule_id > 0 ? $rule_id : null);
            if (!$validation['valid']) {
                throw new \Exception(implode(' ', $validation['errors']));
            }

            $rule_name = trim((string)($data['rule_name'] ?? ''));
            $match_pattern = $validation['pattern'];
            $prepend = preg_replace('/[^0-9*#+]/', '', trim((string)($data['prepend'] ?? '')));
            $append = preg_replace('/[^0-9*#+]/', '', trim((string)($data['append'] ?? '')));
            $strip_front = max(0, min(32, intval($data['strip_front'] ?? 0)));
            $strip_back = max(0, min(32, intval($data['strip_back'] ?? 0)));
            $is_active = PBXHelper::boolFlag($data, 'is_active', 1);
            $description = trim((string)($data['description'] ?? ''));

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

    public static function getAllCountries(): array
    {
        static $list = null;
        if ($list !== null) {
            return $list;
        }
        $file = dirname(__DIR__, 2) . '/config/country_calling_codes.json';
        if (is_file($file)) {
            $decoded = json_decode((string)file_get_contents($file), true);
            if (is_array($decoded)) {
                $list = $decoded;
                return $list;
            }
        }
        return [];
    }

    public static function getCountryInfo(string $code): array
    {
        $code = preg_replace('/[^0-9]/', '', $code);
        $countries = self::getAllCountries();
        return $countries[$code] ?? [
            'name_tr' => "+{$code}",
            'name_en' => "+{$code}",
            'iso2' => '',
            'flag' => '🌐',
        ];
    }

    public static function getCountryName(string $code, string $lang = 'tr'): string
    {
        $info = self::getCountryInfo($code);
        if ($lang === 'tr') {
            return $info['name_tr'] ?? "+{$code}";
        }
        return $info['name_en'] ?? "+{$code}";
    }

    public static function getDefaultPresets(string $countryCode = '90'): array
    {
        $cc = preg_replace('/[^0-9]/', '', $countryCode);
        if ($cc === '' || strlen($cc) > 4) {
            $cc = '90';
        }
        $stripLen = 1 + strlen($cc); // e.g. for +90 -> 3 digits (+, 9, 0)
        $countryName = self::getCountryName($cc, 'tr');

        $presets = [
            // Desktop
            ['desktop', 'Masaüstü: Dış Hat Çıkış Kodunu (0) Temizle', '_00[1-9].', 1, '', 0, '', 1, 1, 'Masaüstü telefonlardan 0 ile dış hat alıp ulusal numara (0xxx) çevrildiğinde baştaki fazla 0ı temizler.'],
            ['desktop', 'Masaüstü: Dış Hat Uluslararası (000 -> 00)', '_000.', 1, '', 0, '', 2, 1, 'Masaüstü telefonlardan 0 dış hat koduyla uluslararası arama yapıldığında baştaki fazla 0ı temizler.'],
            ['desktop', "Masaüstü: E.164 {$countryName} (+{$cc} -> 0)", "_+{$cc}.", $stripLen, '0', 0, '', 3, 1, "Masaüstü telefonlardan veya rehberden +{$cc} ile gelen aramaları ulusal 0 formatına çevirir."],
            ['desktop', 'Masaüstü: E.164 (+) Kaldır ve 00 Yap', '_+X.', 1, '00', 0, '', 4, 1, 'Masaüstü telefonlardan + ile başlayan uluslararası numaraları standart 00 formatına çevirir.'],

            // Mobile
            ['mobile', "Mobil: E.164 {$countryName} (+{$cc} -> 0)", "_+{$cc}.", $stripLen, '0', 0, '', 1, 1, "Mobil rehberdeki +{$cc} ülke numaralarını ulusal 0 formatına çevirir."],
            ['mobile', 'Mobil: E.164 (+) Kaldır ve 00 Yap', '_+X.', 1, '00', 0, '', 2, 1, 'Mobil rehberden + ile başlayan uluslararası numaraları standart 00 formatına çevirir.'],

            // Web
            ['web', "Web: E.164 {$countryName} (+{$cc} -> 0)", "_+{$cc}.", $stripLen, '0', 0, '', 1, 1, "Web softphone veya CRM üzerinden +{$cc} ile gelen aramaları ulusal 0 formatına çevirir."],
            ['web', 'Web: E.164 (+) Kaldır ve 00 Yap', '_+X.', 1, '00', 0, '', 2, 1, 'Web softphone üzerinden + ile başlayan uluslararası numaraları standart 00 formatına çevirir.'],
        ];

        // Specific national mobile formats (e.g. Turkey 10-digit GSM 5xx -> 05xx)
        if ($cc === '90') {
            $presets[] = ['mobile', 'Mobil: 10 Haneli GSM Başına 0 Ekle', '_5XXXXXXXXX', 0, '0', 0, '', 3, 1, 'Mobil rehberde 5xx şeklinde 10 hane kaydedilmiş numaraların başına 0 ekler.'];
            $presets[] = ['web', 'Web: 10 Haneli GSM Başına 0 Ekle', '_5XXXXXXXXX', 0, '0', 0, '', 3, 1, 'Web softphone üzerinden 10 hane girilen GSM numaralarına 0 ekler.'];
        }

        return $presets;
    }

    public static function loadDefaults(string $csrfToken, string $countryCode = '90'): array
    {
        return PBXHelper::handleAction($csrfToken, function() use ($countryCode) {
            $cleanCc = preg_replace('/[^0-9]/', '', $countryCode);
            if ($cleanCc === '' || strlen($cleanCc) > 4) {
                $cleanCc = '90';
            }

            $db = getDB();
            $db->beginTransaction();
            try {
                $db->exec("DELETE FROM pbx_dialplan_rules");

                $stmt = $db->prepare(
                    "INSERT INTO pbx_dialplan_rules (client_type, rule_name, match_pattern, strip_front, prepend, strip_back, append, sort_order, is_active, description, created_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())"
                );

                foreach (self::getDefaultPresets($cleanCc) as $d) {
                    $stmt->execute($d);
                }

                $stmtSet = $db->prepare("INSERT INTO sys_settings (setting_key, setting_value) VALUES ('dialplan_rules_country_code', ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
                $stmtSet->execute([$cleanCc]);

                $db->commit();
            } catch (\Throwable $e) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                throw $e;
            }

            if (function_exists('markPendingSync')) {
                markPendingSync(
                    'outbound_dialplan',
                    'dialplan_rule',
                    'defaults',
                    "Default dialplan normalization rules loaded for country +{$cleanCc}",
                    'update',
                    $_SESSION['user_id'] ?? null
                );
            }

            return sprintf(t('dialplan_rules.msg_defaults_loaded_cc'), $cleanCc);
        });
    }
}
