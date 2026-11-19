<?php

declare(strict_types=1);

require_once __DIR__ . '/ExternalDbService.php';
require_once __DIR__ . '/WebhookService.php';
require_once __DIR__ . '/DataSourceService.php';
require_once __DIR__ . '/AgiScriptService.php';
require_once __DIR__ . '/../repositories/IvrDataStepRepository.php';

/**
 * Dynamic Routing steps (table pbx_ivr_data_steps; first built as the IVR data step).
 *
 * A step takes an input, asks a source (DataSourceService, an AGI script, a
 * value or nothing) and routes by a list of rules. The dialplan is built by
 * SyncIvrDataSteps; database and URL sources are asked through api/ivr_data_lookup.php.
 */
class IvrDataStepService
{
    public const INPUTS = ['dtmf', 'caller_id', 'did', 'variable'];
    public const SOURCES = ['database', 'saved_query', 'http', 'agi', 'value', 'none'];
    public const RULE_TYPES = ['exact', 'prefix', 'range'];
    public const MAX_RULES = 50;

    /**
     * @param array<string, mixed> $data
     * @return array{ok: bool, id?: int, errors: array<string, string>}
     */
    public static function saveStep(array $data, ?int $userId = null): array
    {
        $errors = [];
        $id = !empty($data['id']) ? (int) $data['id'] : null;
        $old = $id !== null ? IvrDataStepRepository::findById($id) : null;
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 100) {
            $errors['name'] = t('ivr_data.err_name');
        }
        $input = (string) ($data['input_source'] ?? 'dtmf');
        if (!in_array($input, self::INPUTS, true)) {
            $errors['input_source'] = t('ivr_data.err_input');
        }
        $inputVar = trim((string) ($data['input_var'] ?? ''));
        if ($input === 'variable' && !preg_match('/^[A-Za-z][A-Za-z0-9_]{0,63}$/', $inputVar)) {
            $errors['input_var'] = t('ivr_data.err_var');
        }
        $source = (string) ($data['data_source'] ?? 'database');
        if (!in_array($source, self::SOURCES, true)) {
            $errors['data_source'] = t('ivr_data.err_source');
        }

        $httpMethod = strtoupper(trim((string) ($data['http_method'] ?? 'GET')));
        // Headers carry tokens: an empty field on edit keeps the saved ones.
        $headersText = trim((string) ($data['http_headers'] ?? ''));
        $headersEnc = $headersText !== '' ? DataSourceService::sealHeaders($headersText)
            : (empty($data['http_headers_clear']) ? (string) ($old['http_headers_enc'] ?? '') : '');
        $ds = self::sourceOf([
            'data_source' => $source, 'external_db_id' => $data['external_db_id'] ?? null,
            'saved_query_id' => $data['saved_query_id'] ?? null,
            'query_sql' => trim((string) ($data['query_sql'] ?? '')), 'http_url' => trim((string) ($data['http_url'] ?? '')),
            'http_method' => $httpMethod, 'http_body' => (string) ($data['http_body'] ?? ''),
            'json_path' => trim((string) ($data['json_path'] ?? '')), 'agi_script' => (string) ($data['agi_script'] ?? ''),
            'value_template' => trim((string) ($data['value_template'] ?? '')),
        ]);
        if (in_array($source, ['database', 'http', 'value'], true)) {
            $ds['headers'] = $headersText;
            foreach (DataSourceService::validate($ds) as $field => $msg) {
                $errors[['db_id' => 'external_db_id', 'sql' => 'query_sql', 'url' => 'http_url', 'method' => 'http_method',
                    'headers' => 'http_headers', 'value' => 'value_template'][$field] ?? $field] = $msg;
            }
        } elseif ($source === 'saved_query' && $ds['db_id'] < 1) {
            $errors['saved_query_id'] = t('external_dbs.query_select_public');
        } elseif ($source === 'agi' && AgiScriptService::dialplanTarget((string) $ds['agi']) === '') {
            $errors['agi_script'] = t('data_source.err_agi');
        }

