<?php

declare(strict_types=1);

/**
 * Normalizes database records for JSON API output (stripping secrets and
 * decoding JSON columns) and merges partial PATCH/PUT payloads onto existing
 * database records so existing domain services receive complete payloads.
 */
final class ResourceNormalizer
{
    private const SECRET_COLUMNS = [
        'password_hash',
        'sip_password',
        'sip_auth_digest',
        'sip_secret',
        'auth_password',
        'voicemail_pin',
        'secret',
        'secret_encrypted',
        'token_hash',
        'token',
        'provision_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
    ];

    private const JSON_COLUMNS = [
        'members_json'        => 'members',
        'static_members_json' => 'static_members',
        'supervisors_json'    => 'supervisors',
        'trunks_json'         => 'trunks',
        'rules_json'          => 'rules',
        'holidays_json'       => 'holidays',
        'patterns_json'       => 'patterns',
        'scopes_json'         => 'scopes',
        'events_json'         => 'events',
        'headers_json'        => 'headers',
        'payload_json'        => 'payload',
    ];

    /**
     * Sanitize a single DB record for API output.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function sanitizeRow(array $row, bool $includeSipPassword = false): array
    {
        $out = [];
        foreach ($row as $k => $v) {
            if ($k === 'sip_password' && $includeSipPassword) {
                $out[$k] = $v;
                continue;
            }
            if (in_array($k, self::SECRET_COLUMNS, true)) {
                continue;
            }
            $out[$k] = $v;
            if (isset(self::JSON_COLUMNS[$k])) {
                $alias = self::JSON_COLUMNS[$k];
                $decoded = is_string($v) && $v !== '' ? json_decode($v, true) : [];
                $out[$alias] = is_array($decoded) ? $decoded : [];
            }
        }
        return $out;
    }

    /**
     * Sanitize a list of DB records for API output.
     *
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    public static function sanitizeList(array $rows): array
    {
        return array_map(fn(array $r) => self::sanitizeRow($r), $rows);
    }

    /**
     * Merge an existing DB row with an incoming JSON payload (for PUT/PATCH/POST)
     * and map structured JSON fields into the array keys expected by AiPBX services.
     *
     * @param array<string, mixed> $existingRow
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public static function mergeForService(string $resource, array $existingRow, array $input): array
    {
        $merged = array_merge($existingRow, $input);

        switch ($resource) {
            case 'user':
            case 'extension':
                if (isset($existingRow['id']) && !isset($merged['user_id'])) {
                    $merged['user_id'] = (int)$existingRow['id'];
                }
                break;

            case 'trunk':
                if (isset($existingRow['id']) && !isset($merged['trunk_id'])) {
                    $merged['trunk_id'] = (int)$existingRow['id'];
                }
                break;

            case 'did_route':
                if (isset($existingRow['id']) && !isset($merged['route_id'])) {
                    $merged['route_id'] = (int)$existingRow['id'];
                }
                break;

            case 'outbound_route':
                if (isset($existingRow['id']) && !isset($merged['route_id'])) {
                    $merged['route_id'] = (int)$existingRow['id'];
                }
                // Map JSON `trunks: [{"trunk_name": "...", "callerid_override": "..."}]` or existing `trunks_json`
                if (!isset($merged['trunk_name'])) {
                    $trunksList = $merged['trunks'] ?? null;
                    if (!is_array($trunksList) && !empty($merged['trunks_json'])) {
                        $trunksList = json_decode((string)$merged['trunks_json'], true);
                    }
                    if (is_array($trunksList)) {
                        $names = [];
                        $cids = [];
                        foreach ($trunksList as $item) {
                            if (is_string($item)) {
                                $names[] = $item;
                                $cids[] = '';
                            } elseif (is_array($item)) {
                                $names[] = (string)($item['trunk_name'] ?? '');
                                $cids[] = (string)($item['callerid_override'] ?? '');
                            }
                        }
                        $merged['trunk_name'] = $names;
                        $merged['trunk_cid'] = $cids;
                    }
                }
                break;

            case 'queue':
                if (isset($existingRow['id']) && !isset($merged['queue_id'])) {
                    $merged['queue_id'] = (int)$existingRow['id'];
                }
                if (!isset($merged['members']) && !empty($existingRow['members_json'])) {
                    $merged['members'] = json_decode((string)$existingRow['members_json'], true) ?: [];
                }
                if (!isset($merged['static_members']) && !empty($existingRow['static_members_json'])) {
                    $merged['static_members'] = json_decode((string)$existingRow['static_members_json'], true) ?: [];
                }
                if (!isset($merged['supervisors']) && !empty($existingRow['supervisors_json'])) {
                    $merged['supervisors'] = json_decode((string)$existingRow['supervisors_json'], true) ?: [];
                }
                break;

            case 'ivr':
                if (isset($existingRow['id']) && !isset($merged['ivr_id'])) {
                    $merged['ivr_id'] = (int)$existingRow['id'];
                }
                break;

            case 'time_condition':
                if (isset($existingRow['id']) && !isset($merged['tc_id'])) {
                    $merged['tc_id'] = (int)$existingRow['id'];
                }
                if (!isset($merged['rules']) && !empty($existingRow['rules_json'])) {
                    $merged['rules'] = json_decode((string)$existingRow['rules_json'], true) ?: [];
                }
                break;

            case 'time_group':
                if (isset($existingRow['id']) && !isset($merged['tg_id'])) {
                    $merged['tg_id'] = (int)$existingRow['id'];
                }
                if (!isset($merged['holidays']) && !empty($existingRow['holidays_json'])) {
                    $hArr = json_decode((string)$existingRow['holidays_json'], true);
                    if (is_array($hArr)) {
                        $merged['holidays'] = implode(',', $hArr);
                    }
                }
                break;

            case 'ring_group':
                if (isset($merged['members']) && is_array($merged['members']) && !isset($merged['numbers_list'])) {
                    $merged['numbers_list'] = implode(',', $merged['members']);
                }
                break;

            case 'feature_code':
                if (isset($existingRow['id']) && !isset($merged['feature_id'])) {
                    $merged['feature_id'] = (int)$existingRow['id'];
                }
                if (isset($merged['allowed_roles']) && is_array($merged['allowed_roles'])) {
                    $merged['allowed_roles'] = implode(',', $merged['allowed_roles']);
                }
                break;
        }

        return $merged;
    }
}