        $channelVar = trim((string) ($data['channel_var_name'] ?? 'IVR_DATA_VAL'));
        if (!preg_match('/^[A-Za-z0-9_]{1,64}$/', $channelVar)) {
            $errors['channel_var_name'] = t('ivr_data.err_var');
        }
        $prefix = strtoupper(trim((string) ($data['var_prefix'] ?? '')));
        if ($prefix !== '' && !preg_match('/^[A-Z][A-Z0-9_]{0,31}$/', $prefix)) {
            $errors['var_prefix'] = t('ivr_data.err_var');
        }
        $nameMode = (string) ($data['caller_name_mode'] ?? '');
        if (!in_array($nameMode, ['', 'prefix', 'replace'], true)) {
            $nameMode = '';
        }
        try {
            $rules = self::normalizeRules($data['rules'] ?? [], $id);
        } catch (\InvalidArgumentException $e) {
            $errors['rules'] = $e->getMessage();
            $rules = [];
        }
        if ($errors) {
            return ['ok' => false, 'errors' => $errors];
        }

        $dest = fn (string $k, string $default): array => self::dest((string) ($data[$k . '_dest_type'] ?? $default), (string) ($data[$k . '_dest_id'] ?? ''), $id);
        [$foundT, $foundI] = $dest('found', 'hangup');
        [$nfT, $nfI] = $dest('not_found', 'hangup');
        [$toT, $toI] = $dest('timeout', 'hangup');
        [$busyT, $busyI] = ($data['busy_dest_type'] ?? '') === '' ? ['', ''] : $dest('busy', 'hangup');
        $maxDigits = max(1, min(30, (int) ($data['max_digits'] ?? 10)));
        $row = [
            'name' => $name,
            'description' => trim((string) ($data['description'] ?? '')),
            'prompt_file' => FileHelper::sanitizeAudioPath((string) ($data['prompt_file'] ?? '')) ?: null,
            'invalid_prompt' => FileHelper::sanitizeAudioPath((string) ($data['invalid_prompt'] ?? '')) ?: null,
            'input_source' => $input,
            'input_var' => $input === 'variable' ? $inputVar : null,
            'max_digits' => $maxDigits,
            'min_digits' => max(1, min($maxDigits, (int) ($data['min_digits'] ?? 1))),
            'input_timeout_sec' => max(1, min(30, (int) ($data['input_timeout_sec'] ?? 5))),
            'retries' => max(0, min(5, (int) ($data['retries'] ?? 0))),
            'data_source' => $source,
            'external_db_id' => $source === 'database' ? (int) $ds['db_id'] : null,
            'saved_query_id' => $source === 'saved_query' ? (int) $data['saved_query_id'] : null,
            'query_sql' => $source === 'database' ? $ds['sql'] : null,
            'http_url' => $source === 'http' ? $ds['url'] : null,
            'http_method' => $httpMethod === 'POST' ? 'POST' : 'GET',
            'http_headers_enc' => $source === 'http' && $headersEnc !== '' ? $headersEnc : null,
            'http_body' => $source === 'http' && trim($ds['body']) !== '' ? $ds['body'] : null,
            'json_path' => $source === 'http' && $ds['json_path'] !== '' ? mb_substr($ds['json_path'], 0, 200) : null,
            'agi_script' => $source === 'agi' ? $ds['agi'] : null,
            'value_template' => $source === 'value' ? mb_substr($ds['value'], 0, 255) : null,
            'channel_var_name' => $channelVar,
            'var_prefix' => $prefix,
            'rules_json' => $rules ? json_encode($rules, JSON_UNESCAPED_UNICODE) : null,
            'caller_name_mode' => $nameMode,
            'caller_name_template' => $nameMode !== '' ? mb_substr(trim((string) ($data['caller_name_template'] ?? '')), 0, 100) : null,
            'direct_extension' => empty($data['direct_extension']) ? 0 : 1,
            'busy_dest_type' => $busyT, 'busy_dest_id' => $busyI,
            'found_dest_type' => $foundT, 'found_dest_id' => $foundI,
            'not_found_dest_type' => $nfT, 'not_found_dest_id' => $nfI,
            'timeout_dest_type' => $toT, 'timeout_dest_id' => $toI,
            'is_active' => isset($data['is_active']) ? (empty($data['is_active']) ? 0 : 1) : 1,
        ];

        $db = getDB();
        if ($id !== null) {
            $set = implode(', ', array_map(fn ($k) => "$k = ?", array_keys($row)));
            $db->prepare("UPDATE pbx_ivr_data_steps SET $set, updated_at = NOW() WHERE id = ?")->execute([...array_values($row), $id]);
        } else {
            $row['created_by'] = $userId;
            $db->prepare('INSERT INTO pbx_ivr_data_steps (' . implode(', ', array_keys($row)) . ', created_at, updated_at) VALUES ('
                . implode(', ', array_fill(0, count($row), '?')) . ', NOW(), NOW())')->execute(array_values($row));
            $id = (int) $db->lastInsertId();
        }
        if (function_exists('markPendingSync')) {
            markPendingSync('ivrs', 'ivr_data', $id, "Dynamic Routing: {$name}", $old ? 'update' : 'create', $userId);
        }
        return ['ok' => true, 'id' => $id, 'errors' => []];
    }

    /**
     * A destination of the form; a step pointing straight at itself would loop, so it hangs up instead.
     *
     * @return array{0: string, 1: string}
     */
    private static function dest(string $type, string $destId, ?int $selfId): array
    {
        $type = sanitizeDestType($type);
        $destId = toCleanAscii($destId);
        if ($selfId !== null && $type === 'ivr_data' && (int) $destId === $selfId) {
            return ['hangup', ''];
        }
        return [$type, $destId];
    }

    /**
     * The rules of the form (value -> destination), in order. Values are kept to
     * characters that are safe in the dialplan; a range needs two numbers.
     *
     * @param mixed $rules list of {type, value, value2, dest_type, dest_id}
     * @return list<array{type: string, value: string, value2: string, dest_type: string, dest_id: string}>
     */
    public static function normalizeRules(mixed $rules, ?int $selfId = null): array
    {
        if (is_string($rules)) {
            $rules = json_decode($rules, true);
        }
        $out = [];
        foreach (is_array($rules) ? $rules : [] as $r) {
            if (!is_array($r) || trim((string) ($r['value'] ?? '')) === '') {
                continue;
            }
            $type = (string) ($r['type'] ?? 'exact');
            if (!in_array($type, self::RULE_TYPES, true)) {
                throw new \InvalidArgumentException(t('ivr_data.err_rule'));
            }
            $value = trim((string) $r['value']);
            $value2 = trim((string) ($r['value2'] ?? ''));
            $num = '/^-?\d{1,15}(\.\d{1,6})?$/';
            if ($type === 'range' ? (!preg_match($num, $value) || !preg_match($num, $value2) || (float) $value > (float) $value2)
                : !preg_match('/^[\p{L}\p{N} _.+@:\/#*-]{1,64}$/u', $value)) {
                throw new \InvalidArgumentException(sprintf(t('ivr_data.err_rule_value'), $value));
            }
            [$dt, $di] = self::dest((string) ($r['dest_type'] ?? 'hangup'), (string) ($r['dest_id'] ?? ''), $selfId);
            $out[] = ['type' => $type, 'value' => $value, 'value2' => $type === 'range' ? $value2 : '', 'dest_type' => $dt, 'dest_id' => $di];
            if (count($out) > self::MAX_RULES) {
                throw new \InvalidArgumentException(t('ivr_data.err_rule_count'));
            }
        }
        return $out;
    }

    /**
     * Saved rules; anything damaged is left out rather than written into the dialplan.
     *
     * @return list<array{type: string, value: string, value2: string, dest_type: string, dest_id: string}>
     */
    public static function decodeRules(string $json): array
    {
        try {
            return self::normalizeRules($json);
        } catch (\InvalidArgumentException $e) {
            return [];
        }
    }

    /**
     * The DataSourceService settings of a step row.
     *
     * @param array<string, mixed> $step
     * @return array<string, mixed>
     */
    public static function sourceOf(array $step): array
    {
        if (($step['data_source'] ?? '') === 'saved_query') {
            require_once __DIR__ . '/ExternalDbQueryService.php';
            return ExternalDbQueryService::ivrSource((int) ($step['saved_query_id'] ?? 0));
        }
        return [
            'type' => (string) ($step['data_source'] ?? 'database'),
            'db_id' => (int) ($step['external_db_id'] ?? 0),
            'sql' => (string) ($step['query_sql'] ?? ''),
            'url' => (string) ($step['http_url'] ?? ''),
            'method' => (string) ($step['http_method'] ?? 'GET'),
            'headers_enc' => (string) ($step['http_headers_enc'] ?? ''),
            'body' => (string) ($step['http_body'] ?? ''),
            'json_path' => (string) ($step['json_path'] ?? ''),
            'agi' => (string) ($step['agi_script'] ?? ''),
            'value' => (string) ($step['value_template'] ?? ''),
        ];
    }

    /**
     * How many places route calls to this step (IVR keys, inbound routes, time
     * plans …): deleting it would leave them pointing at nothing.
     */
    public static function usageCount(int $id): int
    {
        require_once dirname(__DIR__, 2) . '/modules/destinations/DestinationRegistry.php';
        return \PBX\Destinations\DestinationRegistry::usageCount('ivr_data', (string) $id);
    }

    public static function deleteStep(int $id): bool
    {
        $stmt = getDB()->prepare('DELETE FROM pbx_ivr_data_steps WHERE id = ?');
        $stmt->execute([$id]);
        $ok = $stmt->rowCount() > 0;
        if ($ok && function_exists('markPendingSync')) {
            markPendingSync('ivrs', 'ivr_data', $id, "Dynamic Routing #{$id}", 'delete', $_SESSION['user_id'] ?? null);
        }
        return $ok;
    }

    public static function toggleActive(int $id, bool $isActive): bool
    {
        $stmt = getDB()->prepare('UPDATE pbx_ivr_data_steps SET is_active = ?, updated_at = NOW() WHERE id = ?');
        $stmt->execute([$isActive ? 1 : 0, $id]);
        $ok = $stmt->rowCount() > 0;
        if ($ok && function_exists('markPendingSync')) {
            markPendingSync('ivrs', 'ivr_data', $id, "Dynamic Routing #{$id} " . ($isActive ? 'on' : 'off'), 'update', $_SESSION['user_id'] ?? null);
        }
        return $ok;
    }

    /**
     * The lookup of a database or URL step during a call (api/ivr_data_lookup.php).
     *
     * @param array<string, string> $values variables the dialplan sent (input, caller_id, var_X, ...)
     * @return array{status: string, value: string, vars: array<string, string>, ms: int, error: string}
     */
    public static function performLookup(int $stepId, array $values): array
    {
        $step = IvrDataStepRepository::findById($stepId);
        if (!$step || empty($step['is_active']) || !in_array($step['data_source'] ?? '', ['database', 'saved_query', 'http'], true)) {
            return ['status' => 'not_found', 'value' => '', 'vars' => [], 'ms' => 0, 'error' => 'no such step'];
        }
        $known = array_filter($values, fn ($k): bool => DataSourceService::isVar((string) $k), ARRAY_FILTER_USE_KEY);
        $source = self::sourceOf($step);
        if ($step['data_source'] === 'saved_query' &&
            array_diff(DataSourceService::placeholders($source['sql'], true), ['date', 'time'], array_keys($known))) {
            return ['status' => 'timeout', 'value' => '', 'vars' => [], 'ms' => 0, 'error' => 'missing saved query parameters'];
        }
        return DataSourceService::run($source, array_map('strval', $known));
    }
}
